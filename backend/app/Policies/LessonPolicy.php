<?php

namespace App\Policies;

use App\Models\Lesson;
use App\Models\User;
use App\Services\ParentChildScope;

/**
 * ⭐⭐⭐ **سياسة الحصة (PR 1.8).**
 *
 * - موظف (بلا بروفايلات) → غير مقيّد؛ الصلاحيات العامة هي الحكم.
 * - معلم → حصصه فقط (قراءة وتعديل) — تعديل حصة معلم آخر رفض.
 * - ولي أمر → حصة ابنه فقط ومع شرط `can_view_progress`.
 * - طالب → حصصه نفسه (قراءة).
 */
class LessonPolicy
{
    public function view(User $user, Lesson $lesson): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return true;
        }

        $teacher = $user->relationLoaded('teacher') ? $user->teacher : $user->teacher()->first();

        if ($teacher) {
            return (int) $lesson->teacher_id === (int) $teacher->id;
        }

        if (! in_array($lesson->student_id, $ids, true)) {
            return false;
        }

        // ⭐ ولي أمر: الحكومة إما بقرار خاص أو علِم التقدم.
        $profile = $user->relationLoaded('student') ? $user->student : $user->student()->first();

        if ($profile !== null) {
            return true;
        }

        return ParentChildScope::canViewProgress($user, $lesson->student_id);
    }

    /**
     * ⭐ أي mutation على الحصة (تعديل / إلغاء / إكمال / حضور /
     * حفظ) يمر من هنا: معلم غير صاحب الحصة مرفوض.
     */
    public function update(User $user, Lesson $lesson): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return true;
        }

        $teacher = $user->relationLoaded('teacher') ? $user->teacher : $user->teacher()->first();

        if ($teacher) {
            return (int) $lesson->teacher_id === (int) $teacher->id;
        }

        return false;
    }
}
