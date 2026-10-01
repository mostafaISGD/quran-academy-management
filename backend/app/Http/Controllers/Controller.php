<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * يبني رد صفحة مع الإحصائيات المحسوبة من الداتابيز.
     *
     * الـ counts بتتحسب على كل النتائج (مش الصفحة الحالية) عشان
     * الكروت الإحصائية في الواجهة تفضل صح مهما البيانات كبرت.
     *
     * @param  Builder  $query        نفس الفلاتر المطبّقة على البيانات
     * @param  Builder  $countsQuery  نفس الفلاتر (لعدّ الحالات)
     * @param  string[] $countKeys    الأعمدة اللي عايزين نعدّها (status / payment_method ...)
     * @param  string[] $sumKeys      أعمدة نجمعها (total / amount ...)
     */
    protected function paginatedWithCounts(
        Builder $query,
        Builder $countsQuery,
        Request $request,
        array $countKeys = ['status'],
        array $sumKeys = [],
    ) {
        $perPage = $request->integer('per_page') ?: 100;
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