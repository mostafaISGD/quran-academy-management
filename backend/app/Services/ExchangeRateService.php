<?php

namespace App\Services;

use App\Casts\ExchangeRateCast;
use App\Models\ExchangeRate;
use App\Support\Money;

/**
 * ⭐ PR 2.5 — أسعار الصرف.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة 3: «كل مبلغ يتخزن بعملته الأصلية ومعاه سعر الصرف
 * وقت العملية، **ولا نعيد الحساب بأثر رجعي**».
 *
 * ⭐ تقرير مارس يستعمل سعر مارس، حتى لو سعر النهارده مختلف.
 * فلو خزّنا سعر واحد وغيّرناه، كل التقارير القديمة بتتغيّر.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه `Money` مش `float`؟
 *
 * `float $amount` × `float $rate` = عمومتين في الضرب = خطأ تمثيل
 * مركّب. والضبط في التقريب **مش** دالة — كل عملية بتقرّب بطريقتها،
 * فلو الغلط وقع هنا مش هتلاقيه في أي تقرير.
 *
 * ⭐ `Money::multiply($num, $den)` بتعمل half-up على **integers** —
 * فالنتيجة **محدّدة**: نفس المدخلات دايماً نفس المخرجات.
 */
class ExchangeRateService
{
    /**
     * ⭐ حوّل مبلغ — **بـ`Money` في وبـ`Money` بره**.
     *
     * @param Money $amount المبلغ الأصلي بعملته
     * @param string $to العملة المستهدفة
     * @param string|null $onOrBefore التاريخ الساري — السعر في
     *                          ذلك اليوم أو قبله
     *
     * @throws \RuntimeException لو مفيش سعر مسجّل
     */
    public function convert(
        Money $amount,
        string $to,
        ?string $onOrBefore = null,
    ): Money {
        $to = strtoupper($to);

        // ⭐ نفس العملة — مفيش تحويل أصلاً
        if ($amount->currency === $to) {
            return $amount;
        }

        $pair = $this->ratePair($amount->currency, $to, $onOrBefore);

        return Money::of(
            $amount->multiply($pair['numerator'], $pair['denominator'])->amount,
            $to,
        );
    }

    /**
     * ⭐ السعر **كمقيسين** — `numerator`/`denominator`.
     *
     * ⭐ مش float عشان `Money::multiply()` بياخد int pair.
     */
    public function ratePair(
        string $from,
        string $to,
        ?string $onOrBefore = null,
    ): array {
        $date = $onOrBefore ?? now()->toDateString();
        $from = strtoupper($from);
        $to = strtoupper($to);

        // ⭐ السعر المباشر الأول
        $rate = $this->findRate($from, $to, $date);

        if ($rate !== null) {
            return $rate;
        }

        /**
         * ⭐ ولا السعر **العكس** لو الإدارة سجّلت الزوج بالعكس بس
         * (مثلاً دولار→جنيه بس مفيش جنيه→دولار).
         *
         * ⚠️ **القلب في الأعداد الصحيحة مش بـ`1 / $inverse`** —
         * القسمة دي float و`1 / 3` مالها نهاية. فبنقلب البسط
         * والمقام بـ`intdiv` وبنقيس.
         */
        $inverse = $this->findRate($to, $from, $date);

        if ($inverse === null) {
            throw new \RuntimeException(
                "مفيش سعر صرف مسجّل من {$from} لـ {$to} — "
                .'سجّل السعر الأول، ولا حوّل الحسابات.'
            );
        }

        if ($inverse['numerator'] === 0) {
            throw new \RuntimeException(
                "سعر الصرف المسجّل من {$to} لـ {$from} **صفر** — "
                .'مفيش قسمة على صفر. سجّل سعر صحيح.'
            );
        }

        /**
         * ⭐ العكس — بنفس المقياس.
         *
         * ⭐ **الخطأ اللي بيحصل هنا**: إنك تعمل
         * `numerator = denominator` و`denominator = numerator` وتسيب
         * المقياس. النتيجة بتطلع بمقياسين مختلفين والضرب بيفشل.
         *
         *     0.020000 → {20000, 10^6}
         *     1 / 0.02  → {50 * 10^6, 10^6}   ⭐ مش {10^6, 20000}
         */
        return [
            'numerator' => (int) round(
                $inverse['denominator'] * ExchangeRateCast::SCALE / $inverse['numerator']
            ),
            'denominator' => ExchangeRateCast::SCALE,
        ];
    }

    /**
     * ⭐ آخر سعر **في تاريخ معيّن أو قبله** — كمقيسين.
     *
     * `$onOrBefore` مهم: لو عايز سعر ١ مارس، والأسعار المسجلة آخر
     * مرة ٢٥ فبراير، نستخدم سعر ٢٥ — ده السعر **الساري فعلاً**
     * يوم ١ مارس.
     *
     * @return array{numerator: int, denominator: int}|null
     */
    private function findRate(string $from, string $to, string $date): ?array
    {
        $rate = ExchangeRate::where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereDate('valid_date', '<=', $date)
            ->orderByDesc('valid_date')
            ->first();

        if ($rate === null) {
            return null;
        }

        return $rate->scaledRate();
    }
}