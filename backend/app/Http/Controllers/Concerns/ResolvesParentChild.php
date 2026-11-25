<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ParentModel;
use App\Models\Student;
use App\Services\ParentChildScope;
use Illuminate\Http\Request;

/**
 * ⭐⭐ **حارس البوابة: الطالب لازم يكون ابن مؤكَّد للحساب.**
 *
 * ⭐ `parent.only` بتقول «ده ولي أمر»، ومابتقولش «ابن مين». كل
 * مسار بياخد `{student}` لازم يعدّي من هنا — والقرار نفسه في
 * `ParentChildScope` (PR 1.8) مش في الـcontroller.
 *
 * ⭐ الرد **403** مش 404: الطالب موجود، بس مش بتاعك. 404 كان هيخلي
 * الولي يفكر إن ابنه اتمسح.
 */
trait ResolvesParentChild
{
    protected function parentOrFail(Request $request): ParentModel
    {
        $parent = ParentModel::where('user_id', $request->user()->id)->first();

        if (! $parent) {
            abort(404, 'مفيش ملف ولي أمر مرتبط بالحساب ده');
        }

        return $parent;
    }

    protected function childOrFail(Request $request, int $studentId): Student
    {
        $allowed = ParentChildScope::allowedStudentIds($request->user()) ?? [];

        if (! in_array($studentId, $allowed, true)) {
            abort(403, 'الطالب ده مش من أبناء الحساب ده');
        }

        return Student::query()->findOrFail($studentId);
    }

    /** ⭐ الأكاديمي (حضور/تقدم/حصص) بيتقفل بـ`can_view_progress`. */
    protected function requireProgress(Request $request, Student $student): void
    {
        if (! ParentChildScope::canViewProgress($request->user(), $student->id)) {
            abort(403, 'عرض تقدم الابن غير مسموح لهذا الحساب');
        }
    }

    /** ⭐ المال (فواتير/مدفوعات/رصيد) بيتقفل بـ`can_view_finance`. */
    protected function requireFinance(Request $request, Student $student): void
    {
        if (! ParentChildScope::canViewFinance($request->user(), $student->id)) {
            abort(403, 'عرض مال الابن غير مسموح لهذا الحساب');
        }
    }

    /** ⭐ الطلبات (إلغاء/تأجيل/تعليق) بيتقفل بـ`can_request_changes`. */
    protected function requireRequestChanges(Request $request, Student $student): void
    {
        if (! ParentChildScope::canRequestChanges($request->user(), $student->id)) {
            abort(403, 'رفع طلبات على ابنك غير مسموح لهذا الحساب');
        }
    }
}
