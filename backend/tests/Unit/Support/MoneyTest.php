<?php

namespace Tests\Unit\Support;

use App\Support\Money;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ⭐ PR 2.1 — كلاس المال.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة: **مافيش float في أي function عامة.** كل حاجة
 * بالأعداد الصحيحة بأصغر وحدة (القرش/السنت/الليس).
 *
 * ⭐ والـAPI كلها int|Money — مستحيل تخزن `0.1` بالخطأ.
 */
class MoneyTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // ① البناء والعمليات الأساسية
    // ═══════════════════════════════════════════════════════════

    public function test_it_can_be_created_from_an_integer_amount(): void
    {
        $money = Money::of(65000, 'EGP');

        $this->assertSame(65000, $money->amount);
        $this->assertSame('EGP', $money->currency);
    }

    public function test_addition_works_with_integers(): void
    {
        $a = Money::of(30000, 'EGP');
        $b = Money::of(20000, 'EGP');

        $this->assertSame(50000, $a->add($b)->amount);
    }

    public function test_subtraction_works(): void
    {
        $a = Money::of(50000, 'EGP');
        $b = Money::of(20000, 'EGP');

        $this->assertSame(30000, $a->sub($b)->amount);
    }

    public function test_it_rejects_adding_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(10000, 'EGP')->add(Money::of(10000, 'USD'));
    }

    public function test_it_rejects_subtracting_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(10000, 'EGP')->sub(Money::of(10000, 'USD'));
    }

    public function test_it_is_immutable(): void
    {
        $a = Money::of(10000, 'EGP');
        $b = $a->add(Money::of(5000, 'EGP'));

        $this->assertSame(10000, $a->amount, 'الأصلي ما اتغيرش');
        $this->assertSame(15000, $b->amount, 'الجديد ات created');
        $this->assertNotSame($a, $b, 'لازم يكون كائن جديد');
    }

    // ═══════════════════════════════════════════════════════════
    // ② الضرب بالنسبة + التقريب
    // ═══════════════════════════════════════════════════════════

    public function test_multiplication_by_a_ratio_works(): void
    {
        // 50% من 100000 = 50000
        $this->assertSame(
            50000,
            Money::of(100000, 'EGP')->multiply(1, 2)->amount
        );
    }

    public function test_multiplication_rounds_half_up(): void
    {
        // 1.5 → 2 (half-up)
        $this->assertSame(2, Money::of(150, 'EGP')->multiply(1, 100)->amount);
    }

    public function test_multiplication_rounds_half_up_for_exact_half(): void
    {
        // 2.5 → 3
        $this->assertSame(3, Money::of(250, 'EGP')->multiply(1, 100)->amount);
    }

    public function test_multiplication_rounds_down_when_below_half(): void
    {
        // 2.4 → 2
        $this->assertSame(2, Money::of(240, 'EGP')->multiply(1, 100)->amount);
    }

    public function test_multiplication_with_negative_amount(): void
    {
        // -2.5 → -3 (half-up بعيداً عن الصفر)
        $this->assertSame(-3, Money::of(-250, 'EGP')->multiply(1, 100)->amount);
    }

    public function test_multiplication_by_zero(): void
    {
        $this->assertSame(0, Money::of(99999, 'EGP')->multiply(0, 1)->amount);
    }

    public function test_multiplication_by_zero_denominator_throws(): void
    {
        $this->expectException(\DivisionByZeroError::class);

        Money::of(100, 'EGP')->multiply(1, 0);
    }

    // ═══════════════════════════════════════════════════════════
    // ③ القسمة والتوزيع
    // ═══════════════════════════════════════════════════════════

    public function test_division_distributes_remainder_to_first_shares(): void
    {
        $shares = Money::of(100, 'EGP')->divide(3);

        $this->assertCount(3, $shares);
        $this->assertSame(34, $shares[0]->amount, 'أول share بياخد الـremainder');
        $this->assertSame(33, $shares[1]->amount);
        $this->assertSame(33, $shares[2]->amount);
    }

    public function test_division_sum_equals_original(): void
    {
        $original = Money::of(100003, 'EGP');
        $shares = $original->divide(7);

        $sum = array_sum(array_map(fn ($m) => $m->amount, $shares));

        $this->assertSame($original->amount, $sum, 'المجموع لازم يفضل ثابت');
    }

    public function test_division_of_negative_amount(): void
    {
        $shares = Money::of(-100, 'EGP')->divide(3);

        $this->assertSame(-34, $shares[0]->amount);
        $this->assertSame(-33, $shares[1]->amount);
        $this->assertSame(-33, $shares[2]->amount);
    }

    public function test_division_by_zero_throws(): void
    {
        $this->expectException(\DivisionByZeroError::class);

        Money::of(100, 'EGP')->divide(0);
    }

    public function test_division_by_one_returns_single_share(): void
    {
        $shares = Money::of(50000, 'EGP')->divide(1);

        $this->assertCount(1, $shares);
        $this->assertSame(50000, $shares[0]->amount);
    }

    // ═══════════════════════════════════════════════════════════
    // ④ المقارنات
    // ═══════════════════════════════════════════════════════════

    public function test_equality(): void
    {
        $this->assertTrue(Money::of(50000, 'EGP')->equals(Money::of(50000, 'EGP')));
        $this->assertFalse(Money::of(50000, 'EGP')->equals(Money::of(50001, 'EGP')));
    }

    public function test_equality_rejects_different_currencies(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(50000, 'EGP')->equals(Money::of(50000, 'USD'));
    }

    public function test_comparison(): void
    {
        $a = Money::of(10000, 'EGP');
        $b = Money::of(20000, 'EGP');

        $this->assertSame(-1, $a->compare($b));
        $this->assertSame(1, $b->compare($a));
        $this->assertSame(0, $a->compare(Money::of(10000, 'EGP')));
    }

    public function test_is_negative_and_is_positive(): void
    {
        $this->assertTrue(Money::of(-1, 'EGP')->isNegative());
        $this->assertFalse(Money::of(0, 'EGP')->isNegative());
        $this->assertTrue(Money::of(1, 'EGP')->isPositive());
        $this->assertFalse(Money::of(0, 'EGP')->isPositive());
        $this->assertTrue(Money::of(0, 'EGP')->isZero());
    }

    // ═══════════════════════════════════════════════════════════
    // ⑤ التكوين
    // ═══════════════════════════════════════════════════════════

    public function test_currency_config_exists(): void
    {
        $currencies = config('currencies');

        $this->assertIsArray($currencies);
        $this->assertArrayHasKey('default', $currencies);
        $this->assertSame('EGP', $currencies['default']);
    }

    public function test_currency_exponents_are_defined(): void
    {
        $exponents = config('currencies.exponents');

        $this->assertSame(2, $exponents['EGP']);
        $this->assertSame(2, $exponents['USD']);
        $this->assertSame(2, $exponents['SAR']);
        $this->assertSame(3, $exponents['KWD']);
        $this->assertSame(0, $exponents['JPY']);
    }

    public function test_arabic_names_are_defined(): void
    {
        $names = config('currencies.names');

        $this->assertArrayHasKey('EGP', $names);
        $this->assertArrayHasKey('USD', $names);
        $this->assertNotEmpty($names['EGP']);
    }

    // ═══════════════════════════════════════════════════════════
    // ⑥ التنسيق
    // ═══════════════════════════════════════════════════════════

    public function test_format_uses_arabic_currency_name(): void
    {
        $formatted = Money::of(65000, 'EGP')->format();

        $this->assertStringContainsString('650', $formatted);
        $this->assertStringContainsString('ج.م', $formatted);
    }

    public function test_format_handles_zero(): void
    {
        $formatted = Money::of(0, 'EGP')->format();

        $this->assertStringContainsString('0', $formatted);
    }

    public function test_format_handles_small_amounts(): void
    {
        // 7 قرش = 0.07 جنيه
        $formatted = Money::of(7, 'EGP')->format();

        $this->assertStringContainsString('0.07', $formatted);
    }

    public function test_format_handles_three_decimal_currencies(): void
    {
        // KWD: 3 خانات عشرية
        $formatted = Money::of(1234, 'KWD')->format();

        $this->assertStringContainsString('1.234', $formatted);
    }

    public function test_format_handles_zero_decimal_currencies(): void
    {
        // JPY: مفيش خانات عشرية
        $formatted = Money::of(1500, 'JPY')->format();

        $this->assertStringContainsString('1,500', $formatted);
        $this->assertStringNotContainsString('.', $formatted);
    }
}
