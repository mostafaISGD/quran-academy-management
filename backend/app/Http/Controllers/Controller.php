<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * ⭐⭐ الأعمدة المسموح بها في `paginatedWithCounts`.
     *
     * ⭐ ليش whitelist مش validation عادية؟
     *
     * `$key` بيتحقن **مباشرة** في SQL:
     *
     *     ->selectRaw("$key, count(*) as aggregate")->groupBy($key)
     *
     * فأي نص فيه فاصلة أو قوس بيبقى SQL injection. و`$key` جاي
     * من الـdeveloper جوه الـcontroller — فمفيش هجوم خارجي
     * **مباشر**، بس:
     *
     *   • أي تمريرة غلط بتتحول لـ SQL مكسور (بتحصل فعلاً)
     *   • أي كود مستقبلي يمرّر `$_GET` هنا = ثغرة فورية
     *
     * ⭐ الـwhitelist بيقفل الباب في **المصدر** — أي نص مش
     * معروف يرمي exception بدل ما يتبعت لـSQL.
     *
     * @var array<int, string>
     */
    private const ALLOWED_COUNT_COLUMNS = [
        'status', 'payment_method', 'currency', 'type', 'kind',
        'role', 'role_id', 'gender', 'branch_id', 'category_id',
        'method', 'channel', 'direction', 'weekday', 'is_active',
        'lesson_type', 'program_id', 'teacher_id', 'account_id',
        'source', 'reason', 'event_type', 'employment_type',
    ];

    /** ⭐ الأعمدة المسموح بها في `sum()` — نفس المنطق */
    private const ALLOWED_SUM_COLUMNS = [
        'amount', 'total', 'paid_amount', 'balance_due', 'subtotal',
        'discount', 'tax', 'price', 'monthly_salary', 'opening_balance',
        'current_balance', 'worked_hours', 'hours', 'quantity',
        'net_amount', 'commission', 'value', 'lesson_count',
    ];

    /**
     * ⭐⭐⭐ التحقق من اسم العمود قبل ما يوصل لـSQL.
     *
     * @throws \InvalidArgumentException
     */
    private function assertColumnAllowed(string $column, array $allowed, string $kind): string
    {
        // ⭐ نتأكد إن الاسم **فعلاً** عمود: حروف وأرقام و underscore
        // بس. ده يمنع `amount, (select …)` و `amount) as x --`.
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException(
                "صفحة، اسم العمود غير صالح للـ {$kind}: {$column}"
            );
        }

        if (! in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException(
                "العمود «{$column}» مش في قائمة الأعمدة المسموحة للـ {$kind}"
            );
        }

        return $column;
    }

    /**
     * يبني رد صفحة مع الإحصائيات المحسوبة من الداتابيز.
     *
     * الـ counts بتتحسب على كل النتائج (مش الصفحة الحالية) عشان
     * الكروت الإحصائية في الواجهة تفضل صح مهما البيانات كبرت.
     *
     * @param  Builder  $query        نفس الفلاتر المطبّقة على البيانات
     * @param  Builder  $countsQuery  نفس الفلاتر (لعدّ الحالات)
     * @param  string[] $countKeys    الأعمدة اللي عايزين نعدّها
     * @param  string[] $sumKeys      أعمدة نجمعها
     *
     * @throws \InvalidArgumentException عند أي عمود مش مسموح
     */
    protected function paginatedWithCounts(
        Builder $query,
        Builder $countsQuery,
        Request $request,
        array $countKeys = ['status'],
        array $sumKeys = [],
    ) {
        // ⭐⭐ التحقق **قبل** أي SQL. راجع `ALLOWED_COUNT_COLUMNS`.
        $countKeys = array_map(
            fn ($k) => $this->assertColumnAllowed($k, self::ALLOWED_COUNT_COLUMNS, 'counts'),
            $countKeys
        );

        $sumKeys = array_map(
            fn ($k) => $this->assertColumnAllowed($k, self::ALLOWED_SUM_COLUMNS, 'sums'),
            $sumKeys
        );

        $perPage = min(max($request->integer('per_page') ?: 100, 1), 200);
        $paginator = $query->paginate($perPage);

        $response = $paginator->toArray();

        // عدد كل حالة
        $counts = [];
        foreach ($countKeys as $key) {
            $counts[$key] = $countsQuery
                ->clone()
                ->selectRaw("$key, count(*) as aggregate")
                ->groupBy($key)
                ->pluck('aggregate', $key)
                ->map(fn ($v) => (int) $v)
                ->all();
        }

        // مجاميع (إجمالي الفواتير، إجمالي المدفوعات...)
        $sums = [];
        if ($sumKeys) {
            $row = $countsQuery->clone()->selectRaw(
                implode(', ', array_map(fn ($k) => "coalesce(sum($k), 0) as $k", $sumKeys))
            )->first();

            foreach ($sumKeys as $k) {
                $sums[$k] = round((float) ($row->{$k} ?? 0), 2);
            }
        }

        $response['counts'] = $counts;
        if ($sums) {
            $response['sums'] = $sums;
        }

        return response()->json($response);
    }

    /** بنّاء استعلام مكرر للفلاتر — بيتنسخ قبل كل استخدام */
    protected function cloneQuery(Builder $q): Builder
    {
        return clone $q;
    }
}
