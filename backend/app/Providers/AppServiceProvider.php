<?php

namespace App\Providers;

use App\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerDateRangeMacros();
    }

    /**
     * ⭐ `whereRange` / `whereMonthRange` على الـ Query Builder.
     *
     * ليه macro مش helper عادي؟ لأن ٢٦ استعلام في ٤ ملفات كان
     * مكتوب `whereBetween` بنص تاريخ — وآخر يوم دايماً بيقع
     * برّه النتيجة (المقارنة نصية، `'2026-03-31 00:00:00' >
     * '2026-03-31'`).
     *
     * الـ macro بيخلّي البديل **قصير**:
     *
     *     $q->whereRange('date', '2026-03-01', '2026-03-31')
     *
     * فلو حد كتب `whereBetween` بنص تاريخ تاني، الـ guard في
     * `DateRangeBoundaryTest` بيمسكه. والـ API المريح موجود قدامه.
     */
    private function registerDateRangeMacros(): void
    {
        foreach ([Builder::class, QueryBuilder::class] as $class) {
            if (! method_exists($class, 'macro')) {
                continue;
            }

            // فترة بين تاريخين
            $class::macro('whereRange', function (
                string $column,
                $from,
                $to,
                string $boolean = 'and',
            ) {
                /** @var Builder|QueryBuilder $this */
                $range = DateRange::of($from, $to);

                return $this->whereBetween($column, $range->bounds(), $boolean, false);
            });

            // شهر كامل
            $class::macro('whereMonthRange', function (
                string $column,
                $month,
                string $boolean = 'and',
            ) {
                /** @var Builder|QueryBuilder $this */
                return $this->whereBetween(
                    $column,
                    DateRange::monthBounds($month),
                    $boolean,
                    false,
                );
            });

            // يوم واحد.
            //
            // ⚠️ الاسم `whereDay` **ممنوع**: Laravel عندها method
            // حقيقية بنفس الاسم (`whereDay($column, $operator,
            // $value)`)، فالماكرو كان بيتعمّى عليها بصمت — وطلبنا
            // بتاعنا (عمود + تاريخ) كان بيتعامل كـ operator.
            // `whereSameDay` واضح ومفيش تعارض.
            $class::macro('whereSameDay', function (
                string $column,
                $date,
                string $boolean = 'and',
            ) {
                /** @var Builder|QueryBuilder $this */
                return $this->whereBetween(
                    $column,
                    DateRange::day_only($date)->bounds(),
                    $boolean,
                    false,
                );
            });
        }
    }
}