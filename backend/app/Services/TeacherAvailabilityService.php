<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\TeacherSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * خدمة توافر المعلمين.
 *
 * المعلم المستقل بيشتغل في أكاديميّات متعددة، فبيسجّل كل commitments
 * بتاعه في teacher_schedules. الفكرة إن "الوقت المتاح" = كل وقت مش
 * متسجّل عنده — سواء في جدوله ولا في حصة محجوزة في الأكاديمية.
 *
 * يعني الحجز بيتحقق من مصدرين:
 *  1) جدول المعلم (time_blocks) — شغل خارجي + إجازات + اللي هو حجزه بنفسه
 *  2) جدول الحصص (lessons) — الحجز الفعلي في الأكاديمية
 */
class TeacherAvailabilityService
{
    /** @return Collection<int, TeacherSchedule> */
    public function blocksFor(int $teacherId, ?Carbon $week = null): Collection
    {
        $week ??= Carbon::now();

        return TeacherSchedule::where('teacher_id', $teacherId)
            // إما مكرر أسبوعياً، أو بتاريخ محدد داخل الأسبوع ده
            ->where(function ($q) use ($week) {
                $from = $week->copy()->startOfWeek()->toDateString();
                $to = $week->copy()->endOfWeek()->toDateString();

                $q->where('is_recurring', true)
                    ->orWhereBetween('specific_date', [$from, $to]);
            })
            ->orderBy('weekday')
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * المعلم فاضي في الوقت ده؟ (euclidean: تعارض مع أي block أو حصة)
     *
     * @param  int[]  $weekdays  الأيام المطلوبة (0=الأحد)
     */
    public function isAvailable(
        int $teacherId,
        array $weekdays,
        string $startTime,
        string $durationMinutes,
        ?Carbon $onDate = null,
        ?int $excludeLessonId = null,
    ): bool {
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        $startTime = substr($startTime, 0, 5);
        $durationMinutes = (int) $durationMinutes;

        $endTime = $this->addMinutes($startTime, $durationMinutes);

        $blockedBySchedule = $this->scheduleConflict(
            $teacherId, $weekdays, $startTime, $endTime, $onDate, $excludeLessonId
        );
        if ($blockedBySchedule) return false;

        return !$this->lessonConflict(
            $teacherId, $weekdays, $startTime, $endTime, $onDate, $excludeLessonId
        );
    }

    /**
     * تعارض مع جدول المعلم نفسه.
     * ملاحظة: لو المعلم مش مسجّل أي جدول، بنعتبره متاح (الفلترة
     * بتتعمل على مستوى الـ dropdown مش هنا).
     */
    private function scheduleConflict(
        int $teacherId,
        array $weekdays,
        string $startTime,
        string $endTime,
        ?Carbon $onDate,
        ?int $excludeLessonId,
    ): bool {
        $blocks = TeacherSchedule::where('teacher_id', $teacherId)
            ->whereIn('weekday', $weekdays)
            ->where(function ($q) use ($onDate) {
                $q->where('is_recurring', true);
                if ($onDate) {
                    $q->orWhere('specific_date', $onDate->toDateString());
                }
            })
            ->get();

        foreach ($blocks as $block) {
            // block بتاريخ محدد بس في يوم تاني — يتخطّى
            if (!$block->is_recurring && $onDate && $block->specific_date?->ne($onDate)) {
                continue;
            }
            if ($block->overlaps($startTime, $endTime)) {
                return true;
            }
        }

        return false;
    }

    /** تعارض مع حصة محجوزة فعلاً في الأكاديمية */
    private function lessonConflict(
        int $teacherId,
        array $weekdays,
        string $startTime,
        string $endTime,
        ?Carbon $onDate,
        ?int $excludeLessonId,
    ): bool {
        if (!$onDate) {
            // مفيش تاريخ محدد — نفحص أول ميعاد في الأيام المطلوبة
            $onDate = Carbon::now();
        }

        $start = Carbon::parse($onDate->copy()->toDateString() . ' ' . $startTime);
        $end = Carbon::parse($onDate->copy()->toDateString() . ' ' . $endTime);

        // نفحص أسبوع كامل للأيام المطلوبة
        $weekStart = $onDate->copy()->startOfWeek();

        for ($i = 0; $i < 7; $i++) {
            $day = $weekStart->copy()->addDays($i);
            if (!in_array($day->dayOfWeek, $weekdays, true)) continue;

            $dayStart = Carbon::parse($day->toDateString() . ' ' . $startTime);
            $dayEnd = Carbon::parse($day->toDateString() . ' ' . $endTime);

            $conflict = Lesson::query()
                ->where('teacher_id', $teacherId)
                ->blockingTeacherTime()
                ->when($excludeLessonId, fn ($q) => $q->where('id', '!=', $excludeLessonId))
                ->where('scheduled_start_at', '<', $dayEnd)
                ->where('scheduled_end_at', '>', $dayStart)
                ->exists();

            if ($conflict) return true;
        }

        return false;
    }

    /**
     * معرّفات المعلمين اللي مشغولين في المواعيد دي.
     *
     * عشان الـ controller يتحقق قبل الحفظ من غير ما يطلب تقرير كامل
     * بالأسماء والأسباب.
     *
     * @return int[]
     */
    public function busyTeacherIds(
        array $weekdays,
        string $startTime,
        int $durationMinutes,
        ?Carbon $onDate = null,
        ?Carbon $periodTo = null,
    ): array {
        $all = $this->availableTeachers($weekdays, $startTime, $durationMinutes, $onDate, null, $periodTo);

        return array_values(array_map(
            fn (array $t) => (int) $t['id'],
            array_filter($all, fn (array $t) => !$t['available'])
        ));
    }

    /**
     * سعة المعلم في المواعيد المطلوبة خلال فترة معيّنة.
     *
     * السعة = كام **موعد** يقدر ياخده في الفترة دي. كل موعد = تاريخ
     * داخل [من، إلى] واقع في يوم من الأيام المختارة، ونفس الوقت، ومش
     * محجوز لحد تاني.
     *
     * مثال: الأحد + الخميس من 5 أكتوبر لـ 4 نوفمبر = 4 أحد + 4 خميس = 8.
     * فلو الاشتراك فيه 8 حصص، السعة مكفية بالظبط.
     *
     * @return array{
     *   capacity: int,
     *   per_day: array<int, int>,
     *   booked: int,
     * }
     */
    public function capacityFor(
        int $teacherId,
        array $weekdays,
        string $startTime,
        int $durationMinutes,
        ?Carbon $from = null,
        ?Carbon $to = null,
        int $excludeLessonId = null,
    ): array {
        // الـ query string بيدي الأرقام كنص — نحوّلها int عشان المقارنة
        // الصارمة (strict) في المقارنات الداخلية تشتغل صح
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        $startTime = substr($startTime, 0, 5);

        $from ??= Carbon::today();
        $to ??= $from->copy()->addMonth();

        $endTime = $this->addMinutes($startTime, $durationMinutes);

        // نوافذ التدريس (kind = academy) في الأيام المطلوبة
        $teaching = TeacherSchedule::where('teacher_id', $teacherId)
            ->whereIn('weekday', $weekdays)
            ->where('kind', 'academy')
            ->get()
            ->groupBy('weekday');

        // اللي بيحجز جوه النافذة: شغل خارجي / إجازة / شخصي
        $reserved = TeacherSchedule::where('teacher_id', $teacherId)
            ->whereIn('weekday', $weekdays)
            ->whereIn('kind', ['external', 'leave', 'personal'])
            ->get()
            ->groupBy('weekday');

        $hasSchedule = $teaching->isNotEmpty();

        // المواعيد المتاحة: كل تاريخ في الفترة واقع في يوم من المختارة
        $dates = [];
        $cursor = $from->copy()->startOfDay();
        $end = $to->copy()->endOfDay();

        while ($cursor->lte($end)) {
            if (in_array($cursor->dayOfWeek, $weekdays, true)) {
                $dates[] = $cursor->copy();
            }
            $cursor->addDay();
        }

        // ناقص اللي محجوز فعلاً بحصص موجودة
        $bookedDates = $this->bookedDatesFor(
            $teacherId, $startTime, $endTime, $from, $to, $excludeLessonId
        );

        $booked = 0;
        $capacity = 0;
        $perDay = [];

        foreach ($weekdays as $day) {
            // كام خانة بتتّسع في نافذة التدريس بعد خصم المحجوز
            $slotsPerDay = $hasSchedule
                ? $this->slotsInDay($teaching->get($day, collect()), $reserved->get($day, collect()), $startTime, $durationMinutes)
                : 0;

            $dayFree = 0;
            foreach ($dates as $date) {
                if ($date->dayOfWeek !== $day) continue;
                if ($slotsPerDay <= 0) continue;
                $iso = $date->toDateString() . ' ' . substr($startTime, 0, 5);
                if (isset($bookedDates[$iso])) {
                    $booked++;
                    continue;
                }
                $dayFree++;
            }

            $perDay[$day] = $dayFree;
            $capacity += $dayFree;
        }

        return [
            'capacity' => $capacity,
            'per_day' => $perDay,
            'booked' => $booked,
        ];
    }

    /**
     * كام حصة بتسع في يوم واحد: نافذة التدريس ناقص الأوقات المحجوزة جواها،
     * مقسومة على مدة الحصة.
     *
     * مثال: تدريس 17:00-19:00، محجوز 18:00-19:00، حصة 30 د
     *       = نافذة 120 د ناقص 60 د = 60 د → حصة واحدة بس.
     *
     * @param  \Illuminate\Support\Collection  $teachingWindows
     * @param  \Illuminate\Support\Collection  $reservedBlocks
     */
    private function slotsInDay($teachingWindows, $reservedBlocks, string $startTime, int $durationMinutes): int
    {
        $start = $this->toMinutes($startTime);
        $total = 0;

        foreach ($teachingWindows as $window) {
            $wStart = $this->toMinutes($window->starts_at);
            $wEnd = $this->toMinutes($window->ends_at);
            if ($wEnd <= $wStart) continue;

            // نموذذج الوقت على المحور 1440
            $free = [[$wStart, $wEnd]];

            foreach ($reservedBlocks as $block) {
                $bStart = $this->toMinutes($block->starts_at);
                $bEnd = $this->toMinutes($block->ends_at);
                if ($bEnd <= $bStart) continue;
                $free = $this->subtract($free, $bStart, $bEnd);
            }

            foreach ($free as [$fStart, $fEnd]) {
                // الخانة لازم تكون جوه النافذة وتبدأ من وقت الحصة المطلوب
                $usableStart = max($fStart, $start);
                $usableEnd = $fEnd;
                if ($usableEnd - $usableStart < $durationMinutes) continue;
                $total += intdiv($usableEnd - $usableStart, $durationMinutes);
            }
        }

        return $total;
    }

    /** طرح فترة من قائمة فترات */
    private function subtract(array $free, int $cutStart, int $cutEnd): array
    {
        $out = [];

        foreach ($free as [$s, $e]) {
            if ($cutEnd <= $s || $cutStart >= $e) {
                $out[] = [$s, $e];
                continue;
            }
            if ($cutStart > $s) $out[] = [$s, min($cutStart, $e)];
            if ($cutEnd < $e) $out[] = [max($cutEnd, $s), $e];
        }

        return $out;
    }

    /**
     * التواريخ+الأوقات اللي محجوزة فعلاً للمعلم (مفتاح = "Y-m-d H:i").
     *
     * @return array<string, true>
     */
    private function bookedDatesFor(
        int $teacherId,
        string $startTime,
        string $endTime,
        Carbon $from,
        Carbon $to,
        ?int $excludeLessonId,
    ): array {
        $out = [];

        $lessons = Lesson::query()
            ->where('teacher_id', $teacherId)
            ->blockingTeacherTime()
            ->when($excludeLessonId, fn ($q) => $q->where('id', '!=', $excludeLessonId))
            ->where('scheduled_start_at', '<', $to->copy()->endOfDay())
            ->where('scheduled_end_at', '>', $from->copy()->startOfDay())
            ->get(['scheduled_start_at', 'scheduled_end_at']);

        foreach ($lessons as $lesson) {
            $start = Carbon::parse($lesson->scheduled_start_at);
            $end = Carbon::parse($lesson->scheduled_end_at);

            $dayStart = $start->copy()->startOfDay()->setTimeFromTimeString($startTime);
            $dayEnd = $start->copy()->startOfDay()->setTimeFromTimeString($endTime);

            if ($start->lt($dayEnd) && $end->gt($dayStart)) {
                $out[$start->toDateString() . ' ' . $startTime] = true;
            }
        }

        return $out;
    }

    /**
     * قائمة المعلمين المتاحين في المواعيد المطلوبة — للـ dropdown.
     *
     * @param  int[]  $weekdays
     * @return array<int, array{id:int, name:string, available:bool, reason:?string}>
     */
    public function availableTeachers(
        array $weekdays,
        string $startTime,
        int $durationMinutes,
        ?Carbon $onDate = null,
        int $excludeLessonId = null,
        ?Carbon $periodTo = null,
    ): array {
        $weekdays = array_values(array_unique(array_map('intval', $weekdays)));
        $startTime = substr($startTime, 0, 5);
        $durationMinutes = (int) $durationMinutes;

        $teachers = \App\Models\Teacher::query()
            ->where('status', 'active')
            ->orderBy('display_name')
            ->get();

        if ($teachers->isEmpty()) {
            return [];
        }

        $teacherIds = $teachers->pluck('id')->all();
        $endTime = $this->addMinutes($startTime, $durationMinutes);

        // كل الـ blocks في query واحدة بدل N+1
        $blocksByTeacher = TeacherSchedule::whereIn('teacher_id', $teacherIds)
            ->whereIn('weekday', $weekdays)
            ->get()
            ->groupBy('teacher_id');

        // عدد الـ blocks الكلي لكل معلم — عشان نعرف مين ما عندوش جدول أصلاً
        $totalBlocks = TeacherSchedule::whereIn('teacher_id', $teacherIds)
            ->selectRaw('teacher_id, count(*) as c')
            ->groupBy('teacher_id')
            ->pluck('c', 'teacher_id');

        $busyFromLessons = $this->teachersBusyInLessons(
            $teacherIds, $weekdays, $startTime, $endTime, $onDate, $excludeLessonId ?: null
        );

        $out = [];

        foreach ($teachers as $teacher) {
            $dayBlocks = $blocksByTeacher->get($teacher->id, collect());

            // كتلة «أكاديمية» = نافذة تدريس المعلم، يعني وقت متاح للحجز.
            // اللي بيحجز فعلاً هو: شغل خارجي، إجازة، أو التزام شخصي.
            $blocking = null;
            $hasTeachingWindow = false;
            foreach ($dayBlocks as $b) {
                if ($b->kind === 'academy') {
                    if ($b->overlaps($startTime, $endTime)) {
                        $hasTeachingWindow = true;
                    }
                    continue;
                }
                if ($b->overlaps($startTime, $endTime)) {
                    $blocking = $b;
                    break;
                }
            }

            $hasSchedule = (int) ($totalBlocks[$teacher->id] ?? 0) > 0;

            // معلم مالوش جدول أصلاً بيبان مع علامة تحذير (قرار أداري)،
            // لكن معلم عنده جدول وما فيهوش نافذة تدريس على المواعيد دي
            // مش من حقه ياخد حصة — بنعتبره مش متاح
            $noTeachingWindow = !$hasTeachingWindow && $hasSchedule;

            $lessonsBusy = $busyFromLessons->has($teacher->id);
            $available = !$blocking && !$lessonsBusy && !$noTeachingWindow;

            $reason = null;
            if ($blocking) {
                $reason = "مشغول ({$blocking->kind_label}" . ($blocking->title ? " — {$blocking->title}" : '') . ')';
            } elseif ($lessonsBusy) {
                $reason = 'عنده حصة في نفس الوقت';
            } elseif ($noTeachingWindow) {
                $reason = 'ما عندوش نافذة تدريس في المواعيد دي';
            }

            // السعة: كام موعد يقدر ياخده خلال الفترة
            $capacity = 0;
            $bookedCount = 0;
            $perDay = [];

            if ($available) {
                $calc = $this->capacityFor(
                    $teacher->id,
                    $weekdays,
                    $startTime,
                    $durationMinutes,
                    $onDate,
                    $periodTo,
                    $excludeLessonId ?: null,
                );
                $capacity = $calc['capacity'];
                $bookedCount = $calc['booked'];
                $perDay = $calc['per_day'];
            }

            $out[] = [
                'id' => $teacher->id,
                'name' => $teacher->full_name,
                'available' => $available,
                'reason' => $reason,
                // مالهوش جدول مسجّل — ظاهر في الـ dropdown مع علامة تحذير
                'has_schedule' => $hasSchedule,
                // السعة: كام حصة لسه فاضية في المواعيد دي (0 لو مشغول)
                'capacity' => $capacity,
                'booked' => $bookedCount,
                'per_day' => $perDay,
            ];
        }

        return $out;
    }

    /**
     * المعلمين اللي عندهم حصة متداخلة — query واحدة بدل N+1.
     * بترجّع collection من teacher_id متعارضين.
     */
    private function teachersBusyInLessons(
        array $teacherIds,
        array $weekdays,
        string $startTime,
        string $endTime,
        ?Carbon $onDate,
        ?int $excludeLessonId,
    ): Collection {
        $base = $onDate ?? Carbon::now();
        $weekStart = $base->copy()->startOfWeek();

        $query = Lesson::query()
            ->whereIn('teacher_id', $teacherIds)
            ->blockingTeacherTime()
            ->when($excludeLessonId, fn ($q) => $q->where('id', '!=', $excludeLessonId));

        // نافذة أسبوع كامل بيغطي كل الأيام المطلوبة
        $from = $weekStart->copy()->toDateString() . ' 00:00';
        $to = $weekStart->copy()->addDays(7)->toDateString() . ' 00:00';

        return $query
            ->where('scheduled_start_at', '<', $to)
            ->where('scheduled_end_at', '>', $from)
            ->get(['teacher_id', 'scheduled_start_at', 'scheduled_end_at'])
            ->filter(function ($lesson) use ($weekdays, $startTime, $endTime) {
                $start = Carbon::parse($lesson->scheduled_start_at);
                $end = Carbon::parse($lesson->scheduled_end_at);

                if (!in_array($start->dayOfWeek, $weekdays, true)) {
                    return false;
                }

                // نفس اليوم + تداخل في الوقت
                $dayStart = $start->copy()->startOfDay()->setTimeFromTimeString($startTime);
                $dayEnd = $start->copy()->startOfDay()->setTimeFromTimeString($endTime);

                return $start->lt($dayEnd) && $end->gt($dayStart);
            })
            ->pluck('teacher_id')
            ->unique()
            ->values();
    }

    /** دقائق من منتصف الليل */
    private function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return ($h * 60) + $m;
    }

    /** يضيف عدد دقائق لصيغة HH:MM */
    private function addMinutes(string $time, int $minutes): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        $total = ($h * 60) + $m + $minutes;

        // لو عدّى اليوم، نلفّ
        $total %= (24 * 60);

        return sprintf('%02d:%02d', intdiv($total, 60), $total % 60);
    }
}
