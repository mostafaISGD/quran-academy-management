<?php

namespace App\Support;

use Illuminate\Support\Facades\App;

/**
 * رسائل التحقق بالعربي.
 *
 * ⭐ ليه ده موجود؟
 *
 * الواجهة كلها عربية، بس الـ 422 كان بيرجع إنجليزي:
 *
 *     "The records.0.worked_hours field must not be greater than 24."
 *
 * والمستخدم بيقرأ ده قدام طالبه. الـ validation بيحصل في **السيرفر**
 * (ده صح — الواجهة ما تنفعش تتحقق لوحدها)، فالرسايل لازم ترجع
 * عربية معاها.
 *
 * ⭐ بس مش بنترجم كل الجمل. القواعد الثابتة (Laravel عندها
 * ترجمة جاهزة لـ `validation.php`)، واللي بيحتاج ترجمة هو
 * **اسم الحقل** — وده اللي بيقول «حقل records.0.worked_hours
 * field». بعد ما نعرف اسم الحقل، الترجمة بتاعتنا بتتولّد.
 */
class ValidationMessages
{
    /** ترجمة اسم حقل — المفتاح هو اللي السيرفر بيبعته */
    private const FIELDS = [
        // ===== عام =====
        'name' => 'الاسم',
        'email' => 'البريد الإلكتروني',
        'phone' => 'رقم الهاتف',
        'country_code' => 'مفتاح الدولة',
        'password' => 'كلمة المرور',
        'password_confirmation' => 'تأكيد كلمة المرور',
        'gender' => 'النوع',
        'date_of_birth' => 'تاريخ الميلاد',
        'nationality' => 'الجنسية',
        'address' => 'العنوان',
        'photo_url' => 'الصورة',
        'notes' => 'الملاحظات',
        'status' => 'الحالة',
        'currency' => 'العملة',
        'reference' => 'رقم المرجع',
        'payment_method' => 'طريقة الدفع',
        'description' => 'الوصف',
        'title' => 'العنوان',
        'date' => 'التاريخ',
        'time' => 'الوقت',
        'notes_for_teacher' => 'ملاحظات للمعلم',

        // ===== الحضور =====
        'worked_hours' => 'الساعات',
        'check_in' => 'وقت الحضور',
        'check_out' => 'وقت الانصراف',
        'employee_id' => 'الموظف',
        'records' => 'سجلات الحضور',
        'records.*.worked_hours' => 'الساعات',
        'records.*.check_in' => 'وقت الحضور',
        'records.*.check_out' => 'وقت الانصراف',
        'records.*.notes' => 'الملاحظات',
        'records.*.status' => 'الحالة',

        // ===== الموظف =====
        'job_title' => 'المسمى الوظيفي',
        'department' => 'القسم',
        'employment_type' => 'نوع التوظيف',
        'hourly_rate' => 'سعر الساعة',
        'manager_id' => 'المدير المباشر',
        'joined_at' => 'تاريخ الالتحاق',
        'create_account' => 'إنشاء حساب',
        'role_id' => 'الدور',
        'permissions' => 'الصلاحيات',

        // ===== الطلاب =====
        'student_id' => 'الطالب',
        'program_id' => 'البرنامج',
        'teacher_id' => 'المعلم',
        'plan_id' => 'الباقة',
        'level_id' => 'المستوى',
        'lesson_id' => 'الحصة',
        'branch_id' => 'الفرع',
        'parent_id' => 'ولي الأمر',
        'student_code' => 'كود الطالب',
        'first_name' => 'الاسم الأول',
        'last_name' => 'اسم العائلة',
        'lessons_included' => 'عدد الحصص',
        'lesson_duration_minutes' => 'مدة الحصة',
        'duration_days' => 'عدد الأيام',

        // ===== المعلمين =====
        'teacher_code' => 'كود المعلم',
        'display_name' => 'الاسم الظاهر',
        'specialization' => 'التخصص',
        'rate_type' => 'نوع السعر',
        'amount' => 'المبلغ',
        'rate_snapshot' => 'سعر الحصة',
        'lessons_count' => 'عدد الحصص',
        'avatar_url' => 'الصورة',
        'certification' => 'الشهادة',

        // ===== المرتبات =====
        'start_date' => 'تاريخ البداية',
        'end_date' => 'تاريخ النهاية',
        'payroll_period_id' => 'فترة المرتبات',
        'period' => 'الفترة',

        // ===== المدفوعات =====
        'payment_amount' => 'المبلغ المدفوع',
        'payment_date' => 'تاريخ الدفع',
        'installment' => 'قسط',
        'discount' => 'خصم',

        // ===== المجموعات وقائمة الانتظار =====
        'capacity' => 'العدد الأقصى',
        'meeting_url' => 'رابط الاجتماع',
        'meeting_provider' => 'منصة الاجتماع',
        'weekday' => 'اليوم',
        'start_time' => 'وقت البداية',
        'end_time' => 'وقت النهاية',
        'sort_order' => 'الترتيب',
        'group_class_id' => 'المجموعة',
        'entry' => 'السجل',

        // ===== قائمة الانتظار (الصفحة المستقلة) =====
        // ⚠️ من غير السطور دي كان الـ 422 بيرجّع أسماء الحقول
        // **بالإنجليزي** (`current_level`, `parent_phone`...) وسط
        // رسالة عربية — والواجهة كلها عربي.
        'parent_phone' => 'هاتف ولي الأمر',
        'current_level' => 'المستوى الحالي',
        'package_id' => 'الباقة',
        'proposed_group_id' => 'المجموعة المقترحة',

        // ===== آخر صفحة =====
        'slug' => 'المعرّف',
        'color' => 'اللون',
    ];

    /**
     * ⭐ ترجمة رسالة الـ validation.
     *
     * بتاخد الرسالة الإنجليزية وترجّعها عربي. لو مش عارفة
     * ترجمتها، بترجّع **fallback** عربي عام — مش الإنجليزية.
     *
     * ⚠️ ليه fallback عام مش الأصلي؟
     *
     * الرد كان بيطلع **مخلوط**: عربي لعمود وإنجليزي للتاني. ده
     * أسوأ من إنجليزي كله، لأن المستخدم مش عارفaukésي الرسالة
     * اللي قدامه، وم.binding يفرق بين «رسالة ترجمناها» و«رسالة
     * ماعرفناش».
     */
    public static function translate(string $message, ?string $fieldLabel = null): string
    {
        foreach ([
            'translateRequired',
            'translateLength',
            'translateValue',
            'translateComparison',
            'translateSelection',
            'translateBetween',
            'translateDates',
        ] as $rule) {
            $out = self::$rule($message, $fieldLabel);
            if ($out !== null) {
                return $out;
            }
        }

        // ⚠️ ما عرفناش الجملة — بنزوّد الاسم الليبنعرفه
        //
        // ⭐ لو `$fieldLabel` مش جاي، نستخرجه من الرسالة نفسها.
        // الأول كان بيرجّع `': لازم يكون نص'` — نقطتان ومن غير اسم.
        $label ??= self::fieldIn($message) !== null
            ? self::label((string) self::fieldIn($message))
            : null;

        $rule = self::guessRule($message);

        if ($rule === null) {
            // ⭐ حتى الحقل المجهول بياخد رسالة بتقول «مش صحيح»
            // — المستخدم لازم يعرف إن في حقل غلط، مش بس «البيانات
            // المدخلة غير صحيحة» اللي مش بتقوله إيه الغلط.
            return $label !== null
                ? "حقل {$label} مش صحيح"
                : 'في حقل مش صحيح';
        }

        return $label !== null ? "حقل {$label} {$rule}" : $rule;
    }

    // ============================================================
    // القواعد
    // ============================================================

    /** «مطلوب» */
    private static function translateRequired(string $message, ?string $label): ?string
    {
        // "The X field is required." / "The X is required."
        if (! preg_match('/^The (.+?) (?:field )?is required\.$/i', $message, $m)) {
            return null;
        }

        $label ??= self::label($m[1]);

        return $label !== null ? "حقل {$label} مطلوب." : null;
    }

    /** الطول */
    private static function translateLength(string $message, ?string $label): ?string
    {
        $field = self::fieldIn($message);
        $label ??= $field !== null ? self::label($field) : null;
        if ($label === null) {
            return null;
        }

        // "must not be greater than N characters"
        if (preg_match('/must not be greater than (\d+) characters/i', $message, $m)) {
            return "حقل {$label} لازم يكون {$m[1]} حرف على الأكثر.";
        }

        // "must be at least N characters"
        if (preg_match('/must be at least (\d+) characters/i', $message, $m)) {
            return "حقل {$label} لازم يكون {$m[1]} حرف على الأقل.";
        }

        // "must be between N and M characters"
        if (preg_match('/must be between (\d+) and (\d+) characters/i', $message, $m)) {
            return "حقل {$label} لازم يكون بين {$m[1]} و {$m[2]} حرف.";
        }

        return null;
    }

    /** نوع القيمة (إيميل، رابط، تليفون) */
    private static function translateValue(string $message, ?string $label): ?string
    {
        $field = self::fieldIn($message);
        $label ??= $field !== null ? self::label($field) : null;
        if ($label === null) {
            return null;
        }

        if (preg_match('/must be a valid email address/i', $message)) {
            return "حقل {$label} لازم يكون بريد إلكتروني صحيح.";
        }

        if (preg_match('/must be a valid url/i', $message)) {
            return "حقل {$label} لازم يكون رابط صحيح.";
        }

        if (preg_match('/must be a valid phone number/i', $message)) {
            return "حقل {$label} لازم يكون رقم هاتف صحيح.";
        }

        return null;
    }

    /** المقارنات (أكبر/أصغر) */
    private static function translateComparison(string $message, ?string $label): ?string
    {
        $field = self::fieldIn($message);
        $label ??= $field !== null ? self::label($field) : null;
        if ($label === null) {
            return null;
        }

        // ⚠️ الرقم: `\d+(?:\.\d+)?` مش `[\d.]+`.
        //
        // `[\d.]+` بياكل النقطة اللي بعدها، فـ «must not be greater
        // than 24.» بتطلع «24.» — برضو بسملة في نص عربي.
        $n = '\d+(?:\.\d+)?';

        // ⚠️ مفيش `numeric` في الرسالة — Laravel بيرمي نفس الجملة
        // لأرقام وتواريخ. فبنخليها عامة.
        if (preg_match("/must not be greater than ($n)/i", $message, $m)) {
            return "حقل {$label} لازم يكون {$m[1]} أو أقل.";
        }

        if (preg_match("/must be greater than ($n)/i", $message, $m)) {
            return "حقل {$label} لازم يكون أكبر من {$m[1]}.";
        }

        if (preg_match("/must not be less than ($n)/i", $message, $m)) {
            return "حقل {$label} لازم يكون {$m[1]} أو أكتر.";
        }

        if (preg_match("/must be less than ($n)/i", $message, $m)) {
            return "حقل {$label} لازم يكون أصغر من {$m[1]}.";
        }

        return null;
    }

    /** الاختيار (`in`, `exists`) */
    private static function translateSelection(string $message, ?string $label): ?string
    {
        $field = self::fieldIn($message);
        $label ??= $field !== null ? self::label($field) : null;
        if ($label === null) {
            return null;
        }

        // "The selected X is invalid."
        if (preg_match('/^The selected (.+?) is invalid\.$/i', $message, $m) || str_contains($message, 'is invalid')) {
            return "قيمة {$label} مش صحيحة.";
        }

        if (preg_match('/has already been taken/i', $message)) {
            return "{$label} مستخدم قبل كده.";
        }

        return null;
    }

    /** `between` */
    private static function translateBetween(string $message, ?string $label): ?string
    {
        $field = self::fieldIn($message);
        $label ??= $field !== null ? self::label($field) : null;
        if ($label === null) {
            return null;
        }

        // "The X field must be between A and B."
        if (preg_match('/must be between (.+?) and (.+?)\.$/i', $message, $m)) {
            return "حقل {$label} لازم يكون بين {$m[1]} و {$m[2]}.";
        }

        return null;
    }

    /** التواريخ */
    private static function translateDates(string $message, ?string $label): ?string
    {
        $field = self::fieldIn($message);
        $label ??= $field !== null ? self::label($field) : null;
        if ($label === null) {
            return null;
        }

        if (str_contains($message, 'is not a valid date')) {
            return "حقل {$label} لازم يكون تاريخ صحيح.";
        }

        if (str_contains($message, 'must be a date after')) {
            return "حقل {$label} لازم يكون بعد تاريخ البداية.";
        }

        if (str_contains($message, 'must be a date before')) {
            return "حقل {$label} لازم يكون قبل تاريخ النهاية.";
        }

        if (str_contains($message, 'must be after or equal to')) {
            return "حقل {$label} لازم يكون مساوي لل تاريخ البداية أو بعده.";
        }

        if (str_contains($message, 'must be before or equal to')) {
            return "حقل {$label} لازم يكون مساوي لتاريخ النهاية أو قبله.";
        }

        return null;
    }

    /**
     * ⭐ تخمين القاعدة لو الجملة نفسها ماعرفناهاش.
     *
     * مثال: "The X must be a string." — ما فيدها قاعدة عندنا،
     * بس للمستخدم مهم إنه يعرف **إن الحقل غلط**.
     */
    private static function guessRule(string $message): ?string
    {
        if (str_contains($message, 'must be a string')) {
            return 'لازم يكون نص';
        }

        if (str_contains($message, 'must be an integer')) {
            return 'لازم يكون رقم صحيح';
        }

        if (str_contains($message, 'must be a number')) {
            return 'لازم يكون رقم';
        }

        if (str_contains($message, 'must be an array')) {
            return 'لازم يكون قائمة';
        }

        if (str_contains($message, 'must be a boolean')) {
            return 'لازم يكون صح أو خطأ';
        }

        if (str_contains($message, 'must be accepted')) {
            return 'لازم يكون مقبول';
        }

        return null;
    }

    /**
     * يستخرج اسم الحقل من الرسالة الإنجليزية.
     *
     * `The name field is required.` → `name`
     * `The records.0.worked_hours field must not be greater than 24.`
     *                        → `records.0.worked_hours`
     */
    private static function fieldIn(string $message): ?string
    {
        // "The selected X is invalid."
        if (preg_match('/^The selected (.+?) is invalid\.$/i', $message, $m)) {
            return $m[1];
        }

        // "The X field ..." أو "The X ..."
        if (preg_match('/^The (.+?) (?:field )?(?:is|must|has|may)\b/i', $message, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * اسم الحقل بالعربي.
     *
     * ⚠️ لو الحقل لسه مجهول، بترجّع `null` — وبالعربي هنتعامل
     * معه في `translate()`.
     */
    private static function label(string $field): ?string
    {
        // `records.0.worked_hours` → `records.*.worked_hours`
        $normalized = self::normalize($field);

        if (isset(self::FIELDS[$normalized])) {
            return self::FIELDS[$normalized];
        }

        // ⭐ نحاول آخر جزء: `records.0.employee_id` → `employee_id`
        $last = preg_replace('/^.*\./', '', $normalized);

        return self::FIELDS[$last] ?? null;
    }

    /** `records.0.worked_hours` → `records.*.worked_hours` */
    public static function normalize(string $field): string
    {
        return preg_replace('/\.\d+/', '.*', $field);
    }

    /**
     * ترجمة كل رسائل الـ 422 دفعة واحدة.
     *
     * ⭐ **مفيش خلط**: لو حقل واحد مجهول، بنترجمه بالإنجليزي
     * الأصلي. الرد كله عربي أو كله إنجليزي — المخلوط أسوأ من
     * الاثنين، لأن المستخدم مش هيعرف الرسالة اللي قدامه.
     *
     * @param  array<string, array<int, string>>  $errors
     * @return array{0: array<string, array<int, string>>, 1: bool}  [الترجمة، الكل متعرف؟]
     */
    public static function translateAll(array $errors): array
    {
        $out = [];
        $allKnown = true;

        foreach ($errors as $field => $messages) {
            $label = self::label((string) $field);
            $known = $label !== null;

            $key = $known ? $label : (string) $field;

            if (! $known) {
                $allKnown = false;
            }

            $out[$key] = array_map(
                fn (string $m) => self::translate($m, $label),
                (array) $messages
            );
        }

        return [$out, $allKnown];
    }
}