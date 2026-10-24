<?php

/**
 * ⭐⭐⭐ **PR 4.1 — إعدادات الإشعارات.**
 *
 * - `providers`  — قناة → صف مزود يحقق عقد
 *   `NotificationProvider`، أو `null` لو المزود ما اتوصّلش
 *   (المحاولة ترمي سبباً صريحاً بلا «نجاح تقريبي»).
 * - `deferred_channels` — بافية القنوات المؤجلة بمواصفة
 *   المرحلة الرابعة (whatsapp واجهة مؤجلة — تسليم يبقى على
 *   الطابور دون محاولة، حتى يتوفر مزود).
 * - `max_attempts` — سقف المحاولات لكل صف تسليم.
 */

use App\Services\NotificationProviders\MailProvider;

return [

    'max_attempts' => 3,

    'deferred_channels' => ['whatsapp'],

    'providers' => [
        'email' => MailProvider::class,
        'push' => null,
        'whatsapp' => null,
        'sms' => null,
    ],
];
