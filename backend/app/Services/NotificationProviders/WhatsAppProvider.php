<?php

namespace App\Services\NotificationProviders;

/**
 * ⭐⭐⭐ **PR 4.3 — عقد مزوّد واتساب.**
 *
 * واجهة مميزة فوق `NotificationProvider` عشان قناة واتساب تُحقن
 * بمزود بديل في الاختبارات/بيئات بلا Meta (محاكي داخلي) بلا أن
 * يعرف `NotificationService` أي شيء عن تفاصيل القناة.
 *
 * ⭐ نفس القاعدة الذهبية: الرمي = فشل، والنجاح وحده يفتح
 * `sent_at` و`external_id`.
 */
interface WhatsAppProvider extends NotificationProvider
{
}
