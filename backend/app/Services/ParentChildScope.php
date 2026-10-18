<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐⭐ **نطاق العلاقة: ولي / معلم / طالب (PR 1.8).**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الفكرة
 *
 * الصلاحيات العامة (`students.view`) تحدد «إلى أي شجرة من
 * الطلبة يملك الوصول»، والعلاقة تحدد «ما هو هامشك داخلها».
 * هذا الكلاس هو المركز الواحد الذي يحسب الهامش؛ الـPolicies
 * تسأله ولا تعيد الحساب في خمسة أماكن — قاعدة أمنية مُنسوخة
 * خمس مرات ستنسى أربع منها.
 *
 * ⭐ القاعدة
 *
 * - المستخدم له بروفايل ولي → نطاقه أبناؤه فقط (من الـpivot).
 * - له بروفايل معلم → طلابه فقط (دروسه + اشتراكاته).
 * - له بروفايل طالب → نفسه فقط.
 * - له أكثر من بروفايل → **التقاطع** (الأضيق) — ذراعان تعني
 *   خطأً مفهومًا؛ أضيق مجال هو الأكثر أمانًا.
 * - بلا أي بروفايل (موظف) → `null` = المرجع هو الصلاحيات العامة.
 *
 * ⚠️ **فصل الحسابات**: موظف له ابن يسجّل من حساب مستقل عشان
 * بروفايل الولي — لو الإدارة ربطت الموظف ببروفايل ولي، فهو
 * مقيد على أبناء هذا البروفايل بدل المجال الكامل.
 */
final class ParentChildScope
{
    /**
     * هوامش المستخدم.
     *
     * `null` = غير مقيد (موظف)؛ غير ذلك قائمة معرفات الطلبة.
     *
     * @return int[]|null
     */
    public static function allowedStudentIds(User $user): ?array
    {
        $scopes = [];

        $parent = $user->relationLoaded('parentProfile')
            ? $user->parentProfile
            : $user->parentProfile()->first();

        if ($parent) {
            // ⭐⭐⭐ **الرابط المؤكد بس** (PR 1.11): لو لقيت حد بنفس
            // الرقم وربط اتسجل، هو ده **معايا لحد ما الموظف يأكّد**.
            // الفلتر ده في *كل* قارئ — هنا وفي `flag()` — لأن أي
            // قارئ تاني غير ده = باب مفتوح (انتحال صفة ورقية).
            $scopes[] = Student::query()
                ->whereHas(
                    'parents',
                    fn ($q) => $q->where('parents.id', $parent->id)
                        ->where('student_parents.status', ParentLinker::STATUS_VERIFIED)
                )
                ->pluck('students.id')
                ->all();
        }

        $teacher = $user->relationLoaded('teacher') ? $user->teacher : $user->teacher()->first();

        if ($teacher) {
            $fromLessons = Lesson::query()
                ->where('teacher_id', $teacher->id)
                ->whereNotNull('student_id')
                ->distinct()
                ->pluck('student_id')
                ->all();

            $fromSubs = Subscription::query()
                ->where('teacher_id', $teacher->id)
                ->whereNotNull('student_id')
                ->distinct()
                ->pluck('student_id')
                ->all();

            $scopes[] = array_values(array_unique(array_merge($fromLessons, $fromSubs)));
        }

        $student = $user->relationLoaded('student') ? $user->student : $user->student()->first();

        if ($student) {
            $scopes[] = [$student->id];
        }

        // ⭐ بلا بروفايلات = موظف — القيد من الصلاحيات العامة فقط.
        if ($scopes === []) {
            return null;
        }

        // ⭐ أكثر من بروفايل = التقاطع — الأضيق يكسب.
        return array_values(array_intersect(...$scopes));
    }

    /**
     * ⭐ قراءة علم من الـpivot بين الولي والطالب المعين.
     */
    public static function flag(User $user, int $studentId, string $flag): bool
    {
        $parent = $user->relationLoaded('parentProfile')
            ? $user->parentProfile
            : $user->parentProfile()->first();

        if (! $parent) {
            // لا بروفايل ولي → قواعد الولي لا تنطبق أصلاً؛
            // الـpolicy تقرر على أساس نوع العلاقة نفسها.
            return false;
        }

        $row = DB::table('student_parents')
            ->where('parent_id', $parent->id)
            ->where('student_id', $studentId)
            // ⭐ رابط معلّق = مافيش أي صلاحية، حتى لو `can_manage`
            // في الصف (صف قديم اتعمل قبل الـmigration).
            ->where('status', ParentLinker::STATUS_VERIFIED)
            ->first(['parent_id', 'student_id', 'status', $flag]);

        // ⭐ لا ربط → لا علم.
        return (bool) ($row?->$flag ?? false);
    }

    public static function canViewFinance(User $user, int $studentId): bool
    {
        return self::flag($user, $studentId, 'can_view_finance');
    }

    public static function canViewProgress(User $user, int $studentId): bool
    {
        return self::flag($user, $studentId, 'can_view_progress');
    }

    public static function canManage(User $user, int $studentId): bool
    {
        return self::flag($user, $studentId, 'can_manage');
    }

    public static function canPay(User $user, int $studentId): bool
    {
        return self::flag($user, $studentId, 'can_pay');
    }

    /** ⭐ محفوظ لـPR 4.5: طلبات ولي الأمر (تعليق، تغيير حصة...). */
    public static function canRequestChanges(User $user, int $studentId): bool
    {
        return self::flag($user, $studentId, 'can_request_changes');
    }

    /**
     * ⭐ هل هذا المعلم يدرّس أبناء المستخدِم؟ (ولي الأمر يرى
     * معلّم ابنه فقط عند الوصول لبيانات المعلمين.)
     */
    public static function teacherOf(User $user, int $teacherId): bool
    {
        $childIds = self::allowedStudentIds($user) ?? [];

        if ($childIds === []) {
            return false;
        }

        $taught = Lesson::query()
            ->where('teacher_id', $teacherId)
            ->whereIn('student_id', $childIds)
            ->exists();

        return $taught
            || Subscription::query()
                ->where('teacher_id', $teacherId)
                ->whereIn('student_id', $childIds)
                ->exists();
    }

    /**
     * ⭐ كلاس إجرائي — `final` وprivate constructor: سلامة التغليف
     * (تُستخدم كـstatic دائماً؛ لا حالة ولا توضيب شيء).
     *
     * @codeCoverageIgnore
     */
    private function __construct() {}
}
