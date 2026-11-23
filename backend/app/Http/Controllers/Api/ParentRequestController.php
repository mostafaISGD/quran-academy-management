<?php

namespace App\Http\Controllers\Api;

use App\Enums\StudentRequestType;
use App\Http\Controllers\Concerns\ResolvesParentChild;
use App\Http\Controllers\Controller;
use App\Models\StudentRequest;
use App\Services\StudentRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ⭐⭐ **طلبات ولي الأمر — الجهة الطالبة (PR 4.5).**
 *
 * ⭐ الولي بيرفع طلب على ابنه (لو `can_request_changes`)، بيشوف
 * طلباته، ويسحب المعلّق منها. قرار الإدارة في
 * `StudentRequestController`.
 */
class ParentRequestController extends Controller
{
    use ResolvesParentChild;

    public function __construct(private readonly StudentRequestService $service) {}

    /** ⭐ كل طلبات الحساب ده — بكل الحالات. */
    public function index(Request $request)
    {
        $parent = $this->parentOrFail($request);

        return response()->json([
            'requests' => $this->service->forParent($parent),
        ]);
    }

    /** ⭐ طلب جديد على ابن معيّن. */
    public function store(Request $request, int $student)
    {
        $parent = $this->parentOrFail($request);
        $child = $this->childOrFail($request, $student);
        $this->requireRequestChanges($request, $child);

        $data = $request->validate([
            'type' => ['required', Rule::in(StudentRequestType::values())],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'subscription_id' => ['nullable', 'integer', 'exists:subscriptions,id'],
            'requested_date' => ['nullable', 'date', 'after_or_equal:today'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $created = $this->service->create($request->user(), $parent, $child, $data, $request);

        return response()->json([
            'message' => 'تم إرسال الطلب للإدارة',
            'request' => $this->service->present($created),
        ], 201);
    }

    /** ⭐ سحب طلب معلّق — صاحبه بس. */
    public function withdraw(Request $request, StudentRequest $studentRequest)
    {
        $parent = $this->parentOrFail($request);

        $updated = $this->service->withdraw($parent, $studentRequest, $request);

        return response()->json([
            'message' => 'تم سحب الطلب',
            'request' => $this->service->present($updated),
        ]);
    }
}
