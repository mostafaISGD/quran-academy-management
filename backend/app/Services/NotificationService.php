<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Payment;
use App\Models\Student;
use App\Models\User;
use App\Support\DeliveryDeferredException;
use App\Support\DeliveryRetryableException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ⭐⭐⭐ **PR 4.1 — الخدمة الوحيدة للإشعارات.**
 *
 * ═══════════════════════════════════════════════════════════════
 *
 * - كل بناء إشعار يعود إلى هنا: **الصندوق** (`notifications`)
 *   لقناة `database`، و**التسليمات** (`notification_deliveries`)
 *   لكل قناة خارجية.
 * - ⭐ **القاعدة الذهبية (حُجّة القبول)**: `sent_at` في
 *   التسليمات لا يُكتب إلا بعد نجاح مزود حقيقي — بلا أي مسار
 *   آخر يكتبه. كاذبة «تم الإرسال بلا إرسال» هي علة
 *   bulkNotify القديمة ولا تعود.
 * - تفضيلات المستخدم تحظر القنوات المعطّلة، وساعات الهدوء
 *   (من مركز الإعدادات) تؤجل إرسال القنوات الخارجية.
 * - فشل المزود يترك الصف `failed` **بنص السبب**، وسقف
 *   المحاولات يمنع إعادة بلا نهاية.
 */
class NotificationService
{
    /** ⭐ سقف محاولات التسليم لكل صف. */
    public const MAX_ATTEMPTS = 3;

    /** ⭐ قناة الصندوق — تسليمها هو كتابة صف `notifications` نفسه. */
    private const DATABASE_CHANNEL = 'database';

    /**
     * ⭐ المفردة الموافقة لعمود `notifications.event_type`
     * (آخر حالة في `2026_10_21_000009` + إرجاع
     * `subscription_renewed` التي تفعّلها ميجريشن هذا PR).
     */
    /**
     * ⭐ قائمة الأحداث المطابقة لعمود `notifications.event_type`
     * (آخر حالة في `2026_10_24_000005` — أحداث PR 4.2
     * اللحظية الخمسة + مفردة PR 4.1).
     */
    public const EVENT_TYPES = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'subscription_renewed', 'subscription_paused', 'subscription_resumed',
        'payment_received', 'makeup_created', 'trial_reminder',
        'announcement', 'lesson_completed', 'invoice_overdue',
        'invoice_issued', 'student_absence', 'teacher_absence',
        'salary_paid', 'lesson_canceled',
    ];

    /**
     * ⭐⭐⭐ **بناء إشعار واحد لمستلم واحد (User أو Student).**
     *
     * ما يعمل:
     *
     * 1. يتثبت أن الحدث ضمن المفردة أعلاه — وإلا خطأ برمجي صريح.
     * 2. يرشِّح القنوات على تفضيلات المستخدم (القناة المعطلة
     *    لا تُبنى لها تسليمات إطلاقاً).
     * 3. إذا كانت `database` ضمنها (أو طُلب صندوق مع قناة
     *    خارجية) — يكتب **الصندوق** بـ`sent_at = now()`
     *    (الوصول للصندوق هو تسليم `in_app` وسببه صادق)،
     *    متطلباً حساب مستخدم للمستلم — مستلم بلا حساب ما له
     *    صندوق يُقرأ، فالتسليمات الخارجية وحدها تكفيه.
     * 4. لكل قناة خارجية — صف تسليم `queued` على الطابور،
     *    `sent_at = null`، والنص محوَّل من قالب الحدث إن وُجد.
     *
     * @param  User|Student  $recipient  المستلم — لتصنيفه على أي مستوى
     * @param  array<string, mixed>  $data  بيانات الحدث (title/message
     *                                        وعناصر الإحلال)
     * @param  list<string>|null  $channels  قنوات مختارة؛ الافتراضي database
     * @param  bool  $writeInbox  كتابة الصندوق حتي لو ما فيهش database
     * @return NotificationDelivery[] صفوف التسليمات المستحدثة
     *
     * @throws \InvalidArgumentException حدث خارج المفردة المعروفة
     */
    public function dispatch(
        string $event,
        Model $recipient,
        array $data = [],
        ?array $channels = null,
        bool $writeInbox = true,
        ?string $referenceKey = null,
    ): array {
        if (! in_array($event, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("حدث إشعار غير معرَّف: {$event}");
        }

        $orgId = $this->organizationIdOf($recipient);
        [$user, $student] = $this->recipientParts($recipient);
        $lessonId = $data['lesson_id'] ?? null;

        // ⭐⭐ PR 4.2 — مفتاح منع التكرار: كل (حدث، مستلم، مرجع)
        // يُبنى صفّاً واحداً فقط. الصيغة: event|user|reference —
        // مستلم بلا حساب يحمل «s{id}» (تسليماته الخارجية تُختم
        // بهوية الطالب نفسها). الحسم في القاعدة (فهرس فريد) لا
        // في الكاش — تفريغ الكاش لا يفتح باب إعادة الإرسال.
        $dedupKey = null;
        if ($referenceKey !== null) {
            $bearer = $user?->id ?? ($student?->id !== null ? 's'.$student->id : null);

            $dedupKey = $bearer === null
                ? null
                : $event.'|'.$bearer.'|'.$referenceKey;
        }

        if ($dedupKey !== null
            && Notification::query()->where('dedup_key', $dedupKey)->exists()) {
            return []; // أُرسل سلفاً — إعادة التشغيل بلا أثر.
        }

        $requested = $channels === null
            ? [self::DATABASE_CHANNEL]
            : array_values(array_unique($channels));
        $requested = $this->filterByPreferences($user, $requested);

        $deliveries = [];

        try {
            DB::transaction(function () use (&$deliveries, $event, $orgId, $user, $student, $lessonId, $data, $requested, $writeInbox, $dedupKey) {
                // الصندوق: قناة database صراحةً، أو تاريخ إداري مع
                // قناة خارجية ($writeInbox). ⚠️ الصندوق = صندوق
                // **مستخدم** (`user_id` قيد NOT NULL في الجدول) —
                // مستلم بلا حساب يحصل على تسليمات قنواته الخارجية
                // فقط، بلا صف صندوق يتيم لا يقرأه أحد.
                $hasExternal = collect($requested)->contains(fn ($c) => $c !== self::DATABASE_CHANNEL);
                $wantsInbox = in_array(self::DATABASE_CHANNEL, $requested, true)
                    || ($hasExternal && $writeInbox);

                if ($wantsInbox && $user !== null) {
                    Notification::create([
                        'user_id' => $user?->id,
                        'student_id' => $student?->id,
                        'lesson_id' => $lessonId,
                        'event_type' => $event,
                        'channel' => 'in_app',
                        'payload' => array_merge($data, [
                            'title' => (string) ($data['title'] ?? ''),
                            'message' => (string) ($data['message'] ?? ($data['title'] ?? '')),
                        ]),
                        // كتابة الصندوق هي تسليم in_app — ساعتها صادقة.
                        'sent_at' => now(),
                        // ⭐ PR 4.1 + 4.2 — مفتاح منع التكرار (فارغ = لا قيد).
                        'dedup_key' => $dedupKey,
                    ]);
                }

                foreach ($requested as $channel) {
                    if ($channel === self::DATABASE_CHANNEL) {
                        continue; // الصندوق أعلاه هو تسليمها — بلا صف مضاعف.
                    }

                    [$title, $body] = $this->renderViaTemplate($event, $channel, $data, $orgId);

                    $deliveries[] = NotificationDelivery::create([
                        'organization_id' => $orgId,
                        'user_id' => $user?->id,
                        'student_id' => $student?->id,
                        'event' => $event,
                        'channel' => $channel,
                        // ⭐ القاعدة الذهبية: `queued` بدون sent_at —
                        // الساعة تأتي فقط من `attemptDelivery`.
                        'payload' => array_merge($data, ['title' => $title, 'body' => $body]),
                        'status' => 'queued',
                        'attempts' => 0,
                    ]);
                }
            });
        } catch (UniqueConstraintViolationException) {
            // سباق الـdedup (كاتبان متزامنان بنفس المفتاح) —
            // الكاتب الأول فاز، والثاني ينسحب بلا أثر.
            return [];
        }

        // الطابور — الخدمة تُبنى التسليمات، والجوب ينفّذها.
        foreach ($deliveries as $delivery) {
            DeliverNotification::dispatch($delivery->id);
        }

        return $deliveries;
    }

    /**
     * ⭐⭐⭐ **PR 4.2 — كتابة صندوقية مستقلة لمسار «صف لكل مستلم».**
     *
     * مسار الأوامر المجدولة القديمة (`DailySubscriptionService`
     * · `invoices:notify-overdue` · الإيقاف/الاستئناف) كان يكتب
     * `Notification::create` مباشرة لكل مستلم — نُقل إلى البنية
     * عبر هذا المدخل: صف واحد بمفتاح منع تكرار اختياري بلا
     * تسليمات خارجية ولا جوبات.
     *
     * @param  array<string, mixed>  $payload  الـpayload كما حدده
     *   المسار الأصلي (يُحفظ كما هو — بلا دمج مضاف) لسلسلة
     *   الصفوف التاريخية.
     * @param  array{student_id?: int|null, subscription_id?: int|null, lesson_id?: int|null}  $context
     * @return bool كُتب فعلاً (false = أُرسل سلفاً بنفس المفتاح)
     */
    public function dispatchInbox(
        string $event,
        User $user,
        array $payload,
        ?string $referenceKey = null,
        array $context = [],
    ): bool {
        if (! in_array($event, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("حدث إشعار غير معرَّف: {$event}");
        }

        $dedupKey = $referenceKey === null
            ? null
            : $event.'|'.$user->id.'|'.$referenceKey;

        if ($dedupKey !== null
            && Notification::query()->where('dedup_key', $dedupKey)->exists()) {
            return false;
        }

        try {
            DB::transaction(function () use ($event, $user, $payload, $dedupKey, $context): void {
                Notification::create([
                    'user_id' => $user->id,
                    'student_id' => $context['student_id'] ?? null,
                    'subscription_id' => $context['subscription_id'] ?? null,
                    'lesson_id' => $context['lesson_id'] ?? null,
                    'event_type' => $event,
                    'channel' => 'in_app',
                    'payload' => $payload,
                    // كتابة الصندوق هي تسليم in_app — ساعتها صادقة.
                    'sent_at' => now(),
                    'dedup_key' => $dedupKey,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return false; // سباق الكاتبين — الأول فاز.
        }

        return true;
    }

    /**
     * ⭐⭐⭐ **PR 4.2 — إرسال لحظي محمي.**
     *
     * خطّافات الأحداث (صرف مرتب، تسجيل غياب...) تعمل داخل
     * معاملات مالية — فشل الإشعار **لا يجوز** أن يثقل الفعل
     * الأصلي: الالتقاط هنا مع تسجيل صريح في السجل.
     */
    public function safeDispatch(
        string $event,
        Model $recipient,
        array $data = [],
        ?string $referenceKey = null,
    ): void {
        try {
            $this->dispatch($event, $recipient, $data, [self::DATABASE_CHANNEL], true, $referenceKey);
        } catch (\Throwable $e) {
            Log::error('Notification dispatch failed for event', [
                'event' => $event,
                'reference_key' => $referenceKey,
                'recipient' => get_class($recipient).'#'.$recipient->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * ⭐ PR 4.2 — مستقبلات حسابات الطالب: حساب الطالب نفسه + حسابات
     * أولياء الأمر **المؤكدين** فقط (نمط الصلة الموثقة نفسه).
     *
     * @return list<User>
     */
    public function studentAccountUsers(Student $student): array
    {
        $users = [];

        if ($student->user !== null) {
            $users[$student->user->id] = $student->user;
        }

        foreach ($student->parents()->wherePivot('status', 'verified')->with('user')->get() as $parent) {
            if ($parent->user !== null) {
                $users[$parent->user->id] ??= $parent->user;
            }
        }

        return array_values($users);
    }

    // ═══════════════════════════════════════════════════════════
    // ⭐⭐ أحداث PR 4.2 اللحظية — نقاط الدمج الموحّدة (dedup مضمن)
    // ═══════════════════════════════════════════════════════════

    /** إصدار فاتورة — طالب الحساب وأولياء المؤكدين، مفتاح لكل فاتورة. */
    public function notifyInvoiceIssued(Invoice $invoice): void
    {
        $dueDate = optional($invoice->due_date)->toDateString();

        foreach ($this->recipientsFor($invoice->student) as $user) {
            $this->safeDispatch('invoice_issued', $user, [
                'title' => 'فاتورة جديدة صدرت',
                'message' => sprintf(
                    'صدرت الفاتورة %s بمبلغ %s %s — تُستحق في %s.',
                    (string) $invoice->invoice_number,
                    number_format((float) $invoice->total, 2),
                    (string) $invoice->currency,
                    (string) ($dueDate ?? 'بلا تحديد'),
                ),
                'invoice_id' => $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'student_id' => $invoice->student_id,
                'amount_due' => (float) $invoice->balance_due,
                'currency' => (string) $invoice->currency,
                'due_date' => $dueDate,
            ], 'invoice:'.$invoice->id);
        }
    }

    /** استلام دفعة — مفتاح لكل (مستلم، دفعة). */
    public function notifyPaymentReceived(Payment $payment): void
    {
        foreach ($this->recipientsFor($payment->student) as $user) {
            $this->safeDispatch('payment_received', $user, [
                'title' => 'تم استلام دفعة',
                'message' => sprintf(
                    'استُلمت دفعة بمبلغ %s %s عن الطالب %s.',
                    number_format((float) $payment->amount, 2),
                    (string) $payment->currency,
                    (string) ($payment->student?->full_name ?? '—'),
                ),
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoice_id,
                'amount' => (float) $payment->amount,
                'currency' => (string) $payment->currency,
            ], 'payment:'.$payment->id);
        }
    }

    /** غياب طالب (أو بعذر) — مفتاحٍ لكل (حصة، طالب). */
    public function notifyStudentAbsence(Lesson $lesson, int $studentId, string $status): void
    {
        $student = $lesson->students->firstWhere('id', $studentId)
            ?? Student::query()->find($studentId);

        if ($student === null) {
            return;
        }

        foreach ($this->recipientsFor($student) as $user) {
            $this->safeDispatch('student_absence', $user, [
                'title' => 'غياب في الحصة',
                'message' => sprintf(
                    'سُجّل غياب الطالب %s (%s) في حصة %s.',
                    (string) $student->full_name,
                    (string) $status,
                    (string) ($lesson->scheduled_start_at?->format('Y-m-d H:i') ?? '—'),
                ),
                'lesson_id' => $lesson->id,
                'student_id' => $studentId,
                'status' => $status,
            ], 'lesson:'.$lesson->id.':absence:'.$studentId);
        }
    }

    /** غياب المعلم — يوصل لأولياء الأمر والحساب المؤكد. */
    public function notifyTeacherAbsence(Lesson $lesson): void
    {
        foreach ($this->recipientsForStudentSide($lesson) as $user) {
            $this->safeDispatch('teacher_absence', $user, [
                'title' => 'غياب المعلم',
                'message' => sprintf(
                    'غاب المعلم عن حصة %s — ستُعوّض الحصة حسب السياسة.',
                    (string) ($lesson->scheduled_start_at?->format('Y-m-d H:i') ?? '—'),
                ),
                'lesson_id' => $lesson->id,
            ], 'lesson:'.$lesson->id.':teacher-absent');
        }
    }

    /** إلغاء الحصة — ولي الأمر + المعلم معاً. */
    public function notifyLessonCancelled(Lesson $lesson): void
    {
        $reason = trim((string) ($lesson->cancellation_reason ?? ''));

        foreach ($this->recipientsForStudentSide($lesson) as $user) {
            $this->safeDispatch('lesson_canceled', $user, [
                'title' => 'أُلغيت الحصة',
                'message' => sprintf(
                    'أُلغيت حصة %s%s.',
                    (string) ($lesson->scheduled_start_at?->format('Y-m-d H:i') ?? '—'),
                    $reason !== '' ? ' — السبب: '.$reason : '',
                ),
                'lesson_id' => $lesson->id,
            ], 'lesson:'.$lesson->id.':cancelled');
        }

        if (($teacherUser = $lesson->teacher?->user) !== null) {
            $this->safeDispatch('lesson_canceled', $teacherUser, [
                'title' => 'أُلغيت حصتك',
                'message' => sprintf(
                    'أُلغيت حصة %s داخل الأكاديمية — راجع جدولك.',
                    (string) ($lesson->scheduled_start_at?->format('Y-m-d H:i') ?? '—'),
                ),
                'lesson_id' => $lesson->id,
            ], 'lesson:'.$lesson->id.':cancelled');
        }
    }

    /**
     * مستقبلات صالحة لحدث مرتبط بطالب (مستخدم الطالب أو الدفعة
     * الفارغة) — تنظر في الصلة الأصلية إن وُجدت.
     *
     * @return list<User>
     */
    private function recipientsFor(?Student $student): array
    {
        if ($student === null) {
            return [];
        }

        return $this->studentAccountUsers($student);
    }

    /**
     * مستقبلات حدث الحصة (غياب/إلغاء) — حسابات أولياء فقط؛
     * حساب المعلم ينفصل في `notifyLessonCancelled`.
     *
     * @return list<User>
     */
    private function recipientsForStudentSide(Lesson $lesson): array
    {
        $users = [];

        if (($student = $lesson->student) !== null) {
            $users += $this->recipientsFor($student);
        }

        foreach ($lesson->students as $groupStudent) {
            $users += $this->recipientsFor($groupStudent);
        }

        return $users;
    }

    /**
     * ⭐⭐⭐ **محاولة تسليم صف واحد (محور العامل والقيادة اليدوية).**
     *
     * عمليتان منفصلتان عمداً:
     *
     * ① معاملة قفل الصف (`lockForUpdate`) تحسم كل حالات:
     *    - نجاح ⇒ `sent` + `sent_at` + `external_id` ✅.
     *    - فشل وما زالت محاولات متاحة ⇒ `failed` + السبب، ثم
     *      رمي `DeliveryRetryableException` **خارج أي معاملة**
     *      — الرمي من داخل المعاملة كان يرجّع كتابة الحالة
     *      ويبطل السجل (علّة القبول الأولى، دُرست بإختبار).
     *    - فشل واستُنفد السقف ⇒ `failed` نهائية — بلا رمية.
     *    - ساعات الهدوء/قناة مؤجلة (whatsapp) ⇒
     *      `DeliveryDeferredException` — الصف يبقى `queued`
     *      بلا عدّ محاولات.
     *
     * ② استدعاء المزود يحدث **خارج القفل** (شبكة بطيئة ما
     *    تحبس الصف) ثم معاملة قصيرة تحره ثم تُختم الحالة.
     */
    public function attemptDelivery(int $deliveryId): bool
    {
        /** @var array{action: string, provider_class: string|null}|null $plan */
        $plan = DB::transaction(function () use ($deliveryId) {
            /** @var NotificationDelivery|null $delivery */
            $delivery = NotificationDelivery::query()
                ->whereKey($deliveryId)
                ->lockForUpdate()
                ->first();

            if ($delivery === null) {
                return null; // صف مفقود (حذف متراص مثلاً) — لا شيء نفعله.
            }

            if ($delivery->channel === self::DATABASE_CHANNEL) {
                // الصندوق نفسه تسليمها — صف قاعدة بيانات لا يُعاد.
                return ['action' => 'already'];
            }

            if ($delivery->status === 'sent') {
                return ['action' => 'already']; // مقدَّم — idempotent للعامل المتكرر.
            }

            if ($delivery->status === 'failed'
                && (int) $delivery->attempts >= self::MAX_ATTEMPTS) {
                return ['action' => 'already']; // نهائية سلفاً — بلا محاولات إضافية.
            }

            if ($this->isChannelDeferred($delivery->channel)) {
                return ['action' => 'deferred']; // مؤجلة بقرار صريح (واتساب المقفول أو قناة معلنة).
            }

            if ($this->inQuietHours($delivery->channel, (int) $delivery->organization_id)) {
                return ['action' => 'deferred']; // مؤجل بإرادة النظام.
            }

            $providerClass = config("notifications.providers.{$delivery->channel}");

            if (empty($providerClass)) {
                $delivery->attempts = (int) $delivery->attempts + 1;
                $delivery->error = "لا يوجد مزود معرَّف للقناة {$delivery->channel} في config/notifications.php";
                $delivery->status = 'failed';
                $delivery->save();

                return ['action' => 'already']; // نهائية — إعادتها بلا مزود لا تصلح شيئاً.
            }

            // عدّاد المحاولة يُثبَّت **قبل** الاتصال — تسربات
            // العمليات (process kill) تبقى مقيدة بالمجموع.
            $delivery->attempts = (int) $delivery->attempts + 1;
            $delivery->save();

            return ['action' => 'attempt', 'provider_class' => $providerClass];
        });

        if ($plan === null) {
            return false;
        }

        if ($plan['action'] === 'already') {
            return true;
        }

        if ($plan['action'] === 'deferred') {
            throw new DeliveryDeferredException('الإرسال مؤجل — داخل ساعات الهدوء أو قناة معلّقة بمواصفة');
        }

        // ═══════════ عملية المزود — خارج القفل ═══════════

        $delivery = NotificationDelivery::query()->whereKey($deliveryId)->firstOrFail();

        try {
            $result = app()->make($plan['provider_class'])->send($delivery, $delivery->payload ?? []);
        } catch (\Throwable $e) {
            DB::transaction(function () use ($delivery, $e): void {
                $delivery->error = mb_substr($e->getMessage() ?: class_basename($e), 0, 200);
                $delivery->status = 'failed';
                $delivery->save();

                Log::warning('Notification delivery failed', [
                    'delivery_id' => $delivery->id,
                    'channel' => $delivery->channel,
                    'attempts' => (int) $delivery->attempts,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'error' => $delivery->error,
                ]);
            });

            if ((int) $delivery->attempts >= self::MAX_ATTEMPTS) {
                return false; // نهائية — بلا رمية إضافية للطابور.
            }

            throw new DeliveryRetryableException(
                "فشل تسليم الإشعار #{$delivery->id} (محاولة {$delivery->attempts}): {$delivery->error}",
            );
        }

        // ⭐ المزود نجح فعلياً — فقط الآن تُفتح ساعة الإرسال.
        DB::transaction(function () use ($delivery, $result, $plan): void {
            $delivery->provider = class_basename($plan['provider_class']);
            $delivery->external_id = $result['external_id'] ?? null;
            $delivery->sent_at = now();
            $delivery->error = null;
            $delivery->status = 'sent';
            $delivery->save();
        });

        return true;
    }

    /**
     * ⭐ PR 4.3 — هل القناة مؤجلة بقرار صريح؟
     *
     * واتساب يبقى مؤجلاً طالما `notifications.whatsapp_enabled=false`،
     * حتى لو كان مذكوراً في `deferred_channels`. تفعيل العلم يرفع
     * التأجيل عنه وحده — بقية القنوات تتبع الإعداد كما هي.
     */
    private function isChannelDeferred(string $channel): bool
    {
        $deferred = (array) config('notifications.deferred_channels', []);

        if ($channel === 'whatsapp' && (bool) config('notifications.whatsapp_enabled')) {
            $deferred = array_values(array_diff($deferred, ['whatsapp']));
        }

        return in_array($channel, $deferred, true);
    }

    /**
     * ⭐⭐⭐ **داخل ساعات الهدوء؟**
     *
     * النافذة من مركز الإعدادات:
     * `notifications.quiet_hours_start` → `notifications.quiet_hours_end`
     * (صيغة H:i). النافذة تعبر منتصف الليل إذا كانت البداية
     * أكبر من النهاية (مثلاً 22:00 → 07:00 — الإرسال الخارجي
     * موقوف طول ما بينهما).
     */
    public function inQuietHours(string $channel, int $orgId): bool
    {
        if ($channel === self::DATABASE_CHANNEL) {
            return false; // كتابة الصندوق محلية — لا غروب خارجي.
        }

        $start = trim((string) settings()->get('notifications.quiet_hours_start', $orgId));
        $end = trim((string) settings()->get('notifications.quiet_hours_end', $orgId));

        if ($start === '' || $end === '') {
            return false; // نافذة غير مضبوطة = لا هدوء.
        }

        $startMinutes = $this->hmToMinutes($start);
        $endMinutes = $this->hmToMinutes($end);
        if ($startMinutes === null || $endMinutes === null || $startMinutes === $endMinutes) {
            return false; // قيمة معطوبة أو نافذة معدومة — لا منع أسكند.
        }

        $now = Carbon::now();
        $nowMinutes = (int) $now->format('H') * 60 + (int) $now->format('i');

        if ($startMinutes < $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        // النافذة تعبر منتصف الليل (22:00 → 07:00).
        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }

    /**
     * ⭐ تفضيلات المستخدم: بلا صف = القناة مسموحة. الصف بـ
     * `is_enabled = false` يمنعها كلياً — بلا «هنبعتلك ورغم ظهرك».
     *
     * @param  list<string>  $channels
     * @return list<string>
     */
    private function filterByPreferences(?User $user, array $channels): array
    {
        if ($user === null) {
            return $channels; // مستلم بلا حساب — لا تفضيلات موجودة.
        }

        $disabled = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->where('is_enabled', false)
            ->pluck('channel')
            ->all();

        return array_values(array_filter(
            $channels,
            fn ($c) => ! in_array($c, $disabled, true),
        ));
    }

    /**
     * ترجمة القالب: صف `notification_templates` إن وُجد، وإلا
     * المرور الصافي (title/message من بيانات الحدث كما هي).
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: string} [title, body]
     */
    private function renderViaTemplate(string $event, string $channel, array $data, int $orgId): array
    {
        $template = NotificationTemplate::query()
            ->where('organization_id', $orgId)
            ->where('event', $event)
            ->where('channel', $channel)
            ->where('language', 'ar')
            ->first();

        $title = (string) ($data['title'] ?? '');
        $body = (string) ($data['message'] ?? '');

        if ($template !== null) {
            if ($template->title_template !== null) {
                $title = $this->interpolate($template->title_template, $data);
            }
            if (trim((string) $template->body_template) !== '') {
                $body = $this->interpolate((string) $template->body_template, $data);
            }
        }

        return [$title, $body];
    }

    /** استبدال عناصر `:key` بقيم بيانات الحدث. */
    private function interpolate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $template = str_replace(':'.$key, (string) $value, $template);
        }

        return $template;
    }

    private function hmToMinutes(string $value): ?int
    {
        if (! preg_match('/^(\d{1,2}):(\d{2})$/', $value, $m)) {
            return null;
        }

        $h = (int) $m[1];
        $i = (int) $m[2];

        if ($h > 23 || $i > 59) {
            return null;
        }

        return $h * 60 + $i;
    }

    /**
     * @return array{0: User|null, 1: Student|null} (user, student)
     */
    private function recipientParts(Model $recipient): array
    {
        if ($recipient instanceof User) {
            return [$recipient, $recipient->student];
        }

        if ($recipient instanceof Student) {
            return [$recipient->user, $recipient];
        }

        return [null, null];
    }

    private function organizationIdOf(Model $recipient): int
    {
        if (isset($recipient->organization_id) && $recipient->organization_id !== null) {
            return (int) $recipient->organization_id;
        }

        throw new \InvalidArgumentException('مستلم الإشعار يجب أن يكون مرتبطاً بمؤسسة (organization_id)');
    }
}
