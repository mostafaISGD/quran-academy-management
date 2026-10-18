<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * ⭐ PR 2.1 — كائن المال غير القابل للتغيير.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ **القاعدة الذهبية: مافيش float في أي function عامة.**
 *
 * كل المبالغ مخزّنة كـ `int` بأصغر وحدة (قرش/سنت/ليس).
 * العمليات الحسابية كلها بـ `intdiv` والضرب الصحيح — مفيش
 * `0.1 + 0.2 = 0.30000000000000004` هنا أبداً.
 *
 * ⭐ ليه value object immutable؟ عشان لو حد عمل `$a->amount = 500`
 * من غير ما يبني كائن جديد، بيتغلط كل حاجة كانت ناسخة نفس الكائن.
 *
 * ⚠️ الاستثناء الوحيد: `format()` بترجع string للعرض بس — مش
 * للقاعدة. القاعدة دايماً int.
 */
final class Money
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {}

    /**
     * إنشاء من المبلغ بالوحدة الصغرى (القرش/السنت/الليس).
     */
    public static function of(int $amount, string $currency): self
    {
        return new self($amount, strtoupper($currency));
    }

    /**
     * الجمع — نفس العملة فقط.
     */
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * الطرح — نفس العملة فقط.
     */
    public function sub(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount - $other->amount, $this->currency);
    }

    /**
     * الضرب بنسبة (numerator/denominator) مع تقريب half-up.
     *
     * ⭐ بنستخدم `int` للبسط والمقام عشان نتجنب الـfloat نهائياً.
     * مثال: `multiply(1, 2)` = 50% من المبلغ.
     */
    public function multiply(int $numerator, int $denominator): self
    {
        if ($denominator === 0) {
            throw new \DivisionByZeroError('Money::multiply() denominator cannot be zero.');
        }

        $scaled = $this->amount * $numerator;
        $rounded = self::roundHalfUp($scaled, $denominator);

        return new self($rounded, $this->currency);
    }

    /**
     * القسمة على N أجزاء — الباقي بيتتوزع على الأول.
     *
     * مثال: 100 على 3 → 34 + 33 + 33 = 100 ✅
     */
    public function divide(int $parts): array
    {
        if ($parts <= 0) {
            throw new \DivisionByZeroError('Money::divide() parts must be positive.');
        }

        $base = intdiv($this->amount, $parts);
        $remainder = $this->amount % $parts;
        $sign = $remainder > 0 ? 1 : ($remainder < 0 ? -1 : 0);
        $absRemainder = abs($remainder);

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $extra = $i < $absRemainder ? $sign : 0;
            $result[] = new self($base + $extra, $this->currency);
        }

        return $result;
    }

    /**
     * مقارنة: -1 (أصغر) / 0 (مساوي) / 1 (أكبر).
     */
    public function compare(self $other): int
    {
        $this->assertSameCurrency($other);

        return $this->amount <=> $other->amount;
    }

    public function equals(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount === $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isNegative(): bool
    {
        return $this->amount < 0;
    }

    /**
     * تنسيق للعرض: "650.00 ج.م."
     */
    public function format(): string
    {
        $exponent = config("currencies.exponents.{$this->currency}", 2);
        $name = config("currencies.names.{$this->currency}", $this->currency);

        if ($exponent === 0) {
            return number_format($this->amount, 0, '.', ',').' '.$name;
        }

        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);

        $major = intdiv($abs, 10 ** $exponent);
        $minor = $abs % (10 ** $exponent);

        return $sign.number_format($major, 0, '.', ',')
            .'.'.str_pad((string) $minor, $exponent, '0', STR_PAD_LEFT)
            .' '.$name;
    }

    /**
     * إنشاء من الوحدة الكبرى (جنيه/دولار) — للتحويل من الإدخال.
     */
    public static function fromMajor(int|float $major, string $currency): self
    {
        $exponent = config("currencies.exponents.{$currency}", 2);
        $factor = 10 ** $exponent;

        $cents = (int) round($major * $factor);

        return self::of($cents, $currency);
    }

    // ═══════════════════════════════════════════════════════════
    // أدوات خاصة
    // ═══════════════════════════════════════════════════════════

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "لا يمكن التعامل بعملتين مختلفتين: {$this->currency} ≠ {$other->currency}"
            );
        }
    }

    /**
     * تقريب half-up للقسمة: round(n/d) بدون float.
     */
    private static function roundHalfUp(int $numerator, int $denominator): int
    {
        if ($numerator >= 0) {
            return intdiv($numerator * 2 + $denominator, 2 * $denominator);
        }

        return -intdiv(-$numerator * 2 + $denominator, 2 * $denominator);
    }
}
