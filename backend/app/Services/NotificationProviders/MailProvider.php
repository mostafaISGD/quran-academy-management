<?php

namespace App\Services\NotificationProviders;

use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

/**
 * ⭐⭐⭐ **PR 4.1 — مزوّد البريد الإلكتروني (Laravel Mail).**
 *
 * ⭐ **مافيش «إرسال في الذاكرة»**: الإرسال عبر `Mail` الحقيقي
 * (smtp في الإنتاج، أو `array`/`log` محلياً حسب `.env`).
 * نجاح هذا الاستدعاء هو الوحيد المؤهل لكتابة `sent_at`.
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

        Mail::raw($body, function ($message) use ($email, $title) {
            $message->to($email)->subject($title);
        });

        return ['external_id' => null];
    }
}
