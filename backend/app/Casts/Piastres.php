<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **القرش — الوحدة اللي بتتخزّن في القاعدة.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه الكلام ده مهم أصلاً؟
 *
 * القاعدة كانت `decimal(12,2)` — بس **SQLite مافيش decimal
 * حقيقي**. الـ `PRAGMA table_info` بيرجّع `numeric`، وده float.
 *
 * والـ float بيغلط:
 *
 *     0.07 * 3 = 0.21000000000000002   ← مش 0.21
 *
 * ═══════════════════════════════════════════════════════════════
 *
 * ⭐ **الحل:** نخزّن **عدد صحيح بالقرش**.
 *
 *     650 جنيه → 65000 (قرش)
 *
 * الأعداد الصحيحة **مالهاش خطأ تمثيل** — 65000 هي 65000 بالظبط،
 * مهما اتكررت 10,000 مرة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ **وإيه اللي بيشتغل بيه الـ cast ده؟**
 *
 * **كل حاجة فوق القاعدة بتفكر في جنيه. مفيش استثناء.**
 *
 *     $invoice->total            →  650.00   (جنيه)
 *     $invoice->total = 650.00   →  65000    (قرش، متخزّن)
 *     $invoice->balance_due      →  350.00
 *
 * فالـ controller والـ service والـ API والواجهة **مفيش فيهم
 * سطر واحد اتغيّر**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️⚠️⚠️ **الفخّ الأول والأهم: الـ aggregates**
 *
 * الـ cast شغّال على **قراءة الخاصية** بس. أي `SUM()` أو
 * `value()` أو `selectRaw` بيرجّع **الرقم الخام** من القاعدة
 * من غير ما يمرّ على الـ cast:
 *
 *     $this->opening_balance   ← cast شغّال    → 1000.00 (جنيه)
 *     $query->sum('amount')    ← cast ما شغّالش → 50000  (قرش)
 *
 * وجمع الاتنين:
 *
 *     1000.00 + 50000 = 51000   ← ⭐ غلط 100×
 *
 * ⭐ **الحل:** أي رقم جاي من aggregate — اتمرّر من
 * `Piastres::fromDatabase()`.
 *
 * ```php
 * // ❌ غلط — بيخلط وحدتين
 * return $this->opening_balance + $query->sum('amount');
 *
 * // ✅ صح
 * return $this->opening_balance
 *     + Piastres::fromDatabase($query->sum('amount'));
 * ```
 *
 * @implements CastsAttributes<int, string|null>
 */
class Piastres implements CastsAttributes
{
    /**
     * ⭐⭐⭐ القاعدة → التطبيق (قرش → جنيه)
     *
     * ⚠️⚠️⚠️ **بيرجّع نص `"350.00"` — مش float — عن قصد.**
     *
     * الأعمدة دي كانت `decimal:2` قبل التحويل، وLaravel بيرجّع
     * منها **نص** بمنزلتين بالظبط:
     *
     *     number_format(350, 2, '.', '')  →  "350.00"
     *
     * فلو رجّعنا `float`، الـ API هيبقى بيرجّع `350` بدل
     * `"350.00"`، والواجهة اللي بتعمل `toFixed(2)` أو بتقارن
     * نصوص ممكن تتغيّر.
     *
     * ⭐ رجّعنا **نفس الشكل بالظبط** عشان مفيش حاجة في
     * الـ API ولا الواجهة تتغيّر. ده شرط التصميم كله.
     *
     * @see \Illuminate\Database\Eloquent\Casts\AsDecimal::cast
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        // ⭐ `null` معناها "مافيش مبلغ" — مش صفر.
        //
        // `net_amount` و `hourly_rate` nullable. لو رجعنا `0` بدل
        // `null`، الواجهة هتعرض "٠ ج.م" لسطر مش متبتّ أصلاً.
        if ($value === null) {
            return null;
        }

        return self::toDecimal($value);
    }

    /**
     * ⭐ تحويل **بالأعداد الصحيحة بس** — مافيش float في الأفق.
     *
     * ⭐ ليه مش `number_format($value / 100, 2)`؟
     *
     * لأن القسمة بتنتج float، والـ float ده بيرجّع أحيانًا
     * `0.07000000000000001`. إحنا قاعدين على **integers**،
     * فبنقسم بـ `intdiv` — واللي مالهوش خطأ تمثيل أصلاً.
     *
     *     3333 → "33.33"
     *        7 → "0.07"
     *     65000 → "650.00"
     *       50 → "0.50"
     *    -6500 → "-65.00"
     */
    private static function toDecimal(mixed $piastres): string
    {
        $piastres = (int) $piastres;

        $sign = $piastres < 0 ? '-' : '';
        $abs = abs($piastres);

        return $sign
            . intdiv($abs, 100)
            . '.'
            . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * ⭐ من التطبيق → للقاعدة (جنيه → قرش)
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        // ⭐ `round` **قبل** الـ cast للـ int.
        //
        // 19.999999 × 100 = 1999.9999 → round = 2000 ✅
        // من غير round: (int) 1999.9999 = 1999  ❌ قرش ضايع
        return (int) round(((float) $value) * 100);
    }

    /**
     * ⭐⭐⭐ **الأهم في الملف ده** — لأرقام الـ aggregates.
     *
     * ⭐ أي `sum()` أو `value()` أو `selectRaw` أو `increment()`
     * بيرجّع الرقم **الخام** بالقرش. لازم يمرّ من هنا قبل ما
     * ينجمع مع رقم جاي من خاصية (واللي معمولاً cast).
     *
     * مثال الغلط اللي بيحصل من غيرها:
     *
     *     1000.00 (جنيه، من الخاصية) + 50000 (قرش، من SUM)
     *     = 51000  ← ⭐ الرقم غلط 100 مرة
     *
     * ⚠️ لاحظ إن `Collection->sum()` **آمن** — لأن عناصره موديلات
     * والخاصية بتعدّي على الـ cast. الخطر بس في `sum()` اللي
     * بتتنفّذ في SQL على Builder.
     *
     * @see self::class  التوثيق الكامل في الـ docblock فوق
     */
    public static function fromDatabase(mixed $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        return round(((float) $raw) / 100, 2);
    }
}