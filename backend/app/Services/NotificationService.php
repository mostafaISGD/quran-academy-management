<?php

namespace App\Services;

use App\Jobs\DeliverNotification;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Student;
use App\Models\User;
use App\Support\DeliveryDeferredException;
use App\Support\DeliveryRetryableException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
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
    public const EVENT_TYPES = [
        'lesson_reminder', 'subscription_expiring', 'subscription_expired',
        'subscription_renewed', 'subscription_paused', 'subscription_resumed',
        'payment_received', 'makeup_created', 'trial_reminder',
        'announcement', 'lesson_completed', 'invoice_overdue',
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
    ): array {
        if (! in_array($event, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("حدث إشعار غير معرَّف: {$event}");
        }

        $orgId = $this->organizationIdOf($recipient);
        [$user, $student] = $this->recipientParts($recipient);
        $lessonId = $data['lesson_id'] ?? null;

        $requested = $channels === null
            ? [self::DATABASE_CHANNEL]
            : array_values(array_unique($channels));
        $requested = $this->filterByPreferences($user, $requested);

        $deliveries = [];

        DB::transaction(function () use (&$deliveries, $event, $orgId, $user, $student, $lessonId, $data, $requested, $writeInbox) {
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

        // الطابور — الخدمة تُبنى التسليمات، والجوب ينفّذها.
        foreach ($deliveries as $delivery) {
            DeliverNotification::dispatch($delivery->id);
        }

        return $deliveries;
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

            if (in_array($delivery->channel, (array) config('notifications.deferred_channels', []), true)) {
                return ['action' => 'deferred']; // مؤجلة بمواصفة المرحلة الرابعة.
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
