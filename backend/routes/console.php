<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

/**
 * ⭐⭐⭐ **PR 4.1 — إعادة تسليم صفوف الإشعارات المتأخرة.**
 *
 * كل 5 دقائق: صفوف `queued`/`failed` (بسقف محاولات متاح)
 * يُحاول تسليمها مجدداً — هو الذي يحمل إعادة المحاولة في
 * بيئات بلا عامل طوابير مخصص، بجانب tries/backoff من الطابور.
 */
Schedule::command('notifications:retry-queued')->everyFiveMinutes();
