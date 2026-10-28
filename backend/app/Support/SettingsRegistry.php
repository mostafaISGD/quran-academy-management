<?php

namespace App\Support;

/**
 * ⭐⭐⭐ **PR 3.12 — سجل الإعدادات: المصدر المعتمد للقواعد التجارية.**
 *
 * ⭐ **قبل PR ده** القيم بتاعة القواعد (3 أيام قبل الانتهاء،
 * EGP، 16:00، استحقاق بعد 7/14) كانت **مكتوبة داخل كود
 * الخدمات** — تغييرها معناه نزول كود ونشر نسخة.
 *
 * ⭐ وكل مفتاح هنا معرّف بي:
 *
 * - `type`    — int / string / bool / list (القراءة بتتحول بالسجل).
 * - `default` — القيمة لو مفيش صف في القاعدة للمؤسسة.
 * - `min`/`max` — حدود نوشر (مفيش قيمة تاريخية في جو 60 يوم).
 * - `group`   — قِسم الشاشة (الاشتراكات/الدروس/المالية/…).
 * - `label`   — الاسم العربي اللي بيُعرض في مركز الإعدادات.
 *
 * ⭐ أي مفتاح **مش في السجل** = مرفوض (غيّر/أضف هنا) — عشان
 * المفتاحات ما تتساكس: التسمية الموحدة، والقيمة الافتراضية
 * الواحدة، والحدود الواحدة.
 */
final class SettingsRegistry
{
    /** @var array<string, array{type: string, default: mixed, min?: int, max?: int, group: string, label: string}> */
    public const KEYS = [
        // ═══════════ الاشتراكات ═══════════
        'subscriptions.expiry_warning_days' => [
            'type' => 'int',
            'default' => 3,
            'min' => 0,
            'max' => 60,
            'group' => 'الاشتراكات',
            'label' => 'أيام التحذير قبل انتهاء الاشتراك',
        ],

        // ═══════════ الدروس ═══════════
        'lessons.default_start_time' => [
            'type' => 'string',
            'default' => '16:00',
            'group' => 'الدروس',
            'label' => 'الوقت الافتراضي لبداية الحصص (H:i)',
        ],
        'lessons.default_duration_minutes' => [
            'type' => 'int',
            'default' => 30,
            'min' => 10,
            'max' => 240,
            'group' => 'الدروس',
            'label' => 'مدة الحصة الافتراضية (دقيقة)',
        ],
        'lessons.min_duration_minutes' => [
            'type' => 'int',
            'default' => 15,
            'min' => 5,
            'max' => 120,
            'group' => 'الدروس',
            'label' => 'الحد الأدنى لمدة الحصة (دقيقة)',
        ],
        'lessons.cancel_notice_hours' => [
            'type' => 'int',
            'default' => 24,
            'min' => 0,
            'max' => 168,
            'group' => 'الدروس',
            'label' => 'فترة الإلغاء الشائعة (ساعة) — تمهيد أدوار أنواع الدروس',
        ],
        'lessons.max_generation_count' => [
            'type' => 'int',
            'default' => 200,
            'min' => 1,
            'max' => 1000,
            'group' => 'الدروس',
            'label' => 'الحد الأقصى لحصص السلسلة المتكررة',
        ],
        'lessons.max_generation_months' => [
            'type' => 'int',
            'default' => 3,
            'min' => 1,
            'max' => 24,
            'group' => 'الدروس',
            'label' => 'نافذة التوليد (أشهر) للسلسلة المتكررة',
        ],
        'meetings.allowed_domains' => [
            'type' => 'list',
            'default' => ['zoom.us', 'zoom.com', 'meet.google.com', 'teams.microsoft.com'],
            'group' => 'الدروس',
            'label' => 'مضيفين روابط الاجتماعات المسموح لهم',
        ],

        // ═══════════ المالية ═══════════
        'finance.default_currency' => [
            'type' => 'string',
            'default' => 'EGP',
            'group' => 'المالية',
            'label' => 'العملة الافتراضية (كود مثل EGP)',
        ],
        'invoices.due_days' => [
            'type' => 'int',
            'default' => 14,
            'min' => 1,
            'max' => 90,
            'group' => 'المالية',
            'label' => 'أيام استحقاق الفاتورة العادية',
        ],
        'invoices.enrollment_due_days' => [
            'type' => 'int',
            'default' => 7,
            'min' => 1,
            'max' => 90,
            'group' => 'المالية',
            'label' => 'أيام استحقاق فاتورة التسجيل والاشتراك اليومي',
        ],
        'finance.refund_approval_threshold' => [
            'type' => 'int',
            'default' => 0,
            'min' => 0,
            'max' => 9_999_999,
            'group' => 'المالية',
            'label' => 'حد موافقة الاسترداد (قرش) — 0 يعني كل استرداد يأذن',
        ],

        // ═══════════ الرواتب والاعتمادات ═══════════
        'payroll.auto_approve_earnings' => [
            'type' => 'int',
            'default' => 0,
            'min' => 0,
            'max' => 1,
            'group' => 'الرواتب',
            'label' => 'اعتماد أرباح المعلم تلقائيًا (1 = نعم)',
        ],
        'teacher_portal.expose_parent_phone' => [
            'type' => 'bool',
            'default' => false,
            'group' => 'الرواتب',
            'label' => 'إظهار هاتف ولي الأمر للمعلم في البوابة',
        ],
        // ═══════════ الإشعارات ═══════════
        'notifications.quiet_hours_start' => [
            'type' => 'string',
            'default' => '22:00',
            'group' => 'الإشعارات',
            'label' => 'ساعات الهدوء — البداية (H:i)',
        ],
        'notifications.quiet_hours_end' => [
            'type' => 'string',
            'default' => '07:00',
            'group' => 'الإشعارات',
            'label' => 'ساعات الهدوء — النهاية (H:i)',
        ],
        // ⭐⭐ PR 4.2 — مدد تذكير الحصص (قائمة بالدقائق قبل الحصة).
        'notifications.lesson_reminder_offsets' => [
            'type' => 'list',
            'default' => ['60'],
            'group' => 'الإشعارات',
            'label' => 'مدد تذكير الحصص بالدقائق (مثل: 60، 1440)',
        ],
    ];

    public static function definition(string $key): ?array
    {
        return self::KEYS[$key] ?? null;
    }

    public static function has(string $key): bool
    {
        return isset(self::KEYS[$key]);
    }

    /**
     * ⭐ تحقق من القيمة على السجل — عنصر المصفوفة `error` = الرسالة،
     * و`value` = القيمة المحوَّلة. أخطاء القيمة الحبيبة مسبوقة بالسجل،
     * والفَقد ترد رسالة عربية.
     *
     * @return array{error?: string, value?: mixed}
     */
    public static function validate(string $key, mixed $value): array
    {
        $definition = self::definition($key);

        if ($definition === null) {
            return ['error' => 'مفتاح الإعداد غير معرَف في سجل الإعدادات: '.$key];
        }

        return match ($definition['type']) {
            'int' => self::validateInt($definition, $value),
            'bool' => self::validateBool($definition, $value),
            'list' => self::validateList($definition, $value),
            default => self::validateString($definition, $value),
        };
    }

    /** @return array{error?: string, value?: mixed} */
    private static function validateInt(array $definition, mixed $value): array
    {
        if ($value === null || $value === '') {
            return ['error' => 'قيمة الإعداد مطلوبة'];
        }

        if (! is_numeric($value) || (string) $value !== (string) (int) $value) {
            return ['error' => 'قيمة الإعداد لازم تكون رقم صحيح'];
        }

        $intValue = (int) $value;

        if (isset($definition['min']) && $intValue < $definition['min']) {
            return ['error' => "القيمة أقل من الحد الأدنى ({$definition['min']})"];
        }

        if (isset($definition['max']) && $intValue > $definition['max']) {
            return ['error' => "القيمة أكبر من الحد الأقصى ({$definition['max']})"];
        }

        return ['value' => $intValue];
    }

    /** @return array{error?: string, value?: mixed} */
    private static function validateBool(array $definition, mixed $value): array
    {
        if (! is_bool($value)) {
            return ['error' => 'قيمة هذا الإعداد لازم تكون true أو false'];
        }

        return ['value' => $value];
    }

    /** @return array{error?: string, value?: mixed} */
    private static function validateString(array $definition, mixed $value): array
    {
        if ($value === null || ! is_string($value) || trim($value) === '') {
            return ['error' => 'قيمة الإعداد لازم تكون نص غير فارغ'];
        }

        if (mb_strlen($value) > 60) {
            return ['error' => 'القيمة أطول من المسموح (60 حرف)'];
        }

        if (str_contains($definition['label'], 'العملة')) {
            if (! preg_match('/^[A-Z]{3,8}$/', $value)) {
                return ['error' => 'كود العملة لازم يكون حروف إنجليزية كبيرة (3 إلى 8 حروف)'];
            }
        }

        if (str_contains($definition['label'], 'بداية الحصص') && ! preg_match('/^\d{2}:\d{2}$/', $value)) {
            return ['error' => 'وقت بداية الحصص لازم يكون بصيغة H:i (مثل 16:00)'];
        }

        if (str_contains($definition['label'], 'ساعات الهدوء') && ! preg_match('/^\d{2}:\d{2}$/', $value)) {
            return ['error' => 'وقت ساعات الهدوء لازم يكون بصيغة H:i (مثل 22:00)'];
        }

        return ['value' => $value];
    }

    /** @return array{error?: string, value?: mixed} */
    private static function validateList(array $definition, mixed $value): array
    {
        if (is_array($value)) {
            $hosts = array_values(array_filter(array_map(
                fn ($item) => trim((string) $item),
                $value,
            )));
        } elseif (is_string($value) && trim($value) !== '') {
            $hosts = array_values(array_filter(array_map('trim', explode(',', $value))));
        } else {
            return ['error' => 'قيمة الإعداد لازم تكون مصفوفة أو نص مفصول بفواصل'];
        }

        foreach ($hosts as $host) {
            if (! preg_match('/^(?!-)[A-Za-z0-9.-]+$/', $host)) {
                return ['error' => "مضيف غير صالح: {$host}"];
            }
        }

        if (count($hosts) > 30) {
            return ['error' => 'عدد المضيفين أكبر من المسموح (30)'];
        }

        return ['value' => $hosts];
    }
}
