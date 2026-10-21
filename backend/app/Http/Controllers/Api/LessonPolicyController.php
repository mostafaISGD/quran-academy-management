<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LessonPolicy;
use App\Services\LessonPolicyService;
use Illuminate\Http\Request;

/**
 * ⭐⭐⭐ **سياسات الحصص (PR 3.2).**
 *
 * ⭐ **صف لكل (نتيجة × نطاق)** — والتعديل على صف واحد:
 * `PUT /api/lesson-policy/{policy}`.
 *
 * ⭐ **كل تعديل بيتسجَّل** (القديم والجديد) في `audit_logs` — لأن
 * تغيّر قاعدة مالية لازم يبقى مراجَع.
 *
 * ⭐ **التعديل بيغيّر المستقبل بس**: الحصة اللي اتقبلت قبل
 * التعديل محفوظة بلقطةها (`lessons.policy_snapshot`).
 */
class LessonPolicyController extends Controller
{
    public function show(Request $request)
    {
        // ⭐ ضمان وجود السياسات (الافتراضات الآمنة) — فالمدير دايماً
        // بيلاقي صفوف يعدّلها، حتى لو الـseeder ما اشتغلشش.
        app(LessonPolicyService::class)->ensure((int) $request->user()->organization_id);

        $policies = LessonPolicy::where('organization_id', $request->user()->organization_id)
            ->with('program:id,name')
            ->orderBy('outcome')
            ->orderBy('scope')
            ->get();

        return response()->json([
            'data' => [
                'policies' => $policies->map(fn (LessonPolicy $p) => $this->present($p))->all(),
                'defaults' => LessonPolicy::defaults(),
            ],
        ]);
    }

    public function update(Request $request, LessonPolicy $policy)
    {
        // ⭐ السياسة لازم تكون لنفس المؤسسة (مش سياسات أكاديمية تانية).
        if ((int) $policy->organization_id !== (int) $request->user()->organization_id) {
            abort(403);
        }

        $data = $request->validate([
            'deduct_credit' => 'sometimes|boolean',
            'pay_teacher' => 'sometimes|boolean',
            'allow_makeup' => 'sometimes|boolean',
            'makeup_limit' => 'sometimes|integer|min:0|max:60',
            'makeup_window_days' => 'sometimes|integer|min:0|max:365',
            'notify_parent' => 'sometimes|boolean',
            'late_cancel_notice_hours' => 'sometimes|integer|min:0|max:168',
            'makeup_deducts_from_package' => 'sometimes|boolean',
        ]);

        $before = $policy->getOriginal();

        $policy->update($data);

        app(\App\Services\AuditLogService::class)->logUpdate(
            'lesson_policy',
            $policy->id,
            $before,
            $policy->fresh()->toArray(),
            $request,
        );

        return response()->json([
            'message' => 'اتحفظت سياسة الحصة',
            'data' => $this->present($policy->fresh()),
        ]);
    }

    private function present(LessonPolicy $p): array
    {
        return [
            'id' => $p->id,
            'organization_id' => $p->organization_id,
            'outcome' => $p->outcome,
            'scope' => $p->scope,
            'program_id' => $p->program_id,
            'program_name' => $p->program?->name,
            'deduct_credit' => $p->deduct_credit,
            'pay_teacher' => $p->pay_teacher,
            'allow_makeup' => $p->allow_makeup,
            'makeup_limit' => $p->makeup_limit,
            'makeup_window_days' => $p->makeup_window_days,
            'notify_parent' => $p->notify_parent,
            'late_cancel_notice_hours' => $p->late_cancel_notice_hours,
            'makeup_deducts_from_package' => $p->makeup_deducts_from_package,
        ];
    }
}
