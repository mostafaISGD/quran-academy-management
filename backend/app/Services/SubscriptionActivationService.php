<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lesson;
use App\Models\LessonCreditAccount;
use App\Models\LessonCreditTransaction;
use App\Models\StudentLedgerEntry;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * تشغيل الاشتراك: فاتورة + رصيد حصص + جدولة الحصص.
 *
 * بتشتغل مرة واحدة أول ما الاشتراك يتحفظ، وبتتادى من
 * SubscriptionController (إضافة/تعديل) ومن أمر التجديد اليومي.
 *
 * القاعدة الأساسية: ممنوع طالبين في نفس الموعد مع نفس المعلم — فالتوليد
 * بيقفز فوق أي موعد محجوز ويكمّل في المواعيد الفاضية بس.
 */
class SubscriptionActivationService
{
    public function __construct(private TeacherAvailabilityService $availability) {}

    /**
     * @return array{
     *   invoice_id: ?int,
     *   invoice_total: float,
     *   credit_account_id: ?int,
     *   lessons_created: int,
     *   lessons_skipped: array,
     *   schedule: ?string,
     * }
     */
    public function activate(Subscription $subscription, array $slot = []): array
    {
        $result = [
            'invoice_id' => null,
            'invoice_total' => 0.0,
            'credit_account_id' => null,
            'lessons_created' => 0,
            'lessons_skipped' => [],
            'schedule' => $slot['weekdays'] ?? null,
        ];

        // الـ defaults (status/currency) مش متحمّلة على الـ model لسه بعد create()
        // — بنجيب النسخة من الـ DB عشان نشوف القيم الحقيقية
        $subscription->refresh();

        if ($subscription->status !== 'active') {
            return $result;
        }

        $result['invoice_id'] = $this->openInvoice($subscription);
        $result['invoice_total'] = (float) $subscription->price;

        $result['credit_account_id'] = $this->openCreditAccount($subscription);

        if (!empty($slot['weekdays']) && $subscription->teacher_id) {
            $scheduling = $this->scheduleLessons($subscription, $slot);
            $result['lessons_created'] = $scheduling['created'];
            $result['lessons_skipped'] = $scheduling['skipped'];
        }

        return $result;
    }

    /**
     * فاتورة مفتوحة للاشتراك — status = issued لأنها مستحقة فعلاً.
     */
    private function openInvoice(Subscription $subscription): ?int
    {
        $amount = (float) $subscription->price;
        if ($amount <= 0) {
            return null;
        }

        // فاتورة موجودة لنفس الاشتراك؟ نرجعها بدل ما نضاعف
        $existing = Invoice::where('subscription_id', $subscription->id)
            ->whereIn('status', ['draft', 'issued', 'partially_paid', 'overdue'])
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return DB::transaction(function () use ($subscription, $amount) {
            $issueDate = Carbon::parse($subscription->start_date ?? now());
            $description = 'اشتراك ' . ($subscription->program?->name ?? 'البرنامج');

            $invoice = Invoice::create([
                'organization_id' => $subscription->organization_id,
                'student_id' => $subscription->student_id,
                'subscription_id' => $subscription->id,
                'invoice_number' => 'INV-' . strtoupper(uniqid()),
                'currency' => $subscription->currency ?? 'EGP',
                'issue_date' => $issueDate->toDateString(),
                'due_date' => $issueDate->copy()->addDays(14)->toDateString(),
                'subtotal' => $amount,
                'discount' => 0,
                'tax' => 0,
                'total' => $amount,
                'paid_amount' => 0,
                'balance_due' => $amount,
                // issued مش draft — المستحق ظاهر فوراً على ولي الأمر
                'status' => 'issued',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $description,
                'item_type' => 'subscription',
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ]);

            StudentLedgerEntry::create([
                'student_id' => $subscription->student_id,
                'invoice_id' => $invoice->id,
                'type' => 'invoice',
                'debit' => $amount,
                'balance_after' => $amount,
                'currency' => $invoice->currency,
                'description' => 'فاتورة ' . $invoice->invoice_number,
                'reference_type' => 'invoice',
                'reference_id' => $invoice->id,
            ]);

            return $invoice->id;
        });
    }

    /**
     * رصيد الحصص — بيتفتح بـ lessons_included، مع حركة «رصيد أولي».
     */
    private function openCreditAccount(Subscription $subscription): ?int
    {
        $included = (int) ($subscription->lessons_included ?? 0);
        if ($included <= 0) {
            return null;
        }

        $existing = LessonCreditAccount::where('subscription_id', $subscription->id)->first();
        if ($existing) {
            return $existing->id;
        }

        return DB::transaction(function () use ($subscription, $included) {
            $account = LessonCreditAccount::create([
                'student_id' => $subscription->student_id,
                'subscription_id' => $subscription->id,
                'credit_type' => 'regular',
                'current_balance' => $included,
                'expires_at' => $subscription->end_date
                    ? Carbon::parse($subscription->end_date)->endOfDay()
                    : null,
                'status' => 'active',
            ]);

            LessonCreditTransaction::create([
                'credit_account_id' => $account->id,
                'student_id' => $subscription->student_id,
                'subscription_id' => $subscription->id,
                'lesson_id' => null,
                'type' => 'initial',
                'quantity' => $included,
                'balance_after' => $included,
                'reason' => 'رصيد الباقة عند الاشتراك',
                // الجدول مفيهاش updated_at
                'created_at' => now(),
            ]);

            return $account->id;
        });
    }

    /**
     * إلغاء الحصص المجدولة اللي بقت غلط بعد تعديل المواعيد.
     *
     * لو المعلم اتغيّر أو اليوم/الوقت اتغيّروا، أي حصة لسه في المستقبل
     * وبتتعارض مع الإعداد الجديد بتتخلى (status = cancelled) عشان ما
     * تبقاش موجودة مرتين في التقويم.
     *
     * @return int عدد اللي اتلغت
     */
    public function cancelStaleLessons(Subscription $subscription, array $slot): int
    {
        $weekdays = array_values(array_unique(array_map('intval', $slot['weekdays'] ?? [])));
        $startTime = substr($slot['start_time'] ?? '', 0, 5);
        $duration = (int) ($slot['duration_minutes'] ?? $subscription->lesson_duration_minutes ?? 30);

        if (empty($weekdays) || !preg_match('/^\d{2}:\d{2}$/', $startTime)) {
            return 0;
        }

        [$hour, $minute] = array_map('intval', explode(':', $startTime));
        $now = Carbon::now();

        $stale = Lesson::where('subscription_id', $subscription->id)
            ->where('status', 'scheduled')
            ->where('scheduled_start_at', '>=', $now)
            ->get();

        $cancelled = 0;

        foreach ($stale as $lesson) {
            $start = Carbon::parse($lesson->scheduled_start_at);

            $wrongTeacher = (int) $lesson->teacher_id !== (int) $subscription->teacher_id;
            $wrongDay = !in_array($start->dayOfWeek, $weekdays, true);
            $wrongTime = $start->format('H:i') !== $startTime;
            $wrongDuration = (int) $lesson->duration_minutes !== $duration;

            if ($wrongTeacher || $wrongDay || $wrongTime || $wrongDuration) {
                $lesson->update(['status' => 'cancelled']);
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * توليد الحصص على المواعيد المختارة خلال مدة الاشتراك.
     *
     * @return array{created:int, skipped:array}
     */
    private function scheduleLessons(Subscription $subscription, array $slot): array
    {
        $weekdays = array_values(array_unique(array_map('intval', $slot['weekdays'])));
        $startTime = substr($slot['start_time'] ?? '16:00', 0, 5);
        $duration = (int) ($slot['duration_minutes'] ?? $subscription->lesson_duration_minutes ?? 30);
        $limit = (int) ($subscription->lessons_included ?? 0);

        if (empty($weekdays) || $limit <= 0) {
            return ['created' => 0, 'skipped' => []];
        }

        // الحصص اللي متولدة فعلاً — عشان إعادة التفعيل ما تزودش حصة زيادة
        $existing = Lesson::where('subscription_id', $subscription->id)
            ->where('status', '!=', 'cancelled')
            ->count();

        $toCreate = $limit - $existing;
        if ($toCreate <= 0) {
            return ['created' => 0, 'skipped' => []];
        }

        // ما قبل النهاردة مش هنحجزه — الاشتراك يبدأ من النهاردة أو بعده
        $from = max(
            Carbon::parse($subscription->start_date ?? now())->startOfDay(),
            Carbon::today()->startOfDay(),
        );
        $to = $subscription->end_date
            ? Carbon::parse($subscription->end_date)->endOfDay()
            : $from->copy()->addMonths(3);

        [$hour, $minute] = array_map('intval', explode(':', $startTime));

        $created = 0;
        $skipped = [];

        // قاعدة أمان — مستحيل نعدّي ده في الاستخدام العادي بس يمنع
        // طلب واحد يعمل آلاف الحصص لوحده مدح بالأرقام
        $hardCap = 200;

        $cursor = $from->copy();

        while ($cursor->lte($to) && $created < $toCreate && $created < $hardCap) {
            if (in_array($cursor->dayOfWeek, $weekdays, true)) {
                $start = $cursor->copy()->setTime($hour, $minute);
                $end = $start->copy()->addMinutes($duration);

                // المعلم مشغول في الوقت ده؟ نتخطى — بس لو الخانة دي
                // فيها حصة من نفس الاشتراك يبقى هي أصلاً متجدولة
                $blocking = Lesson::query()
                    ->where('teacher_id', $subscription->teacher_id)
                    ->blockingTeacherTime()
                    ->where('scheduled_start_at', '<', $end)
                    ->where('scheduled_end_at', '>', $start)
                    ->first(['id', 'subscription_id']);

                $alreadyOurs = $blocking
                    && (int) $blocking->subscription_id === (int) $subscription->id;

                if ($blocking && !$alreadyOurs) {
                    $skipped[] = [
                        'date' => $start->toDateString(),
                        'reason' => 'المعلم عنده حصة في نفس الوقت',
                    ];
                } elseif (!$blocking) {
                    Lesson::create([
                        'organization_id' => $subscription->organization_id,
                        'student_id' => $subscription->student_id,
                        'teacher_id' => $subscription->teacher_id,
                        'subscription_id' => $subscription->id,
                        'program_id' => $subscription->program_id,
                        'lesson_type' => 'regular',
                        'scheduled_start_at' => $start,
                        'scheduled_end_at' => $end,
                        'duration_minutes' => $duration,
                        'status' => 'scheduled',
                    ]);
                    $created++;
                }
            }

            $cursor->addDay();
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
