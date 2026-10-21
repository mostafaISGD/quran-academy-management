<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonCreditAccount;
use App\Models\LessonCreditTransaction;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\TeacherEarning;
use App\Models\User;
use App\Services\LessonCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.5 — الحضور يدرّج النتيجة (Attendance drives the effect).**
 *
 * ⭐ **الفكرة**: الحضور **مش سجل جانبي** — هو اللي بيحدّد نتيجة
 * الحصة (ولكل طالب في الحصة الجماعية) وبيشغّل الأثر المالي:
 *
 * - `present` → الحصة اتقبلت + **خصم** حصة (حسب السياسة).
 * - `absent` → الطالب غاب: **بلا خصم** (حسب السياسة الافتراضية)
 *   + **سطر حركة** يوثّق الغياب (صفر خصم).
 * - `late` → حاضر بس متأخر ⇒ خصم (والتأخير في السجل).
 * - `excused` → بعذر: **مفيش خصم** ومفيش تعويض تلقائي.
 *
 * ⭐ **الحصة الجماعية**: صف `lessons` واحد + جدول `lesson_students`
 * (5 طلاب) — والحضور **لكل طالب**، والاستهلاك **لكل طالب** من
 * حسابه، وأجر المعلم **ثابت للحصة** (قرار 7) = مستحق واحد.
 *
 * ⭐ القبول: حصة جماعية بـ5 طلاب (4 حاضر + 1 غائب) ⇒ **5 حركات
 * رصيد** حسب السياسة + **مستحق معلم واحد**.
 */
class AttendanceDrivesOutcomeTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول: 5 طلاب ⇒ 5 حركات رصيد + مستحق واحد
    // ═══════════════════════════════════════════════════════════════

    public function test_a_group_lesson_marks_one_credit_entry_per_student_and_one_teacher_earning(): void
    {
        $manager = $this->makeUserWithRole('attendance-manager', ['lessons.update']);
        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher);
        $this->makeRate($teacher, ['amount' => 50]);

        [$lesson, $students] = $this->groupLesson($teacher, 5);

        $entries = [];
        foreach ($students as $index => $student) {
            $entries[] = [
                'student_id' => $student->id,
                'status' => $index < 4 ? 'present' : 'absent',
            ];
        }

        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", ['entries' => $entries], $manager)
            ->assertSuccessful();

        // ⭐ 5 حركات رصيد — واحدة لكل طالب.
        $movements = LessonCreditTransaction::where('lesson_id', $lesson->id)->count();
        $this->assertSame(5, $movements, 'عدد حركات الرصيد لازم يساوي عدد الطلاب');

        // ⭐ 4 حاضرين اتخصم منهم (−1) والغائب اتسجّل بصفر خصم.
        $deducted = (float) LessonCreditTransaction::where('lesson_id', $lesson->id)
            ->where('type', 'lesson_used')
            ->sum('quantity');
        $this->assertEquals(-4.0, $deducted, '4 حاضرين يتخصم منهم حصة كل واحد');

        // ⭐ مستحق معلم **واحد** (أجر ثابت للحصة — قرار 7).
        $this->assertSame(
            1,
            TeacherEarning::where('lesson_id', $lesson->id)->count(),
            'أجر المعلم للحصة الجماعية = مستحق واحد'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ الحضور للطالب الواحد يدرّج النتيجة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_attendance_drives_the_lesson_outcome(): void
    {
        $manager = $this->makeUserWithRole('attendance-manager', ['lessons.update']);
        $teacher = $this->makeActiveTeacher();
        [$lesson, $students] = $this->groupLesson($teacher, 1);

        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", [
            'entries' => [['student_id' => $students[0]->id, 'status' => 'present']],
        ], $manager)->assertSuccessful();

        $this->assertSame('completed', $lesson->fresh()->status, 'الحضور (حاضر) لازم يكمّل الحصة');
    }

    public function test_an_absent_student_leaves_the_lesson_outcome_open_for_the_group(): void
    {
        $manager = $this->makeUserWithRole('attendance-manager', ['lessons.update']);
        $teacher = $this->makeActiveTeacher();
        [$lesson, $students] = $this->groupLesson($teacher, 2);

        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", [
            'entries' => [
                ['student_id' => $students[0]->id, 'status' => 'present'],
                ['student_id' => $students[1]->id, 'status' => 'absent'],
            ],
        ], $manager)->assertSuccessful();

        // ⭐ المعلم قدّم الحصة ⇒ بتخلص — و**غياب الطالب** ماشي
        // حاله في سجل الحضور (نتيجة لكل طالب، مش حالة للحصة).
        $this->assertSame('completed', $lesson->fresh()->status);

        $attendance = \App\Models\LessonAttendance::where('lesson_id', $lesson->id)
            ->where('student_id', $students[1]->id)
            ->first();

        $this->assertNotNull($attendance);
        $this->assertSame('absent', $attendance->status);
    }

    public function test_the_credit_of_an_absent_student_is_not_deducted(): void
    {
        $manager = $this->makeUserWithRole('attendance-manager', ['lessons.update']);
        $teacher = $this->makeActiveTeacher();
        [$lesson, $students] = $this->groupLesson($teacher, 1);

        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", [
            'entries' => [['student_id' => $students[0]->id, 'status' => 'absent']],
        ], $manager)->assertSuccessful();

        $account = LessonCreditAccount::where('student_id', $students[0]->id)->firstOrFail();

        $this->assertEquals(
            8.0,
            (float) app(LessonCreditService::class)->balance($account->id),
            'الطالب الغايب ما بيتخصمش منه (الافتراضي الآمن)'
        );
    }

    public function test_an_excused_student_is_not_deducted_either(): void
    {
        $manager = $this->makeUserWithRole('attendance-manager', ['lessons.update']);
        $teacher = $this->makeActiveTeacher();
        [$lesson, $students] = $this->groupLesson($teacher, 1);

        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", [
            'entries' => [['student_id' => $students[0]->id, 'status' => 'excused']],
        ], $manager)->assertSuccessful();

        $account = LessonCreditAccount::where('student_id', $students[0]->id)->firstOrFail();

        $this->assertEquals(8.0, (float) app(LessonCreditService::class)->balance($account->id));
    }

    public function test_the_attendance_can_be_marked_twice_without_double_deducting(): void
    {
        $manager = $this->makeUserWithRole('attendance-manager', ['lessons.update']);
        $teacher = $this->makeActiveTeacher();
        [$lesson, $students] = $this->groupLesson($teacher, 1);

        $payload = ['entries' => [['student_id' => $students[0]->id, 'status' => 'present']]];

        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", $payload, $manager)->assertSuccessful();
        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", $payload, $manager)->assertSuccessful();

        $account = LessonCreditAccount::where('student_id', $students[0]->id)->firstOrFail();

        $this->assertEquals(
            7.0,
            (float) app(LessonCreditService::class)->balance($account->id),
            'تسجيل الحضور مرتين خصف حصة مرتين'
        );
    }

    public function test_the_teacher_pay_mode_setting_can_switch_to_per_student(): void
    {
        // ⭐ قرار 7: الأجر **ثابت للحصة** افتراضياً — بس الأكاديمية
        // تقدر تختار أجراً لكل طالب.
        \App\Models\Setting::set('lessons.group_teacher_pay_mode', 'per_student', $this->org->id);

        $manager = $this->makeUserWithRole('attendance-manager', ['lessons.update']);
        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher);
        $this->makeRate($teacher, ['amount' => 50]);

        [$lesson, $students] = $this->groupLesson($teacher, 3);

        $entries = [];
        foreach ($students as $student) {
            $entries[] = ['student_id' => $student->id, 'status' => 'present'];
        }

        $this->postJsonAs("/api/lessons/{$lesson->id}/attendance", ['entries' => $entries], $manager)
            ->assertSuccessful();

        $earning = TeacherEarning::where('lesson_id', $lesson->id)->firstOrFail();

        $this->assertEquals(150.0, (float) $earning->amount, 'وضع per_student = السعر × عدد الطلاب');
        $this->assertSame('per_student', $earning->rate_snapshot['pay_mode']);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ حصة جماعية: صف `lessons` واحد (student_id = null) +
     * طلاب في `lesson_students`، لكل واحد اشتراكه ورصيده.
     *
     * @return array{0: Lesson, 1: array<int, Student>}
     */
    private function groupLesson($teacher, int $count): array
    {
        $program = $this->makeProgram();
        $students = [];

        for ($i = 0; $i < $count; $i++) {
            $student = $this->makeStudent();
            $subscription = $this->makeSubscription($program, $student, [], $teacher);

            $account = LessonCreditAccount::create([
                'student_id' => $student->id,
                'subscription_id' => $subscription->id,
                'credit_type' => 'regular',
                'current_balance' => 8,
                'status' => 'active',
            ]);

            LessonCreditTransaction::create([
                'credit_account_id' => $account->id,
                'student_id' => $student->id,
                'subscription_id' => $subscription->id,
                'type' => 'initial',
                'quantity' => 8,
                'balance_after' => 8,
                'reason' => 'رصيد الباقة',
                'created_at' => now(),
            ]);

            $students[] = $student;
        }

        $lesson = Lesson::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => null,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'lesson_type' => 'group',
            'scheduled_start_at' => now()->addDays(2)->setTime(16, 0),
            'scheduled_end_at' => now()->addDays(2)->setTime(16, 30),
            'duration_minutes' => 30,
            'status' => 'confirmed',
        ]);

        foreach ($students as $student) {
            $lesson->students()->attach($student->id);
        }

        return [$lesson->fresh(), $students];
    }
}
