<?php

namespace App\Services\NotificationProviders;

use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * ⭐⭐⭐ **PR 4.1 / 4.3 — مزوّد البريد الإلكتروني (Laravel Mail).**
 *
 * ⭐ **مافيش «إرسال في الذاكرة»**: الإرسال عبر `Mail` الحقيقي
 * (smtp في الإنتاج، أو `array`/`log` محلياً حسب `.env`).
 * نجاح هذا الاستدعاء هو الوحيد المؤهل لكتابة `sent_at`.
 *
 * ⭐⭐ PR 4.3 — النص العادي (`Mail::raw`) بقى `NotificationMail`:
 * قالب عربي RTL واحد بهوية الأكاديمية، والنص من الـpayload
 * يمر على الهروب التلقائي في الـBlade.
 */
class MailProvider implements NotificationProvider
{
    public function send(NotificationDelivery $delivery, array $payload): array
    {
        $email = $delivery->user?->email;

        if (empty($email)) {
            throw new RuntimeException('المُستلِم لا يملك بريداً إلكترونياً للإرسال');
        }

        $title = (string) ($payload['title'] ?? 'إشعار من الأكاديمية');
        $body = (string) ($payload['body'] ?? '');

        if (trim($body) === '') {
            throw new RuntimeException('محتوى البريد فارغ — لا إرسال لرسالة خالية');
        }

        Mail::to($email)->send(new NotificationMail(
            title: $title,
            messageBody: $body,
            recipientName: $delivery->user?->name,
            organizationName: $delivery->organization?->name,
            actionUrl: isset($payload['action_url']) && is_string($payload['action_url'])
                ? $payload['action_url']
                : null,
        ));

        return ['external_id' => null];
    }
}
