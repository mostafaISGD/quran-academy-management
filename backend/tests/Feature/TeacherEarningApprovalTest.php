<?php

namespace Tests\Feature;

use App\Models\FinanceAlert;
use App\Models\TeacherEarning;
use App\Models\TeacherLedgerEntry;
use App\Models\TeacherPayrollLine;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PR 2.10 - خط أنابيب أرباح المعلمين.
 *
 * الوضع قبل كده:
 *
 * ① `LessonController::complete` مالوش حارس حالة - نداء تاني
 *    بيكتب **مستحق تاني** لنفس الحصة (مافيس unique حتى).
 * ② مافيش `rate_snapshot` - السعر اتغير بعدين فالمستحق القديم
 *    ماليش مرجع لسعر لحظة الحصة.
 * ③ مافيش طريق اعتماد على الإطلاق - كل المستحقات لسه `pending`
 *    فما حدش وصلّا لكشف المرتبات (`harvest()` بتقرا
 *    `approved|paid` بس).
 * ④ الاعتماد مش بيكتب في دفتر المعلم - فمقارنة (د) في
 *    `finance:reconcile` (PR 2.9) هتصرخ أول اعتماد.
 *
 * القبول: نداء «إكمال» مرتين = مستحق واحد؛ والمستحق المعتمد
 * بيظهر في كشف مرتبات المعلم.
 */
class TeacherEarningApprovalTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // 1 - الحارس: إكمال مرة واحدة = مستحق واحد
    // ═══════════════════════════════════════════════════════════

    public function test_completing_a_lesson_twice_creates_a_single_earning(): void
    {
        [$lesson, $teacher] = $this->readyLesson();
        $clerk = $this->makeUserWithRole('clerk', ['lessons.update']);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $clerk)->assertOk();

        // النداء التاني لازم يترفض مش يكتب مستحق تاني
        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $clerk)->assertStatus(422);

        $this->assertSame(
            1,
            TeacherEarning::where('lesson_id', $lesson->id)->count(),
            'نداء الإكمال مرتين = مستحق واحد بس',
        );
        $this->assertSame('completed', $lesson->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════
    // 2 - لقطة السعر وقت الحصة
    // ═══════════════════════════════════════════════════════════

    public function test_the_earning_captures_a_rate_snapshot(): void
    {
        [$lesson, $teacher] = $this->readyLesson();
        $clerk = $this->makeUserWithRole('clerk', ['lessons.update']);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $clerk)->assertOk();

        $earning = TeacherEarning::where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertEquals(50.0, (float) $earning->amount);

        $snapshot = $earning->rate_snapshot;
        $this->assertIsArray($snapshot, 'التقطاع لازم يتخزن JSON');
        $this->assertEquals(50.0, $snapshot['amount']);
        $this->assertSame('EGP', $snapshot['currency']);
        $this->assertSame('per_lesson', $snapshot['rate_type']);
        $this->assertSame('per_lesson', $snapshot['contract_type']);
        $this->assertSame($teacher->activeContract->id, (int) $snapshot['contract_id']);
        $this->assertArrayHasKey('captured_at', $snapshot);
    }

    // ═══════════════════════════════════════════════════════════
    // 3 - الاعتماد بيوصّل المستحق لكشف المرتبات
    // ═══════════════════════════════════════════════════════════

    public function test_an_approved_earning_reaches_the_payroll_statement(): void
    {
        [$lesson, $teacher] = $this->readyLesson();
        $clerk = $this->makeUserWithRole('clerk', ['lessons.update']);
        $approver = $this->makeUserWithRole('payroll', [
            'payroll.periods.manage', 'payroll.earnings.approve',
        ]);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $clerk)->assertOk();
        $earning = TeacherEarning::where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame('pending', $earning->status);

        $period = $this->makePeriod(
            now()->subDays(5)->toDateString(),
            now()->addDays(25)->toDateString(),
        );

        // قبل الاعتماد: `pending` مش بيتحصّد
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $approver)->assertOk();
        $this->assertSame(0, TeacherPayrollLine::count(), 'المعلق مش بيتحصّد');

        // الاعتماد
        $r = $this->postJsonAs("/api/teacher-earnings/{$earning->id}/approve", [], $approver);
        $r->assertOk()->assertJsonPath('data.status', 'approved');

        // بعد الاعتماد: `harvest()` بيشوف المستحق
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $approver)->assertOk();
        $line = TeacherPayrollLine::where('teacher_id', $teacher->id)->first();
        $this->assertNotNull($line, 'المستحق المعتمد لازم يوصل لكشف المرتبات');
        $this->assertEquals(50.0, (float) $line->amount);
    }

    // ═══════════════════════════════════════════════════════════
    // 4 - الاعتماد بيكتب دفتر المعلم (رصيد تجميعي)
    //     — عشان مقارنة (د) في `finance:reconcile` ما تصرخش
    // ═══════════════════════════════════════════════════════════

    public function test_approval_writes_a_running_teacher_ledger_row(): void
    {
        $teacher = $this->makeActiveTeacher();

        $first = $this->makeEarning($teacher, now()->toDateString(), ['amount' => 100, 'status' => 'pending']);
        $second = $this->makeEarning($teacher, now()->toDateString(), ['amount' => 40, 'status' => 'pending', 'currency' => 'EGP']);

        $approver = $this->makeUserWithRole('payroll', ['payroll.earnings.approve']);

        $this->postJsonAs("/api/teacher-earnings/{$first->id}/approve", [], $approver)->assertOk();
        $this->postJsonAs("/api/teacher-earnings/{$second->id}/approve", [], $approver)->assertOk();

        $rows = TeacherLedgerEntry::where('teacher_id', $teacher->id)->orderBy('id')->get();

        $this->assertCount(2, $rows, 'كل اعتماد = سطر دفتر');
        $this->assertSame('lesson_earning', $rows[0]->type);
        $this->assertSame('100.00', (string) $rows[0]->credit);
        $this->assertSame((string) $first->id, (string) $rows[0]->earning_id);
        $this->assertSame((string) $first->id, (string) $rows[0]->reference_id);
        $this->assertSame('100.00', $rows[0]->balance_after);
        $this->assertSame('140.00', $rows[1]->balance_after, 'الرصيد تجميعي');

        // مع 2.9: كشف (د) ساكت بعد الاعتماد
        $this->artisan('finance:reconcile')->assertExitCode(0);
        $this->assertNull(
            FinanceAlert::where('kind', 'teacher_ledger_drift')
                ->where('entity_type', 'teacher')
                ->where('entity_id', $teacher->id)
                ->first(),
            'اعتماد مع سطر دفتر = ما فيش انحراف، فلا تنبيه',
        );
    }

    // ═══════════════════════════════════════════════════════════
    // 5 - اعتماد المعلق فقط
    // ═══════════════════════════════════════════════════════════

    public function test_only_pending_earnings_can_be_approved(): void
    {
        $teacher = $this->makeActiveTeacher();
        $approved = $this->makeEarning($teacher, now()->toDateString(), ['status' => 'approved']);
        $paid = $this->makeEarning($teacher, now()->toDateString(), ['status' => 'paid', 'amount' => 70]);
        $cancelled = $this->makeEarning($teacher, now()->toDateString(), ['status' => 'cancelled', 'amount' => 30]);

        $approver = $this->makeUserWithRole('payroll', ['payroll.earnings.approve']);

        foreach ([$approved, $paid, $cancelled] as $earning) {
            $this->postJsonAs("/api/teacher-earnings/{$earning->id}/approve", [], $approver)->assertStatus(422);
        }

        // الحالات ما اتغيّرتش + مفيش أي سطر دفتر اتباع اتصنع
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertSame(0, TeacherLedgerEntry::count(), 'اعتماد المعلَق فقط — الباقي ملكله تأثير');
    }

    // ═══════════════════════════════════════════════════════════
    // 6 - الاعتماد الجماعي يفوت المعلق فقط + الصلاحية
    // ═══════════════════════════════════════════════════════════

    public function test_bulk_approve_processes_only_pending(): void
    {
        $teacher = $this->makeActiveTeacher();
        $pendingA = $this->makeEarning($teacher, now()->toDateString(), ['amount' => 10, 'status' => 'pending']);
        $pendingB = $this->makeEarning($teacher, now()->toDateString(), ['amount' => 20, 'status' => 'pending']);
        $cancelled = $this->makeEarning($teacher, now()->toDateString(), ['amount' => 30, 'status' => 'cancelled']);

        $approver = $this->makeUserWithRole('payroll', ['payroll.earnings.approve']);
        $stranger = $this->makeUserWithRole('guest', []);

        // الصلاحية مطلوبة
        $this->postJsonAs('/api/teacher-earnings/bulk-approve', [
            'earning_ids' => [$pendingA->id, $pendingB->id, $cancelled->id],
        ], $stranger)->assertStatus(403);

        $r = $this->postJsonAs('/api/teacher-earnings/bulk-approve', [
            'earning_ids' => [$pendingA->id, $pendingB->id, $cancelled->id],
        ], $approver);

        $r->assertOk();
        $this->assertEquals(2, $r->json('approved_count'));
        $this->assertSame('approved', $pendingA->fresh()->status);
        $this->assertSame('approved', $pendingB->fresh()->status);
        $this->assertSame('cancelled', $cancelled->fresh()->status, 'الملغي ما بيتلعبش');
        $this->assertSame(2, TeacherLedgerEntry::where('teacher_id', $teacher->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // 7 - الاعتماد التلقائي من الإعدادات
    // ═══════════════════════════════════════════════════════════

    public function test_the_auto_approve_setting_creates_approved_earnings(): void
    {
        \App\Models\Setting::set('payroll.auto_approve_earnings', 1, $this->org->id);

        [$lesson, $teacher] = $this->readyLesson();
        $clerk = $this->makeUserWithRole('clerk', ['lessons.update']);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $clerk)->assertOk();

        $earning = TeacherEarning::where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame('approved', $earning->status, 'التلقائي بيعتمد فوراً');
        $this->assertSame(1, TeacherLedgerEntry::where('teacher_id', $teacher->id)->count(), 'وبيكتب دفتر المعلم');

        // مقارنة (د): اعتماد + دفتر = مفيش تنبيه
        $this->artisan('finance:reconcile')->assertExitCode(0);
        $this->assertNull(
            FinanceAlert::where('kind', 'teacher_ledger_drift')
                ->where('entity_id', $teacher->id)
                ->first(),
        );
    }

    // ═══════════════════════════════════════════════════════════
    // fixtures
    // ═══════════════════════════════════════════════════════════

    /**
     * معلم بعقد وسعر جاهزين + حصة مجدولة.
     *
     * @return array{0: \App\Models\Lesson, 1: \App\Models\Teacher}
     */
    private function readyLesson(): array
    {
        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $teacher = $this->makeActiveTeacher();

        $this->makeContract($teacher);
        $this->makeRate($teacher, ['amount' => 50]);
        $lesson = $this->makeLesson($program, $student, $teacher);

        return [$lesson, $teacher];
    }
}
