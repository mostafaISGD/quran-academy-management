<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * حدود فترة زمنية صحيحة.
 *
 * ⭐ ليه الدالة دي موجودة أصلاً؟
 *
 * الكود كان مكتوب كده في ٢٦ مكان:
 *
 *     ->whereBetween('date', ['2026-03-01', '2026-03-31'])
 *
 * والمقارنة في SQL **نصية**. فلو العمود متخزّن
 * `2026-03-31 00:00:00` (وده اللي بيعمله cast `date` في Eloquent)،
 * نلاقي:
 *
 *     '2026-03-31 00:00:00' > '2026-03-31'   ← true
 *
 * يعني **آخر يوم في الفترة دايماً بيقع برّه النتيجة**. «مارس»
 * ناقص يوم، وكل تقرير بيقلّل — من غير error ولا 500، رقم غلط بس.
 * ودي أخطر صورة للـ bug.
 *
 * الحل: نخلي الحدود تغطي اليوم **كامل**:
 *
 *     ->whereBetween('date', ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])
 *
 * ⚠️ `endOfDay` بيشيل الـ microseconds، فحصة عند `23:59:59.5`
 * هتتزحل. عشان كده الحد الأقصى `23:59:59` مش `23:59:59.999999`.
 * لو في حقول وقت بح submicro، استخدم `nextDay()->startOfDay()` مع
 * `<` بدل `whereBetween` — أو `->whereDateBetween()`.
 */
class DateRange
{
    private function __construct(
        public readonly string $start,
        public readonly string $end,
    ) {}

    /**
     * @param  mixed  $from  أي حاجةCarbon بيعرفها (نص، Carbon، DateTime)
     * @param  mixed  $to
     */
    public static function of($from, $to): self
    {
        return new self(
            self::day($from)->startOfDay()->format('Y-m-d H:i:s'),
            self::day($to)->endOfDay()->format('Y-m-d H:i:s'),
        );
    }

    /** حدود شهر كامل (أول يوم : آخر يوم) */
    public static function month($month): self
    {
        $m = self::day($month);

        return self::of($m->copy()->startOfMonth(), $m->copy()->endOfMonth());
    }

    /** حدود يوم واحد */
    public static function day_only($date): self
    {
        return self::of($date, $date);
    }

    /**
     * ⭐ الدالة الأساسية: `whereBetween` بحدود سليمة.
     *
     * الاستخدام في place of العادي:
     *   $q->whereBetween('date', ['2026-03-01', '2026-03-31'])
     * لـ:
     *   $q->whereRange('date', '2026-03-01', '2026-03-31')
     */
    public static function apply(
        Builder|QueryBuilder $query,
        string $column,
        $from,
        $to,
    ) {
        $range = self::of($from, $to);

        return $query->whereBetween($column, [$range->start, $range->end]);
    }

    /** حدود الشهر بصيغة `[$start, $end]` — للاستخدام مع `whereBetween` */
    public static function monthBounds($month): array
    {
        return self::month($month)->bounds();
    }

    public function bounds(): array
    {
        return [$this->start, $this->end];
    }

    public function __toString(): string
    {
        return "{$this->start} → {$this->end}";
    }

    private static function day($value): Carbon
    {
        return $value instanceof Carbon
            ? $value->copy()
            : Carbon::parse((string) $value);
    }
}