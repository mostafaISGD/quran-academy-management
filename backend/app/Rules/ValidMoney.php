<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ⭐ PR 2.1 — قاعدة التحقق من المبلغ.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ بتقبل رقم بالوحدة الكبرى (جنيه/دولار) وتتحقق إنه:
 *   • رقم فعلاً (numeric)
 *   • موجب أو صفر (مش سالب — المبالغ السالبة للمرتجعات بس)
 *   • ضمن الحد الأقصى (999,999,999.99 في أي عملة)
 *
 * ⭐ والتحويل لـ int (أصغر وحدة) بيحصل في الـ cast.
 */
class ValidMoney implements ValidationRule
{
    public function __construct(
        private readonly ?string $currency = null,
        private readonly bool $nullable = false,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            if ($this->nullable) {
                return;
            }

            $fail('المبلغ مطلوب.');

            return;
        }

        if (! is_numeric($value)) {
            $fail('المبلغ لازم يكون رقم.');

            return;
        }

        $numeric = (float) $value;

        if ($numeric < 0) {
            $fail('المبلغ ما ينفعش يكون سالب.');

            return;
        }

        $exponent = $this->exponent();

        // الحد الأقصى: 999,999,999 في الوحدة الكبرى
        $maxMajor = 999999999;

        if ($numeric > $maxMajor) {
            $fail("المبلغ أكبر من الحد المسموح ({$maxMajor}).");

            return;
        }

        // التحقق من عدد الخانات العشرية
        $decimalPart = $numeric - floor($numeric);
        $maxDecimals = $exponent;

        if ($maxDecimals === 0) {
            if ($decimalPart > 0) {
                $fail("العملة دي مفيهاش كسور ({$this->currency()}).");
            }

            return;
        }

        $stringValue = (string) $numeric;

        if (! str_contains($stringValue, '.')) {
            return;
        }

        $decimals = strlen(substr($stringValue, (int) strpos($stringValue, '.') + 1));

        if ($decimals > $maxDecimals) {
            $fail("الحد الأقصى {$maxDecimals} خانات عشرية للعملة {$this->currency()}.");
        }
    }

    private function currency(): string
    {
        return $this->currency ?? config('currencies.default', 'EGP');
    }

    private function exponent(): int
    {
        return config("currencies.exponents.{$this->currency()}", 2);
    }
}
