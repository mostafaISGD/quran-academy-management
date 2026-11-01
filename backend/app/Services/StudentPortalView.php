<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\MemorizationRecord;
use App\Models\ProgressRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * ⭐⭐⭐ **PR 4.6 — شكل بيانات بوابة الطالب.**
 *
 * ⭐ الـcontroller بيحوّل الطلب لرد، والخدمة دي بتبني **الشكل**
 * نفسه. السبب: «نظرة عامة» و«جدولي» و«تقدمي» كلهم بيرجّعوا نفس
 * الحصة بنفس المفاتيح — فلو الشكل اتكتب في الـcontroller تلات
 * مرات، أول تعديل هيسيب نسخة قديمة.
 *
 * ⭐⭐ القاعدة الحاكمة: **مفيش `student_id` من العميل**. الطالب
 * بيتحدد من `students.user_id` بتاع الحساب الحالي (`studentFor`)،
 * فمستحيل طالب يطلب بيانات طالب تاني — مفيش معامل أصلاً.
 *
 * ⭐ ومفيش أي مسار مالي هنا: لا فواتير ولا مدفوعات ولا أرصدة.
 * البوابة دي أكاديمية بالكامل.
 */
final class StudentPortalView
{
    /** ⭐ الحالات اللي بتظهر في «القادم» */
    private const UPCOMING_STATUSES = [
        LessonStatus::Scheduled->value,
        LessonStatus::Confirmed->value,
        LessonStatus::InProgress->value,
    ];

    /** ⭐ تسميات الحضور بالعربي */
    private const ATTENDANCE_LABELS = [
        'present' => 'حاضر',
        'late' => 'متأخر',
        'absent' => 'غائب',
        'excused' => 'بعذر',
    ];

    /** ⭐ تسميات فئات التقدم */
    private const CATEGORY_LABELS = [
        'memorization' => 'حفظ',
        'tajweed' => 'تجويد',
        'pronunciation' => 'مخارج الحروف',
        'reading' => 'قراءة',
        'fluency' => 'طلاقة',
        'revision' => 'مراجعة',
    ];

    /**
     * ⭐ الطالب صاحب الحساب — أو 403 لو الحساب مش طالب.
     *
     * ⭐ 403 مش 404: الصلاحية موجودة (الراو محمي بـ
     * `student.portal`)، بس مفيش بروفايل طالب. ده حساب إداري
     * بصلاحية غلط، مش مورد مش موجود.
     */
    public function studentFor(User $user): Student
    {
        $student = $user->student()->first();

        if ($student === null) {
            abort(403, 'الحساب الحالي مش مربوط بملف طالب');
        }

        return $student;
    }

    /**
     * ⭐ استعلام الحصص **بتاعته هو**: حصة فردية (`student_id`)
     * أو حصة مجموعة هو عضو فيها (`lesson_students`).
     */
    public function lessonsQuery(Student $student): Builder
    {
        return Lesson::query()
            ->where('organization_id', $student->organization_id)
            ->where(function (Builder $query) use ($student): void {
                $query->where('student_id', $student->id)
                    ->orWhereHas(
                        'students',
                        fn (Builder $members) => $members->whereKey($student->id),
                    );
            });
    }

    /** ⭐ هل الحصة دي بتاعت الطالب ده؟ (فردية أو مجموعة) */
    public function ownsLesson(Student $student, Lesson $lesson): bool
    {
        if ((int) $lesson->student_id === $student->id) {
            return true;
        }

        return $lesson->students()->whereKey($student->id)->exists();
    }

    /** ⭐ نظرة عامة: الحصة القادمة + الحضور + ملخص الحفظ. */
    public function overview(Student $student): array
    {
        $upcoming = $this->upcomingLessons($student);
        $attendance = $this->attendance($student);
        $memorization = $this->memorizationRows($student);

        return [
            'student' => $this->studentRow($student),
            'next_lesson' => ($next = $upcoming->first())
                ? $this->lessonRow($next)
                : null,
            'upcoming_count' => $upcoming->count(),
            'attendance_rate' => $attendance['rate'],
            'attendance_summary' => $attendance['summary'],
            'memorization_summary' => [
                'surahs' => $memorization->pluck('surah_id')->unique()->count(),
                'ayahs' => (int) $memorization->sum(
                    fn (array $row): int => $row['to_ayah'] - $row['from_ayah'] + 1,
                ),
                'records' => $memorization->count(),
            ],
            // ⚠️ مفيش جدول واجبات في المخطط (PR 4.6) — بنقولها
            // صريحة للواجهة بدل ما نرجّع قائمة فاضية بلا تفسير.
            'homework' => [
                'available' => false,
                'items' => [],
            ],
        ];
    }

    /** ⭐ جدولي: القادم والسابق. */
    public function schedule(Student $student): array
    {
        $past = $this->lessonsQuery($student)
            ->where('scheduled_start_at', '<', now())
            ->with($this->lessonRelations())
            ->orderByDesc('scheduled_start_at')
            ->limit(30)
            ->get()
            ->map(fn (Lesson $lesson): array => $this->lessonRow($lesson))
            ->values()
            ->all();

        return [
            'student_id' => $student->id,
            'upcoming' => $this->upcomingLessons($student)
                ->map(fn (Lesson $lesson): array => $this->lessonRow($lesson))
                ->values()
                ->all(),
            'past' => $past,
        ];
    }

    /** ⭐ تقدمي: الحضور + التقدم + الحفظ + خريطة الحفظ. */
    public function progress(Student $student): array
    {
        $memorization = $this->memorizationRows($student);

        return [
            'student_id' => $student->id,
            'attendance' => $this->attendance($student),
            'progress' => $this->progressRows($student),
            'memorization' => $memorization,
            'memorization_map' => $this->memorizationMap($memorization),
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /** @return Collection<int, Lesson> */
    private function upcomingLessons(Student $student, int $limit = 60): Collection
    {
        return $this->lessonsQuery($student)
            ->whereIn('status', self::UPCOMING_STATUSES)
            ->where('scheduled_start_at', '>=', now())
            ->with($this->lessonRelations())
            ->orderBy('scheduled_start_at')
            ->limit($limit)
            ->get();
    }

    /** @return array<int, string> */
    private function lessonRelations(): array
    {
        return [
            'teacher:id,display_name',
            'program:id,name',
            'level:id,name',
        ];
    }

    /** @return array<string, mixed> */
    private function lessonRow(Lesson $lesson): array
    {
        $join = app(LessonJoinService::class);

        return [
            'id' => $lesson->id,
            'scheduled_start_at' => $lesson->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $lesson->scheduled_end_at?->toIso8601String(),
            'duration_minutes' => (int) $lesson->duration_minutes,
            'status' => $lesson->status,
            'status_label' => LessonStatus::fromDatabase((string) $lesson->status)->label(),
            'lesson_type' => $lesson->lesson_type,
            'teacher_name' => $lesson->teacher?->full_name,
            'program_name' => $lesson->program?->name,
            'level_name' => $lesson->level?->name,
            // ⭐ الواجهة بتخفي زر الانضمام لو `false` — نفس حكم
            // السيرفر بالظبط (`LessonJoinService`).
            'can_join' => $join->isJoinable($lesson),
        ];
    }

    /** @return array<string, mixed> */
    private function studentRow(Student $student): array
    {
        return [
            'id' => $student->id,
            'full_name' => $student->full_name,
            'student_code' => $student->student_code,
            'status' => $student->status,
        ];
    }

    /**
     * ⭐ الحضور: الملخص + النسبة + السطور.
     *
     * ⭐ «متأخر» بيتحسب **حضور** (زي `LessonService` بالظبط:
     * `present`/`late` ⇒ الحصة اتحسبت). «بعذر» مش حضور ولا غياب
     * في النسبة — بيظهر في الملخص بس.
     *
     * @return array{summary: array<string, int>, rate: int, rows: array<int, array<string, mixed>>}
     */
    private function attendance(Student $student): array
    {
        $rows = LessonAttendance::query()
            ->where('student_id', $student->id)
            ->with([
                'lesson:id,scheduled_start_at,scheduled_end_at,teacher_id',
                'lesson.teacher:id,display_name',
            ])
            ->orderByDesc('marked_at')
            ->limit(100)
            ->get();

        $summary = [
            'present' => $rows->where('status', 'present')->count(),
            'late' => $rows->where('status', 'late')->count(),
            'absent' => $rows->where('status', 'absent')->count(),
            'excused' => $rows->where('status', 'excused')->count(),
        ];

        $summary['total'] = array_sum($summary);
        $attended = $summary['present'] + $summary['late'];

        return [
            'summary' => $summary,
            'rate' => $summary['total'] > 0
                ? (int) round($attended / $summary['total'] * 100)
                : 0,
            'rows' => $rows
                ->map(fn (LessonAttendance $row): array => [
                    'id' => $row->id,
                    'lesson_id' => $row->lesson_id,
                    'status' => $row->status,
                    'status_label' => self::ATTENDANCE_LABELS[$row->status] ?? $row->status,
                    'late_minutes' => (int) $row->late_minutes,
                    'marked_at' => $row->marked_at?->toIso8601String(),
                    'lesson_start_at' => $row->lesson?->scheduled_start_at?->toIso8601String(),
                    'teacher_name' => $row->lesson?->teacher?->full_name,
                ])
                ->values()
                ->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function progressRows(Student $student): array
    {
        return ProgressRecord::query()
            ->where('student_id', $student->id)
            ->with([
                'teacher:id,display_name',
                'program:id,name',
                'level:id,name',
            ])
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->get()
            ->map(fn (ProgressRecord $row): array => [
                'id' => $row->id,
                'category' => $row->category,
                'category_label' => self::CATEGORY_LABELS[$row->category] ?? $row->category,
                'score' => $row->score !== null ? (float) $row->score : null,
                'notes' => $row->notes,
                'recorded_at' => $row->recorded_at?->toIso8601String(),
                'teacher_name' => $row->teacher?->full_name,
                'program_name' => $row->program?->name,
                'level_name' => $row->level?->name,
            ])
            ->values()
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function memorizationRows(Student $student): Collection
    {
        return MemorizationRecord::query()
            ->where('student_id', $student->id)
            ->with('surah')
            ->orderByDesc('recorded_at')
            ->limit(200)
            ->get()
            ->map(fn (MemorizationRecord $row): array => [
                'id' => $row->id,
                'surah_id' => $row->surah_id,
                'surah_name' => $row->surah?->name_ar,
                'surah_number' => $row->surah?->number,
                'from_ayah' => (int) $row->from_ayah,
                'to_ayah' => (int) $row->to_ayah,
                'quality' => $row->quality !== null ? (int) $row->quality : null,
                'notes' => $row->notes,
                'recorded_at' => $row->recorded_at?->toIso8601String(),
            ]);
    }

    /**
     * ⭐ **خريطة الحفظ**: السورة مرة واحدة، وأقل آية وأكتر آية
     * اتسجّلوا فيها، والمقاطع نفسها.
     *
     * ⭐ مش بنلمّ الآيات في مدى واحد ونقول «حفظ من 1 لـ7» لو
     * فيه فجوة — بنرجّع `ayahs` (المقاطع) عشان الواجهة ترسم
     * اللي اتسجّل فعلاً.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function memorizationMap(Collection $rows): array
    {
        return $rows
            ->groupBy('surah_id')
            ->map(function (Collection $segments): array {
                $first = $segments->first();
                $sorted = $segments->sortBy('from_ayah')->values();

                return [
                    'surah_id' => (int) $first['surah_id'],
                    'surah_name' => $first['surah_name'],
                    'surah_number' => $first['surah_number'],
                    'from_ayah' => (int) $sorted->min('from_ayah'),
                    'to_ayah' => (int) $sorted->max('to_ayah'),
                    'ayahs' => $sorted
                        ->map(fn (array $segment): array => [
                            'from_ayah' => $segment['from_ayah'],
                            'to_ayah' => $segment['to_ayah'],
                            'quality' => $segment['quality'],
                            'recorded_at' => $segment['recorded_at'],
                        ])
                        ->values()
                        ->all(),
                ];
            })
            ->sortBy('surah_number')
            ->values()
            ->all();
    }
}
