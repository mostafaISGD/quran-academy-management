<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\TeacherPayrollLine;
use App\Models\PeriodLock;
use App\Models\Payment;
use App\Models\Expense;
use App\Models\AccountMovement;
use Tests\TestCase;

/**
 * PR 2.13 — الأقفال كانت على الحسابات بس.
 *
 * الوضع قبل كده:
 *
 * ① صلاحيات scope كانت كلها `all`/`account` — مش ممكن تقفل
 *    «المرتبات بس» أو «المدفوعات بس» وتبقى باقي الكتابة مفتوحة.
 * ② المدفوعات والاسترداد والمصروفات والمرتبات ببتتسجل في
 *    فترة مقفولة من غير أي رفض — الدفعة كانت بتتحفظ والحركة
 *    المالية بتضيع مع تحذير.
 * ③ فتح الفترة كان بيجي بدون تأكيد بكلمة السر — وإيميل مصر.
 * ④ عكس حركة (تصحيح) كان ممنوع لو **الفترة الأصلية** مقفولة —
 *    والإصلاح المفروض يتم في أول فترة مفتوحة بمرجّع للأصل.
 */
class PeriodLockScopesTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // 1 — تحصيل في فترة مقفولة → 422 (المواصفة: مرفوض)
    // ═══════════════════════════════════════════════════════════

    public function test_a_payment_in_a_locked_period_is_rejected(): void
    {
        $this->makeAccount();
        $student = $this->makeStudent();
        $clerk = $this->makeUserWithRole('cashier', ['finance.payments.create']);

        $lock = PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->startOfMonth()->toDateString(),
            end: now()->endOfMonth()->toDateString(),
            scope: 'payments',
            userId: $clerk->id,
        );
        $this->assertNotNull($lock, 'قفل بنطاق payments مقبول');

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $clerk);

        $r->assertStatus(422);
        $this->assertSame(0, Payment::count(), 'مافيش دفعة في فترة مقفولة');
        $this->assertSame(0, AccountMovement::count());
    }

    public function test_locking_scopes_of_payments_and_invoices_is_accepted(): void
    {
        $clerk = $this->makeUserWithRole('locking', ['finance.periods.lock']);

        $this->postJsonAs('/api/period-locks', [
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'scope' => 'payments',
            'reason' => 'إفصاح بتعامل أمر',
        ], $clerk)->assertCreated();

        $this->postJsonAs('/api/period-locks', [
            'start' => '2026-10-01',
            'end' => '2026-10-31',
            'scope' => 'invoices',
        ], $clerk)->assertCreated();
    }

    // ═══════════════════════════════════════════════════════════
    // 2 — النطاقات مستقلة: قفل المدفوعات يمنع التعصب للآخر
    // ═══════════════════════════════════════════════════════════

    public function test_a_payments_scope_lock_does_not_block_expenses(): void
    {
        $treasurer = $this->makeUserWithRole('treasurer', [
            'finance.expenses.create', 'finance.payments.create', 'finance.expenses.approve', 'finance.expenses.pay',
        ]);

        $category = $this->makeExpenseCategory();
        $exp = Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'amount' => 50,
            'currency' => 'EGP',
            'expense_date' => now()->toDateString(),
            'description' => 'مصروف تجاري',
            'created_by' => $treasurer->id,
            'status' => 'approved',
        ]);

        $this->makeAccount(['opening_balance' => 10000]);

        PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->startOfMonth()->toDateString(),
            end: now()->endOfMonth()->toDateString(),
            scope: 'payments',
            userId: $treasurer->id,
        );

        // ⭐ قفل نطاق payments بيجمد التحصيل فقط — المصروف
        //    ما يتأثرش: الصراف يصرف عادي مع حركة مالية كاملة.
        $r = $this->postJsonAs("/api/expenses/{$exp->id}/pay", [], $treasurer);
        $r->assertOk();
        $this->assertSame('paid', $exp->fresh()->status);
        $this->assertSame(1, AccountMovement::where('reference_type', 'expense')->count(),
            'قفل payments ما يمنعش حركة المصروف');

        // ⭐ بالمقابل — نفس الفترة تقفل تحصيل جديد
        $student = $this->makeStudent();
        $pr = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 100,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $treasurer);
        $pr->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 3 — صرف مصروف في فترة مقفولة → 422 (كان بيعدّي مع تحذير)
    // ═══════════════════════════════════════════════════════════

    public function test_paying_an_expense_in_a_locked_period_is_rejected(): void
    {
        $treasurer = $this->makeUserWithRole('treasurer', [
            'finance.expenses.pay',
        ]);

        $category = $this->makeExpenseCategory();
        $exp = Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'amount' => 100,
            'currency' => 'EGP',
            'expense_date' => now()->toDateString(),
            'description' => 'كهرباء',
            'created_by' => $treasurer->id,
            'status' => 'approved',
        ]);

        $this->makeAccount(['opening_balance' => 10000]);

        PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->startOfMonth()->toDateString(),
            end: now()->endOfMonth()->toDateString(),
            scope: 'expenses',
            userId: $treasurer->id,
        );

        $r = $this->postJsonAs("/api/expenses/{$exp->id}/pay", [], $treasurer);
        $r->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 4 — صرف مرتب معلم في فترة مقفولة → 422 (كان تحذير بس)
    // ═══════════════════════════════════════════════════════════

    public function test_paying_teacher_payroll_in_a_locked_period_is_rejected(): void
    {
        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher, ['start_date' => now()->startOfMonth()->subMonth()->toDateString()]);
        $this->makeRate($teacher, ['amount' => 100, 'effective_from' => now()->startOfMonth()->subMonth()->toDateString()]);

        $earning = $this->makeEarning($teacher, now()->toDateString(), [
            'amount' => 100,
            'status' => 'pending',
        ]);

        $approver = $this->makeUserWithRole('approver', ['payroll.earnings.approve']);
        $this->postJsonAs("/api/teacher-earnings/{$earning->id}/approve", [], $approver)->assertOk();

        $this->makeAccount();

        $period = $this->makePeriod(
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        );
        $manager = $this->makeUserWithRole('manager2', ['payroll.periods.manage']);
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $manager)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $manager)->assertOk();

        $line = TeacherPayrollLine::where('payroll_period_id', $period->id)
            ->where('teacher_id', $teacher->id)->firstOrFail();

        $payer = $this->makeUserWithRole('payer2', ['payroll.payments.create']);

        // ⭐ قفل payroll يمنع الصرف الحقيقي في الشهر
        PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->startOfMonth()->toDateString(),
            end: now()->endOfMonth()->toDateString(),
            scope: 'payroll',
            userId: $payer->id,
        );

        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $payer)->assertStatus(422);
        $this->assertSame(0, AccountMovement::where('reference_type', 'teacher_payroll_line')->count());
        $this->assertNotSame('paid', $line->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════
    // 5 — فاتورة جديدة في فترة مقفولة (scope invoices) → 422
    // ═══════════════════════════════════════════════════════════

    public function test_creating_an_invoice_in_a_locked_period_is_rejected(): void
    {
        $student = $this->makeStudent();
        $clerk = $this->makeUserWithRole('clerk', ['finance.payments.create', 'students.view']);

        PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->startOfMonth()->toDateString(),
            end: now()->endOfMonth()->toDateString(),
            scope: 'invoices',
            userId: $clerk->id,
        );

        $r = $this->postJsonAs('/api/invoices', [
            'student_id' => $student->id,
            'issue_date' => now()->toDateString(),
            'items' => [
                ['description' => 'قسط', 'quantity' => 1, 'unit_price' => 300],
            ],
        ], $clerk);

        $r->assertStatus(422);
        $this->assertSame(0, Invoice::count(), 'مافيش فاتورة في فترة مقفولة');
    }

    // ═══════════════════════════════════════════════════════════
    // 6 — إعادة فتح الفترة تحتاج تأكيد كلمة السر (1.9)
    // ═══════════════════════════════════════════════════════════

    public function test_unlocking_requires_the_current_password(): void
    {
        $lock = $this->makeLock();
        // المستخدم اللي هيعمل unlock — كلمة سره من الـfixture:
        // 'password' (TestCase بيسجّل دخول بنفسها في كل نداء).
        $user = $this->makeUserWithRole('unlocker', ['finance.periods.unlock']);

        $this->postJsonAs("/api/period-locks/{$lock->id}/unlock", [
            'reason' => 'بدون كلمة السر',
        ], $user)->assertStatus(422);

        $this->postJsonAs("/api/period-locks/{$lock->id}/unlock", [
            'reason' => 'كلمة سر غلط',
            'current_password' => 'wrong-password',
        ], $user)->assertStatus(422);

        $this->postJsonAs("/api/period-locks/{$lock->id}/unlock", [
            'reason' => 'فتح مع شرح واضح',
            'current_password' => 'password',
        ], $user)->assertOk();

        $this->assertFalse($lock->fresh()->isActive());
    }

    // ═══════════════════════════════════════════════════════════
    // 7 — التصحيح في فترة مقفولة = إدخال عكسي في أول فترة
    //     مفتوحة (تاريخ اليوم) بإشارة للأصل
    // ═══════════════════════════════════════════════════════════

    public function test_a_reversal_of_a_movement_in_a_locked_period_lands_in_the_open_period(): void
    {
        $reverser = $this->makeUserWithRole('reverser', ['finance.movements.reverse']);
        $account = $this->makeAccount(['opening_balance' => 1000]);

        // ⭐ حركة قديمة في الشهر اللي فات
        $movement = $this->makeMovement($account, [
            'direction' => 'in',
            'category' => 'income',
            'amount' => 500,
            'movement_date' => now()->subMonth()->startOfMonth()->toDateString(),
        ]);

        // ⭐⭐ الشهر الأصلي مقفول (قفل أشترايا على الحساب)
        PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->subMonth()->startOfMonth()->toDateString(),
            end: now()->subMonth()->endOfMonth()->toDateString(),
            scope: 'account',
            accountId: $account->id,
            userId: $reverser->id,
        );

        // ⭐ العكس بيتسجل **النهارده** (الشهر الحالي مفتوح)
        //    بإشارة للأصل — مش ممنوع لأن الأصل مقفول.
        $r = $this->postJsonAs("/api/movements/{$movement->id}/reverse", [
            'reason' => 'الدفعة اتسجلت في الشهر الغلط',
        ], $reverser);

        $r->assertCreated();

        $reversal = \App\Models\AccountMovement::where('reverses_movement_id', $movement->id)->first();
        $this->assertNotNull($reversal, 'العكس متربط بالأصل');
        $this->assertSame(now()->toDateString(), (string) $reversal->movement_date->toDateString(),
            'إدخال التصحيح في أول فترة مفتوحة');
        $this->assertSame('out', $reversal->direction);
    }
}
