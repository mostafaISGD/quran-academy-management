<?php

namespace App\Services;

use App\Exceptions\SchedulingConflictException;
use App\Models\Lesson;
use App\Models\LessonReschedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class LessonScheduler
{
    public function findConflict(
        string $teacherId,
        Carbon $startAt,
        Carbon $endAt,
        ?string $excludeLessonId = null
    ): ?Lesson {
        return Lesson::query()
            ->where('teacher_id', $teacherId)
            ->blockingTeacherTime()
            ->when($excludeLessonId, fn ($q) => $q->where('id', '!=', $excludeLessonId))
            ->where('scheduled_start_at', '<', $endAt)
            ->where('scheduled_end_at', '>', $startAt)
            ->first();
    }

    /**
     * @throws SchedulingConflictException
     */
    public function createLesson(array $attributes): Lesson
    {
        $start = Carbon::parse($attributes['scheduled_start_at']);
        $end = Carbon::parse($attributes['scheduled_end_at']);

        $conflict = $this->findConflict($attributes['teacher_id'], $start, $end);

        if ($conflict) {
            throw new SchedulingConflictException($conflict);
        }

        return Lesson::create($attributes);
    }

    /**
     * @return array{created: Lesson[], skipped: array}
     */
    public function createRecurringSeries(array $data): array
    {
        $groupId = (string) Str::uuid();
        $created = [];
        $skipped = [];

        $cursor = CarbonImmutable::parse($data['series_start_date']);
        $endBoundary = CarbonImmutable::parse($data['end_date'])->endOfDay();

        while ($cursor <= $endBoundary) {
            if (in_array($cursor->dayOfWeek, $data['weekdays'], true)) {
                [$hour, $minute] = explode(':', $data['start_time']);
                $start = $cursor->setTime((int) $hour, (int) $minute);
                $end = $start->addMinutes($data['duration_minutes']);

                $conflict = $this->findConflict(
                    $data['teacher_id'],
                    Carbon::instance($start),
                    Carbon::instance($end)
                );

                if ($conflict) {
                    $skipped[] = [
                        'date' => $start->toDateString(),
                        'reason' => "تعارض مع حصة رقم {$conflict->id}",
                    ];
                } else {
                    $created[] = Lesson::create([
                        'organization_id' => $data['organization_id'],
                        'student_id' => $data['student_id'],
                        'teacher_id' => $data['teacher_id'],
                        'subscription_id' => $data['subscription_id'] ?? null,
                        'program_id' => $data['program_id'],
                        'level_id' => $data['level_id'] ?? null,
                        'lesson_type' => $data['lesson_type'] ?? 'regular',
                        'scheduled_start_at' => $start,
                        'scheduled_end_at' => $end,
                        'duration_minutes' => $data['duration_minutes'],
                        'status' => 'scheduled',
                    ]);
                }
            }

            $cursor = $cursor->addDay();
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @throws SchedulingConflictException
     */
    public function createMakeupLesson(Lesson $originalLesson, Carbon $newStart, Carbon $newEnd): Lesson
    {
        return $this->createLesson([
            'organization_id' => $originalLesson->organization_id,
            'student_id' => $originalLesson->student_id,
            'teacher_id' => $originalLesson->teacher_id,
            'subscription_id' => $originalLesson->subscription_id,
            'program_id' => $originalLesson->program_id,
            'level_id' => $originalLesson->level_id,
            'lesson_type' => 'makeup',
            'scheduled_start_at' => $newStart,
            'scheduled_end_at' => $newEnd,
            'duration_minutes' => $originalLesson->duration_minutes,
            'status' => 'scheduled',
            'parent_lesson_id' => $originalLesson->id,
        ]);
    }
}
