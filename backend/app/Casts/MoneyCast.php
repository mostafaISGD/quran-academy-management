<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ PR 2.2 — Cast الـ Eloquent للمال (القاعدة بقت `bigint`).
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة: **المبلغ في القاعدة = int بأصغر وحدة** (قرش/سنت).
 * مافيش `decimal` ولا `float` في أي مسار فلوس.
 *
 * ⭐ **الـcast بيرجّع نص** (`"350.00"`) زي `Piastres` بالظبط — عشان
 * الـAPI contract ما يتغيّرش في PR 2.2. تغيير شكل الـAPI شغل
 * **PR 2.3**، وعندها الـcast هيتحوّل لـ `Money` object.
 *
 * ⚠️ **`fromAggregate()` مطلوب دايماً**: `sum()` و`value()`
 * و`selectRaw` بيرجّعوا **الخام بالقرش** (int) — بيعدّوا على الـcast.
 *     $account->opening_balance  →  نص بالجنيه (1000.00)
 *     $query->sum('amount')      →  int بالقرش    (50000)
 *     1000.00 + 50000            →  ⭐ غلط 100×
 *
 * ⭐ والتحويل كله **int**: `toMajor()` بتعمل `intdiv` — مافيش
 * `round(x / 100, 2)` عشان دي بترجّع float.
 *
 * @implements CastsAttributes<string|null, int|float|string|null>
 */
class MoneyCast implements CastsAttributes
{
    /**
     * ⭐ القاعدة → التطبيق: `int` بالقرش → نص بالوحدة الكبرى.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // ⭐ `null` معناها "مافيش مبلغ" — مش صفر.
        if ($value === null || $value === '') {
            return null;
        }

        return self::toMajor((int) $value, $this->currencyFor($attributes));
    }

    /**
     * ⭐ التطبيق → القاعدة: نص/رقم بالوحدة الكبرى → `int` بالقرش.
     *
     * ⚠️ `Money` object مدعوم كمدخل — الكود الجديد يقدر يكتب
     * `$invoice->total = Money::of(35000, 'EGP')` بدل `350.00`.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Money) {
            return $value->amount;
        }

        $currency = $this->currencyFor($attributes);

        // ⭐⭐ **الرقم = وحدة كبرى دايماً** (جنيه) — زي `Piastres`
        // بالظبط، عشان الـAPI contract والاختبارات ماتتغيّرش.
        //
        // ⚠️ الـ`int` هنا **مش** أصغر وحدة. القارئ الوحيد للأصغر
        // هو `Money::of(65000, 'EGP')` — لازم `Money` object صريح.
        // تخمين النية من نوع الـPHP هو بالظبطBug اللي الـcast
        // بيمنعه.
        $factor = 10 ** config("currencies.exponents.{$currency}", 2);

        return (int) round(((float) $value) * $factor);
    }

    /**
     * ⭐⭐⭐ **الأهم** — لنتائج الـ `aggregates`.
     *
     * ⭐ أي `sum()` أو `value()` أو `selectRaw` بيرجّع الرقم **الخام
     * بالقرش**. لازم يمرّ من هنا قبل ما ينجمع مع رقم جاي من خاصية
     * (واللي بيعدّي على الـcast) — غير كده بتخلط وحدتين.
     *
     * @return string|null نص بالوحدة الكبرى
     */
    public static function fromAggregate(mixed $raw, ?string $currency = null): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::toMajor(
            (int) $raw,
            $currency ?? config('currencies.default', 'EGP'),
        );
    }

    /**
     * ⭐ نفس `fromAggregate` بس بيرجّع `Money` object — للكود الجديد
     * اللي عايز يتعامل بقيمة عملة صريحة بدل نص.
     */
    public static function aggregateMoney(mixed $raw, ?string $currency = null): ?Money
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return Money::of(
            (int) $raw,
            $currency ?? config('currencies.default', 'EGP'),
        );
    }

    /**
     * ⭐ `int|float|string` بالوحدة الكبرى → نص بالوحدة الكبرى.
     *
     * ⭐ بتقبل الرقم زي ما هو (سلوك قديم: `650.5` → `"650.50"`)،
     * عشان الـAPI ما يتغيّرش.
     */
    public static function major(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * ⭐ `int` بالقرش → نص بالوحدة الكبرى — **من غير float خالص**.
     *
     * ⭐ ليه مش `number_format($piastres / 100, 2)`؟ لأن القسمة
     * بتنتج float، و`0.07000000000000001` ممكن يطلع. إحنا قاعدين
     * على **integers** — فبنقسم بـ`intdiv` واللي مالوش خطأ تمثيل.
     *
     *     3333 → "33.33"
     *        7 → "0.07"
     *    65000 → "650.00"
     *       50 → "0.50"
     *    -6500 → "-65.00"
     */
    public static function toMajor(int $minor, ?string $currency = null): string
    {
        $currency = $currency ?? config('currencies.default', 'EGP');

        $exponent = config("currencies.exponents.{$currency}", 2);

        if ($exponent === 0) {
            return (string) $minor;
        }

        $factor = 10 ** $exponent;

        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);

        return $sign
            . intdiv($abs, $factor)
            . '.'
            . str_pad((string) ($abs % $factor), $exponent, '0', STR_PAD_LEFT);
    }

    /**
     * عملة العمود الحالي — من الـ attributes أو الـ default.
     */
    private function currencyFor(array $attributes): string
    {
        return $attributes['currency'] ?? config('currencies.default', 'EGP');
    }
}