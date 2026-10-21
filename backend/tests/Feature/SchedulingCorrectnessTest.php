<?php

namespace Tests\Feature;

use App\Models\Holiday;
use App\Models\Lesson;
use App\Models\Setting;
use App\Models\TeacherSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.6 — صحّة الجدولة.**
 *
 * ⭐ **القاعدة**: مفيش حجز متعارض، ومفيش حجز في وقت مش متاح.
 *
 * - التعارض بيشمل **المعلم والطالب** (مش المعلم بس) — الطالب
 *   مش بيتناقضش مع نفسه.
 * - **العطلات** (رسمية + أكاديمية) بتمنع الحجز برسالة واضحة.
 * - **حظر جدول المعلم** (`teacher_schedules`) بيمنع الحجز.
 * - ⭐ بِغ: نطاق «الحصة المشغولة» كان بيستثني `cancelled` — وهي
 *   مفردة **اختفت** في PR 3.1 ⇒ الحصة الملغاة كانت **بتتحجب
 *   المعلم** (والحجز في وقتها كان بيترفض).
 */
class SchedulingCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول: تعارض الطالب = 422
    // ═══════════════════════════════════════════════════════════════

    public function test_booking_a_student_at_the_time_of_another_lesson_is_refused(): void
    {
        $staff = $this->makeUserWithRole('schedule-admin', ['lessons.create', 'lessons.update']);
        $student = $this->makeStudent();
        $program = $this->makeProgram();

        $start = now()->addDays(3)->setTime(16, 0);

        $this->makeLesson($program, $student, $this->makeTeacher(), [
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addMinutes(30),
        ]);

        $r = $this->postJsonAs('/api/lessons', [
            'student_id' => $student->id,
            'teacher_id' => $this->makeTeacher()->id,
            'program_id' => $program->id,
            'scheduled_start' => $start->toDateTimeString(),
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(422);
        $this->assertStringContainsString('الطالب', (string) $r->json('message'));
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول: العطلة = 422 برسالة واضحة
    // ═══════════════════════════════════════════════════════════════

    public function test_booking_on_an_official_holiday_is_refused(): void
    {
        $staff = $this->makeUserWithRole('schedule-admin', ['lessons.create']);
        $student = $this->makeStudent();
        $program = $this->makeProgram();

        $date = now()->addDays(5)->startOfDay();

        Holiday::create([
            'date' => $date->toDateString(),
            'name' => 'عيد الفطر',
            'type' => 'official',
            'organization_id' => null,
        ]);

        $r = $this->postJsonAs('/api/lessons', [
            'student_id' => $student->id,
            'teacher_id' => $this->makeTeacher()->id,
            'program_id' => $program->id,
            'scheduled_start' => $date->setTime(16, 0)->toDateTimeString(),
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(422);
        $this->assertStringContainsString('عطلة', (string) $r->json('message'));
    }

    public function test_booking_on_an_academy_holiday_is_refused(): void
    {
        $staff = $this->makeUserWithRole('schedule-admin', ['lessons.create']);
        $student = $this->makeStudent();
        $program = $this->makeProgram();

        $date = now()->addDays(6)->startOfDay();

        Holiday::create([
            'date' => $date->toDateString(),
            'name' => 'إجازة الأكاديمية',
            'type' => 'academy',
            'organization_id' => $this->org->id,
        ]);

        $this->postJsonAs('/api/lessons', [
            'student_id' => $student->id,
            'teacher_id' => $this->makeTeacher()->id,
            'program_id' => $program->id,
            'scheduled_start' => $date->setTime(16, 0)->toDateTimeString(),
            'duration_minutes' => 30,
        ], $staff)->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ بِغ: الحصة الملغاة ما بتتحجبش المعلم
    // ═══════════════════════════════════════════════════════════════

    public function test_a_cancelled_lesson_does_not_block_the_teacher(): void
    {
        $staff = $this->makeUserWithRole('schedule-admin', ['lessons.create']);
        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $start = now()->addDays(4)->setTime(16, 0);

        $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addMinutes(30),
            'status' => 'cancelled_by_academy',
        ]);

        $r = $this->postJsonAs('/api/lessons', [
            'student_id' => $this->makeStudent()->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'scheduled_start' => $start->toDateTimeString(),
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(201);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ حظر جدول المعلم
    // ═══════════════════════════════════════════════════════════════

    public function test_a_teacher_blocked_by_his_own_schedule_cannot_be_booked(): void
    {
        $staff = $this->makeUserWithRole('schedule-admin', ['lessons.create']);
        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $start = now()->addDays(7)->setTime(16, 0);

        TeacherSchedule::create([
            'teacher_id' => $teacher->id,
            'weekday' => $start->dayOfWeek,
            'starts_at' => '15:00',
            'ends_at' => '18:00',
            'kind' => 'external',
            'is_recurring' => true,
        ]);

        $r = $this->postJsonAs('/api/lessons', [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'scheduled_start' => $start->toDateTimeString(),
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(422);
        $this->assertStringContainsString('متاح', (string) $r->json('message'));
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ حدود التوليد من الإعدادات
    // ═══════════════════════════════════════════════════════════════

    public function test_the_generation_limit_comes_from_the_settings(): void
    {
        Setting::set('lessons.max_generation_count', 2, $this->org->id);

        $staff = $this->makeUserWithRole('schedule-admin', ['lessons.create']);
        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $start = now()->addDays(1)->setTime(16, 0);

        $r = $this->postJsonAs('/api/lessons/recurring', [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'series_start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(20)->toDateString(),
            'weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'start_time' => '16:00',
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(201);
        $this->assertCount(2, $r->json('created'), 'حد التوليد لازم يقرأ من الإعدادات');
    }
}
