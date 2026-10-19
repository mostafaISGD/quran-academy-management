<?php

namespace Tests\Feature;

use App\Casts\MoneyCast;
use App\Rules\ValidMoney;
use App\Support\Money;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * ⭐ PR 2.1 — اختبارات MoneyCast و ValidMoney.
 */
class MoneyCastAndRuleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // MoneyCast
    // ═══════════════════════════════════════════════════════════

    // ⭐⭐ PR 2.2 غيّر القرار: الـcast بيرجّع **نص** بالوحدة
    // الكبرى (زي `Piastres`) — عشان الـAPI contract ما يتغيّرش.
    // الـ`Money` object بقى للـAPI الجديد في PR 2.3.
    public function test_cast_get_returns_decimal_string_from_int(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        $this->assertSame(
            '650.00',
            $cast->get($model, 'amount', 65000, ['currency' => 'EGP'])
        );
    }

    public function test_cast_get_returns_null_for_null(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        $this->assertNull($cast->get($model, 'amount', null, []));
    }

    public function test_cast_set_accepts_money_object(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        $money = Money::of(65000, 'EGP');

        $this->assertSame(65000, $cast->set($model, 'amount', $money, []));
    }

    // ⭐⭐ الأرقام = **وحدة كبرى** دايماً (زي `Piastres` بالظبط).
    // القارئ الوحيد للأصغر هو `Money::of(65000, 'EGP')` — صريح.
    //
    // ⚠️ ده اللي بيمنعBug الـ100×: لو `1000` كانت تُقرأ كقرش،
    // القيمة هتطلع `10.00` جنيه بدل `1000.00`.
    public function test_cast_set_treats_numbers_as_major_units(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        // ⭐ `500` = خمسمائة جنيه (مش 500 قرش!)
        $this->assertSame(50000, $cast->set($model, 'amount', 500, []));

        // ⭐ والقرش الصريح لازم `Money` object
        $this->assertSame(
            50000,
            $cast->set($model, 'amount', Money::of(50000, 'EGP'), [])
        );
    }

    public function test_cast_set_converts_float_to_int(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        // 650.50 → 65050
        $this->assertSame(65050, $cast->set($model, 'amount', 650.50, ['currency' => 'EGP']));
    }

    public function test_cast_set_converts_string_to_int(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        $this->assertSame(100000, $cast->set($model, 'amount', '1000.00', ['currency' => 'EGP']));
    }

    public function test_cast_uses_default_currency_when_not_in_attributes(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        // ⭐ مافيش `currency` في الـattributes = الـdefault (EGP)
        $this->assertSame('500.00', $cast->get($model, 'amount', 50000, []));
    }

    public function test_cast_handles_different_exponents(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        // ⭐ KWD: 3 خانات عشرية → 1234.567 دينار
        $this->assertSame(
            '1234.567',
            $cast->get($model, 'amount', 1234567, ['currency' => 'KWD'])
        );

        // ⭐ JPY: صفر خانات → 1500 ين
        $this->assertSame(
            '1500',
            $cast->get($model, 'amount', 1500, ['currency' => 'JPY'])
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ⭐ PR 2.2 — مافيش float في أي مسار تحويل
    // ═══════════════════════════════════════════════════════════

    public function test_the_cast_never_uses_float_for_the_read_path(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        // ⭐ 0.07 * 3 = 0.21000000000000002 في float — في القرش مافيش
        // خطأ تمثيل إطلاقاً، فالنتيجة **بالظبط** 21.
        $this->assertSame('0.21', $cast->get($model, 'amount', 21, ['currency' => 'EGP']));
        $this->assertSame('0.07', $cast->get($model, 'amount', 7, ['currency' => 'EGP']));
        $this->assertSame('-65.00', $cast->get($model, 'amount', -6500, ['currency' => 'EGP']));
    }

    public function test_from_aggregate_converts_the_raw_integer(): void
    {
        // ⭐ ده الـ"فخّ الـaggregates": الـ`SUM` بيرجّع خام بالقرش
        $this->assertSame('500.00', MoneyCast::fromAggregate(50000));
        $this->assertSame('500.00', MoneyCast::fromAggregate(50000, 'EGP'));
        $this->assertNull(MoneyCast::fromAggregate(null));
    }

    public function test_a_hundred_thousand_writes_do_not_drift(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        // ⭐ 10,000 دورة حفظ/قراءة على نفس المبلغ — لازم يفضل ثابت
        $raw = 65000;

        for ($i = 0; $i < 10000; $i++) {
            $raw = $cast->set($model, 'amount', $cast->get($model, 'amount', $raw, []), []);
        }

        $this->assertSame(65000, $raw, 'المبلغ اتغيّر بعد 10,000 دورة');
    }

    // ═══════════════════════════════════════════════════════════
    // ValidMoney Rule
    // ═══════════════════════════════════════════════════════════

    public function test_rule_accepts_positive_number(): void
    {
        $rule = new ValidMoney(currency: 'EGP');

        $validator = Validator::make(['amount' => '650.50'], ['amount' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_rule_accepts_integer(): void
    {
        $rule = new ValidMoney(currency: 'EGP');

        $validator = Validator::make(['amount' => 1000], ['amount' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_rule_rejects_negative_number(): void
    {
        $rule = new ValidMoney(currency: 'EGP');

        $validator = Validator::make(['amount' => '-50'], ['amount' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_rule_rejects_non_numeric(): void
    {
        $rule = new ValidMoney(currency: 'EGP');

        $validator = Validator::make(['amount' => 'abc'], ['amount' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_rule_rejects_null_when_not_nullable(): void
    {
        $rule = new ValidMoney(currency: 'EGP', nullable: false);

        $validator = Validator::make(['amount' => null], ['amount' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_rule_accepts_null_when_nullable(): void
    {
        $rule = new ValidMoney(currency: 'EGP', nullable: true);

        $validator = Validator::make(['amount' => null], ['amount' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_rule_rejects_too_many_decimals(): void
    {
        $rule = new ValidMoney(currency: 'EGP');

        $validator = Validator::make(['amount' => '100.999'], ['amount' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_rule_rejects_amount_above_max(): void
    {
        $rule = new ValidMoney(currency: 'EGP');

        $validator = Validator::make(['amount' => '1000000000'], ['amount' => [$rule]]);

        $this->assertFalse($validator->passes());
    }

    public function test_rule_accepts_zero(): void
    {
        $rule = new ValidMoney(currency: 'EGP');

        $validator = Validator::make(['amount' => '0'], ['amount' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_rule_allows_decimals_for_jpy(): void
    {
        $rule = new ValidMoney(currency: 'JPY');

        $validator = Validator::make(['amount' => '1000'], ['amount' => [$rule]]);

        $this->assertTrue($validator->passes());
    }

    public function test_rule_rejects_decimals_for_jpy(): void
    {
        $rule = new ValidMoney(currency: 'JPY');

        $validator = Validator::make(['amount' => '1000.50'], ['amount' => [$rule]]);

        $this->assertFalse($validator->passes());
    }
}
