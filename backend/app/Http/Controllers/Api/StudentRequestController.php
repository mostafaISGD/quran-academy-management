<?php

namespace App\Http\Controllers\Api;

use App\Enums\StudentRequestStatus;
use App\Http\Controllers\Controller;
use App\Models\StudentRequest;
use App\Services\StudentRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐⭐ **صندوق طلبات أولياء الأمور — الإدارة (PR 4.5).**
 *
 * ⭐ `student_requests.view` للصندوق، و`student_requests.manage`
 * للقرار. الاتنين منفصلين: موظف الاستقبال بيشوف الطلبات عشان
 * يرد على التليفون، بس مش بيوافق ولا يرفض.
 *
 * ⭐ كل استعلام مربوط بمؤسسة المستخدم — طلب مؤسسة تانية = 404.
 */
class StudentRequestController extends Controller
{
    public function __construct(private readonly StudentRequestService $service) {}

    public function index(Request $request)
    {
        $orgId = $request->user()->organization_id;

        $base = StudentRequest::query()
            ->where('organization_id', $orgId)
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')));

        // ⭐ الافتراضي: المعلّق بس — ده «الصندوق». `status=all` للتاريخ.
        $status = $request->string('status')->toString();

        $query = (clone $base)
            ->when($status !== '' && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($status === '', fn ($q) => $q->where('status', StudentRequestStatus::Pending->value))
            ->with(['student', 'parent', 'lesson', 'subscription'])
            ->orderByDesc('created_at');

        $perPage = min(max($request->integer('per_page') ?: 50, 1), 200);
        $page = $query->paginate($perPage);

        // ⭐ العدّادات على **كل** الحالات، مش الصفحة الحالية.
        $statusCounts = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();

        return response()->json([
            'data' => collect($page->items())
                ->map(fn (StudentRequest $r) => $this->service->present($r))
                ->all(),
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
            'counts' => [
                'status' => $statusCounts,
                'type' => $this->typeCounts(clone $base),
            ],
        ]);
    }

    public function approve(Request $request, StudentRequest $studentRequest)
    {
        $this->assertSameOrganization($request, $studentRequest);

        $data = $request->validate([
            'admin_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->service->decide(
            $request->user(),
            $studentRequest,
            StudentRequestStatus::Approved,
            $data['admin_notes'] ?? null,
            $request
        );

        return response()->json([
            'message' => 'تمت الموافقة على الطلب — سجّل القرار، والتطبيق على مسار الحصة',
            'request' => $this->service->present($updated),
        ]);
    }

    public function reject(Request $request, StudentRequest $studentRequest)
    {
        $this->assertSameOrganization($request, $studentRequest);

        $data = $request->validate([
            'admin_notes' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        $updated = $this->service->decide(
            $request->user(),
            $studentRequest,
            StudentRequestStatus::Rejected,
            $data['admin_notes'],
            $request
        );

        return response()->json([
            'message' => 'تم رفض الطلب',
            'request' => $this->service->present($updated),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    private function assertSameOrganization(Request $request, StudentRequest $studentRequest): void
    {
        abort_unless(
            $studentRequest->organization_id === $request->user()->organization_id,
            404,
            'الطلب مش موجود'
        );
    }

    /** @return array<string, int> */
    private function typeCounts(Builder $query): array
    {
        return $query
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
