<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SchedulingConflictException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonReschedule;
use App\Models\MemorizationRecord;
use App\Models\ProgressRecord;
use App\Models\TeacherEarning;
use App\Services\LessonScheduler;
use Carbon\Carbon;
use Illuminate\Http\Request;

class LessonController extends Controller
{
    public function __construct(private readonly LessonScheduler $scheduler) {}

    public function schedule(Request $request)
    {
        $view = $request->string('view', 'week');
        $date = $request->filled('date') ? Carbon::parse($request->string('date')) : now();

        [$from, $to] = match ((string) $view) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            default => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
        };

        $query = Lesson::query()
            ->whereBetween('scheduled_start_at', [$from, $to])
            ->with(['student', 'teacher', 'program', 'level']);

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->string('teacher_id'));
        }
        if ($request->filled('student_id')) {
            $query->where('student_id', $request->string('student_id'));
        }

        return response()->json($query->orderBy('scheduled_start_at')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'teacher_id' => 'required|exists:teachers,id',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'program_id' => 'required|exists:programs,id',
            'level_id' => 'nullable|exists:levels,id',
            'lesson_type' => 'nullable|in:regular,trial,makeup,extra,free,assessment',
            'scheduled_start' => 'required|date',
            'duration_minutes' => 'required|integer|min:15',
            'meeting_provider' => 'nullable|in:zoom,google_meet,other',
            'meeting_url' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['lesson_type'] = $data['lesson_type'] ?? 'regular';

        $start = Carbon::parse($data['scheduled_start']);
        $end = $start->copy()->addMinutes($data['duration_minutes']);

        try {
            $lesson = $this->scheduler->createLesson([
                ...$data,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
            ]);
        } catch (SchedulingConflictException $e) {
            return $e->render();
        }

        return response()->json($lesson, 201);
    }

    public function storeRecurring(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'teacher_id' => 'required|exists:teachers,id',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'program_id' => 'required|exists:programs,id',
            'level_id' => 'nullable|exists:levels,id',
            'weekdays' => 'required|array|min:1',
            'weekdays.*' => 'integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|min:15',
            'series_start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $data['organization_id'] = $request->user()->organization_id;

        $result = $this->scheduler->createRecurringSeries($data);

        return response()->json([
            'created_count' => count($result['created']),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ], 201);
    }

    public function update(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'scheduled_start' => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:15',
            'notes' => 'nullable|string',
            'status' => 'sometimes|in:scheduled,confirmed,in_progress,completed,cancelled,student_absent,teacher_absent,technical_issue,rescheduled',
        ]);

        if (isset($data['scheduled_start']) || isset($data['duration_minutes'])) {
            $start = Carbon::parse($data['scheduled_start'] ?? $lesson->scheduled_start_at);
            $duration = $data['duration_minutes'] ?? $lesson->duration_minutes;
            $end = $start->copy()->addMinutes($duration);

            $conflict = $this->scheduler->findConflict($lesson->teacher_id, $start, $end, excludeLessonId: $lesson->id);
            if ($conflict) {
                return (new SchedulingConflictException($conflict))->render();
            }

            LessonReschedule::create([
                'lesson_id' => $lesson->id,
                'old_start' => $lesson->scheduled_start_at,
                'old_end' => $lesson->scheduled_end_at,
                'new_start' => $start,
                'new_end' => $end,
                'reason' => 'تحديث يدوي',
                'changed_by' => $request->user()->id,
                'created_at' => now(),
            ]);

            $data['scheduled_start_at'] = $start;
            $data['scheduled_end_at'] = $end;
            $data['duration_minutes'] = $duration;

            unset($data['scheduled_start']);
        }

        $lesson->update($data);
        return response()->json($lesson->fresh());
    }

    public function cancel(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'cancellation_reason' => 'required|string|max:255',
        ]);

        $lesson->update([
            'status' => 'cancelled',
            'cancellation_reason' => $data['cancellation_reason'],
        ]);

        return response()->json($lesson->fresh());
    }

    public function makeup(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'scheduled_start' => 'required|date',
        ]);

        $lesson->update(['status' => 'teacher_absent']);

        $start = Carbon::parse($data['scheduled_start']);
        $end = $start->copy()->addMinutes($lesson->duration_minutes);

        try {
            $makeup = $this->scheduler->createMakeupLesson($lesson, $start, $end);
        } catch (SchedulingConflictException $e) {
            return $e->render();
        }

        return response()->json($makeup, 201);
    }

    public function complete(Request $request, Lesson $lesson)
    {
        $lesson->update(['status' => 'completed']);

        $teacher = $lesson->teacher;
        if ($teacher && $teacher->activeContract?->contract_type === 'per_lesson') {
            $rate = $teacher->currentRate;
            if ($rate) {
                TeacherEarning::create([
                    'teacher_id' => $teacher->id,
                    'lesson_id' => $lesson->id,
                    'contract_id' => $teacher->activeContract->id,
                    'rate_id' => $rate->id,
                    'amount' => $rate->amount,
                    'currency' => $rate->currency,
                    'earning_date' => now()->toDateString(),
                    'status' => 'pending',
                ]);
            }
        }

        return response()->json($lesson->fresh());
    }

    public function attendance(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'status' => 'required|in:present,absent,late,excused',
            'late_minutes' => 'nullable|integer|min:0',
            'notes' => 'nullable|string',
        ]);

        $attendance = LessonAttendance::updateOrCreate(
            ['lesson_id' => $lesson->id],
            [
                'student_id' => $lesson->student_id,
                'status' => $data['status'],
                'late_minutes' => $data['late_minutes'] ?? null,
                'marked_at' => now(),
                'marked_by' => $request->user()->id,
                'notes' => $data['notes'] ?? null,
            ]
        );

        return response()->json($attendance, 201);
    }

    public function memorization(Request $request, Lesson $lesson)
    {
        $data = $request->validate([
            'surah_id' => 'required|exists:quran_surahs,id',
            'from_ayah' => 'required|integer|min:1',
            'to_ayah' => 'required|integer|min:1|gte:from_ayah',
            'quality' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string',
        ]);

        $record = MemorizationRecord::create([
            'student_id' => $lesson->student_id,
            'lesson_id' => $lesson->id,
            'teacher_id' => $lesson->teacher_id,
            ...$data,
            'recorded_at' => now(),
        ]);

        ProgressRecord::create([
            'student_id' => $lesson->student_id,
            'lesson_id' => $lesson->id,
            'teacher_id' => $lesson->teacher_id,
            'program_id' => $lesson->program_id,
            'level_id' => $lesson->level_id,
            'category' => 'memorization',
            'score' => $data['quality'] ?? null,
            'recorded_at' => now(),
        ]);

        return response()->json($record, 201);
    }
}
