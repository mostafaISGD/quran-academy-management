<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\ExchangeRate;
use App\Models\FinancialAccount;
use App\Models\PeriodLock;
use App\Services\FinancialLedgerService;
use Tests\TestCase;

/**
 * ⭐⭐ الأساس المالي — الخزائن + دفتر الحركات + قفل الفترات.
 *
 * ⭐ **المشكلة اللي بتحلها:**
 *
 * قبل كده الدفعة والمصروف ودفعة المعلم كل واحد في جدول منفصل،
 * ومفيش مكان واحد بيقول «فين الفلوس». `account_movements` بقى
 * **المصدر الوحيد للحقيقة**: كل قرش بيتسجل هنا، والرصيد بيتحسب.
 *
 * ⭐ القواعد المحاسبية اللي الاختبارات دي بتقفلها:
 *
 *  ① **لا تعديل على السجلات التاريخية** — التصحيح بسجل عكسي
 *  ② **لا نخزّن الرصيد** — بيتحسب من الافتتاحي + الحركات
 *  ③ **سعر الصرف وقت العملية** — ولا نعيد حساب بأثر رجعي
 *  ④ **تحويل = حركتان** (صادر + وارد) — مش إيراد ولا مصروف
 *  ⑤ **قفل الفترات** — بعد الاعتماد ما حدش يكتب
 */
class FinancialLedgerTest extends TestCase
{
    private function ledger(): FinancialLedgerService
    {
        return app(FinancialLedgerService::class);
    }

    // ============================================================
    // ① الرصيد محسوب — مش مخزّن
    // ============================================================

    public function test_an_account_with_no_movements_has_only_its_opening_balance(): void
    {
        $a = $this->makeAccount(['opening_balance' => 5000]);

        $this->assertSame(5000.0, $a->balance());
    }

    public function test_the_balance_is_opening_plus_in_minus_out(): void
    {
        $a = $this->makeAccount(['opening_balance' => 1000]);
        $this->makeMovement($a, ['direction' => 'in', 'amount' => 500]);
        $this->makeMovement($a, ['direction' => 'out', 'amount' => 200]);

        $this->assertSame(1300.0, $a->fresh()->balance());
        $this->assertSame(500.0, $a->totalIn());
        $this->assertSame(200.0, $a->totalOut());
    }

    public function test_reversed_movements_do_not_count_toward_the_balance(): void
    {
        // ⭐⭐ العكس بيطبّع لوحده: الأصل (+1000) والسطر العكسي
        // (ناقص 1000) = صفر. **من غير أي استثناء في المعادلة**
        // ومن غير ما نلمس الأصل.
        //
        // ⚠️ التصميم الأول كان بيحط `reversed` على الأصل، ومعادلة
        // الرصيد بتستبعدها — فالمحصّلة كانت ناقص 1000. الاختبار ده
        // هو اللي مسك الغلط.
        $a = $this->makeAccount(['opening_balance' => 0]);
        $m = $this->makeMovement($a, ['direction' => 'in', 'amount' => 1000]);

        $this->assertSame(1000.0, $a->fresh()->balance());

        $this->ledger()->reverse($m, 'دفعة بالغلط');

        $this->assertSame(0.0, $a->fresh()->balance(), 'العكس لازم يرجّع الرصيد مكانه بالظبط');
    }

    public function test_pending_movements_do_not_affect_the_balance(): void
    {
        // ⭐ حركة مش معتمدة لسه = فلوس لسه ما دخلتش
        $a = $this->makeAccount(['opening_balance' => 0]);
        $this->makeMovement($a, ['direction' => 'in', 'amount' => 1000]);
        $this->makeMovement($a, ['direction' => 'in', 'amount' => 500, 'status' => 'pending']);

        $this->assertSame(1000.0, $a->fresh()->balance());
    }

    // ============================================================
    // ② القاعدة ١ — العكس بسطرين
    // ============================================================

    public function test_reversing_a_movement_adds_a_row_and_does_not_edit_the_original(): void
    {
        $a = $this->makeAccount(['opening_balance' => 0]);
        $m = $this->makeMovement($a, ['direction' => 'in', 'amount' => 1000, 'movement_date' => '2026-10-01']);

        $this->ledger()->reverse($m, 'دفعة بالغلط');

        $m->refresh();

        // ⭐ السطر الأصلي **ما اتمسحش وما اتعدّلش مبلغه ولا تاريخه ولا
        // حالته** — القاعدة ١: لا تعديل على السجلات التاريخية
        $this->assertSame(1000.0, (float) $m->amount);
        // ⭐ `movement_date` متـ cast لـ `date` — نرجّعه نص
        // (`(string)` هيرجع وقت ومكان، فمقارنة التاريخ هتغلط)
        $this->assertSame('2026-10-01', $m->movement_date->toDateString());
        $this->assertSame('posted', $m->status, 'الأصل ما يتلمسش خالص — لا حتى الحالة');

        // ⭐ وفي سطر عكسي **جديد** بنفس المبلغ والاتجاه المضاد
        $reversal = $m->reversedBy->first();
        $this->assertNotNull($reversal);
        $this->assertSame(1000.0, (float) $reversal->amount);
        $this->assertSame('out', $reversal->direction);

        // ⭐ والرصيد رجع مكانه بالظبط
        $this->assertSame(0.0, $a->fresh()->balance());
    }

    public function test_reversing_twice_is_refused(): void
    {
        // ⭐ cancel for cancel — الحركة المعكوسة مش بتتعمل لها
        // حركة عكسية تانية، عشان السجل يفضل مقروء.
        $a = $this->makeAccount();
        $m = $this->makeMovement($a, ['direction' => 'in', 'amount' => 100]);

        $this->ledger()->reverse($m, 'سبب أول');

        $this->expectException(\LogicException::class);
        $this->ledger()->reverse($m->fresh(), 'سبب تاني');
    }

    public function test_a_reversal_needs_a_reason(): void
    {
        // ⭐ من غير سبب، بعد سنة مش حد هيعرف ليه اتعكست
        $a = $this->makeAccount();
        $m = $this->makeMovement($a, ['direction' => 'in', 'amount' => 100]);

        $this->expectException(\InvalidArgumentException::class);
        $this->ledger()->reverse($m, '   ');
    }

    // ============================================================
    // ③ القاعدة ٤ — التحويل حركتين، مش إيراد
    // ============================================================

    public function test_a_transfer_moves_money_without_changing_the_total(): void
    {
        // ⭐⭐ ده أهم اختبار في الملف: **تحويل مش إيراد**.
        // 10,000 من البنك للخزينة = «الدخل زاد 10,000»؟ لأ.
        $bank = $this->makeAccount(['type' => 'bank', 'opening_balance' => 20000]);
        $cash = $this->makeAccount(['type' => 'cash', 'opening_balance' => 1000]);

        $transfer = $this->ledger()->transfer($bank, $cash, 10000, notes: 'سحب من البنك');

        // ⭐ الحسابين اتغيروا
        $this->assertSame(10000.0, $bank->fresh()->balance());
        $this->assertSame(11000.0, $cash->fresh()->balance());

        // ⭐ بس **الإجمالي ما اتغيرش** — دي الحجة كلها
        // ⚠️ float المقارنة: `20000 + 1000` int، والرصيد float.
        // `assertSame` بيفرّق بينهم، فبنقارن كـ float.
        $totalBefore = 21000.0;
        $totalAfter = $bank->fresh()->balance() + $cash->fresh()->balance();
        $this->assertSame($totalBefore, round($totalAfter, 2));

        // ⭐ وفصلناه كـ transfer مش income
        $out = $bank->fresh()->movements()->where('category', 'transfer_out')->first();
        $in = $cash->fresh()->movements()->where('category', 'transfer_in')->first();

        $this->assertNotNull($out);
        $this->assertNotNull($in);
        $this->assertSame($transfer->id, $out->account_transfer_id);
        $this->assertSame($transfer->id, $in->account_transfer_id);

        $this->assertSame(0, $bank->fresh()->movements()->where('category', 'income')->count());
    }

    public function test_a_transfer_cannot_exceed_the_balance(): void
    {
        $from = $this->makeAccount(['opening_balance' => 500]);
        $to = $this->makeAccount();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('رصيد');
        $this->ledger()->transfer($from, $to, 1000);
    }

    // ============================================================
    // ④ القاعدة ٣ — سعر الصرف وقت العملية
    // ============================================================

    public function test_the_exchange_rate_is_saved_with_the_operation(): void
    {
        // ⭐ التقرير القديم لازم يفضل على سعر يومه، مش السعر
        // الحالي. فلو ما خزّناش السعر، تغيير سعر النهاردة كان هيغيّر
        // رصيد امبارح — وده «إعادة حساب بأثر رجعي» اللي الخطة منعتها.
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50,
            'valid_date' => '2026-10-01',
        ]);

        $usd = $this->makeAccount(['currency' => 'USD', 'opening_balance' => 1000]);
        $egp = $this->makeAccount(['currency' => 'EGP', 'opening_balance' => 0]);

        $transfer = $this->ledger()->transfer($usd, $egp, 100, transferDate: '2026-10-01');

        // ⭐ السعر اتسجل مع العملية
        $this->assertSame(50.0, (float) $transfer->exchange_rate);
        $this->assertSame('EGP', $transfer->target_currency);

        // ⭐ 100 دولار × 50 = 5,000 جنيه وصلوا فعلاً
        $this->assertSame(5000.0, $egp->fresh()->balance());

        // ⭐ وبعدين السعر بيتغيّر — Ralصيد الوارد **ما بيتغيرش**
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 90,
            'valid_date' => '2026-10-05',
        ]);

        $this->assertSame(5000.0, $egp->fresh()->balance());
    }

    public function test_a_rate_from_before_the_date_is_used(): void
    {
        // ⭐ لو عايز سعر ١ مارس، والأسعار المسجلة آخر مرة ٢٥ فبراير،
        // نستخدم سعر ٢٥ — ده السعر الساري فعلاً يوم ١ مارس.
        ExchangeRate::create([
            'from_currency' => 'EUR',
            'to_currency' => 'EGP',
            'rate' => 55,
            'valid_date' => '2026-02-25',
        ]);

        $rate = ExchangeRate::rateFor('EUR', 'EGP', '2026-03-01');
        $this->assertSame(55.0, (float) $rate);
    }

    public function test_the_reverse_rate_is_used_when_only_it_is_recorded(): void
    {
        // ⭐ الإدارة ممكن تسجّل «دولار→جنيه» بس. فالتحويل
        // «جنيه→دولار» لازم يشتغل بالأسinverse.
        ExchangeRate::create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50,
            'valid_date' => '2026-10-01',
        ]);

        $converted = ExchangeRate::convert(100, 'EGP', 'USD', '2026-10-01');
        $this->assertSame(2.0, (float) $converted);
    }

    public function test_a_missing_rate_fails_loudly(): void
    {
        // ⭐ أحسن من إننا نحسب صفر بالغلط
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('سعر صرف');
        ExchangeRate::convert(100, 'SAR', 'EGP', '2026-10-01');
    }

    // ============================================================
    // ⑤ قفل الفترات
    // ============================================================

    public function test_a_locked_month_blocks_new_movements(): void
    {
        $a = $this->makeAccount();
        $this->makeLock(); // قفل الشهر الحالي

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('مقفولة');
        $this->ledger()->recordIncome($a, 500, 'payment', 1);
    }

    public function test_the_lock_message_says_why(): void
    {
        // ⭐ رسالة من غير سبب = «الفترة مقفولة» ومفيش حد يفهم ليه
        $a = $this->makeAccount();
        $this->makeLock(['reason' => 'تم اعتماد مرتبات الشهر']);

        try {
            $this->ledger()->recordIncome($a, 500, 'payment', 1);
            $this->fail('كان المفروض يرمي');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('تم اعتماد مرتبات الشهر', $e->getMessage());
        }
    }

    public function test_a_month_lock_blocks_its_whole_range_including_both_edges(): void
    {
        // ⭐ شهر مارس = ١ → ٣١، فلو يوم ٣١ مسدول لازم يتقفل
        $a = $this->makeAccount();
        $this->makeLock([
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
        ]);

        foreach (['2026-03-01', '2026-03-15', '2026-03-31'] as $date) {
            try {
                $this->ledger()->recordIncome($a, 100, 'payment', 1, movementDate: $date);
                $this->fail("التاريخ {$date} كان المفروض يتقفل");
            } catch (\RuntimeException) {
                // متوقع
            }
        }

        // ⭐ بس شهر تاني برّه — مفتوح
        $this->ledger()->recordIncome($a, 100, 'payment', 1, movementDate: '2026-04-01');
        $this->assertSame(100.0, $a->fresh()->balance());
    }

    public function test_locking_one_account_does_not_block_the_others(): void
    {
        $locked = $this->makeAccount();
        $free = $this->makeAccount();

        $this->makeLock([
            'scope' => 'account',
            'financial_account_id' => $locked->id,
        ]);

        // ⭐ التانية مفتوحة
        $this->ledger()->recordIncome($free, 100, 'payment', 1);
        $this->assertSame(100.0, $free->fresh()->balance());

        // ⭐ واللي اتقفل رفض
        $this->expectException(\RuntimeException::class);
        $this->ledger()->recordIncome($locked, 100, 'payment', 1);
    }

    public function test_an_unlocked_period_allows_writing_again(): void
    {
        // ⭐ قرار الإدارة: **المدير بس** يفتح الفترة.
        $a = $this->makeAccount();
        $lock = $this->makeLock();

        // ⚠️ `unlocked_by` FK على `users` — لازم مستخدم حقيقي.
        // رقم وهمي كان بيرمي exception من الـ DB (وده سلوك
        // صحيح — الـ FK بيشتغل زي المفروض).
        $manager = $this->makeUser('المدير');

        $lock->unlock('تصحيح رقم في المرتبات', $manager->id);

        $this->assertNotNull($lock->fresh()->unlocked_at);

        $this->ledger()->recordIncome($a, 100, 'payment', 1);
        $this->assertSame(100.0, $a->fresh()->balance());
    }

    public function test_reversing_inside_a_locked_period_is_refused(): void
    {
        // ⭐ العكس بيغيّر أرقام — والقفل موجود عشان الأرقام اللي
        // اتفقنا عليها ماتتغيّرش.
        $a = $this->makeAccount(['opening_balance' => 5000]);
        $m = $this->makeMovement($a, [
            'direction' => 'in',
            'amount' => 1000,
            'movement_date' => now()->startOfMonth()->toDateString(),
        ]);

        $this->makeLock(['period_start' => now()->startOfMonth()->toDateString()]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('مقفولة');
        $this->ledger()->reverse($m, 'تصحيح');
    }

    // ============================================================
    // ⑥ التسجيل عبر الخدمة
    // ============================================================

    public function test_income_expense_and_teacher_payment_are_separate_categories(): void
    {
        // ⭐ التقرير لازم يفرق بين المصروفات ومستحقات المعلمين —
        // عشان كده类别 منفصلة، مش `expense` للكل.
        $a = $this->makeAccount(['opening_balance' => 10000]);

        $this->ledger()->recordIncome($a, 2000, 'payment', 1);
        $this->ledger()->recordExpense($a, 500, 'expense', 1);
        $this->ledger()->recordTeacherPayment($a, 300, 'teacher_payment', 1);

        $this->assertSame(11200.0, $a->fresh()->balance());
        $this->assertSame(1, $a->movements()->where('category', 'income')->count());
        $this->assertSame(1, $a->movements()->where('category', 'expense')->count());
        $this->assertSame(1, $a->movements()->where('category', 'teacher_payment')->count());
    }

    public function test_the_amount_is_stored_positive_whatever_the_input(): void
    {
        // ⭐ مبلغ سالب في دفتر الحركات = أول خطأ بيطلّع نتيجة غلط
        // في أي تقرير. الاتجاه في `direction` مش في إشارة المبلغ.
        $a = $this->makeAccount();

        $m = $this->ledger()->record(
            account: $a,
            direction: 'out',
            category: 'expense',
            amount: -250,
        );

        $this->assertSame(250.0, (float) $m->amount);
        $this->assertSame('out', $m->direction);
    }

    public function test_the_movement_links_back_to_its_source(): void
    {
        // ⭐ «كل حركات الفاتورة دي» — عشان نتفرج على تاريخ الدفعة
        $a = $this->makeAccount();

        $m = $this->ledger()->recordIncome($a, 300, 'payment', 77);

        $this->assertSame('payment', $m->reference_type);
        $this->assertSame(77, $m->reference_id);
    }

    public function test_the_currency_defaults_to_the_account_currency(): void
    {
        $a = $this->makeAccount(['currency' => 'EGP']);

        $m = $this->ledger()->recordIncome($a, 100, 'payment', 1);

        $this->assertSame('EGP', $m->currency);
    }
}