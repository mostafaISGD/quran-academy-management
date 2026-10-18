<?php

namespace App\Policies;

use App\Models\Student;
use App\Models\User;
use App\Services\ParentChildScope;

/**
 * ⭐⭐⭐ **سياسة بيانات الطالب (PR 1.8).**
 *
 * الصلاحيات العامة (`students.view` / `students.update`) تبقى
 * الحد الأقصى للـstaff؛ هذه السياسة تفرض هوامش العلاقة داخل ذلك
 * المجال:
 *
 * - موظف (بلا بروفايلات) → غير مقيّد — الصلاحية العامة هي الحكم.
 * - ولي أمر → أبناؤه فقط؛ التعديل يطلب `can_manage`، ودفتّر
 *   المدفوعات يطلب `can_view_finance`.
 * - معلم → طلابه فقط، ومشاهدة فقط.
 * - طالب → نفسه (يقرأ ودفته المالي، ولا يعدّل سجله).
 */
class StudentPolicy
{
    public function view(User $user, Student $student): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        return $ids === null || in_array($student->id, $ids, true);
    }

    public function update(User $user, Student $student): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            // ⭐ موظف - الصلاحية العامة شغالة كما هي.
            return true;
        }

        // ⭐ التعديل = `can_manage` — العلم الموجود من اليوم الأول
        // والمحدد بقراءته (الـroadmap ينص صراحة إنه كان بكتب ولم
        // يُقرأ). المعلم والطالب يقرأن فقط ولا يعدّلان سجل الطالب.
        return in_array($student->id, $ids, true)
            && ParentChildScope::canManage($user, $student->id);
    }

    /**
     * ⭐ دفتر مدفوعات الطالب (`GET /students/{id}/payments`) —
     * بيانات مالية: للولي شرط `can_view_finance`، وللطالب نفسه
     * دفتر مدفوعاته الخاص؛ المعلم مثلا غير مؤهل ماليا فلا يرى.
     */
    public function payments(User $user, Student $student): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return true;
        }

        if (! in_array($student->id, $ids, true)) {
            return false;
        }

        // ⭐ الطالب يرى دفتر مدفوعاته نفسه؛ ذوو العلاقات الأخرى
        // (المعلم) يحتاجون علم الـpivot المالي.
        $profile = $user->relationLoaded('student')
            ? $user->student
            : $user->student()->first();

        if ($profile !== null) {
            return true;
        }

        return ParentChildScope::canViewFinance($user, $student->id);
    }
}
