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
 *
 * ⭐⭐ PR 4.3 — واتساب:
 *
 * - `whatsapp_enabled` — العلم الوحيد لفتح القناة. `false`
 *   افتراضياً، ووقتها تبقى القناة مؤجلة حتى لو كانت في
 *   `deferred_channels` أو لا. لا إرسال بلا تفعيل صريح.
 * - `whatsapp` — بيانات Meta Cloud API والقوالب **المعتمدة
 *   مسبقاً** فقط. الحدث بلا قالب معتمد = فشل صريح، لا نص حر
 *   (واتساب Business لا يسمح بذلك خارج نافذة 24 ساعة).
 */

use App\Services\NotificationProviders\MailProvider;
use App\Services\NotificationProviders\WhatsAppBusinessAdapter;

return [

    'max_attempts' => 3,

    'deferred_channels' => ['whatsapp'],

    'whatsapp_enabled' => (bool) env('NOTIFICATIONS_WHATSAPP_ENABLED', false),

    'whatsapp' => [
        'base_url' => env('WHATSAPP_API_URL', 'https://graph.facebook.com/v20.0'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'timeout' => 15,

        /**
         * قوالب معتمدة: اسم القالب على Meta + لغة + ترتيب
         * عناصر الإحلال من `payload` التسليم. أي حدث غير مذكور
         * هنا لا يُرسل على واتساب (فشل صريح).
         *
         * @var array<string, array{name: string, language: string, parameters: list<string>}>
         */
        'templates' => [
            'lesson_reminder' => [
                'name' => 'lesson_reminder_ar',
                'language' => 'ar',
                'parameters' => ['title', 'message'],
            ],
            'invoice_issued' => [
                'name' => 'invoice_issued_ar',
                'language' => 'ar',
                'parameters' => ['invoice_number', 'amount_due', 'currency', 'due_date'],
            ],
        ],
    ],

    'providers' => [
        'email' => MailProvider::class,
        'push' => null,
        'whatsapp' => WhatsAppBusinessAdapter::class,
        'sms' => null,
    ],
];
