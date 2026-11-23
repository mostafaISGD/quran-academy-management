<?php

namespace App\Services\NotificationProviders;

use App\Models\NotificationDelivery;

/**
 * ⭐⭐⭐ **PR 4.1 — عقد مزود الإشعارات.**
 *
 * كل قناة تقنية (email/push/whatsapp/…) تدخل بمزود يحقق هذا
 * العقد. المزود وحده يحدد النتيجة الصادقة — والخدمة تسجل
 * `sent_at` + `external_id` **فقط** بعد نجاحه الصريح.
 *
 * ⭐ الرمي داخل المزود = فشل — بلا «نجاح تقريبي» ولا تسكّت.
 *
 * @return array{external_id: string|null} معرّف الموثقة عند المزود إن وجدت
 */
interface NotificationProvider
{
    /**
     * @param  array<string, mixed>  $payload  حاوية العنوان والنص وبقية بيانات الحدث
     */
    public function send(NotificationDelivery $delivery, array $payload): array;
}
