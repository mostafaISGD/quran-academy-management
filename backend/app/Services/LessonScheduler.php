<?php

namespace App\Services;

use App\Exceptions\LessonOnHoliday;
use App\Exceptions\SchedulingConflictException;
use App\Exceptions\TeacherUnavailable;
use App\Models\Holiday;
use App\Models\Lesson;
use App\Models\LessonReschedule;
use App\Models\Setting;
use App\Models\TeacherSchedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class LessonScheduler
{
    /**
     * ⭐⭐⭐ **PR 3.6 — التعارض يشمل المعلم **والطالب** (والمجموعة).**     *
     * ⭐ قبل PR ده كان بيفحص المعلم بس ⇒ كان ممكن تحجز نفس
     * الطالب في وقت حصته ( الطالب بيتناقض مع نفسه ) — والقبول
     * في المواصفة: «حجز طالب في وقت حصة أخرى = 422».
     *
     * @param  int[]  $studentIds  طلاب الحصة (جماعية) — كلهم لازم
     *                            يبقوا فاضيين
     */
    public function findConflict(
        string $teacherId,
        Carbon $startAt,
        Carbon $endAt,
        ?string $excludeLessonId = null,
        int|array|null $studentIds = null,
    ): ?Lesson {
        $studentIds = array_values(array_filter(array_map(
            'intval',
            is_array($studentIds) ? $studentIds : [$studentIds],
        )));

        return Lesson::query()
            ->blockingTeacherTime()
            ->when($excludeLessonId, fn ($q) => $q->where('id', '!=', $excludeLessonId))
            ->where('scheduled_start_at', '<', $endAt)
            ->where('scheduled_end_at', '>', $startAt)
            ->where(function ($query) use ($teacherId, $studentIds) {
                $query->where('teacher_id', $teacherId);

                if ($studentIds !== []) {
                    $query->orWhereIn('student_id', $studentIds);
                }
            })
            ->first();
    }

    /**
     * ⭐⭐⭐ **PR 3.6 — فحص الجدولة كله قبل الحجز.**
     *
     * الترتيب: **العطلة** (أوضح رسالة) ← **حظر المعلم** ←
     * **التعارض** (معلم أو طالب).
     *
     * @throws LessonOnHoliday
     * @throws TeacherUnavailable
     * @throws SchedulingConflictException
     */
    public function assertBookable(
        string $teacherId,
        Carbon $startAt,
        Carbon $endAt,
        ?int $organizationId = null,
        int|array|null $studentIds = null,
        ?string $excludeLessonId = null,
    ): void {
        // ① العطلة (رسمية أو أكاديمية) — بتغطي اليوم كله.
        $holiday = Holiday::findFor($startAt->toDateString(), $organizationId);

        if ($holiday !== null) {
            throw new LessonOnHoliday($holiday, $startAt);
        }

        // ② حظر جدول المعلم (شغل خارجي / إجازة / شخصي).
        if ($this->teacherIsBlocked($teacherId, $startAt, $endAt)) {
            throw new TeacherUnavailable($teacherId, $startAt);
        }

        // ③ التعارض (معلم أو طالب) — والسبب بيحدّد الرسالة.
        $conflict = $this->findConflict($teacherId, $startAt, $endAt, $excludeLessonId, $studentIds);

        if ($conflict !== null) {
            $studentIds = array_values(array_filter(array_map(
                'intval',
                is_array($studentIds) ? $studentIds : [$studentIds],
            )));

            $reason = (string) $conflict->teacher_id === (string) $teacherId
                ? 'teacher'
                : 'student';

            throw new SchedulingConflictException($conflict, $reason);
        }
    }

    /**
     * ⭐ هل المعلم محظور في الوقت ده (من `teacher_schedules`)؟
     */
    public function teacherIsBlocked(string $teacherId, Carbon $startAt, Carbon $endAt): bool
    {
        $teacherId = (int) $teacherId;

        return TeacherSchedule::query()
            ->where('teacher_id', $teacherId)
            ->where(function ($query) use ($startAt) {
                // ⭐ أسبوعي (is_recurring) أو محدّد بيوم معيّن.
                $query->where(function ($q) use ($startAt) {
                    $q->where('is_recurring', true)->where('weekday', $startAt->dayOfWeek);
                })->orWhere(function ($q) use ($startAt) {
                    $q->where('is_recurring', false)->whereDate('specific_date', $startAt->toDateString());
                });
            })
            ->get()
            ->contains(function (TeacherSchedule $block) use ($startAt, $endAt) {
                // ⭐⭐⭐ **PR 3.7 — ساعات الحظر محلية ⇒ نقارنها في
                // نفس منطقة الموعد.** قبل PR 3.7 كانت كل القيم
                // UTC فالمقارنة كانت متسقة بالصدفة؛ بعد ما بقى
                // الموعد بينتاقص في منطقة المستخدم، لازم الحظر
                // يتقارن بنفس المنطقة وإلا غاب الحظر.
                $blockStart = Carbon::parse(
                    $startAt->toDateString().' '.$block->starts_at,
                    $startAt->getTimezone(),
                );
                $blockEnd = Carbon::parse(
                    $startAt->toDateString().' '.$block->ends_at,
                    $startAt->getTimezone(),
                );

                // ⭐ التداخل: بداية الحصة قبل نهاية الحظر **أو**
                // نهايتها بعد بداية الحظر.
                return $startAt->lt($blockEnd) && $endAt->gt($blockStart);
            });
    }

    /**
     * ⭐⭐⭐ **PR 3.6 — حدود التوليد من الإعدادات.**
     *
     * ⭐ كانت أرقام ثابتة في الكود (200 حصة / 3 شهور) — دلوقتي
     * من `settings` (الافتراضيات زي الأول) عشان مركز الإعدادات
     * (PR 3.12) يتحكم فيها من غير كود.
     */
    public function maxGenerationCount(?int $organizationId = null): int
    {
        return (int) Setting::get('lessons.max_generation_count', 200, $organizationId);
    }

    /** ⭐ نافذة التوليد بالأشهر (3 افتراضيًا). */
    public function maxGenerationMonths(?int $organizationId = null): int
    {
        return (int) Setting::get('lessons.max_generation_months', 3, $organizationId);
    }

    /**
     * @throws SchedulingConflictException
     */
    public function createLesson(array $attributes): Lesson
    {
        $start = Carbon::parse($attributes['scheduled_start_at']);
        $end = Carbon::parse($attributes['scheduled_end_at']);

        $this->assertBookable(
            (string) $attributes['teacher_id'],
            $start,
            $end,
            $attributes['organization_id'] ?? null,
            $attributes['student_id'] ?? null,
        );

        /**
         * ⭐⭐⭐ **PR 3.7 — التخزين بـUTC صراحةً.**
         *
         * ⚠️ Eloquent **مش** بيحوّل Carbon للمنطقة قبل الكتابة
         * (`fromDateTime` بيكتب `format('Y-m-d H:i:s')` زي ما هو)
         * ⇒ حصة اتعملت 16:00 القاهرة كانت بتتخزّن 16:00 (أي
         * ساعتين غلط عن UTC).
         *
         * ⭐ فبيحوّل دي للـUTC **قبل** الحفظ — وبكده كل `scheduled_*_at`
         * مخزّن UTC زي ما المواصفة بتقول.
         */
        $attributes['scheduled_start_at'] = $start->copy()->setTimezone('UTC');
        $attributes['scheduled_end_at'] = $end->copy()->setTimezone('UTC');

        return Lesson::create($attributes);
    }

    /**
     * ⭐⭐⭐ **PR 3.7 — توليد السلسلة المتكررة في المنطقة المحلية.**
     *
     * ⭐ **ليه؟** التوقيت الصيفي (DST) بيغيّر فرق UTC بس **الساعة
     * المحلية ما بتتغيّرش**. فلو ولّدنا بـUTC (16:00 UTC) ⇒ بعد
     * التغير الصيفي الساعة المحلية بقت 17:00/15:00 — غلط.
     *
     * ⭐ فبنولّد بـCarbon في **منطقة السلسلة** (`$timezone`)، وبنخزّن
     * `timezone` + `local_start_time` على كل حصة ⇒ الساعة المحلية
     * محفوظة زي ما هي مهما تغيّر التوقيت الصيفي.
     *
     * @return array{created: Lesson[], skipped: array}
     */
    public function createRecurringSeries(array $data): array
    {
        $groupId = (string) Str::uuid();
        $created = [];
        $skipped = [];

        // ⭐ منطقة السلسلة (المعلم/المؤسسة) — الافتراضي UTC.
        $timezone = (string) ($data['timezone'] ?? config('app.timezone'));

        $cursor = CarbonImmutable::parse($data['series_start_date'], $timezone);
        $endBoundary = CarbonImmutable::parse($data['end_date'], $timezone)->endOfDay();

        // ⭐⭐⭐ **PR 3.6 — حد التوليد من الإعدادات** (200 افتراضيًا).
        $max = $this->maxGenerationCount($data['organization_id'] ?? null);

        while ($cursor <= $endBoundary) {
            // ⭐ الحد وصل ⇒ نقف (والتاولة لو عايز/تعرف).
            if (count($created) >= $max) {
                $skipped[] = [
                    'date' => $cursor->toDateString(),
                    'reason' => "وصلنا لحد التوليد ({$max} حصة) — زوّد `lessons.max_generation_count` لو أكتر",
                ];
                break;
            }

            if (in_array($cursor->dayOfWeek, $data['weekdays'], true)) {
                [$hour, $minute] = explode(':', $data['start_time']);
                // ⭐ `setTime` في **منطقة السلسلة** ⇒ الساعة المحلية
                // ثابتة عبر التوقيت الصيفي.
                $start = $cursor->setTime((int) $hour, (int) $minute);
                $end = $start->addMinutes($data['duration_minutes']);

                // ⭐ العطلات وحظر المعلم — بنفس قواعد الحجز المفرد.
                $holiday = Holiday::findFor($start->toDateString(), $data['organization_id'] ?? null);

                if ($holiday !== null) {
                    $skipped[] = [
                        'date' => $start->toDateString(),
                        'reason' => "يوم عطلة ({$holiday->name})",
                    ];
                } elseif ($this->teacherIsBlocked($data['teacher_id'], Carbon::instance($start), Carbon::instance($end))) {
                    $skipped[] = [
                        'date' => $start->toDateString(),
                        'reason' => 'المعلم مش متاح في التوقيت ده (التزام في جدوله)',
                    ];
                } else {
                    $conflict = $this->findConflict(
                        $data['teacher_id'],
                        Carbon::instance($start),
                        Carbon::instance($end),
                        null,
                        $data['student_id'] ?? null,
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
                        'scheduled_start_at' => $start->copy()->setTimezone('UTC'),
                        'scheduled_end_at' => $end->copy()->setTimezone('UTC'),
                        'duration_minutes' => $data['duration_minutes'],
                        // ⭐ PR 3.7 — المنطقة + الساعة المحلية الأصلية.
                        'timezone' => $timezone,
                        'local_start_time' => $start->format('H:i:s'),
                        'status' => 'scheduled',
                    ]);
                }
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
