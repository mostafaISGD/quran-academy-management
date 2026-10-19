<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\TeacherEarning;
use App\Models\TeacherLedgerEntry;
use App\Models\TeacherPayment;
use App\Models\TeacherPayrollLine;
use Tests\TestCase;

/**
 * PR 2.11 — الصرف بيكتب كل آثاره المالية.
 *
 * الوضع قبل كده:
 *
 * ① صرف سطر المعلم كان بيسجل حركة حساب بس — مافيش
 *    `TeacherPayment` أصلاً (الجدول موجود ومفيش كاتب واحد!)،
 *    ومافيش سطر دفتر معلم، والمستحقات ما بتتحولش `paid`.
 * ② صرف سطر الموظف كان بيسجل الخط بس — مافيش أي حركة حساب.
 * ③ المساران من غير transaction ومن غير lockForUpdate —
 *    نداءان متزامنان بيدفعان مرتين.
 * ④ مافيش كشف حساب معلم ولا مكافأة/خصم/سلفة.
 * ⑤ التقرير المالي بيحسب «مستحقات لم تُدفع» على كل
 *    المستحقات (حتى الملغي والمعلق) — رقم غلط.
 */
class PayrollPaymentEffectsTest extends TestCase
{
    private const START = '2026-03-01';

    private const END = '2026-03-31';

    private function payer()
    {
        return $this->makeUserWithRole('payer', [
            'payroll.payments.create', 'payroll.periods.manage', 'payroll.view',
        ]);
    }

    private function manager()
    {
        return $this->makeUserWithRole('manager', ['payroll.periods.manage']);
    }

    /**
     * 10 مستحقات × 100 (معلقة) لمعلم واحد + فترة مفتوحة تغطيها.
     *
     * @return array{0: \App\Models\Teacher, 1: array<int, \App\Models\TeacherEarning>}
     */
    private function tenPendingEarnings(): array
    {
        $teacher = $this->makeActiveTeacher();
        $earnings = [];

        for ($i = 0; $i < 10; $i++) {
            $earnings[] = $this->makeEarning($teacher, now()->toDateString(), [
                'amount' => 100,
                'status' => 'pending',
            ]);
        }

        return [$teacher, $earnings];
    }

    // ═══════════════════════════════════════════════════════════
    // 1 — القبول: 10 دروس × 100 + مكافأة 50 − سلفة 200 = 850
    // ═══════════════════════════════════════════════════════════

    public function test_earnings_bonus_and_advance_leave_the_right_balance(): void
    {
        [$teacher, $earnings] = $this->tenPendingEarnings();

        $approver = $this->makeUserWithRole('approver', ['payroll.earnings.approve']);
        $bonusClerk = $this->makeUserWithRole('bonus', ['payroll.bonuses.create']);
        $advanceClerk = $this->makeUserWithRole('advance', ['payroll.advances.create']);
        $reader = $this->makeUserWithRole('reader', ['payroll.view']);
        $stranger = $this->makeUserWithRole('stranger', []);

        $this->postJsonAs('/api/teacher-earnings/bulk-approve', [
            'earning_ids' => array_map(fn ($e) => $e->id, $earnings),
        ], $approver)->assertOk();

        // ⭐ مكافأة + سلفة — مسارات جديدة (كانت مش موجودة)
        $this->postJsonAs("/api/teachers/{$teacher->id}/bonus", [
            'amount' => 50,
        ], $bonusClerk)->assertStatus(201);

        $this->postJsonAs("/api/teachers/{$teacher->id}/advance", [
            'amount' => 200,
        ], $advanceClerk)->assertStatus(201);

        // الكشف — مسار جديد (كان مش موجود)
        $r = $this->getJsonAs("/api/teachers/{$teacher->id}/statement", $reader);
        $r->assertOk();

        $egp = $r->json('currencies.EGP');
        $this->assertEquals(850.0, (float) ($egp['balance'] ?? 0), '1000 + 50 - 200 = 850');
        $this->assertEquals(1050.0, (float) ($egp['total_credit'] ?? 0));
        $this->assertEquals(200.0, (float) ($egp['total_debit'] ?? 0));
        $this->assertCount(12, $r->json('entries'));

        // الصلاحية مطلوبة للقراءة
        $this->getJsonAs("/api/teachers/{$teacher->id}/statement", $stranger)->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════
    // 2 — الصرف يكتب: دفعة + سطر دفتر + حركة حساب + تحويل المستحقات
    // ═══════════════════════════════════════════════════════════

    public function test_paying_a_teacher_line_writes_payment_ledger_and_movement(): void
    {
        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher, ['start_date' => '2026-02-01']);
        $this->makeRate($teacher, ['amount' => 100, 'effective_from' => '2026-02-01']);

        $earning = $this->makeEarning($teacher, '2026-03-15', [
            'amount' => 100,
            'status' => 'pending',
        ]);

        $approver = $this->makeUserWithRole('approver', ['payroll.earnings.approve']);
        $this->postJsonAs("/api/teacher-earnings/{$earning->id}/approve", [], $approver)->assertOk();

        // ⭐ الحساب المالي — بدونه resolveAccount بيرجع null ومفيش حركة
        $this->makeAccount();

        $period = $this->makePeriod(self::START, self::END);
        $manager = $this->manager();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $manager)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $manager)->assertOk();

        $line = TeacherPayrollLine::where('payroll_period_id', $period->id)
            ->where('teacher_id', $teacher->id)->firstOrFail();

        $payer = $this->payer();
        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $payer)->assertOk();

        // ⭐ الدفعة نفسها — كان الجدول فاضي أبداً (مفيش كاتب)
        $payment = TeacherPayment::where('teacher_id', $teacher->id)->first();
        $this->assertNotNull($payment, 'الصرف بيسجل TeacherPayment');
        $this->assertEquals(100.0, (float) $payment->amount);
        $this->assertSame('completed', $payment->status);
        $this->assertSame((string) $period->id, (string) $payment->payroll_period_id);

        // ⭐ سطر الدفتر — مدين (الفلوس خرجت للمعلم)
        $ledger = TeacherLedgerEntry::where('teacher_id', $teacher->id)
            ->where('type', 'payment')->first();
        $this->assertNotNull($ledger, 'الصرف بيكتب سطر دفتر المعلم');
        $this->assertSame('100.00', (string) $ledger->debit);
        $this->assertSame((string) $payment->id, (string) $ledger->payment_id);
        // الاستحقاق دائن 100 + الصرف مدين 100 = الرصيد صفر
        $this->assertSame('0.00', (string) $ledger->balance_after);

        // ⭐ حركة الحساب (كانت شغالة قبل كده — نحافظ عليها)
        $movement = AccountMovement::where('reference_type', 'teacher_payroll_line')
            ->where('reference_id', $line->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);

        // ⭐ المستحقات بقت مدفوعة — كان عمرها ما بتتحول
        $this->assertSame('paid', $earning->fresh()->status);
        $this->assertSame('paid', $line->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════
    // 3 — الصرف المزدوج مرفوض
    // ═══════════════════════════════════════════════════════════

    public function test_paying_the_same_line_twice_is_rejected(): void
    {
        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher, ['start_date' => '2026-02-01']);
        $this->makeRate($teacher, ['amount' => 100, 'effective_from' => '2026-02-01']);

        $earning = $this->makeEarning($teacher, '2026-03-15', [
            'amount' => 100,
            'status' => 'pending',
        ]);

        $approver = $this->makeUserWithRole('approver', ['payroll.earnings.approve']);
        $this->postJsonAs("/api/teacher-earnings/{$earning->id}/approve", [], $approver)->assertOk();

        // ⭐ الحساب المالي — بدونه resolveAccount بيرجع null ومفيش حركة
        $this->makeAccount();

        $period = $this->makePeriod(self::START, self::END);
        $manager = $this->manager();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $manager)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $manager)->assertOk();

        $line = TeacherPayrollLine::where('payroll_period_id', $period->id)
            ->where('teacher_id', $teacher->id)->firstOrFail();

        $payer = $this->payer();
        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $payer)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $payer)->assertStatus(422);

        $this->assertSame(1, TeacherPayment::where('teacher_id', $teacher->id)->count());
        $this->assertSame(
            1,
            TeacherLedgerEntry::where('teacher_id', $teacher->id)->where('type', 'payment')->count(),
        );
        $this->assertSame(
            1,
            AccountMovement::where('reference_type', 'teacher_payroll_line')
                ->where('reference_id', $line->id)->count(),
        );
    }

    /**
     * ⭐ قبول المواصفة: نداءان متزامنان ما بيدفعوش مرتين.
     *
     * الاختبار ده محتاج PostgreSQL حقيقي (testPool/processes) —
     * من غير harness الحقيقي هو بس التكرار المتسلسل (اختبار 3)
     * اللي بيغطي الحارس. بتشتغل على pgschema لما تتهيأ.
     */
    #[\PHPUnit\Framework\Attributes\Group('pgsql')]
    public function test_two_concurrent_pays_do_not_pay_twice(): void
    {
        $this->markTestSkipped('محتاج harness PostgreSQL حقيقي — الحارس المتسلسل مغطى في اختبار 3');
    }

    // ═══════════════════════════════════════════════════════════
    // 4 — صرف الموظف = حركة حساب خارج
    // ═══════════════════════════════════════════════════════════

    public function test_employee_paying_writes_an_outflow_movement(): void
    {
        $employee = $this->makeEmployee([
            'employment_type' => 'contract',
            'hourly_rate' => 100,
        ]);
        $this->makeAttendance($employee, '2026-03-05', [
            'status' => 'present',
            'worked_hours' => 8,
        ]);

        $period = $this->makePeriod(self::START, self::END);
        // ⭐ الحساب المالي — بدونه مفيش حركة
        $this->makeAccount();
        $manager = $this->manager();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $manager)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $manager)->assertOk();
        $line = \App\Models\EmployeePayrollLine::where('payroll_period_id', $period->id)
            ->where('employee_id', $employee->id)->firstOrFail();

        $payer = $this->payer();
        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $payer)->assertOk();

        // ⭐ الحركة — كانت مش موجودة خالص في مسار الموظفين
        $movement = AccountMovement::where('reference_type', 'employee_payroll_line')
            ->where('reference_id', $line->id)->first();
        $this->assertNotNull($movement, 'صرف الموظف بيسجل حركة حساب');
        $this->assertSame('out', $movement->direction);
        $this->assertEquals(800.0, (float) $movement->amount);

        $this->assertSame('paid', $line->fresh()->status);

        // والدفع المزدوج مرفوض برضه
        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $payer)->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 5 — الخصم بصلاحيته وبيخصم الدفتر
    // ═══════════════════════════════════════════════════════════

    public function test_deduction_debits_the_teacher_ledger_with_its_own_permission(): void
    {
        $teacher = $this->makeActiveTeacher();
        $clerk = $this->makeUserWithRole('ded', ['payroll.deductions.create']);
        $stranger = $this->makeUserWithRole('stranger', []);

        $this->postJsonAs("/api/teachers/{$teacher->id}/deduction", ['amount' => 30], $stranger)
            ->assertStatus(403);

        $this->postJsonAs("/api/teachers/{$teacher->id}/deduction", ['amount' => 30], $clerk)
            ->assertStatus(201);

        $row = TeacherLedgerEntry::where('teacher_id', $teacher->id)->firstOrFail();
        $this->assertSame('deduction', $row->type);
        $this->assertSame('30.00', (string) $row->debit);
        $this->assertSame('-30.00', (string) $row->balance_after);

        // مبلغ سالب مرفوض
        $this->postJsonAs("/api/teachers/{$teacher->id}/deduction", ['amount' => -5], $clerk)
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 6 — التقرير: المستحقات المؤكدة فقط (المعتمد والمدفوع)
    // ═══════════════════════════════════════════════════════════

    public function test_the_financial_report_counts_confirmed_earnings_only(): void
    {
        $teacher = $this->makeActiveTeacher();

        $this->makeEarning($teacher, '2026-03-10', ['amount' => 100, 'status' => 'approved']);
        $this->makeEarning($teacher, '2026-03-11', ['amount' => 50, 'status' => 'pending']);
        $this->makeEarning($teacher, '2026-03-12', ['amount' => 30, 'status' => 'cancelled']);

        TeacherPayment::create([
            'teacher_id' => $teacher->id,
            'amount' => 100,
            'currency' => 'EGP',
            'status' => 'completed',
            'paid_at' => '2026-03-20 10:00:00',
            'processed_by' => $this->makeUserWithRole('u', [])->id,
        ]);

        $reader = $this->makeUserWithRole('reader', ['reports.view']);

        $r = $this->getJsonAs('/api/reports/financial?from=2026-03-01&to=2026-03-31', $reader);
        $r->assertOk();

        // ⭐ المعتمد/المدفوع فقط — كان بيجمع حتى المعلق والملغي (180)
        $this->assertEquals(100.0, (float) $r->json('teacher_costs.gross_earnings'));
        $this->assertEquals(100.0, (float) $r->json('teacher_costs.payments_made'));
        $this->assertEquals(0.0, (float) $r->json('alerts.unpaid_teacher_dues'));
    }
}
