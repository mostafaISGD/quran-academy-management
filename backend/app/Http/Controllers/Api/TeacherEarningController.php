<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherEarning;
use App\Services\TeacherEarningService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class TeacherEarningController extends Controller
{
    public function index(Request $request)
    {
        $query = TeacherEarning::query()->with(['teacher', 'lesson', 'rate', 'contract'])
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->where('earning_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('earning_date', '<=', $request->string('to')))
            ->orderByDesc('earning_date');

        $countsQuery = TeacherEarning::query()
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->where('earning_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('earning_date', '<=', $request->string('to')));

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['status'], ['amount']);
    }

    /**
     * ⭐⭐ PR 2.10 — اعتماد مستحق (المعلَق فقط).
     *
     * الصلاحية `payroll.earnings.approve` — قرار مالي بيقدّم
     * المستحق لكشف المرتبات (`harvest()` بتقرأ `approved|paid`).
     * الاعتماد بيكتب في `audit_logs` وسطر دفتر المعلم.
     */
    public function approve(TeacherEarning $earning): JsonResponse
    {
        try {
            $fresh = app(TeacherEarningService::class)
                ->approve($earning->id, auth()->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'تم اعتماد المستحق',
            'data' => $fresh->fresh(),
        ]);
    }

    /**
     * ⭐⭐ PR 2.10 — اعتماد جماعي: المعلَق فقط، والباقي بيرجع
     * في `skipped` بمعارفه.
     */
    public function bulkApprove(Request $request): JsonResponse
    {
        $data = $request->validate([
            'earning_ids' => 'required|array|min:1',
            'earning_ids.*' => 'required|integer|distinct',
        ]);

        $result = app(TeacherEarningService::class)
            ->bulkApprove($data['earning_ids'], $request->user());

        $count = count($result['approved']);

        return response()->json([
            'message' => $count > 0
                ? "تم اعتماد {$count} من المستحقات"
                : 'مفيش مستحقات معلقة هنا',
            'approved' => $result['approved'],
            'skipped' => $result['skipped'],
            'approved_count' => $count,
        ]);
    }
}
