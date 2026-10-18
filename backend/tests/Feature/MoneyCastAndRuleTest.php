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

    public function test_cast_get_returns_money_from_int(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        $money = $cast->get($model, 'amount', 65000, ['currency' => 'EGP']);

        $this->assertInstanceOf(Money::class, $money);
        $this->assertSame(65000, $money->amount);
        $this->assertSame('EGP', $money->currency);
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

    public function test_cast_set_accepts_int(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        $this->assertSame(50000, $cast->set($model, 'amount', 50000, []));
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

        $money = $cast->get($model, 'amount', 50000, []);

        $this->assertSame('EGP', $money->currency);
    }

    public function test_cast_handles_different_exponents(): void
    {
        $cast = new MoneyCast();
        $model = new class extends \Illuminate\Database\Eloquent\Model {};

        // KWD: 3 decimal places
        $money = $cast->get($model, 'amount', 1234567, ['currency' => 'KWD']);

        $this->assertSame(1234567, $money->amount);
        $this->assertSame('KWD', $money->currency);
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
