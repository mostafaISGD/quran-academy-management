<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\SettingsRegistry;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * ⭐⭐⭐ **PR 3.12 — خدمة الإعدادات: قراءة كاملة من السجل.**
 *
 * ⭐ **الفرق عن `Setting::get`:**
 *
 * - **السجل أولًا**: أي مفتاح هنا لازم يكون معرّف في
 *   `SettingsRegistry` — المفتاح غير المعرّف = استثناء، لأن
 *   **الافتراضي ملك للسجل** مش من النداء.
 * - **التحويل بالنوع**: int/bool/string/list — السجل هو اللي
 *   بيحول، فمفيش `'7'` بتترجع نص.
 * - **cache لكل (مفتاح، مؤسسة)** — مكسوب على التعديل
 *   (`Settings::forget` من `Setting::set`).
 *
 * ⭐ الاستعمال في الخدمات:
 *
 * ```php
 * settings()->get('subscriptions.expiry_warning_days', $orgId)
 * ```
 *
 * ⭐ مؤسسة null = ما فيه صف في القاعدة — بترجع **الافتراضي من
 * السجل** (الجدول مؤسسًا بالكامل، مفيش صفوف مشتركة).
 */
final class Settings
{
    private const CACHE_PREFIX = 'settings:v1';

    /**
     * ⭐ قيمة إعداد (حسب المؤسسة) — محوّلة بالنوع من السجل.
     */
    public function get(string $key, ?int $organizationId = null): mixed
    {
        $definition = SettingsRegistry::definition($key);

        if ($definition === null) {
            throw new InvalidArgumentException("مفتاح الإعداد غير معرَف في سجل الإعدادات: {$key}");
        }

        return Cache::rememberForever(
            $this->cacheKey($key, $organizationId),
            function () use ($key, $definition, $organizationId) {
                $query = Setting::query()->where('key', $key);

                if ($organizationId !== null) {
                    $query->where('organization_id', $organizationId);
                }

                $row = $query->first();

                // ⭐ ما فيه صف مؤسسي — أو الصف موجود وقيمته
                // **null** (بعد الرجوع) — بترجع الافتراضي من
                // السجل. (التحويل على null كان بيرجع 0 دجَل
                // الافتراضي!)
                if ($row === null || $row->value === null) {
                    return $definition['default'] ?? null;
                }

                return $this->castValue($definition['type'], $row->value);
            },
        );
    }

    /**
     * ⭐ إبطال حَكش المؤسسة + المؤسسة غير محددة عند القراءة.
     * `Setting::set`/`Setting::forget` بيسسّوها — بدالة
     * تعديل واحدة.
     */
    public static function forget(string $key, ?int $organizationId = null): void
    {
        Cache::forget(self::cacheKey($key, $organizationId));
        Cache::forget(self::cacheKey($key, null));
    }

    public static function cacheKey(string $key, ?int $organizationId): string
    {
        return self::CACHE_PREFIX.':'.($organizationId ?? 'shared').':'.$key;
    }

    /**
     * ⭐ التحويل على النوع من السجل — عام: الـcontroller بيستعملها
     * مع القيم الخارجة من `pluck` (بلا كَست).
     */
    public function castValue(string $type, mixed $raw): mixed
    {
        return match ($type) {
            'int' => (int) $raw,
            'bool' => $raw === true || $raw === 1 || $raw === '1' || $raw === 'true',
            'list' => $this->castList($raw),
            default => $raw,
        };
    }

    private function castList(mixed $raw): array
    {
        if (is_array($raw)) {
            return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $raw)));
        }

        $s = is_string($raw) ? $raw : (string) $raw;
        $parts = array_filter(array_map('trim', explode(',', $s)));

        return array_values($parts);
    }
}
