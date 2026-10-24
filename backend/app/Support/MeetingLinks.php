<?php

namespace App\Support;

use App\Models\Setting;

/**
 * ⭐⭐⭐ **PR 3.11 — معايير روابط الاجتماعات.**
 *
 * ⭐ **قبل PR ده** رابط الحصة حقل حر — أي رابط بيتقبّل
 * (`meeting_url => nullable|string`), فالحصة يمكن تفتح رابط
 * عشوائي مش من Zoom/Meet.
 *
 * ⭐ **المفردات**: قائمة **مضيفين مسموحين** (suffix match) —
 * `zoom.us` بيجيب `zoom.us/...` و`us02web.zoom.us/...` — والمحدد
 * من إعداد `meetings.allowed_domains` (فصل بفواصل أو مصفوفة)
 * فوق الافتراضي.
 */
final class MeetingLinks
{
    /** ⭐ مفتاح الإعداد لقائمة المضيفين. */
    public const ALLOWED_SETTING = 'meetings.allowed_domains';

    /** @var array<int, string> الافتراضي: Zoom + Meet + Teams. */
    public const DEFAULT_HOSTS = [
        'zoom.us',
        'zoom.com',
        'meet.google.com',
        'teams.microsoft.com',
    ];

    /**
     * ⭐ قائمة المضيفين المسموح به — الإعداد يتفرّع فوق الافتراضي.
     *
     * @return array<int, string>
     */
    public static function allowedHosts(?int $organizationId): array
    {
        $raw = Setting::get(self::ALLOWED_SETTING, null, $organizationId);
        $hosts = $raw ? array_map('trim', explode(',', implode(',', (array) $raw))): [];

        return array_values(array_filter($hosts) ?: self::DEFAULT_HOSTS);
    }

    /**
     * ⭐ الرابط مسموح؟ الفارغ/الناقص مسموح (الحصة بدون رابط).
     */
    public static function allowed(?int $organizationId, ?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return true;
        }

        $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        if ($host === null || $host === '') {
            return false;
        }

        foreach (self::allowedHosts($organizationId) as $domain) {
            $domain = strtolower(trim($domain));

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⭐ مزوّد الرابط — للعرض (zoom / google_meet / other).
     */
    public static function providerFromUrl(?string $url): ?string
    {
        $host = $url === null || trim($url) === ''
            ? null
            : strtolower((string) parse_url(trim($url), PHP_URL_HOST));

        if ($host === null || $host === '') {
            return null;
        }

        if ($host === 'zoom.us' || str_ends_with($host, '.zoom.us') || $host === 'zoom.com' || str_ends_with($host, '.zoom.com')) {
            return 'zoom';
        }

        if ($host === 'meet.google.com' || str_ends_with($host, '.meet.google.com')) {
            return 'google_meet';
        }

        return 'other';
    }
}
