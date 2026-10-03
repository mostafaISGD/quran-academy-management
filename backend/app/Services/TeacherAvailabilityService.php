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
        $endTime = $this->addMinutes($startTime, (int) $durationMinutes);

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
    ): array {
        $all = $this->availableTeachers($weekdays, $startTime, $durationMinutes, $onDate);

        return array_values(array_map(
            fn (array $t) => (int) $t['id'],
            array_filter($all, fn (array $t) => !$t['available'])
        ));
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
    ): array {
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
            $blocking = null;
            foreach ($blocksByTeacher->get($teacher->id, collect()) as $b) {
                if ($b->overlaps($startTime, $endTime)) {
                    $blocking = $b;
                    break;
                }
            }

            $lessonsBusy = $busyFromLessons->has($teacher->id);
            $available = !$blocking && !$lessonsBusy;

            $reason = null;
            if ($blocking) {
                $reason = "مشغول ({$blocking->kind_label}" . ($blocking->title ? " — {$blocking->title}" : '') . ')';
            } elseif ($lessonsBusy) {
                $reason = 'عنده حصة في نفس الوقت';
            }

            $out[] = [
                'id' => $teacher->id,
                'name' => $teacher->full_name,
                'available' => $available,
                'reason' => $reason,
                // مالهوش جدول مسجّل — ظاهر في الـ dropdown مع علامة تحذير
                'has_schedule' => (int) ($totalBlocks[$teacher->id] ?? 0) > 0,
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
