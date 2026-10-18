<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Models\Subscription;
use App\Models\Teacher;
use Tests\TestCase;

/**
 * ⭐⭐ ربط الـ flows الموجودة بالدفتر المالي الجديد.
 *
 * ⭐ **اللي بيحصل في الملف ده:**
 *
 * قبل الـ ledger، كان فيه **ثغرة حقيقية**:
 *
 *  • **دفعة المعلم** — كانت بتتسجل في `teacher_payments` بس،
 *    ومفيش أي مكان بيقول إن الفلوس **خرجت من الخزينة**.
 *    يعني «صافي التشغيل» في التقرير كان **غلط**: المعلم اتدفع
 *    والمصروف ده ما ظهرش خالص.
 *
 *    • **الاسترداد** — نفس الكلام. الخزنة نقصت وقت التحصيل،
 *    وبعدين رجعنا للطالب من غير تسجيل خروج.
 *
 *  • **المصروف** — بيتخزن `pending` دايماً (مفيش endpoint
 *    اعتماد)، فلو سجّلناه `posted` كان هيبقى منتهى وانتهى.
 *
 * ⭐ الاختبارات دي بتقفل التلاتة.
 */
class LedgerIntegrationTest extends TestCase
{
    private function accountant()
    {
        return $this->makeUserWithRole('accountant', [
            'finance.payments.view', 'finance.payments.create', 'finance.payments.refund',
            'payroll.periods.manage', 'payroll.payments.create', 'students.view',
            'finance.accounts.view', 'finance.movements.create', 'finance.accounts.manage',

            // ⭐ المصروفات — راوتس كانت **مفتوحة** قبل PR 0.1
            // (auth:sanctum من غير permission)، فاختبارات المصروفات
            // هنا كانت بتعدّي من غير ما تطلب صلاحية. دلوقتي محمية.
            'finance.expenses.view', 'finance.expenses.create',
        ]);
    }

    private function studentWithSubscription()
    {
        $program = $this->makeProgram();
        $this->defaultPlan($program);
        $student = $this->makeStudent();
        $this->makeSubscription($program, $student, ['price' => 500]);

        return [$student, $program];
    }

    // ============================================================
    // ① الدفعة بتتحفظ + بتتسجل حركة دخل
    // ============================================================

    public function test_recording_a_payment_writes_an_income_movement(): void
    {
        $account = $this->makeAccount(['name' => 'الخزينة']);
        [$student] = $this->studentWithSubscription();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->subDay()->toDateString(),
        ], $this->accountant());

        $r->assertCreated();
        $r->assertJsonPath('ledger.recorded', true);
        $r->assertJsonPath('ledger.account_id', $account->id);
        $this->assertNull($r->json('warning'));

        // ⭐ الحركة موجودة فعلاً في الدفتر
        $movement = AccountMovement::where('reference_type', 'payment')->first();
        $this->assertNotNull($movement);
        $this->assertSame('in', $movement->direction);
        $this->assertSame('income', $movement->category);
        $this->assertSame(500.0, (float) $movement->amount);

        // ⭐ والرصيد اتحسب
        $this->assertSame(500.0, $account->fresh()->balance());
    }

    public function test_a_pending_payment_is_not_a_collection(): void
    {
        // ⭐ دفعة `pending` أو `failed` = **مش** فلوس دخلت.
        // لو سجّلناها `posted` كان الرصيد أعلى من الحقيقي.
        $account = $this->makeAccount();
        [$student] = $this->studentWithSubscription();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'pending',
            'paid_at' => now()->toDateString(),
        ], $this->accountant());

        $r->assertCreated();
        $r->assertJsonPath('ledger.recorded', false);

        $this->assertSame(0, AccountMovement::where('reference_type', 'payment')->count());
        $this->assertSame(0.0, $account->fresh()->balance());
    }

    public function test_the_payment_date_is_the_movement_date_not_today(): void
    {
        // ⭐ فرق مهم: الدفعة اتسجلت امبارح بس `movement_date` بتاعها
        // امبارح. التقارير بتقصّ بالتاريخ المالي مش بامتى اتكتب السطر.
        $this->makeAccount();
        [$student] = $this->studentWithSubscription();
        $date = now()->subDays(3)->toDateString();

        $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => $date,
        ], $this->accountant())->assertCreated();

        $this->assertSame($date, AccountMovement::first()->movement_date->toDateString());
    }

    public function test_a_payment_without_any_account_is_still_saved_with_a_warning(): void
    {
        // ⭐⭐ **الحالة الحرجة.**
        //
        // مافيش حساب = الحركة ماتتسجلش. بس **الدفعة بتتحفظ**.
        //
        // ليه؟ فلو رمينا exception، العميل واقف في البوابة
        // ومااتسجلش عنده حاجة — وهو دفع فلوس حقيقية!
        //
        // فالدفعة تتحفظ، والرد يرجع تحذير صريح.
        [$student] = $this->studentWithSubscription();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->accountant());

        $r->assertCreated();
        $r->assertJsonPath('ledger.recorded', false);
        $this->assertStringContainsString(
            'مفيش حساب مالي',
            (string) $r->json('warning')
        );

        // ⭐ والدفعة **تحفظت** — دي الحجة
        $this->assertDatabaseHas('payments', ['student_id' => $student->id]);
    }

    public function test_a_payment_in_a_locked_period_is_saved_with_a_warning(): void
    {
        // ⭐ نفس المنطق: قفل الشهر **مش** يمنع التحصيل.
        // يمنع **التسجيل**، والدفعة بتتحفظ مع تحذير.
        $account = $this->makeAccount();
        [$student] = $this->studentWithSubscription();
        $this->makeLock();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->accountant());

        $r->assertCreated();
        $r->assertJsonPath('ledger.recorded', false);
        $this->assertStringContainsString('مقفولة', (string) $r->json('warning'));

        $this->assertDatabaseHas('payments', ['student_id' => $student->id]);
    }

    // ============================================================
    // ② الاسترداد — الثغرة اللي كانت موجودة
    // ============================================================

    public function test_a_refund_writes_an_out_movement(): void
    {
        // ⭐⭐ من غير السطر ده: الخزينة نقصت 500 وقت التحصيل،
        // وبعدين رجعنا للطالب 500 من غير ما نكتب حاجة.
        // فالمصروف الشبكي بينجب **بارتفاع**.
        $account = $this->makeAccount(['opening_balance' => 1000]);
        [$student] = $this->studentWithSubscription();

        $payment = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->accountant());

        $paymentId = $payment->json('id');
        $this->assertSame(1500.0, $account->fresh()->balance());

        $accountant = $this->accountant();


        $r = $this->postJsonAs("/api/payments/{$paymentId}/refund", [
            'amount' => 500,
            'reason' => 'ألغى الاشتراك',
        ], $accountant);

        $r->assertCreated();
        $r->assertJsonPath('ledger.recorded', true);

        // ⭐ الفئة `refund` مش `expense` — التقرير يفرّق
        $movement = AccountMovement::where('reference_type', 'refund')->first();
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('refund', $movement->category);

        // ⭐ والرصيد رجع 1000 (الافتتاحي بس)
        $this->assertSame(1000.0, $account->fresh()->balance());
    }

    // ============================================================
    // ③ دفعة المعلم — الثغرة الأكبر
    // ============================================================

    public function test_paying_a_teacher_writes_an_out_movement(): void
    {
        // ⭐⭐ دي كانت ثغرة: مستحق المعلم كان بيتدفع ومفيش أي
        // سطر بيقول إن الفلوس خرجت. فـ «صافي التشغيل» كان غلط.
        $account = $this->makeAccount(['opening_balance' => 5000]);

        $period = $this->makePeriod('2026-09-01', '2026-09-30', ['status' => 'finalized']);
        $teacher = $this->makeActiveTeacher();

        $line = $period->teacherLines()->create([
            'organization_id' => $this->org->id,
            'teacher_id' => $teacher->id,
            'status' => 'approved',
            'amount' => 1200,
        ]);

        $r = $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [
            'payment_method' => 'bank_transfer',
        ], $this->accountant());

        $r->assertOk();
        $r->assertJsonPath('ledger.recorded', true);

        $movement = AccountMovement::where('reference_type', 'teacher_payroll_line')->first();
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);

        // ⭐⭐ **الفئة** — مش `expense`. الفرق في التقرير مهم:
        // مستحقات المعلمين مش «مصروفات تشغيلية».
        $this->assertSame('teacher_payment', $movement->category);

        // ⭐ والرصيد نقص فعلاً — وده اللي كان ناقص
        $this->assertSame(3800.0, $account->fresh()->balance());
    }

    // ============================================================
    // ④ المصروف — pending لحد الاعتماد
    // ============================================================

    public function test_an_expense_is_recorded_but_does_not_move_the_balance(): void
    {
        // ⭐ المصروف بيتخزن `pending` دايماً (مفيش endpoint اعتماد)،
        // فلو سجّلناه `posted` كان هيبقى منتهى وانتهى — ومينفعش
        // نرجّعه.
        $account = $this->makeAccount(['opening_balance' => 2000]);
        $category = $this->makeExpenseCategory(['name' => 'إيجار']);

        $r = $this->postJsonAs('/api/expenses', [
            'amount' => 800,
            'expense_date' => now()->toDateString(),
            'description' => 'إيجار الشهر',
            'category_id' => $category->id,
        ], $this->accountant());

        $r->assertCreated();
        $r->assertJsonPath('ledger.recorded', true);
        $r->assertJsonPath('ledger.posted', false);

        // ⭐ الحركة موجودة — بس **مش معتمدة**
        $movement = AccountMovement::where('reference_type', 'expense')->first();
        $this->assertNotNull($movement);
        $this->assertSame('pending', $movement->status);

        // ⭐ والرصيد **ما اتغيرش**
        $this->assertSame(2000.0, $account->fresh()->balance());
    }

    public function test_approving_an_expense_posts_its_movement(): void
    {
        // ⭐ دي اللحظة اللي فيها المصروف بيطلع من الخزينة. وقتها
        // الـ `status` بيتحول `posted` — وبعدين التصحيح = **عكس**
        // مش تعديل.
        $account = $this->makeAccount(['opening_balance' => 2000]);
        $category = $this->makeExpenseCategory(['name' => 'إيجار']);

        $this->postJsonAs('/api/expenses', [
            'amount' => 800,
            'expense_date' => now()->toDateString(),
            'description' => 'إيجار',
            'category_id' => $category->id,
        ], $this->accountant())->assertCreated();

        $movement = AccountMovement::where('reference_type', 'expense')->first();
        $this->assertSame(2000.0, $account->fresh()->balance());

        app(\App\Services\FinancialLedgerService::class)->pendingToPosted($movement);

        $this->assertSame('posted', $movement->fresh()->status);
        $this->assertSame(1200.0, $account->fresh()->balance());
    }

    public function test_approving_twice_does_not_double_charge(): void
    {
        // ⭐ دبل-كليك على زرار «اعتماد» مايرميش 500 ومايخصمش
        // مرتين. نفس المنطق اللي في `GroupMember::admit`.
        $account = $this->makeAccount(['opening_balance' => 2000]);
        $category = $this->makeExpenseCategory(['name' => 'إيجار']);

        $this->postJsonAs('/api/expenses', [
            'amount' => 800,
            'expense_date' => now()->toDateString(),
            'description' => 'إيجار',
            'category_id' => $category->id,
        ], $this->accountant())->assertCreated();

        $movement = AccountMovement::where('reference_type', 'expense')->first();
        $ledger = app(\App\Services\FinancialLedgerService::class);

        $ledger->pendingToPosted($movement);
        $ledger->pendingToPosted($movement->fresh());

        $this->assertSame(1200.0, $account->fresh()->balance());
    }

    // ============================================================
    // ⑤ اختيار الحساب حسب طريقة الدفع
    // ============================================================

    public function test_a_wallet_payment_goes_to_the_wallet_account(): void
    {
        // ⭐ لو حد دفع بـ InstaPay، الفلوس دخلت **محفظة** مش
        // الخزينة النقدية. من غير ده رصيد الخزينة غلط والبوابة
        // مش هيبان خالص.
        $cash = $this->makeAccount(['name' => 'خزينة', 'type' => 'cash']);
        $wallet = $this->makeAccount(['name' => 'محفظة', 'type' => 'wallet']);

        [$student] = $this->studentWithSubscription();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'wallet',
            'paid_at' => now()->toDateString(),
        ], $this->accountant());

        $r->assertJsonPath('ledger.account_id', $wallet->id);
        $r->assertJsonPath('ledger.account_pick', 'by_type');

        $this->assertSame(0.0, $cash->fresh()->balance());
        $this->assertSame(500.0, $wallet->fresh()->balance());
    }

    public function test_the_admin_can_pick_the_account_explicitly(): void
    {
        $bank = $this->makeAccount(['name' => 'بنك', 'type' => 'bank']);
        [$student] = $this->studentWithSubscription();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'financial_account_id' => $bank->id,
        ], $this->accountant());

        $r->assertJsonPath('ledger.account_id', $bank->id);
        $r->assertJsonPath('ledger.account_pick', 'requested');
    }

    public function test_the_first_active_account_is_the_default(): void
    {
        $first = $this->makeAccount(['name' => 'الأول']);
        $this->makeAccount(['name' => 'التاني']);

        [$student] = $this->studentWithSubscription();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->accountant());

        $r->assertJsonPath('ledger.account_id', $first->id);
        $r->assertJsonPath('ledger.account_pick', 'default');
    }

    public function test_an_inactive_account_is_never_used(): void
    {
        $old = $this->makeAccount(['name' => 'خزينة قديمة', 'status' => 'inactive']);
        $active = $this->makeAccount(['name' => 'نشطة']);

        [$student] = $this->studentWithSubscription();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->accountant());

        $r->assertJsonPath('ledger.account_id', $active->id);
        $this->assertNotSame($old->id, $active->id);
    }
}
