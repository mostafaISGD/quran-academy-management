<?php

use App\Services\Settings;

/**
 * ⭐⭐⭐ **PR 3.12 — مُساعد الإعدادات.**
 *
 * الاستعمال الموثق في المواصفة:
 *
 * ```php
 * settings()->get('lessons.cancel_notice_hours', $orgId)
 * ```
 */
if (! function_exists('settings')) {
    function settings(): Settings
    {
        return app(Settings::class);
    }
}
