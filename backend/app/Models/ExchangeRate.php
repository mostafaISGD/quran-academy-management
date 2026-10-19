<?php

namespace App\Models;

use App\Casts\ExchangeRateCast;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ سعر صرف لزوج عملات في يوم معيّن.
 *
 * ⭐ **ليه تاريخ مع كل سعر؟**
 *
 * القاعدة ٣: «كل مبلغ يتخزن بعملته الأصلية ومعاه سعر الصرف
 * وقت العملية، ولا نعيد الحساب بأثر رجعي».
 *
 * يعني تقرير مارس يستعمل سعر مارس، حتى لو سعر اليوم مختلف.
 * فلو خزّنا سعر واحد بس وغيّرناه، كل التقارير القديمة بتتغيّر
 * — وهل دي بالظبط «إعادة حساب بأثر رجعي» اللي الخطة منعتها.
 */
class ExchangeRate extends Model
{
    protected $fillable = [
        'from_currency', 'to_currency', 'rate', 'valid_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => ExchangeRateCast::class,
            'valid_date' => 'date',
        ];
    }

    /**
     * ⭐ السعر **مقيس** (`int × 10^6`) — مش float.
     *
     * ⭐ وليه؟ لأن `48.5 * 100` = `4850.000000000001` في float،
     * والضرب المتكرر بيجمع الخطأ. والقسمة على 10^6 وقت
     * التحويل بتدي رقم نضيف.
     *
     * @return array{numerator: int, denominator: int}|null
     */
    public function scaledRate(): ?array
    {
        $raw = $this->getRawOriginal('rate');

        if ($raw === null || $raw === '') {
            return null;
        }

        return [
            'numerator' => (int) $raw,
            'denominator' => ExchangeRateCast::SCALE,
        ];
    }

    /**
     * ⭐ آخر سعر **في تاريخ معيّن أو قبله**.
     *
     * `$onOrBefore` مهم: لو عايز سعر ١ مارس، والأسعار المسجلة
     * آخر مرة ٢٥ فبراير، نستخدم سعر ٢٥ — ده السعر الساري فعلاً
     * يوم ١ مارس.
     */
    public static function rateFor(
        string $from,
        string $to,
        ?string $onOrBefore = null,
    ): ?float {
        $date = $onOrBefore ?? now()->toDateString();

        $rate = static::where('from_currency', $from)
            ->where('to_currency', $to)
            ->whereDate('valid_date', '<=', $date)
            ->orderByDesc('valid_date')
            ->first();

        return $rate ? (float) $rate->rate : null;
    }

    /**
     * ⭐ حوّل مبلغ من عملة workpiece — مع سجل سعر الصرف.
     *
     * المستدعي لازم يحفظ السعر اللي طلع مع العملية، عشان
     * يتحقق بعدين. الخدمة `FinancialLedgerService` بتعمل كده.
     *
     * @throws \RuntimeException  لو مفيش سعر مسجّل
     */
    public static function convert(
        float $amount,
        string $from,
        string $to,
        ?string $onOrBefore = null,
    ): float {
        if ($from === $to) {
            return round($amount, 2);
        }

        // ⭐ السعر المباشر الأول
        $rate = static::rateFor($from, $to, $onOrBefore);

        // ⭐ ولا السعر **العكس** لو الإدارة سجّلت الزوج
        // بالعكس بس (مثلاً دولار→جنيه بس مفيش جنيه→دولار)
        if ($rate === null) {
            $inverse = static::rateFor($to, $from, $onOrBefore);

            if ($inverse === null || (float) $inverse === 0.0) {
                throw new \RuntimeException(
                    "مفيش سعر صرف مسجّل من {$from} لـ {$to}"
                );
            }

            $rate = 1 / (float) $inverse;
        }

        return round($amount * (float) $rate, 2);
    }
}