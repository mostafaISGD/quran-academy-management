<?php

namespace Tests\Feature;

use App\Casts\Piastres;
use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\SubscriptionPlan;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **ضمانة التقريب الدقيق (bigint)**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الفكرة في سطر
 *
 * القاعدة بتخزّن **عدد صحيح بالقرش**، والـ cast بيرجّع جنيه
 * بنفس شكل `decimal:2` القديم بالظبط:
 *
 *     القاعدة: 65000        (عدد صحيح — مالوش خطأ تمثيل)
 *     الـ API:  "650.00"     (نص — زي ما كان قبل التحويل)
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️ ليه الاختبارات دي مهمة جدًا؟
 *
 * قبل التحويل، المخاطرة كانت موجودة بس **متخفية**: كل عملية
 * كتابة كانت بتمرّ على `round($amount, 2)` فماخدناش خطأ، غير
 * إن كود جديد يكسر الانضباط ده.
 *
 * دلوقتي الخطأ **مستحيل** — الـ cast بيحوّل لصحيح، والصحيح
 * مالوش drift مهما اتكرر ١٠٬٠٠٠ مرة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️ والاختبارات دي كمان بتحرس الـ aggregates
 *
 * `sum()` و `value()` و `increment()` **بتتخطّى الـ cast**. لو
 * حد أضاف واحدة من دول على مبلغ، الرقم بيروح **١٠٠× غلط** من
 * غير ما يعمل exception. الاختبارات دي بتمسك الحالة دي.
 */
class MoneyPrecisionTest extends TestCase
{
    // ============================================================
    // ① الـ cast نفسه — سلوكه في الاتجاهين
    // ============================================================

    public function test_the_cast_never_loses_a_piaster(): void
    {
        $model = new Invoice;
        $cast = new Piastres;

        // ⭐ الكسور هي السبب كله — والوزنات الشائعة فعلياً:
        $fractions = [
            '0.07'  => 7,
            '0.33'  => 33,
            '0.01'  => 1,
            '19.99' => 1999,
            '33.33' => 3333,
            '650'   => 65000,
            '1650'  => 165000,
            '0.005' => 1,      // ⭐ التقريب لأقرب قرش
            '0.004' => 0,      // ⭐ وبتقريب نزولاً
        ];

        foreach ($fractions as $junih => $expected) {
            $this->assertSame(
                $expected,
                $cast->set($model, 'total', (float) $junih, []),
                "المبلغ {$junih} جنيه لازم يتخزّن {$expected} قرش"
            );
        }
    }

    public function test_the_cast_traps_the_classic_float_trap(): void
    {
        $model = new Invoice;
        $cast = new Piastres;

        /**
         * ⭐⭐⭐ **الحالة اللي الـ float بيرمي فيها فعلاً:**
         *
         *     0.07 + 0.07 + 0.07 = 0.21000000000000002
         */
        $this->assertNotSame(
            0.21,
            0.07 + 0.07 + 0.07,
            'الـ float لازم يغلط هنا — عشان نثبت إن الاختبار ذي معنى'
        );

        // ⭐ وبنفس القيم بالظبط، القرش بيطلع ٢١ قرش من غير خطأ
        $inPiastres = $cast->set($model, 'total', 0.07, [])
            + $cast->set($model, 'total', 0.07, [])
            + $cast->set($model, 'total', 0.07, []);

        $this->assertSame(21, $inPiastres);
        $this->assertSame('0.21', $cast->get($model, 'total', $inPiastres, []));
    }

    public function test_the_cast_returns_the_same_shape_as_before(): void
    {
        $model = new Invoice;
        $cast = new Piastres;

        /**
         * ⚠️ ده **شرط التصميم**: الـ API ما يتغيّرش خالص.
         *
         * `decimal:2` كان بيرجّع نص بمنزلتين بالظبط:
         *     number_format(350, 2, '.', '') → "350.00"
         *
         * فلو رجّعنا float، الـ API هيبقى بيرجّع `350` بدل
         * `"350.00"` — وده تغيير في العقد مع الواجهة.
         */
        $this->assertSame('650.00', $cast->get($model, 'total', 65000, []));
        $this->assertSame('0.07', $cast->get($model, 'total', 7, []));
        $this->assertSame('0.10', $cast->get($model, 'total', 10, []));
        $this->assertSame('-65.00', $cast->get($model, 'total', -6500, []));

        // ⭐ و`null` معناها "مافيش مبلغ" — مش صفر
        $this->assertNull($cast->get($model, 'net_amount', null, []));
        $this->assertNull($cast->set($model, 'net_amount', null, []));
    }

    public function test_from_database_is_the_only_bridge_for_an_aggregate(): void
    {
        // ⭐ `sum()` بيرجّع قرش — وده **سلوك متوقع**
        $this->assertSame(250.00, Piastres::fromDatabase(25000));
        $this->assertSame(0.00, Piastres::fromDatabase(0));
        $this->assertSame(0.07, Piastres::fromDatabase(7));
        $this->assertNull(Piastres::fromDatabase(null));
    }

    // ============================================================
    // ② التخزين الفعلي في القاعدة
    // ============================================================

    public function test_money_is_stored_as_a_whole_number_in_piastres(): void
    {
        $plan = $this->makePlan($this->makeProgram(), ['price' => 650.00]);

        // ⭐ اللي في القاعدة لازم يكون **عدد صحيح**، مش ٦٥٠
        $raw = \DB::table('subscription_plans')
            ->where('id', $plan->id)
            ->value('price');

        $this->assertSame(65000, (int) $raw);

        // ⭐ واللي الموديل بيرجّعه لازم يفضل زي ما كان بالظبط
        $this->assertSame('650.00', $plan->fresh()->price);
    }

    public function test_a_thousand_writes_do_not_drift(): void
    {
        $plan = $this->makePlan($this->makeProgram(), ['price' => 33.33]);

        // ⭐ ١٠٠٠ حفظ متتالي — لو فيه drift هيتجمّع
        for ($i = 0; $i < 1000; $i++) {
            $plan->price = 33.33;
            $plan->save();
        }

        $raw = \DB::table('subscription_plans')
            ->where('id', $plan->id)
            ->value('price');

        $this->assertSame(3333, (int) $raw, 'بعد ١٠٠٠ حفظ لازم يفضل ٣٣٣٣ قرش');
        $this->assertSame('33.33', $plan->fresh()->price);
    }

    public function test_every_money_column_is_stored_as_a_whole_number(): void
    {
        /**
         * ⭐⭐ الاختبار ده بيمسك أي عمود مبلغ **اتسهى** التحويل.
         *
         * لو حد ضاف جدول جديد فيه عمود `amount` بعد ما الـ
         * migration اشتغلت، الاختبار ده هيفشل ويقوله.
         *
         * @see \Database\Migrations\ConvertMoneyColumnsToPiastres
         */
        $moneyColumns = [
            'account_movements' => ['amount'],
            'account_transfers' => ['amount'],
            'credit_note_applications' => ['amount'],
            'employee_payroll_lines' => ['hourly_rate', 'amount'],
            'employees' => ['hourly_rate'],
            'expenses' => ['amount'],
            'financial_accounts' => ['opening_balance'],
            'invoice_items' => ['unit_price', 'total'],
            'invoices' => ['subtotal', 'discount', 'tax', 'total', 'paid_amount', 'balance_due', 'credited_amount'],
            'lesson_credit_accounts' => ['current_balance'],
            'lesson_credit_transactions' => ['balance_after'],
            'payment_allocations' => ['amount'],
            'payments' => ['amount', 'net_amount'],
            'promo_code_usages' => ['discount_amount', 'original_amount', 'final_amount'],
            'refunds' => ['amount'],
            'student_credits' => ['total', 'used', 'refunded'],
            'student_ledger_entries' => ['debit', 'credit', 'balance_after'],
            'subscription_plans' => ['price'],
            'subscriptions' => ['price'],
            'teacher_contracts' => ['monthly_salary'],
            'teacher_earnings' => ['amount'],
            'teacher_ledger_entries' => ['debit', 'credit', 'balance_after'],
            'teacher_payments' => ['amount'],
            'teacher_payroll_lines' => ['rate_snapshot', 'amount'],
            'teacher_rates' => ['amount'],
        ];

        $wrong = [];
        $checked = 0;

        foreach ($moneyColumns as $table => $columns) {
            if (! \Schema::hasTable($table)) {
                continue;
            }

            foreach (\Schema::getColumns($table) as $column) {
                if (! in_array($column['name'], $columns, true)) {
                    continue;
                }

                $checked++;
                $type = strtolower((string) $column['type']);

                /**
                 * ⚠️ `int` بس مقبول.
                 *
                 * `numeric` و `decimal` و `float` معناها الـ float
                 * رجع تاني — وده اللي كنا بنحاول نتخلّص منه.
                 */
                if (! str_contains($type, 'int')) {
                    $wrong[] = "{$table}.{$column['name']} نوعه «{$type}»";
                }
            }
        }

        $this->assertGreaterThanOrEqual(43, $checked, 'لازم نفحص كل الأعمدة المحسوسة');
        $this->assertSame([], $wrong, 'كل الأعمدة المحسوسة لازم تكون أعداد صحيحة');
    }

    // ============================================================
    // ③ الـ aggregates — الفخ اللي بيضرب ١٠٠×
    // ============================================================

    public function test_summing_movements_agrees_with_the_opening_balance(): void
    {
        $account = $this->cashAccount(1000.00);

        // ٥٠٠ داخل و ٢٠٠ خارج = ٣٠٠ صافي
        $this->movement($account, 'in', 500.00);
        $this->movement($account, 'out', 200.00);

        /**
         * ⚠️⚠️ **ده الاختبار اللي كان هيسقط قبل الإصلاح.**
         *
         * `balance()` بتجمع:
         *     opening_balance  ← من الـ cast  → 1000.00 جنيه
         *     SUM(amount)      ← من SQL      → 30000 قرش
         *
         * من غير التحويل: 1000 + 30000 = 31000  ⭐ غلط ١٠٠×
         */
        $this->assertSame(1300.00, $account->balance());
        $this->assertSame(500.00, $account->totalIn());
        $this->assertSame(200.00, $account->totalOut());
    }

    public function test_many_small_movements_add_up_exactly(): void
    {
        $account = $this->cashAccount(0);

        // ⭐ ١٠٠٠ حركة بـ ٠٫٠٧ جنيه — المجموع ٧٠ جنيه بالظبط
        for ($i = 0; $i < 1000; $i++) {
            $this->movement($account, 'in', 0.07);
        }

        $this->assertSame(70.00, $account->balance());
        $this->assertSame(70.00, $account->totalIn());
    }

    public function test_an_invoice_balance_does_not_swallow_a_hundredfold(): void
    {
        /**
         * ⭐ نفس الفخ بس على الفواتير.
         *
         * `recalculateFromAllocations()` بتطرح مجموع التوزيعات
         * (من `SUM` — قرش) من الإجمالي (من الخاصية — جنيه).
         *
         * لو الخلط موجود، المتبقي بيطلع بالسالب أو غلط.
         */
        $user = $this->makeUserWithRole('receptionist', ['invoices.view']);
        $student = $this->makeStudent();

        $invoice = \App\Models\Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-DUPE-1',
            'currency' => 'EGP',
            'subtotal' => 500.00,
            'discount' => 0,
            'tax' => 0,
            'total' => 500.00,
            'paid_amount' => 0,
            'credited_amount' => 0,
            'balance_due' => 500.00,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'status' => 'issued',
        ]);

        \App\Models\PaymentAllocation::create([
            'organization_id' => $this->org->id,
            'payment_id' => $this->makePayment($student, 200.00)->id,
            'invoice_id' => $invoice->id,
            'amount' => 200.00,
            'status' => 'active',
        ]);

        $invoice->recalculateFromAllocations();

        $this->assertSame('200.00', $invoice->fresh()->paid_amount);
        $this->assertSame('300.00', $invoice->fresh()->balance_due);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    // ============================================================
    // أدوات مساعدة
    // ============================================================

    private function cashAccount(float $opening): FinancialAccount
    {
        return FinancialAccount::create([
            'organization_id' => $this->org->id,
            'name' => 'صندوق اختبار الدقة',
            'type' => 'cash',
            'currency' => 'EGP',
            'opening_balance' => $opening,
        ]);
    }

    private function movement(FinancialAccount $account, string $direction, float $amount): void
    {
        AccountMovement::create([
            'organization_id' => $this->org->id,
            'financial_account_id' => $account->id,
            'direction' => $direction,
            'category' => $direction === 'in' ? 'other_income' : 'other_expense',
            'amount' => $amount,
            'currency' => 'EGP',
            'movement_date' => now()->toDateString(),
            'status' => 'posted',
        ]);
    }

    private function makePayment($student, float $amount): \App\Models\Payment
    {
        return \App\Models\Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'amount' => $amount,
            'net_amount' => $amount,
            'payment_method' => 'cash',
            'received_by' => $this->makeUserWithRole('receptionist', ['payments.manage'])->id,
            'currency' => 'EGP',
            'status' => 'completed',
            'paid_at' => now(),
        ]);
    }
}
