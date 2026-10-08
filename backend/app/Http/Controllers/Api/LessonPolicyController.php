<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonPolicy;
use Illuminate\Http\Request;

/**
 * ⭐ سياسة استهلاك الحصة — غياب الطالب، غياب المعلم، التعويض.
 *
 * ⭐ كل أكاديمية صف واحد. السياسات بت
 * عبر API مش طبيعي تعديلها من الداتابيز مباشرةً.
 */
class LessonPolicyController extends Controller
{
    public function show(Request $request)
    {
        $policy = LessonPolicy::current($request->user()->organization_id);

        return response()->json(['data' => $this->present($policy)]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'student_absence_consumes_lesson' => 'sometimes|boolean',
            'student_absence_teacher_paid' => 'sometimes|boolean',
            'teacher_absence_consumes_lesson' => 'sometimes|boolean',
            'teacher_absence_teacher_paid' => 'sometimes|boolean',
            'makeup_consumes_lesson' => 'sometimes|boolean',
            'makeup_teacher_paid' => 'sometimes|boolean',
            'max_makeup_per_month' => 'sometimes|integer|min:0|max:31',
            'max_makeup_days' => 'sometimes|integer|min:0|max:365',
            'makeup_deducts_from_package' => 'sometimes|boolean',
        ]);

        $policy = LessonPolicy::current($request->user()->organization_id);
        $policy->update($data);

        app(\App\Services\AuditLogService::class)->logUpdate(
            'lesson_policy',
            $policy->id,
            $policy->getOriginal(),
            $policy->fresh()->toArray(),
            $request,
        );

        return response()->json([
            'message' => 'اتحفظت سياسة الحصص',
            'data' => $this->present($policy->fresh()),
        ]);
    }

    private function present(LessonPolicy $p): array
    {
        return [
            'id' => $p->id,
            'organization_id' => $p->organization_id,
            'student_absence_consumes_lesson' => $p->student_absence_consumes_lesson,
            'student_absence_teacher_paid' => $p->student_absence_teacher_paid,
            'teacher_absence_consumes_lesson' => $p->teacher_absence_consumes_lesson,
            'teacher_absence_teacher_paid' => $p->teacher_absence_teacher_paid,
            'makeup_consumes_lesson' => $p->makeup_consumes_lesson,
            'makeup_teacher_paid' => $p->makeup_teacher_paid,
            'max_makeup_per_month' => $p->max_makeup_per_month,
            'max_makeup_days' => $p->max_makeup_days,
            'makeup_deducts_from_package' => $p->makeup_deducts_from_package,
        ];
    }
}
