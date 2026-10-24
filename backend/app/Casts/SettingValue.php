<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **PR 3.12 — قيمة الإعداد: تخزين ذكي، قراءة ذكية.**
 *
 * ⭐ **قبل PR ده** عمود `value` كان مسكوب على `array` — أي قيمة
 * نصية (مثل `EGP`) بتتقرأ `json_decode('EGP')` = **null**! القيمة
 * النصية كانت بتتشب ضنًا.
 *
 * ⭐ دلوقتي:
 *
 * - الكتابة: المصفوفات/القيم المنطقية تتخزّن json-encoded —
 *   النصوص والأرقام تتخزّن كما هي.
 * - القراءة: جربنا `json_decode` — لو فشل (نص عادي)، النص
 *   نفسه هو القيمة.
 */
class SettingValue implements CastsAttributes
{
    /**
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     */
    public function get($model, string $key, $value, array $attributes): mixed
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    /**
     * @param  Model  $model
     * @param  string  $key
     * @param  mixed  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set($model, string $key, $value, array $attributes): mixed
    {
        if (is_array($value)) {
            return json_encode(array_values($value), JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return json_encode($value);
        }

        if ($value === null) {
            return null;
        }

        if (is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }
}
