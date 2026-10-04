<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\ParentModel;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * المعالجة اليومية للاشتراكات.
 *
 * بتتادى مرة كل يوم من أمر `subscriptions:process-day`، وممكن تتنادى
 * يدوي من زر «معالجة يوم». آمنة للتكرار: تشغيلها مرتين في نفس اليوم
 * مش بيعمل أي تكرار.
 *
 * الترتيب مهم — التجديد **قبل** الانتهاء:
 *   1. تجديد الاشتراكات اللي auto_renew و-ended أو قربت تنتهي
 *   2. إشعار «هينتهي قريب» للي لسه فاضلهم ٣ أيام
 *   3. إقفال اللي فعلاً انتهى ومتجددش
 *   4. تنبيه الحساب: رصيد أو فاتورة قديمة
 *
 * ليش الترتيب كده؟ لو أقفلنا الأول، الاشتراك اللي مرشّح للتجديد
 * هيقفل قبل ما الأمر يوصله، ومينفعش يتحي بعد ما يتقفل.
 */
class DailySubscriptionService
{
    /** كام يوم قبل الانتهاء نبعت إشعار */
    public const EXPIRY_WARNING_DAYS = 3;

    public function __construct(
        private SubscriptionActivationService $activation,
    ) {}

    /**
     * @return array{
     *   date: string,
     *   renewed: array,
     *   expiring_notified: array,
     *   expired: array,
     *   invoices_created: int,
     *   lessons_created: int,
     *   lessons_skipped: int,
     *   skipped_reasons: array<string, int>,
     *   notifications_created: int,
     * }
     */
    public function run(?Carbon $onDate = null): array
    {
        $today = ($onDate ?? Carbon::today())->copy()->startOfDay();

        $result = [
            'date' => $today->toDateString(),
            'renewed' => [],
            'expiring_notified' => [],
            'expired' => [],
            'invoices_created' => 0,
            'lessons_created' => 0,
            'lessons_skipped' => 0,
            'skipped_reasons' => [],
            'notifications_created' => 0,
        ];

        // 1) تجديد الاشتراكات المستحقة — قبل الإقفال، عشان
        //    اللي مرشّح للتجديد ما يتقفلش قبل ما يتبعت عليه
        $this->renewDue($result, $today);

        // 1) إشعار «هينتهي قريب»
        $this->notifyExpiring($result, $today);

        // 2) إقفال اللي انتهى فعلاً
        $this->expirePast($result, $today);

        return $result;
    }

    // ============================================================
    // 1) التجديد
    // ============================================================

    /**
     * تجديد الاشتراكات اللي عليها auto_renew ووصلت أو قاربت توصل
     * لنهاية المدة.
     *
     * المدّة الجديدة بتتحسب من `max(end_date, النهاردة)` — عشان اشتراك
     * خلاص عدّى شهرين ما ياخدش شهرين ببلاش.
     *
     * @param  array<string, mixed>  $result
     */
    private function renewDue(array &$result, Carbon $today): void
    {
        $due = Subscription::query()
            ->where('status', 'active')
            ->where('auto_renew', true)
            ->whereNotNull('end_date')
            // نبدأ قبل الانتهاء بيوم عشان ما نسيبش اشتراك يتقفل
            // بالليل ونلحقش نجدّده
            ->whereDate('end_date', '<=', $today->copy()->addDay()->toDateString())
            ->with(['student', 'teacher', 'plan'])
            ->orderBy('end_date')
            ->get();

        foreach ($due as $subscription) {
            //態 idempotency: لو خلّصنا له اليوم قبل كده، ما نكرّرش
            if ($this->alreadyRenewedToday($subscription, $today)) {
                continue;
            }

            $periodDays = $this->periodDays($subscription);

            if ($periodDays <= 0) {
                // باقة بالحصة الواحدة — مش ليها مدة، يعني التجديد
                // التلقائي مش منطقي. بنسيبها للأدمن.
                $result['skipped_reasons']['per_lesson'] =
                    ($result['skipped_reasons']['per_lesson'] ?? 0) + 1;
                continue;
            }

            $oldEnd = Carbon::parse($subscription->end_date)->startOfDay();
            $base = $oldEnd->lt($today) ? $today : $oldEnd;
            $newEnd = $base->copy()->addDays($periodDays);

            $slot = [
                'weekdays' => $subscription->schedule_weekdays ?? [],
                'start_time' => $subscription->schedule_start_time,
                'duration_minutes' => $subscription->lesson_duration_minutes,
            ];

            // نمدّد الأول، وبعدين نشغّل — عشان الحساب الجديد يتعمل
            // على المدة الجديدة
            $subscription->update([
                'start_date' => $subscription->start_date,
                'end_date' => $newEnd->toDateString(),
            ]);

            // فاتورة جديدة للفترة الجديدة (مش بنعدّل فاتورة القديمة)
            $invoiceId = $this->openRenewalInvoice($subscription, $periodDays);

            if ($invoiceId) {
                $result['invoices_created']++;
            }

            // رصيد الحصص للفترة الجديدة
            $accountId = $this->openRenewalCredit($subscription);

            // جدولة الحصص على المواعيد
            $activationResult = $this->activation->activate(
                $subscription->fresh(),
                $slot,
            );

            $result['lessons_created'] += $activationResult['lessons_created'];
            $result['lessons_skipped'] += count($activationResult['lessons_skipped']);

            foreach ($activationResult['lessons_skipped'] as $skip) {
                $key = $skip['reason'];
                $result['skipped_reasons'][$key] = ($result['skipped_reasons'][$key] ?? 0) + 1;
            }

            // إشعار التجديد — للأدمن والمعلم وأولياء الأمور
            $sent = $this->notifyRenewal(
                $subscription->fresh(),
                [
                    'old_end_date' => $oldEnd->toDateString(),
                    'new_end_date' => $newEnd->toDateString(),
                    'invoice_id' => $invoiceId,
                    'credit_account_id' => $accountId,
                    'lessons_created' => $activationResult['lessons_created'],
                ],
                $today,
            );
            $result['notifications_created'] += $sent;

            $result['renewed'][] = [
                'subscription_id' => $subscription->id,
                'student' => $subscription->student?->full_name,
                'teacher' => $subscription->teacher?->full_name,
                'old_end_date' => $oldEnd->toDateString(),
                'new_end_date' => $newEnd->toDateString(),
                'invoice_id' => $invoiceId,
                'lessons_created' => $activationResult['lessons_created'],
                'lessons_skipped' => count($activationResult['lessons_skipped']),
            ];
        }
    }

    /** مدة الباقة بالأيام — من الخطة، وإلا ٣٠ يوم للباقات الشهرية */
    private function periodDays(Subscription $subscription): int
    {
        $plan = $subscription->plan;

        if ($plan?->duration_days) {
            return (int) $plan->duration_days;
        }

        // مفيش خطة (الاشتراك بيتسجّل بالسعر مباشرة) — الشهرية = ٣٠ يوم
        return $subscription->billing_type === 'monthly' ? 30 : 0;
    }

    /**
     * هل اشتراك ده اتجدّد بالفعل النهاردة؟
     *
     * العلامة = فاتورة من نوع تجديد اتعملت اليوم لنفس الاشتراك.
     * العكس لازم يتحقق قبل ما نمدّد، عشان تشغيل الأمر مرتين
     * ما يعملش شهرين ببلاش.
     */
    private function alreadyRenewedToday(Subscription $subscription, Carbon $today): bool
    {
        return Invoice::where('subscription_id', $subscription->id)
            ->where('issue_date', $today->toDateString())
            ->where('status', '!=', 'void')
            ->exists();
    }

    /**
     * فاتورة تجديد — مستقلة عن فاتورة الفترة الأولى.
     *
     * ليش مش بنعدّل فاتورة الفترة القديمة؟ عشان سجل الفواتير يفضل
     * صحيح: الفاتورة القديمة تفضل على حالها (مدفوعة/متأخرة) والفترة
     * الجديدة ليها فاتورتها.
     */
    private function openRenewalInvoice(Subscription $subscription, int $periodDays): ?int
    {
        if ((float) $subscription->price <= 0) {
            return null;
        }

        $existing = Invoice::where('subscription_id', $subscription->id)
            ->where('issue_date', Carbon::today()->toDateString())
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return DB::transaction(function () use ($subscription, $periodDays) {
            $issueDate = Carbon::today();
            $amount = (float) $subscription->price;

            $invoice = Invoice::create([
                'organization_id' => $subscription->organization_id,
                'student_id' => $subscription->student_id,
                'subscription_id' => $subscription->id,
                'invoice_number' => 'INV-' . strtoupper(uniqid()),
                'currency' => $subscription->currency ?? 'EGP',
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->copy()->addDays(7)->toDateString(),
                'subtotal' => $amount,
                'discount' => 0,
                'tax' => 0,
                'total' => $amount,
                'paid_amount' => 0,
                'balance_due' => $amount,
                'status' => 'issued',
            ]);

            $invoice->items()->create([
                'description' => 'تجديد اشتراك — ' . $periodDays . ' يوم',
                'item_type' => 'subscription',
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ]);

            // الرصيد بعد تحميل الفاتورة = الرصيد قبلها + المبلغ
            $balanceAfter = $this->currentBalance($subscription->student_id) + $amount;

            DB::table('student_ledger_entries')->insert([
                'student_id' => $subscription->student_id,
                'invoice_id' => $invoice->id,
                'type' => 'invoice',
                'debit' => $amount,
                'balance_after' => $balanceAfter,
                'currency' => $invoice->currency,
                'description' => 'فاتورة تجديد ' . $invoice->invoice_number,
                'reference_type' => 'invoice',
                'reference_id' => (string) $invoice->id,
                'created_at' => now(),
            ]);

            return $invoice->id;
        });
    }

    /** رصيد الطالب الحالي من كشف الحساب — عشان السطر الجديد يبقى صحيح */
    private function currentBalance(int $studentId): float
    {
        $last = DB::table('student_ledger_entries')
            ->where('student_id', $studentId)
            ->orderByDesc('id')
            ->first();

        return (float) ($last->balance_after ?? 0);
    }

    /**
     * رصيد حصص جديد للفترة الجديدة.
     *
     * الرصيد القديم بيتقفل (expired) مش بيتشال — عشان التقارير
     * تبان استهلك كام في كل فترة.
     */
    private function openRenewalCredit(Subscription $subscription): ?int
    {
        $included = (int) ($subscription->lessons_included ?? 0);
        if ($included <= 0) {
            return null;
        }

        return DB::transaction(function () use ($subscription, $included) {
            DB::table('lesson_credit_accounts')
                ->where('subscription_id', $subscription->id)
                ->where('status', 'active')
                ->update(['status' => 'expired', 'updated_at' => now()]);

            $accountId = DB::table('lesson_credit_accounts')->insertGetId([
                'student_id' => $subscription->student_id,
                'subscription_id' => $subscription->id,
                'credit_type' => 'regular',
                'current_balance' => $included,
                'expires_at' => $subscription->end_date
                    ? Carbon::parse($subscription->end_date)->endOfDay()
                    : null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('lesson_credit_transactions')->insert([
                'credit_account_id' => $accountId,
                'student_id' => $subscription->student_id,
                'subscription_id' => $subscription->id,
                'lesson_id' => null,
                'type' => 'initial',
                'quantity' => $included,
                'balance_after' => $included,
                'reason' => 'رصيد فترة جديدة — تجديد',
                'created_at' => now(),
            ]);

            return $accountId;
        });
    }

    // ============================================================
    // 2) إشعار «هينتهي قريب»
    // ============================================================

    /** @param array<string, mixed> $result */
    private function notifyExpiring(array &$result, Carbon $today): void
    {
        $warningEnd = $today->copy()->addDays(self::EXPIRY_WARNING_DAYS);

        $expiring = Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $warningEnd->toDateString())
            // اللي اتجدّد النهاردة خلاص اتجدّد — ما نبعتش إشعار «هينتهي»
            ->where(function ($q) use ($today) {
                $q->where('auto_renew', false)
                    ->orWhereDate('end_date', '>', $today->copy()->addDay()->toDateString());
            })
            ->with(['student', 'teacher', 'plan'])
            ->get();

        foreach ($expiring as $subscription) {
            $daysLeft = (int) Carbon::today()->diffInDays(
                Carbon::parse($subscription->end_date)->startOfDay(),
                false,
            );

            $sent = $this->notify(
                'subscription_expiring',
                $subscription,
                [
                    'title' => 'اشتراك ' . ($subscription->student?->full_name ?? '') . ' هينتهي قريب',
                    'message' => $daysLeft === 0
                        ? 'الاشتراك خلاص انتهى النهاردة — لازم تجديد أو إنهاء.'
                        : "فاضل {$daysLeft} يوم على نهاية الاشتراك.",
                    'end_date' => $subscription->end_date->toDateString(),
                    'days_left' => $daysLeft,
                    'auto_renew' => (bool) $subscription->auto_renew,
                ],
                $today,
            );

            if ($sent > 0) {
                $result['notifications_created'] += $sent;
                $result['expiring_notified'][] = [
                    'subscription_id' => $subscription->id,
                    'student' => $subscription->student?->full_name,
                    'end_date' => $subscription->end_date->toDateString(),
                    'days_left' => $daysLeft,
                    'recipients' => $sent,
                ];
            }
        }
    }

    // ============================================================
    // 3) إقفال اللي انتهى
    // ============================================================

    /** @param array<string, mixed> $result */
    private function expirePast(array &$result, Carbon $today): void
    {
        $expired = Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', $today->toDateString())
            ->with(['student', 'teacher'])
            ->get();

        foreach ($expired as $subscription) {
            // ما نقفلش اشتراك اتجدّد النهاردة
            if (Invoice::where('subscription_id', $subscription->id)
                ->where('issue_date', $today->toDateString())
                ->where('status', '!=', 'void')
                ->exists()) {
                continue;
            }

            $subscription->update(['status' => 'expired']);

            // الحصص المجدولة اللي لسه ما حصلت — نلغيها
            $cancelledLessons = Lesson::where('subscription_id', $subscription->id)
                ->where('status', 'scheduled')
                ->where('scheduled_start_at', '>=', $today)
                ->update(['status' => 'cancelled']);

            // رصيد الحصص يتقفل
            DB::table('lesson_credit_accounts')
                ->where('subscription_id', $subscription->id)
                ->where('status', 'active')
                ->update(['status' => 'expired', 'updated_at' => now()]);

            $sent = $this->notify(
                'subscription_expired',
                $subscription,
                [
                    'title' => 'انتهى اشتراك ' . ($subscription->student?->full_name ?? ''),
                    'message' => 'الاشتراك انتهى في ' . $subscription->end_date->toDateString()
                        . ' — اتقفل وحصصه المجدولة اتلغت.',
                    'end_date' => $subscription->end_date->toDateString(),
                    'lessons_cancelled' => $cancelledLessons,
                ],
                $today,
            );

            $result['notifications_created'] += $sent;
            $result['expired'][] = [
                'subscription_id' => $subscription->id,
                'student' => $subscription->student?->full_name,
                'end_date' => $subscription->end_date->toDateString(),
                'lessons_cancelled' => $cancelledLessons,
                'recipients' => $sent,
            ];
        }
    }

    // ============================================================
    // الإشعارات
    // ============================================================

    /**
     * إشعار تجديد.
     *
     * @param  array<string, mixed>  $payload
     * @return int عدد اللي اتبعتوا فعلاً
     */
    private function notifyRenewal(Subscription $subscription, array $payload, Carbon $today): int
    {
        return $this->notify('subscription_renewed', $subscription, [
            'title' => 'اتجدّد اشتراك ' . ($subscription->student?->full_name ?? ''),
            'message' => 'الاشتراك اتمدّد لحد ' . $payload['new_end_date']
                . ($payload['lessons_created'] > 0
                    ? " و اتجدولت {$payload['lessons_created']} حصة."
                    : ''),
        ] + $payload, $today);
    }

    /**
     * يبعت الإشعار لكل ليهم حق: الأدمن، المعلم، وأولياء الأمور.
     *
     * التكرار بيتمنع بـ payload.event_key — نفس المفتاح = نفس الإشعار،
     * مهما اتشغّل الأمر كام مرة.
     *
     * @param  array<string, mixed>  $payload
     * @return int عدد المستلمين
     */
    private function notify(string $eventType, Subscription $subscription, array $payload, Carbon $today): int
    {
        $eventKey = $eventType . ':' . $subscription->id . ':' . ($payload['end_date'] ?? $today->toDateString());

        $recipients = $this->recipients($subscription);

        $sent = 0;

        // المفتاح لازم يكون لكل مستخدم — «إشعار اتبعت للأدمن» مش معناها
        // «اتبعت للمعلم». قبل كده كان المفتاح لوحده فكان أول مستلم
        // بس هو اللي بياخد الإشعار والباقي بيترفضوا على إن المفتاح
        // موجود.
        $sentKeys = $this->existingEventKeys($recipients);

        foreach ($recipients as $userId) {
            $key = $userId . '|' . $eventKey;

            if (isset($sentKeys[$key])) {
                continue;
            }

            Notification::create([
                'user_id' => $userId,
                'event_type' => $eventType,
                'channel' => 'in_app',
                'payload' => $payload + [
                    'event_key' => $eventKey,
                    'entity_type' => 'subscription',
                    'entity_id' => $subscription->id,
                ],
                'sent_at' => now(),
            ]);

            $sentKeys[$key] = true;
            $sent++;
        }

        return $sent;
    }

    /**
     * مين ياخد الإشعار؟
     *
     * - كل الأدمن (مستخدم من غير حساب معلم)
     * - المعلم المسؤول عن الاشتراك
     * - كل أولياء الأمور اللي عندهم can_receive_notifications
     *
     * @return int[] معرّفات المستخدمين
     */
    private function recipients(Subscription $subscription): array
    {
        $ids = User::query()
            ->where('is_parent', false)
            ->whereNotIn('id', Teacher::query()->whereNotNull('user_id')->pluck('user_id'))
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if ($subscription->teacher_id) {
            $teacherUserId = Teacher::where('id', $subscription->teacher_id)->value('user_id');
            if ($teacherUserId) {
                $ids[] = (int) $teacherUserId;
            }
        }

        $parentUserIds = ParentModel::query()
            ->whereIn('id', DB::table('student_parents')
                ->where('student_id', $subscription->student_id)
                ->where('can_receive_notifications', true)
                ->pluck('parent_id'))
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();

        return array_values(array_unique(array_map('intval', array_merge($ids, $parentUserIds))));
    }

    /**
     * المفاتيح اللي اتبعتت قبل كده، بصيغة "userId|eventKey".
     *
     * بنجيبها مرة واحدة بدل query لكل مستلم. من غير البادئة هتبقى
     * المفاتيح متشابهة بين المستخدمين المختلفين.
     *
     * @param  int[]  $userIds
     * @return array<string, true>
     */
    private function existingEventKeys(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $rows = DB::table('notifications')
            ->whereIn('user_id', $userIds)
            ->whereNotNull('payload')
            ->get(['user_id', 'payload']);

        $keys = [];

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);
            $key = $payload['event_key'] ?? null;
            if ($key) {
                $keys[$row->user_id . '|' . $key] = true;
            }
        }

        return $keys;
    }
}
