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

        // weekdays/start_time جوّه الـ payload بس — بيتخزّنوا في أعمدة
        // schedule_weekdays / schedule_start_time، مش في weekdays/start_time
        $weekdays = array_values(array_unique(array_map('intval', $data['weekdays'] ?? [])));
        $startTime = isset($data['start_time']) ? substr($data['start_time'], 0, 5) : null;
        unset($data['weekdays'], $data['start_time'], $data['apply_plan_defaults']);

        // لو الخطة اتبعت، بنعبّي الحقول الناقصة من قيمتها
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

        $data['schedule_weekdays'] = $weekdays ?: null;
        $data['schedule_start_time'] = $startTime;

        // لو بعت مواعيد + معلم، نتأكد إن المعلم فاضي وإن سعته تكفي
        if ($weekdays && !empty($data['teacher_id'])) {
            $start = $startTime ?? '16:00';
            $duration = (int) ($data['lesson_duration_minutes'] ?? 30);
            $onDate = \Carbon\Carbon::parse($data['start_date']);

            $busy = app(\App\Services\TeacherAvailabilityService::class)
                ->busyTeacherIds($weekdays, $start, $duration, $onDate);

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
                        $weekdays,
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

        $subscription = Subscription::create($data);

        // تشغيل الاشتراك: فاتورة + رصيد حصص + جدولة الحصص على المواعيد
        $activation = app(\App\Services\SubscriptionActivationService::class)->activate(
            $subscription->load(['program', 'plan']),
            [
                'weekdays' => $weekdays,
                'start_time' => $startTime,
                'duration_minutes' => $subscription->lesson_duration_minutes,
            ]
        );

        return response()->json([
            'subscription' => $subscription->load(['student', 'program', 'teacher', 'plan']),
            'activation' => $activation,
        ], 201);
    }

    public function show(Subscription $subscription)
    {
        return response()->json($subscription->load(['student', 'plan', 'program', 'teacher', 'pauses', 'lessons']));
    }

    public function update(Request $request, Subscription $subscription)
    {
        $data = $request->validate([
            'program_id' => 'sometimes|exists:programs,id',
            'teacher_id' => 'nullable|exists:teachers,id',
            'status' => 'sometimes|in:active,expired,paused,cancelled',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date',
            'billing_type' => 'nullable|in:monthly,per_lesson',
            'price' => 'nullable|numeric',
            'currency' => 'nullable|string|size:3',
            'lesson_duration_minutes' => 'nullable|integer',
            'lessons_included' => 'nullable|integer',
            'auto_renew' => 'nullable|boolean',
            'notes' => 'nullable|string',
            'weekdays' => 'nullable|array|min:1|max:7',
            'weekdays.*' => 'integer|min:0|max:6',
            'start_time' => 'nullable|date_format:H:i',
        ]);

        // لازم نقرأ الـ flags قبل الـ unset، وإلا $slotChanged هتبقى false دايماً
        $teacherChanged = array_key_exists('teacher_id', $data)
            && (int) $data['teacher_id'] !== (int) $subscription->teacher_id;
        $slotChanged = $teacherChanged
            || array_key_exists('weekdays', $data)
            || array_key_exists('start_time', $data)
            || array_key_exists('lesson_duration_minutes', $data);

        // weekdays/start_time جوّه الـ payload بس — بيتخزّنوا في schedule_*
        $weekdays = array_key_exists('weekdays', $data)
            ? array_values(array_unique(array_map('intval', $data['weekdays'])))
            : ($subscription->schedule_weekdays ?? []);
        $startTime = array_key_exists('start_time', $data)
            ? substr((string) $data['start_time'], 0, 5)
            : $subscription->schedule_start_time;

        unset($data['weekdays'], $data['start_time']);

        $slot = [
            'weekdays' => $weekdays,
            'start_time' => $startTime,
            'duration_minutes' => $data['lesson_duration_minutes'] ?? $subscription->lesson_duration_minutes,
        ];

        // بنحدّث قيم الـ model قبل المقارنة عشان تكون بالإعداد الجديد،
        // وبعدين $subscription->update يحفظهم كلهم
        if (array_key_exists('teacher_id', $data)) {
            $subscription->teacher_id = $data['teacher_id'];
        }
        $data['schedule_weekdays'] = $weekdays ?: null;
        $data['schedule_start_time'] = $startTime;

        $cancelled = $slotChanged
            ? app(\App\Services\SubscriptionActivationService::class)->cancelStaleLessons($subscription, $slot)
            : 0;

        $subscription->update($data);

        $activation = app(\App\Services\SubscriptionActivationService::class)->activate(
            $subscription->fresh(),
            $slot,
        );

        return response()->json([
            'subscription' => $subscription->fresh()->load(['student', 'program', 'teacher', 'plan']),
            'activation' => $activation + ['lessons_cancelled' => $cancelled],
        ]);
    }

    public function destroy(Subscription $subscription)
    {
        $subscription->delete();
        return response()->json(['message' => 'تم حذف الاشتراك']);
    }
}
