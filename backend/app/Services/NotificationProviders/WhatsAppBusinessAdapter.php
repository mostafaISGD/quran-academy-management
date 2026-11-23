<?php

namespace App\Services\NotificationProviders;

use App\Models\NotificationDelivery;
use App\Models\Student;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ⭐⭐⭐ **PR 4.3 — WhatsApp Business (Meta Cloud API).**
 *
 * ⭐⭐ قواعد صريحة:
 *
 * - **مقفول افتراضياً**: `notifications.whatsapp_enabled=false`.
 *   لا محاولة ولا إرسال قبل تفعيل صريح (الخدمة تؤجل القناة).
 * - **قوالب معتمدة فقط**: لا نص حر. الحدث بلا قالب في
 *   `config/notifications.php` ⇒ فشل صريح. هذا شرط Meta نفسه
 *   خارج نافذة الـ24 ساعة.
 * - **`external_id` من رد Meta** (`messages[0].id`) — بلا رد
 *   صالح لا «نجاح تقريبي».
 * - الرقم يُطبَّع لأرقام دولية بلا `+` (صيغة Cloud API).
 */
class WhatsAppBusinessAdapter implements WhatsAppProvider
{
    public function send(NotificationDelivery $delivery, array $payload): array
    {
        if (! (bool) config('notifications.whatsapp_enabled')) {
            throw new RuntimeException('واتساب غير مفعّل — لا إرسال قبل تفعيل العلم');
        }

        $template = $this->templateFor($delivery->event);

        $phone = $this->recipientPhone($delivery, $payload);

        if ($phone === null) {
            throw new RuntimeException('لا يوجد رقم واتساب صالح للمُستلِم');
        }

        $phoneNumberId = trim((string) config('notifications.whatsapp.phone_number_id'));
        $accessToken = trim((string) config('notifications.whatsapp.access_token'));

        if ($phoneNumberId === '' || $accessToken === '') {
            throw new RuntimeException('بيانات واتساب غير مكتملة — رقم المرسل أو رمز الوصول مفقود');
        }

        $url = rtrim((string) config('notifications.whatsapp.base_url', 'https://graph.facebook.com/v20.0'), '/')
            .'/'.$phoneNumberId.'/messages';

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout((int) config('notifications.whatsapp.timeout', 15))
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'to' => $phone,
                'type' => 'template',
                'template' => [
                    'name' => $template['name'],
                    'language' => ['code' => $template['language']],
                    'components' => [[
                        'type' => 'body',
                        'parameters' => array_map(
                            fn (string $key): array => [
                                'type' => 'text',
                                'text' => $this->parameterValue($key, $payload[$key] ?? null),
                            ],
                            $template['parameters'],
                        ),
                    ]],
                ],
            ]);

        if ($response->failed()) {
            $reason = $response->json('error.message');
            $reason = is_string($reason) && trim($reason) !== '' ? ': '.trim($reason) : '';

            // ⚠️ لا token ولا headers في النص — السبب فقط.
            throw new RuntimeException(
                'فشل إرسال واتساب (HTTP '.$response->status().')'.$reason,
            );
        }

        $externalId = $response->json('messages.0.id');

        if (! is_string($externalId) || trim($externalId) === '') {
            throw new RuntimeException('واتساب قبل الطلب بلا معرّف رسالة — لا اعتماد بلا دليل');
        }

        return ['external_id' => $externalId];
    }

    /**
     * @return array{name: string, language: string, parameters: list<string>}
     */
    private function templateFor(string $event): array
    {
        $template = config("notifications.whatsapp.templates.{$event}");

        if (! is_array($template)
            || ! is_string($template['name'] ?? null)
            || ! is_array($template['parameters'] ?? null)) {
            throw new RuntimeException("لا يوجد قالب واتساب معتمد للحدث: {$event}");
        }

        return [
            'name' => $template['name'],
            'language' => (string) ($template['language'] ?? 'ar'),
            'parameters' => array_values(array_map('strval', $template['parameters'])),
        ];
    }

    /**
     * أول رقم صالح: حساب المستخدم ثم بيانات الطالب (واتساب/هاتف/
     * الهاتف المعلَّم واتساب). الطالب يُجلب من التسليم أو من
     * `payload.student_id` (إشعارات الفواتير تصل لولي الأمر).
     *
     * @param  array<string, mixed>  $payload
     */
    private function recipientPhone(NotificationDelivery $delivery, array $payload): ?string
    {
        $candidates = [$delivery->user?->phone];

        $student = $delivery->student;

        if ($student === null && isset($payload['student_id'])) {
            $student = Student::query()->find((int) $payload['student_id']);
        }

        if ($student instanceof Student) {
            $candidates[] = $student->whatsapp ?? null;
            $candidates[] = $student->phone;

            $candidates[] = $student->phones()
                ->where('is_whatsapp', true)
                ->orderByDesc('is_primary')
                ->value('phone_number');
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizePhone(is_string($candidate) ? $candidate : null);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizePhone(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return $digits !== '' ? $digits : null;
    }

    private function parameterValue(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if ($key === 'amount_due' || $key === 'amount') {
            return number_format((float) $value, 2, '.', '');
        }

        return (string) $value;
    }
}
