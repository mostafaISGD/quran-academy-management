<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Teacher;
use App\Models\TeacherLedgerEntry;
use App\Services\TeacherLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * PR 2.11 — كشف حساب المعلم + التسويات (مكافأة/خصم/سلفة).
 *
 * كل واحد **صلاحية منفصلة** لأنها قرارات مالية مختلفة:
 *   - `payroll.view` للقراءة.
 *   - `payroll.bonuses.create` / `payroll.deductions.create` /
 *     `payroll.advances.create` للكتابة.
 *
 * المكافأة دائن (تزيد رصيد المعلم)، والخصم والسلفة مدين
 * ينقصوه. مافيش حركة حساب هنا — دي قيود دفتر؛ الصرف النقدي
 * فعلي مصروفي بيجي من مسارات الصرف/المصروفات.
 */
class TeacherLedgerController extends Controller
{
    /**
     * ⭐ كشف حساب المعلم — سطور الدفتر + إجماليات لكل عملة.
     *
     * نفس شكل كشف الطالب (PR 2.9): `currencies` و `entries`.
     */
    public function statement(Teacher $teacher)
    {
        Gate::authorize('view', $teacher);

        // ⭐⭐⭐ **PR 3.10 — الكشف بقى خدمة** (`TeacherLedgerService::
        // statementFor`) — بوابة المعلم بتستعمل نفس الخدمة، فشكل
        // الكشف موحّد ومفيش نسخة تانية بتشتغل خطأ.
        return response()->json(
            app(TeacherLedgerService::class)->statementFor($teacher),
        );
    }

    public function bonus(Request $request, Teacher $teacher): JsonResponse
    {
        return $this->storeAdjustment($request, $teacher, [
            'type' => 'bonus',
            'direction' => 'credit',
        ]);
    }

    public function deduction(Request $request, Teacher $teacher): JsonResponse
    {
        return $this->storeAdjustment($request, $teacher, [
            'type' => 'deduction',
            'direction' => 'debit',
        ]);
    }

    public function advance(Request $request, Teacher $teacher): JsonResponse
    {
        return $this->storeAdjustment($request, $teacher, [
            'type' => 'advance',
            'direction' => 'debit',
        ]);
    }

    /**
     * ⭐⭐ الكاتب الوحيد للتسويات — سطر دفتر + تدقيق.
     *
     * @param  array{type: string, direction: string}  $spec
     */
    private function storeAdjustment(Request $request, Teacher $teacher, array $spec): JsonResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999999',
            'currency' => 'nullable|string|size:3',
            'description' => 'nullable|string|max:255',
        ]);

        $amount = round((float) $data['amount'], 2);

        $entry = DB::transaction(function () use ($request, $teacher, $spec, $amount, $data) {
                $row = [
                    'teacher_id' => $teacher->id,
                    'type' => $spec['type'],
                    'currency' => strtoupper($data['currency'] ?? 'EGP'),
                    $spec['direction'] => $amount,
                    'description' => $data['description'] ?? match ($spec['type']) {
                        'bonus' => 'مكافأة',
                        'deduction' => 'خصم',
                        'advance' => 'سلفة على المرتب',
                        default => 'تسوية',
                    },
                    'created_by' => $request->user()->id,
                ];

            $created = app(TeacherLedgerService::class)->write($row);

            app(\App\Services\AuditLogService::class)->log(
                'ledger_adjustment',
                'teacher_ledger_entry',
                $created->id,
                null,
                ['type' => $spec['type'], 'amount' => $amount, 'currency' => $row['currency']],
                null,
                $request->user()->id,
            );

            return $created;
        });

        return response()->json([
            'message' => 'تم تسجيل سطر الدفتر',
            'data' => $entry->fresh(),
        ], 201);
    }
}
