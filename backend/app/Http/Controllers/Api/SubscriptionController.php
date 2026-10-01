<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        $query = Subscription::query()->with(['student', 'plan', 'program', 'teacher'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->string('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('program_id'), fn ($q) => $q->where('program_id', $request->string('program_id')))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->orderByDesc('created_at');

        $countsQuery = Subscription::query()
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->string('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('program_id'), fn ($q) => $q->where('program_id', $request->string('program_id')))
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')));

        return $this->paginatedWithCounts(
            $query,
            $countsQuery,
            $request,
            ['status', 'billing_type'],
            ['price'],
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'plan_id' => 'required|exists:subscription_plans,id',
            'program_id' => 'required|exists:programs,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date',
            'billing_type' => 'nullable|in:monthly,per_lesson,custom',
            'price' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'lesson_duration_minutes' => 'nullable|integer',
            'lessons_included' => 'nullable|integer',
            'auto_renew' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
        $data['organization_id'] = $request->user()->organization_id;
        return response()->json(Subscription::create($data), 201);
    }

    public function show(Subscription $subscription)
    {
        return response()->json($subscription->load(['student', 'plan', 'program', 'teacher', 'pauses', 'lessons']));
    }

    public function update(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'status' => 'sometimes|in:active,expired,paused,cancelled',
            'end_date' => 'nullable|date',
            'auto_renew' => 'nullable|boolean',
            'notes' => 'nullable|string',
        ]);
        $subscription->update($data);
        return response()->json($subscription->fresh());
    }

    public function destroy(Subscription $subscription)
    {
        $subscription->delete();
        return response()->json(['message' => 'تم حذف الاشتراك']);
    }
}
