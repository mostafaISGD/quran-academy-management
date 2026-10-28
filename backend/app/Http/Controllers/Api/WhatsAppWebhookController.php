<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ⭐⭐⭐ **PR 4.3 — Webhook حالات تسليم واتساب.**
 *
 * ⭐ نقبل فقط ما وقعته Meta بـ`X-Hub-Signature-256` (HMAC-SHA256
 * على **جسم الطلب الخام** بمفتاح `WHATSAPP_APP_SECRET`). توقيع
 * غلط أو مفتاح غير مضبوط = 401 — قبل قراءة أي حمولة.
 *
 * ⭐ التحديث بمفتاح `external_id` (معرّف رسالة Meta)، لا بالـid
 * المحلي. الرسالة المجهولة تُرد 200 عمداً: غير كده Meta تعيد
 * المحاولة بلا نهاية.
 *
 * ⭐ الحالات الناجحة (`sent/delivered/read`) تُبقي التسليم `sent`
 * وتسجّل آخر حالة في الـpayload؛ `failed` تجعله `failed` بسبب
 * مقروء. لا نلمس `attempts` — المحاولات شغل الطابور وحده.
 */
class WhatsAppWebhookController extends Controller
{
    /** تحقق Meta من عنوان الـwebhook (GET عند التسجيل). */
    public function verify(Request $request): Response
    {
        $expected = trim((string) config('notifications.whatsapp.verify_token'));
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        abort_unless(
            $mode === 'subscribe'
                && $expected !== ''
                && hash_equals($expected, $token),
            403,
        );

        return response($challenge, 200, ['Content-Type' => 'text/plain']);
    }

    public function receive(Request $request): JsonResponse
    {
        $secret = trim((string) config('notifications.whatsapp.app_secret'));
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $raw = $request->getContent();

        if ($secret === '' || ! str_starts_with($signature, 'sha256=')) {
            return response()->json(['message' => 'توقيع غير صالح'], 401);
        }

        $expected = 'sha256='.hash_hmac('sha256', $raw, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'توقيع غير صالح'], 401);
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->json(['message' => 'حمولة غير صالحة'], 422);
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $this->applyStatus($status);
                }
            }
        }

        // 200 دائماً بعد توقيع صحيح — حتى للرسائل المجهولة.
        return response()->json(['received' => true]);
    }

    private function applyStatus(mixed $status): void
    {
        if (! is_array($status)) {
            return;
        }

        $externalId = $status['id'] ?? null;
        $state = $status['status'] ?? null;

        if (! is_string($externalId) || $externalId === '' || ! is_string($state)) {
            return;
        }

        $delivery = NotificationDelivery::query()
            ->where('external_id', $externalId)
            ->first();

        if ($delivery === null) {
            return; // مجهول — ack بلا أثر.
        }

        $payload = $delivery->payload ?? [];
        $payload['whatsapp_status'] = $state;

        if ($state === 'failed') {
            $delivery->status = 'failed';
            $delivery->error = mb_substr($this->failureReason($status), 0, 200);
        } elseif (in_array($state, ['sent', 'delivered', 'read'], true)
            && $delivery->status !== 'failed') {
            $delivery->status = 'sent';
            $delivery->sent_at ??= now();
            $delivery->error = null;
        }

        $delivery->payload = $payload;
        $delivery->save();
    }

    /** @param array<string, mixed> $status */
    private function failureReason(array $status): string
    {
        $reason = $status['errors'][0]['title']
            ?? $status['errors'][0]['message']
            ?? $status['errors'][0]['error_data']['details']
            ?? null;

        $reason = is_string($reason) && trim($reason) !== ''
            ? trim($reason)
            : 'رفض مزوّد واتساب التسليم';

        return 'فشل تسليم واتساب: '.$reason;
    }
}
