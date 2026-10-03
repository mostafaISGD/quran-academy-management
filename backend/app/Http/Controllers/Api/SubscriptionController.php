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
            // الباقة اختيارية — الاشتراك بيتسجّل بالسعر ونوع الفوترة مباشرة
            'plan_id' => 'nullable|exists:subscription_plans,id',
            'program_id' => 'required|exists:programs,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'billing_type' => 'nullable|in:monthly,per_lesson',
            'price' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'lesson_duration_minutes' => 'nullable|integer',
            'lessons_included' => 'nullable|integer',
            'auto_renew' => 'nullable|boolean',
            'notes' => 'nullable|string',
            // الخطة بتدي السعر والدة الافتراضية — لو اتبعت، بنستخدم قيمها
            'apply_plan_defaults' => 'nullable|boolean',

            // المواعيد الأسبوعية — اختيارية، بس لو بُعتت لازم تتحقق
            'weekdays' => 'nullable|array|min:1|max:7',
            'weekdays.*' => 'integer|min:0|max:6',
            'start_time' => 'nullable|date_format:H:i',
        ]);

        $data['organization_id'] = $request->user()->organization_id;

        // لو الخطة اتبعت، بنعبّي الحقول الناقصة من قيمتها
        unset($data['apply_plan_defaults']);
        if (!empty($data['plan_id'])) {
            $plan = \App\Models\SubscriptionPlan::find($data['plan_id']);
            if ($plan) {
                $data['price'] ??= $plan->price;
                $data['currency'] ??= $plan->currency;
                $data['billing_type'] ??= $plan->billing_type;
                $data['lesson_duration_minutes'] ??= $plan->lesson_duration_minutes;
                $data['lessons_included'] ??= $plan->lessons_count;
            }
        }
        $data['currency'] ??= 'EGP';

        // لو بعت مواعيد + معلم، نتأكد إن المعلم فاضي وإن سعته تكفي
        if (!empty($data['weekdays']) && !empty($data['teacher_id'])) {
            $start = $data['start_time'] ?? '16:00';
            $duration = (int) ($data['lesson_duration_minutes'] ?? 30);
            $onDate = \Carbon\Carbon::parse($data['start_date']);

            $busy = app(\App\Services\TeacherAvailabilityService::class)
                ->busyTeacherIds(array_map('intval', $data['weekdays']), $start, $duration, $onDate);

            if (in_array((int) $data['teacher_id'], $busy, true)) {
                return response()->json([
                    'message' => 'المعلم مشغول في المواعيد دي — غيّر المواعيد أو المعلم',
                ], 422);
            }

            // ممنوع أكتر من طالب في نفس الموعد مع نفس المعلم — فلازم
            // السعة تكون أكبر من أو تساوي عدد الحصص المطلوبة
            $wanted = (int) ($data['lessons_included'] ?? 0);
            if ($wanted > 0) {
                $calc = app(\App\Services\TeacherAvailabilityService::class)
                    ->capacityFor(
                        (int) $data['teacher_id'],
                        array_map('intval', $data['weekdays']),
                        $start,
                        $duration,
                        $onDate,
                        $data['end_date'] ? \Carbon\Carbon::parse($data['end_date']) : null,
                    );

                if ($calc['capacity'] < $wanted) {
                    return response()->json([
                        'message' => 'السعة مش مكفية',
                        'capacity' => $calc['capacity'],
                        'requested' => $wanted,
                        'per_day' => $calc['per_day'],
                    ], 422);
                }
            }
        }

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
