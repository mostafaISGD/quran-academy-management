<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **سياسة حصة واحدة (PR 3.2).**
 *
 * ⭐ **صف = (نتيجة × نطاق)**:
 *
 * - `outcome`: النتيجة النهائية للحصة (`completed` · `student_absent` ·
 *   `teacher_absent` · `cancelled_by_academy` · `cancelled_late`).
 * - `scope`: `global` (لكل الأكاديمية) أو `program` (لبرنامج واحد —
 *   بيغلب العام).
 *
 * ⭐ **ليه صف مش أعمدة؟** كل نتيجة جديدة كانت هتحتاج عمودين
 * جدد، وكل نطاق برنامج كان هيحتاج نسخة كاملة — الشكل أفقي
 * بيكبر بلا نهاية. الشكل الجديد بيكبر **رأسياً** (صف جديد لكل
 * قرار) من غير ما يمسّ الجدول.
 *
 * ⭐ **لا تعدّل صف تاريخي**: التعديل على السياسات بيغيّر
 * **المستقبل** بس — والتاريخ ثابت، واللقطة على الحصة
 * (`policy_snapshot`) هي التي تحفظ قواعد وقت تطبيقها.
 */
class LessonPolicy extends Model
{
    protected $fillable = [
        'organization_id', 'outcome', 'scope', 'program_id',
        'deduct_credit', 'pay_teacher', 'allow_makeup',
        'makeup_limit', 'makeup_window_days', 'notify_parent',
        'late_cancel_notice_hours', 'makeup_deducts_from_package',
    ];

    protected function casts(): array
    {
        return [
            'deduct_credit' => 'boolean',
            'pay_teacher' => 'boolean',
            'allow_makeup' => 'boolean',
            'notify_parent' => 'boolean',
            'makeup_deducts_from_package' => 'boolean',
            'makeup_limit' => 'integer',
            'makeup_window_days' => 'integer',
            'late_cancel_notice_hours' => 'integer',
            'program_id' => 'integer',
        ];
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    /**
     * ⭐ **الافتراضات الآمنة** — مصدر واحد للـseeder وللمخدمة.
     *
     * ⭐ **ليه آمنة؟** الأكاديمية في الغالب ما عايزاش تخصم من
     * الطالب ولا تدفع للمعلم في الغياب والإلغاء (خلافاتها
     * المالية بتبدأ من هنا). والحصة المكتملة هي اللي بتخصم
     * وتدفع.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            'completed' => [
                'deduct_credit' => true,
                'pay_teacher' => true,
                'allow_makeup' => false,
                'makeup_limit' => 0,
                'makeup_window_days' => 0,
                'notify_parent' => true,
                'late_cancel_notice_hours' => 0,
                'makeup_deducts_from_package' => true,
            ],
            'student_absent' => [
                'deduct_credit' => false,
                'pay_teacher' => false,
                'allow_makeup' => true,
                'makeup_limit' => 4,
                'makeup_window_days' => 30,
                'notify_parent' => true,
                'late_cancel_notice_hours' => 0,
                'makeup_deducts_from_package' => true,
            ],
            'teacher_absent' => [
                'deduct_credit' => false,
                'pay_teacher' => false,
                'allow_makeup' => true,
                'makeup_limit' => 4,
                'makeup_window_days' => 30,
                'notify_parent' => true,
                'late_cancel_notice_hours' => 0,
                'makeup_deducts_from_package' => true,
            ],
            'cancelled_by_academy' => [
                'deduct_credit' => false,
                'pay_teacher' => false,
                'allow_makeup' => true,
                'makeup_limit' => 4,
                'makeup_window_days' => 30,
                'notify_parent' => true,
                // ⭐⭐⭐ PR 3.12 — فترة الإلغاء الشائعة من مركز
                // الإعدادات (`lessons.cancel_notice_hours`) —
                // الافتراضي نفسه (24) فمفيش تغيّر سلوك. (defaults()
                // بعد ما سِف مؤسسة — بتلوت بالافتراضي العام.)
                'late_cancel_notice_hours' => (int) settings()->get('lessons.cancel_notice_hours'),
                'makeup_deducts_from_package' => true,
            ],
            'cancelled_late' => [
                'deduct_credit' => false,
                'pay_teacher' => false,
                'allow_makeup' => false,
                'makeup_limit' => 0,
                'makeup_window_days' => 0,
                'notify_parent' => true,
                'late_cancel_notice_hours' => 0,
                'makeup_deducts_from_package' => true,
            ],

            /**
             * ⭐⭐⭐ **PR 3.10 — الحالات الانتقالية: بلا أثر مالي.**
             *
             * ⭐ **قبل PR ده الكتالوج ما كانش فيه صفوف للحالات
             * الانتقالية** (`confirmed`/`in_progress`/`rescheduled`)
             * — و`LessonPolicyService::resolve()` كان بيرجع
             * **افتراضي «completed»** لأي نتيجة مش موجودة
             * (`deduct_credit = true`) ⇒ **بداية الدرس كانت بتخصم
             * رصيد** — والإتمام بتخصم تاني (دبل-خصم!). بِغ اكتشفه
             * اختبار البوابة «بداية ثم إتمام».
             *
             * ⭐ الصفوف الانتقالية بتتصفّر كل الآثار: بلا خصم
             * رصيد، بلا أجر معلم، بلا إشعار ولي أمر (الإشعار جاي
             * مع النتيجة النهائية: اكتمل الحصة أو الغى).
             */
            'confirmed' => [
                'deduct_credit' => false,
                'pay_teacher' => false,
                'allow_makeup' => false,
                'makeup_limit' => 0,
                'makeup_window_days' => 0,
                'notify_parent' => false,
                'late_cancel_notice_hours' => 0,
                'makeup_deducts_from_package' => true,
            ],
            'in_progress' => [
                'deduct_credit' => false,
                'pay_teacher' => false,
                'allow_makeup' => false,
                'makeup_limit' => 0,
                'makeup_window_days' => 0,
                'notify_parent' => false,
                'late_cancel_notice_hours' => 0,
                'makeup_deducts_from_package' => true,
            ],
            'rescheduled' => [
                'deduct_credit' => false,
                'pay_teacher' => false,
                'allow_makeup' => false,
                'makeup_limit' => 0,
                'makeup_window_days' => 0,
                'notify_parent' => false,
                'late_cancel_notice_hours' => 0,
                'makeup_deducts_from_package' => true,
            ],
        ];
    }

    /**
     * ⭐ **اللقطة** — النسخة اللي بتتخزّن على الحصة وقت التحويل.
     *
     * ⭐ snapshot = **نسخة** مش مرجع: تعديل السياسة بعدين ما
     * يلمسش التاريخ (قبول المواصفة).
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'policy_id' => $this->id,
            'outcome' => $this->outcome,
            'scope' => $this->scope,
            'program_id' => $this->program_id,
            'deduct_credit' => (bool) $this->deduct_credit,
            'pay_teacher' => (bool) $this->pay_teacher,
            'allow_makeup' => (bool) $this->allow_makeup,
            'makeup_limit' => (int) $this->makeup_limit,
            'makeup_window_days' => (int) $this->makeup_window_days,
            'notify_parent' => (bool) $this->notify_parent,
            'late_cancel_notice_hours' => (int) $this->late_cancel_notice_hours,
            'makeup_deducts_from_package' => (bool) $this->makeup_deducts_from_package,
        ];
    }
}
