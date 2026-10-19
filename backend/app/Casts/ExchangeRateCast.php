<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ PR 2.5 — cast سعر الصرف.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ السعر **مش مبلغ**، لكن لايزال float فيه يعني خطأ تمثيل.
 *
 *     48.5 * 100 = 4850.000000000001   ⭐ مش 4850
 *
 * ⭐ فبنخزّنه كـ **int × 10^6** (6 خانات، زي decimal(18,6)).
 * والسعر المطبَّق بيطلع `48500000` → قسمة على `10^6` وقت
 * التحويل.
 *
 * ⚠️ **الفرق عن `MoneyCast`:** السعر بيتقار من الـAPI كنص،
 * `MoneyCast` بترجّع نص بالوحدة الكبرى. هنا نفس الشكل بالظبط —
 * `MoneyCast` مش هتشتغل لأن أساسها 2 مش 6.
 *
 * @implements CastsAttributes<string|null, string|float|int|null>
 */
class ExchangeRateCast implements CastsAttributes
{
    public const SCALE = 1_000_000; // 10^6

    /**
     * ⭐ `48500000` (int) → `"48.500000"`
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $scaled = (int) $value;
        $sign = $scaled < 0 ? '-' : '';
        $abs = abs($scaled);

        return $sign
            . intdiv($abs, self::SCALE)
            . '.'
            . str_pad((string) ($abs % self::SCALE), 6, '0', STR_PAD_LEFT);
    }

    /**
     * ⭐ `"48.500000"` أو `48.5` → `48500000` (int)
     *
     * ⚠️ `round()` **قبل** الـint cast — نفس سبب `MoneyCast`:
     * `48.5 * 10^6 = 48499999.99999999` من غير تقريب.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) round(((float) $value) * self::SCALE);
    }

    /**
     * ⭐ السعر المطبَّق كـ **نسبة** — للخدمات اللي بتحسب.
     *
     * ⭐ بيرجّع int مقيس (numerator/denominator) عشان نضمن
     * التقريب half-up بلا float:
     *
     *     Money::of(10000, 'USD')->multiply(48500000, 1000000)
     */
    public static function toScaledPair(mixed $raw): ?array
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return [(int) $raw, self::SCALE];
    }
}