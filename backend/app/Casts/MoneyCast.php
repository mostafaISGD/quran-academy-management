<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ PR 2.1 — Cast الـ Eloquent للمال.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ بدل `Piastres` (اللي كان hard-coded لـ EGP) — ده بـ **عملة
 * العمود نفسه**: لو الجدول فيه `currency`، بيستخدمه. ولو مش
 * موجود، بيرجع للـ default من الـ config.
 *
 * ⭐ الـ aggregate (`sum()`, `value()`, `selectRaw`) بيشتغل بـ
 * `Money::of((int) $raw, $currency)` — مش بـ float خالص.
 *
 * ⚠️ الـ `get()` بيرجع `Money` object — مش float ولا string.
 * والـ `set()` بيقبل `Money` أو `int` (أصغر وحدة) أو `float`
 * (وحدة كبرى) ويحوّلها لـ int.
 */
class MoneyCast implements CastsAttributes
{
    /**
     * قراءة من القاعدة: int → Money.
     *
     * @return Money|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Money::of((int) $value, $this->currencyFor($attributes));
    }

    /**
     * كتابة للقاعدة: Money|int|float|string → int (أصغر وحدة).
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Money) {
            return $value->amount;
        }

        if (is_int($value)) {
            return $value;
        }

        // string أو float → وحدة كبرى → حوّل
        $currency = $this->currencyFor($attributes);
        $exponent = config("currencies.exponents.{$currency}", 2);
        $factor = 10 ** $exponent;

        return (int) round(((float) $value) * $factor);
    }

    /**
     * عملة العمود الحالي — من الـ attributes أو الـ default.
     */
    private function currencyFor(array $attributes): string
    {
        return $attributes['currency'] ?? config('currencies.default', 'EGP');
    }
}
