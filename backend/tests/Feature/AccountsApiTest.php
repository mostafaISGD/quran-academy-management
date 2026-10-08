<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Models\PeriodLock;
use Tests\TestCase;

/**
 * ⭐⭐ واجهة الخزائن ودفتر الحركات.
 *
 * ⭐ **اللي بيحصل في الملف ده:** الأدمن بيفتح «الخزائن» عشان
 * يعرف «معانا فلوس كام» و«فين الفلوس تتحرك».
 *
 * ⭐ **أهم قرار في الملف:** الحساب (accountant) **يقدر** يشوف
 * Accounts ويعمل تحويل ويموت بـ 🔒 على الأرقام — بس **مش**
 * يقدر يعكس حركة ولا يفتح فترة. فالاختبارات بتقفل الفصل ده
 * مرتين: من ناحية الصلاحية، ومن ناحية إن الأرقام مابتفعش
 * من التحويل.
 */
class AccountsApiTest extends TestCase
{
    private function manager()
    {
        return $this->makeUserWithRole('admin', [
            'finance.view', 'finance.accounts', 'finance.movements',
            'finance.reverse', 'finance.periods.lock', 'finance.periods.unlock',
        ]);
    }

    private function accountant()
    {
        return $this->makeUserWithRole('accountant', [
            'finance.view', 'finance.accounts', 'finance.movements',
            'finance.periods.lock',
        ]);
    }

    /**
     * ⭐⭐ مقارنة مبلغ — **مش** `assertSame`.
     *
     * ⭐ **السبب:** JSON **مافيهوش** فرق بين `1500` و `1500.0`.
     * فلو الحساب رجع `1500` (رقم صحيح) والاختبار expects `1500.0`
     * بـ `assertSame`، الاختبار **بيفشل** والرقم **صح**.
     *
     * فالمقارنة المالية هنا **رقمية** (float)، وده الصح — القرش
     * بيساوي القرش سواء اتكتب `1500` ولا `1500.00`.
     *
     * @param  float|int  $expected
     */
    private function assertMoney($expected, $actual, string $message = ''): void
    {
        $this->assertEquals((float) $expected, (float) $actual, $message);
    }

    // ============================================================
    // ① الحسابات والأرصدة
    // ============================================================

    public function test_the_list_shows_each_account_with_its_computed_balance(): void
    {
        $cash = $this->makeAccount(['name' => 'الخزينة', 'opening_balance' => 1000]);
        $bank = $this->makeAccount(['name' => 'البنك', 'type' => 'bank', 'opening_balance' => 1000]);

        $this->makeMovement($cash, ['direction' => 'in', 'category' => 'income', 'amount' => 500]);
        $this->makeMovement($bank, ['direction' => 'out', 'category' => 'expense', 'amount' => 200]);

        $r = $this->getJsonAs('/api/accounts', $this->manager());

        $r->assertOk();
        $this->assertCount(2, $r->json('data'));

        $first = $r->json('data.0');
        $this->assertSame('الخزينة', $first['name']);
        $this->assertSame('نقدية', $first['type_label']);
        $this->assertMoney(1500, $first['balance']);
        $this->assertMoney(500, $first['total_in']);
        $this->assertMoney(0, $first['total_out']);

        $this->assertMoney(800, $r->json('data.1.balance'));
    }

    public function test_totals_are_grouped_by_currency_not_summed_into_one(): void
    {
        // ⭐⭐ الحسابات ممكن تعمل عملات مختلفة. جمعهم في رقم واحد
        // بيحتاج سعر صرف، والسعر بيتغيّر — فن-display رقم
        // مضلّل. الرد بيرجع الإجمالي **لكل عملة لوحدها**.
        $this->makeAccount(['currency' => 'EGP', 'opening_balance' => 100]);
        $this->makeAccount(['name' => 'دولار', 'currency' => 'USD', 'opening_balance' => 20]);

        $r = $this->getJsonAs('/api/accounts', $this->manager());

        $this->assertMoney(100, $r->json('meta.by_currency.EGP'));
        $this->assertMoney(20, $r->json('meta.by_currency.USD'));

        // ⭐ `meta.total` = **عدد الحسابات** (٢)، مش مجموع الجنيه.
        // الرقم المحاسبي المجمع هو اللي في `by_currency`.
        $this->assertSame(2, $r->json('meta.total'));
    }

    public function test_pending_movements_do_not_count_toward_the_balance(): void
    {
        // ⭐⭐ المصروف المعلّق موجود في الدفتر بس الفلوس لسه ما
        // خرجتش. لو حسبناه كان الرصيد أقل من الحقيقي والأدمن
        // بيفتكر إن في مصروف طلع وهو لسه معتمد.
        $account = $this->makeAccount(['opening_balance' => 2000]);
        $this->makeMovement($account, ['direction' => 'out', 'category' => 'expense', 'amount' => 800, 'status' => 'pending']);

        $r = $this->getJsonAs('/api/accounts', $this->manager());

        $this->assertMoney(2000, $r->json('data.0.balance'));
        // ⭐ بس الصفحة لازم **تقول** إن في سطر معلق
        $this->assertSame(1, $r->json('data.0.pending_count'));
        $this->assertSame(1, $r->json('data.0.movements_count'));
    }

    public function test_inactive_accounts_come_last_not_hidden(): void
    {
        // ⭐ الحساب المقفول بيظهر — بس في الآخر. عشان الأدمن
        // يشوف «فيه خزينة قديمة»، بس مايتلخبطش ويحسبها نشطة.
        $this->makeAccount(['name' => 'نشطة']);
        $this->makeAccount(['name' => 'مقفولة', 'status' => 'inactive']);

        $r = $this->getJsonAs('/api/accounts', $this->manager());

        $this->assertSame('نشطة', $r->json('data.0.name'));
        $this->assertSame('مقفولة', $r->json('data.1.name'));
        $this->assertSame('inactive', $r->json('data.1.status'));
    }

    public function test_the_opening_balance_cannot_be_edited_after_creation(): void
    {
        // ⭐⭐⭐ **قاعدة محاسبية أساسية.**
        //
        // الرصيد الافتتاحي جزء من **تاريخ الحساب**. لو حد غيّره،
        // الأرصدة القديمة هتتغيّر بأثر رجعي والضريبة جت.
        //
        // التصحيح الصح = **تسوية** في الدفتر (سطر وسبب)، مش
        // تعديل رقم قديم.
        $account = $this->makeAccount([
            'name' => 'الخزينة الأصلية',
            'opening_balance' => 1000,
        ]);

        $r = $this->putJsonAs("/api/accounts/{$account->id}", [
            'name' => 'الخزينة المعدّلة',
            'opening_balance' => 5000,
        ], $this->manager());

        $r->assertStatus(422);
        $this->assertStringContainsString('تسوية', $r->json('message'), 'الرد بيقول الحل');

        // ⭐⭐ **الطلب كله اترفض — ذرّياً.**
        //
        // الاسم كمان مااتغيرش. وده المطلوب: لو غيّرنا الاسم
        // وبطلنا الرصيد، المستخدم يبقى شايف «اتحفظ» وجزء اتغيّر
        // وجزء لأ من غير ما يعرف.
        $account->refresh();
        $this->assertSame('الخزينة الأصلية', $account->name, 'الطلب اترفض كله');
        $this->assertSame(1000.0, (float) $account->opening_balance);
    }

    public function test_the_accountant_can_create_an_account(): void
    {
        $r = $this->postJsonAs('/api/accounts', [
            'name' => 'خزينة الفرع',
            'type' => 'cash',
            'currency' => 'EGP',
            'opening_balance' => 200,
        ], $this->accountant());

        $r->assertCreated();
        $this->assertMoney(200, $r->json('data.balance'));
        $this->assertDatabaseHas('financial_accounts', ['name' => 'خزينة الفرع']);
    }

    // ============================================================
    // ② دفتر الحركات
    // ============================================================

    public function test_the_ledger_shows_the_period_totals(): void
    {
        $cash = $this->makeAccount();
        $bank = $this->makeAccount(['type' => 'bank']);

        $this->makeMovement($cash, ['direction' => 'in', 'category' => 'income', 'amount' => 1000]);
        $this->makeMovement($cash, ['direction' => 'in', 'category' => 'income', 'amount' => 500]);
        $this->makeMovement($bank, ['direction' => 'out', 'category' => 'teacher_payment', 'amount' => 700]);
        $this->makeMovement($bank, ['direction' => 'out', 'category' => 'refund', 'amount' => 300]);

        $r = $this->getJsonAs('/api/accounts/movements', $this->manager());

        $r->assertOk();
        $this->assertSame(4, $r->json('meta.total'));
        // ⭐ الإجماليات محسوبة في **قاعدة البيانات** — مش في
        // المتصفح. صفحة 100 صف مش كل الجدول، فلو جمعنا في
        // المتصفح الرقم كان غلط.
        $this->assertMoney(1500, $r->json('meta.totals.in'));
        $this->assertMoney(1000, $r->json('meta.totals.out'));
    }

    public function test_movement_categories_get_arabic_labels(): void
    {
        // ⭐ `teacher_payment` جواها underscore وإنجليزي — لو
        // عرضناها زي ما هي، المستخدم يشوف كلمة مش هيفهمها.
        $account = $this->makeAccount();
        $this->makeMovement($account, ['direction' => 'out', 'category' => 'teacher_payment', 'amount' => 100]);

        $r = $this->getJsonAs('/api/accounts/movements', $this->manager());

        $this->assertSame('دفعة لمعلم', $r->json('data.0.category_label'));
    }

    public function test_a_reversed_movement_is_flagged_without_hiding_the_original(): void
    {
        // ⭐⭐ **قاعدة الدفتر:** السطر الأصلي **ما بيتلمسش**.
        // بيتعمل سطر عكسي. فالصفحة لازم تبيّن «معكوس» — لأن
        // الأدمن لازم يعرف إن الرقم ده اتصحّح، مش إنه ناقص.
        $account = $this->makeAccount(['opening_balance' => 1000]);
        $movement = $this->makeMovement($account, ['direction' => 'in', 'category' => 'income', 'amount' => 500]);

        app(\App\Services\FinancialLedgerService::class)->reverse(
            $movement,
            'اتسجل غلط',
            $this->manager(),
        );

        $r = $this->getJsonAs('/api/accounts/movements', $this->manager());

        $reversal = $r->json('data.0');
        $original = $r->json('data.1');

        // ⭐⭐ **الاتجاه بس** اللي بيتقلب — الفئة بتفضل زي ما هي.
        //
        // السبب: تقرير «الدخل» بيجمع `income`. لو خلينا العكس
        // فئته `adjustment`، التقرير هيبقى شايف 500 دخل **لسه**
        // والـ adjustment مش هيلغيها. فالفئة لازم تفضل
        // `income` والاتجاه `out` — فالجمع cancels.
        $this->assertSame('income', $reversal['category'], 'الفئة بتفضل زي ما هي');
        $this->assertSame('out', $reversal['direction'], 'الاتجاه المضاد');
        $this->assertFalse($reversal['is_reversed'], 'العكس نفسه مش معكوس');
        $this->assertNotNull($reversal['reversal_of']);

        $this->assertTrue($original['is_reversed']);
        $this->assertTrue($original['has_reversal']);
        // ⭐ والأصلي لسه `posted` — مااتغيرش لـ reversed
        $this->assertSame('posted', $original['status']);
        $this->assertSame('in', $original['direction']);

        // ⭐ والنتيجة: الرصيد رجع زي ما كان
        $this->assertMoney(1000, $account->fresh()->balance());
    }

    public function test_the_ledger_can_be_filtered_by_account_and_direction(): void
    {
        $cash = $this->makeAccount(['name' => 'خزينة']);
        $bank = $this->makeAccount(['name' => 'بنك', 'type' => 'bank']);

        $this->makeMovement($cash, ['direction' => 'in', 'category' => 'income', 'amount' => 100]);
        $this->makeMovement($bank, ['direction' => 'out', 'category' => 'expense', 'amount' => 50]);

        $r = $this->getJsonAs("/api/accounts/movements?account_id={$bank->id}", $this->manager());
        $this->assertSame(1, $r->json('meta.total'));
        $this->assertSame('بنك', $r->json('data.0.account.name'));

        $r = $this->getJsonAs('/api/accounts/movements?direction=in', $this->manager());
        $this->assertSame(1, $r->json('meta.total'));
    }

    // ============================================================
    // ③ التحويل — الخطر الأكبر في السطح ده
    // ============================================================

    public function test_a_transfer_moves_money_without_touching_total_net(): void
    {
        // ⭐⭐⭐ **الاختبار الأهم في الملف.**
        //
        // التحويل بين الخزينة والبنك **مش** إيراد ومش مصروف. فلو
        // اتحسب كـ `income` و `expense`، تقرير «الدخل» هيبقى
        // غلط والتشغيل هيبقى غلط.
        //
        // الناتج: الخزينة ناقصة، البنك زائد، بس **الصافي ثابت**.
        $cash = $this->makeAccount(['name' => 'خزينة', 'opening_balance' => 1000]);
        $bank = $this->makeAccount(['name' => 'بنك', 'type' => 'bank', 'opening_balance' => 0]);

        $r = $this->postJsonAs('/api/accounts/transfer', [
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'amount' => 400,
            'reference' => 'إيداع في البنك',
        ], $this->accountant());

        $r->assertCreated();
        $this->assertMoney(400, $r->json('data.amount'));
        // ⭐ الفئات **مفصولة** عن الإيراد والمصروف
        $this->assertMoney(600, $r->json('data.from.balance'));
        $this->assertMoney(400, $r->json('data.to.balance'));

        $in = AccountMovement::where('category', 'transfer_in')->first();
        $out = AccountMovement::where('category', 'transfer_out')->first();

        $this->assertNotNull($in);
        $this->assertNotNull($out);
        $this->assertSame('in', $in->direction);
        $this->assertSame('out', $out->direction);
        $this->assertSame('transfer_in', $in->category);
        $this->assertSame('transfer_out', $out->category);

        // ⭐⭐ ومفيش ولا سطر إيراد أو مصروف اتعمل
        $this->assertSame(0, AccountMovement::where('category', 'income')->count());
        $this->assertSame(0, AccountMovement::where('category', 'expense')->count());

        // ⭐ والصافي **ثابت**: 1000 قبل، 1000 بعد
        $this->assertMoney(1000, $cash->fresh()->balance() + $bank->fresh()->balance());
    }

    public function test_a_transfer_cannot_exceed_the_available_balance(): void
    {
        // ⭐ فلوس الخزينة لازم تغطي التحويل. من غير ده الخزينة
        // بتروح سالبة والتقرير بيقول إن الأكاديمية اقترضت من
        // غير ما حد قال ذلك.
        $cash = $this->makeAccount(['opening_balance' => 100]);
        $bank = $this->makeAccount(['type' => 'bank']);

        $r = $this->postJsonAs('/api/accounts/transfer', [
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'amount' => 500,
        ], $this->accountant());

        $r->assertStatus(422);
        $this->assertSame(0, AccountMovement::count(), 'مفيش حركة اتعملت');
        $this->assertMoney(100, $cash->fresh()->balance());
    }

    public function test_a_transfer_needs_two_different_accounts(): void
    {
        // ⭐ نفس الحساب — معناه «حرّك فلوسك من نفسك لنفسك»،
        // وبيعمل رصيد غلط لو اتقبل.
        $account = $this->makeAccount(['opening_balance' => 1000]);

        $r = $this->postJsonAs('/api/accounts/transfer', [
            'from_account_id' => $account->id,
            'to_account_id' => $account->id,
            'amount' => 100,
        ], $this->accountant());

        $r->assertStatus(422);
        $this->assertStringContainsString('مختلفين', $r->json('message'));
        $this->assertMoney(1000, $account->fresh()->balance());
    }

    public function test_a_transfer_into_a_locked_period_is_rejected_with_an_arabic_reason(): void
    {
        $cash = $this->makeAccount(['opening_balance' => 1000]);
        $bank = $this->makeAccount(['type' => 'bank']);
        $this->makeLock(['reason' => 'تم اعتماد مرتبات الشهر']);

        $r = $this->postJsonAs('/api/accounts/transfer', [
            'from_account_id' => $cash->id,
            'to_account_id' => $bank->id,
            'amount' => 400,
            'transfer_date' => now()->toDateString(),
        ], $this->accountant());

        $r->assertStatus(422);
        $this->assertStringContainsString('مقفولة', $r->json('message'));
        $this->assertStringContainsString('مرتبات', $r->json('message'), 'السبب يوصل للمستخدم');
        $this->assertSame(0, AccountMovement::count());
    }

    // ============================================================
    // ④ الفصل بين الأدوار — قرار الإدارة
    // ============================================================

    public function test_the_accountant_cannot_reverse_a_movement(): void
    {
        // ⭐⭐ قرار الإدارة: العكس **للمدير بس**.
        // السبب: العكس بيلغي رقم متفق عليه — حد عادي يقدرده
        // يمسح أثر مالي ويخفي فرق.
        $account = $this->makeAccount(['opening_balance' => 1000]);
        $movement = $this->makeMovement($account, ['direction' => 'in', 'category' => 'income', 'amount' => 500]);

        $r = $this->postJsonAs("/api/movements/{$movement->id}/reverse", [
            'reason' => 'مش عايزه',
        ], $this->accountant());

        $r->assertForbidden();
        $this->assertSame(0, AccountMovement::where('reverses_movement_id', $movement->id)->count());
        $this->assertMoney(1500, $account->fresh()->balance(), 'الرصيد ما اتغيّرش بعد رفض العكس');
    }

    public function test_the_manager_can_reverse_but_must_give_a_reason(): void
    {
        $account = $this->makeAccount(['opening_balance' => 1000]);
        $movement = $this->makeMovement($account, ['direction' => 'in', 'category' => 'income', 'amount' => 500]);

        // ⭐ من غير سبب، مينفعش نعرف بعد سنة ليه اتصحّح الرقم
        $r = $this->postJsonAs("/api/movements/{$movement->id}/reverse", [], $this->manager());
        $r->assertStatus(422);
        $this->assertStringContainsString('سبب العكس إجباري', $r->json('message'));

        $r = $this->postJsonAs("/api/movements/{$movement->id}/reverse", [
            'reason' => 'الدفعة اتسجلت على الطالب الغلط',
        ], $this->manager());

        $r->assertCreated();
        $this->assertMoney(1000, $account->fresh()->balance());
    }

    public function test_the_accountant_cannot_unlock_a_period(): void
    {
        $lock = $this->makeLock();

        $this->postJsonAs("/api/period-locks/{$lock->id}/unlock", [
            'reason' => 'عايز أعدل',
        ], $this->accountant())->assertForbidden();

        $this->assertTrue($lock->fresh()->isActive());
        $this->assertNull($lock->fresh()->unlocked_at);
    }

    public function test_the_accountant_can_lock_a_period(): void
    {
        // ⭐ القفل **مسموح** للحساب — لأنه بيحمي الدفاتر.
        // الفتح بس هو اللي محجوز للمدير.
        $r = $this->postJsonAs('/api/period-locks', [
            'start' => now()->startOfMonth()->toDateString(),
            'end' => now()->endOfMonth()->toDateString(),
            'reason' => 'قفل تجريبي',
        ], $this->accountant());

        $r->assertCreated();
        $this->assertDatabaseHas('period_locks', ['reason' => 'قفل تجريبي']);
    }

    public function test_unlocking_keeps_the_original_lock_recorded(): void
    {
        // ⭐⭐ **مين فتح وإمتى** معلومة محاسبية. فبنضيف `unlocked_by`
        // بدل ما نمسح القفل — سجل القفل الأولاني يفضل موجود.
        $lock = $this->makeLock(['reason' => 'قفل أول']);

        $r = $this->postJsonAs("/api/period-locks/{$lock->id}/unlock", [
            'reason' => 'صححنا الغلط',
        ], $this->manager());

        $r->assertOk();
        $lock->refresh();

        $this->assertFalse($lock->isActive());
        $this->assertSame('قفل أول', $lock->reason);
        $this->assertSame('صححنا الغلط', $lock->unlock_reason);
        $this->assertNotNull($lock->locked_at);
        $this->assertNotNull($lock->unlocked_at);
    }

    public function test_unlocking_twice_is_rejected_with_a_clear_message(): void
    {
        // ⭐ دبل-كليك مايرميش 500.
        $lock = $this->makeLock();
        $this->postJsonAs("/api/period-locks/{$lock->id}/unlock", [
            'reason' => 'أول مرة',
        ], $this->manager())->assertOk();

        $r = $this->postJsonAs("/api/period-locks/{$lock->id}/unlock", [
            'reason' => 'تاني مرة',
        ], $this->manager());

        $r->assertStatus(422);
        $this->assertStringContainsString('مفتوحة', $r->json('message'));
    }

    public function test_a_lock_on_one_account_does_not_block_another(): void
    {
        // ⭐⭐ قفل الحساب **مش** قفل الأكاديمية كلها. من غير ده
        // قفل خزينة واحدة بيقفل التحصيل كله.
        $locked = $this->makeAccount(['name' => 'الخزينة المقفولة', 'opening_balance' => 1000]);
        $bank = $this->makeAccount(['name' => 'بنك', 'type' => 'bank', 'opening_balance' => 500]);
        $wallet = $this->makeAccount(['name' => 'محفظة', 'type' => 'wallet']);

        PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->startOfMonth()->toDateString(),
            end: now()->endOfMonth()->toDateString(),
            scope: 'account',
            accountId: $locked->id,
            userId: $this->manager()->id,
        );

        // ⭐ حسابين تانيين مش مقفولين → التحويل يمشي عادي
        $r = $this->postJsonAs('/api/accounts/transfer', [
            'from_account_id' => $bank->id,
            'to_account_id' => $wallet->id,
            'amount' => 100,
        ], $this->manager());

        $r->assertCreated();
    }

    public function test_a_locked_account_is_blocked_in_both_directions(): void
    {
        // ⭐⭐ القفل بيمنع الحساب **كمصدر أو وجهة**.
        //
        // لوTraversal► سمحنا التحويل **لـ** حساب مقفول، الحساب
        // المقفول رصيده بيتغير — فمعنى القفل ضاع.
        $locked = $this->makeAccount(['name' => 'الخزينة المقفولة', 'opening_balance' => 1000]);
        $bank = $this->makeAccount(['name' => 'بنك', 'type' => 'bank', 'opening_balance' => 500]);

        PeriodLock::lock(
            organizationId: $this->org->id,
            start: now()->startOfMonth()->toDateString(),
            end: now()->endOfMonth()->toDateString(),
            scope: 'account',
            accountId: $locked->id,
            userId: $this->manager()->id,
        );

        // ⭐ من المقفول ← مرفوض
        $this->postJsonAs('/api/accounts/transfer', [
            'from_account_id' => $locked->id,
            'to_account_id' => $bank->id,
            'amount' => 100,
        ], $this->manager())->assertStatus(422);

        // ⭐ إلى المقفول ← مرفوض برضه
        $this->postJsonAs('/api/accounts/transfer', [
            'from_account_id' => $bank->id,
            'to_account_id' => $locked->id,
            'amount' => 100,
        ], $this->manager())->assertStatus(422);

        $this->assertSame(0, AccountMovement::count(), 'مفيش تحويل اتنفذ');
        $this->assertMoney(1000, $locked->fresh()->balance());
        $this->assertMoney(500, $bank->fresh()->balance());
    }

    // ============================================================
    // ⑤ العزل بين المنظمات
    // ============================================================

    public function test_an_account_from_another_organization_is_invisible(): void
    {
        // ⭐⭐ أي leak بين المنظمات = حد يشوف فلوس حد تاني.
        $account = $this->makeAccount(['name' => 'خزينة الأكس']);

        // ⭐ الاختبارات كلها بتعمل المستخدم في أكاديمية الاختبار،
        // فبنعمل أكاديمية تانية ونحطه فيها.
        $otherOrg = \App\Models\Organization::create([
            'name' => 'أكاديمية تانية',
            'slug' => 'other-org-'.uniqid(),
        ]);

        $other = $this->makeUserWithRole('admin', [
            'finance.view', 'finance.accounts', 'finance.movements',
        ]);
        $other->forceFill(['organization_id' => $otherOrg->id])->save();

        $this->getJsonAs("/api/accounts/{$account->id}", $other)->assertNotFound();
        $this->putJsonAs("/api/accounts/{$account->id}", ['name' => 'اختراق'], $other)
            ->assertNotFound();

        // ⭐ والتعديل بالـ route Model Binding **مش** بيرمي 403
        // (-performance guard) ولا 500 — بيرمي 404 عشان ما نقولش
        // للمستخدم «الحساب موجود بس مش ليك».
        $r = $this->getJsonAs('/api/accounts/movements', $other);
        $r->assertOk();
        $this->assertSame(0, $r->json('meta.total'));
    }
}
