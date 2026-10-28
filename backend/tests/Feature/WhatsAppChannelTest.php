<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Services\NotificationProviders\WhatsAppBusinessAdapter;
use App\Services\NotificationService;
use App\Support\DeliveryDeferredException;
use App\Support\DeliveryRetryableException;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 4.3 — واتساب: العلم، القوالب، وwebhook الحالات.**
 *
 * ⭐ **القبول:**
 *
 * ② واتساب مقفول افتراضياً (`whatsapp_enabled=false`) — الصف يبقى
 *    `queued` بلا محاولة، ومفعّل فقط لما العلم يتفتح.
 * ③ عند التفعيل يرسل قالباً معتمداً (تذكير حصة/فاتورة) ويعتمد
 *    `external_id` من رد المزود.
 * ④ webhook بحالة تسليم بتوقيع غلط = 401، وبالتوقيع الصح يحدّث
 *    صف التسليم نفسه.
 */
class WhatsAppChannelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        config([
            'notifications.whatsapp_enabled' => false,
            'notifications.providers.whatsapp' => WhatsAppBusinessAdapter::class,
            'notifications.whatsapp.base_url' => 'https://graph.test/v20.0',
            'notifications.whatsapp.phone_number_id' => '1234567890',
            'notifications.whatsapp.access_token' => 'test-access-token',
            'notifications.whatsapp.app_secret' => 'test-app-secret',
            'notifications.whatsapp.verify_token' => 'test-verify-token',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ واتساب — العلم والمزود
    // ═══════════════════════════════════════════════════════════════

    public function test_whatsapp_stays_queued_without_any_attempt_while_the_flag_is_off(): void
    {
        $this->assertFalse((bool) config('notifications.whatsapp_enabled'));

        $user = $this->makeUser('سارة');
        $user->update(['phone' => '01000000000']);

        $this->service()->dispatch('lesson_reminder', $user, [
            'title' => 'تذكير بحصة',
            'message' => 'نص',
        ], ['whatsapp']);

        $delivery = NotificationDelivery::query()->sole();

        try {
            $this->service()->attemptDelivery($delivery->id);
            $this->fail('القناة المقفولة اتحاولت من غير العلم');
        } catch (DeliveryDeferredException) {
            // السلوك المنتظر.
        }

        $delivery->refresh();
        $this->assertSame('queued', $delivery->status);
        $this->assertSame(0, (int) $delivery->attempts);
        $this->assertNull($delivery->sent_at);
    }

    public function test_enabled_whatsapp_sends_the_lesson_reminder_template(): void
    {
        Http::fake([
            'graph.test/*' => Http::response([
                'messages' => [['id' => 'wamid.LESSON-1']],
            ], 200),
        ]);

        $this->enableWhatsApp();

        $user = $this->makeUser('أستاذ خالد');
        $user->update(['phone' => '+20 100 000 0000']);

        $this->service()->dispatch('lesson_reminder', $user, [
            'title' => 'تذكير بحصة',
            'message' => 'حصة التجويد تبدأ ٤:٠٠ م',
        ], ['whatsapp']);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertTrue($this->service()->attemptDelivery($delivery->id));

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('WhatsAppBusinessAdapter', $delivery->provider);
        $this->assertSame('wamid.LESSON-1', $delivery->external_id);
        $this->assertNotNull($delivery->sent_at);

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();

            return $request->url() === 'https://graph.test/v20.0/1234567890/messages'
                && $request->hasHeader('Authorization', 'Bearer test-access-token')
                && $body['messaging_product'] === 'whatsapp'
                && $body['to'] === '201000000000'
                && $body['type'] === 'template'
                && $body['template']['name'] === 'lesson_reminder_ar'
                && $body['template']['language']['code'] === 'ar'
                && $body['template']['components'][0]['parameters'] === [
                    ['type' => 'text', 'text' => 'تذكير بحصة'],
                    ['type' => 'text', 'text' => 'حصة التجويد تبدأ ٤:٠٠ م'],
                ];
        });
    }

    public function test_enabled_whatsapp_sends_the_invoice_template_with_its_parameters(): void
    {
        Http::fake([
            'graph.test/*' => Http::response([
                'messages' => [['id' => 'wamid.INVOICE-1']],
            ], 200),
        ]);

        $this->enableWhatsApp();

        $user = $this->makeUser('ولي أمر');
        $user->update(['phone' => '201111111111']);

        $this->service()->dispatch('invoice_issued', $user, [
            'title' => 'فاتورة جديدة صدرت',
            'message' => 'نص',
            'invoice_number' => 'INV-2026-0042',
            'amount_due' => 1250.5,
            'currency' => 'EGP',
            'due_date' => '2026-11-05',
        ], ['whatsapp']);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertTrue($this->service()->attemptDelivery($delivery->id));

        Http::assertSent(function (ClientRequest $request): bool {
            $body = $request->data();

            return $body['template']['name'] === 'invoice_issued_ar'
                && $body['template']['components'][0]['parameters'] === [
                    ['type' => 'text', 'text' => 'INV-2026-0042'],
                    ['type' => 'text', 'text' => '1250.50'],
                    ['type' => 'text', 'text' => 'EGP'],
                    ['type' => 'text', 'text' => '2026-11-05'],
                ];
        });
    }

    public function test_whatsapp_http_failure_marks_the_delivery_failed_without_leaking_the_token(): void
    {
        Http::fake([
            'graph.test/*' => Http::response([
                'error' => ['message' => 'Invalid parameter', 'code' => 100],
            ], 400),
        ]);

        $this->enableWhatsApp();

        $user = $this->makeUser('أستاذ خالد');
        $user->update(['phone' => '201000000000']);

        $this->service()->dispatch('lesson_reminder', $user, [
            'title' => 'تذكير بحصة',
            'message' => 'نص',
        ], ['whatsapp']);

        $delivery = NotificationDelivery::query()->sole();

        try {
            $this->service()->attemptDelivery($delivery->id);
            $this->fail('فشل HTTP اتسجل إرسالاً');
        } catch (DeliveryRetryableException) {
            // السلوك المنتظر: فشل مسجّل قابل لإعادة المحاولة.
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->sent_at);
        $this->assertStringContainsString('HTTP 400', (string) $delivery->error);
        $this->assertStringContainsString('Invalid parameter', (string) $delivery->error);
        $this->assertStringNotContainsString('test-access-token', (string) $delivery->error);
    }

    public function test_whatsapp_requires_a_recipient_phone(): void
    {
        $this->enableWhatsApp();

        $user = $this->makeUser('بلا هاتف');

        $this->service()->dispatch('lesson_reminder', $user, [
            'title' => 'تذكير بحصة',
            'message' => 'نص',
        ], ['whatsapp']);

        $delivery = NotificationDelivery::query()->sole();

        try {
            $this->service()->attemptDelivery($delivery->id);
            $this->fail('إرسال واتساب بلا رقم مرّ');
        } catch (DeliveryRetryableException) {
            // السلوك المنتظر.
        }

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringContainsString('واتساب', (string) $delivery->fresh()->error);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ Webhook حالات التسليم
    // ═══════════════════════════════════════════════════════════════

    public function test_whatsapp_webhook_rejects_a_wrong_signature(): void
    {
        $payload = $this->statusPayload('wamid.TEST-1', 'failed');

        $response = $this->postWebhook($payload, 'wrong-secret');

        $response->assertStatus(401);
        $this->assertSame(0, NotificationDelivery::count());
    }

    public function test_whatsapp_webhook_updates_the_delivery_by_external_id(): void
    {
        $delivery = $this->whatsappDelivery('wamid.TEST-1');

        $response = $this->postWebhook($this->statusPayload('wamid.TEST-1', 'failed'));

        $response->assertOk();

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('Re-engagement', (string) $delivery->error);
        $this->assertSame('failed', $delivery->payload['whatsapp_status'] ?? null);
    }

    public function test_whatsapp_webhook_acknowledges_unknown_messages(): void
    {
        $this->postWebhook($this->statusPayload('wamid.UNKNOWN', 'delivered'))->assertOk();
    }

    public function test_whatsapp_verification_endpoint_echoes_the_challenge_only_with_the_right_token(): void
    {
        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=test-verify-token&hub.challenge=challenge-123')
            ->assertOk()
            ->assertSee('challenge-123');

        $this->get('/api/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=challenge-123')
            ->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════════
    // أدوات الاختبار
    // ═══════════════════════════════════════════════════════════════

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }

    private function enableWhatsApp(): void
    {
        config([
            'notifications.whatsapp_enabled' => true,
            'notifications.providers.whatsapp' => WhatsAppBusinessAdapter::class,
        ]);
    }

    private function whatsappDelivery(string $externalId): NotificationDelivery
    {
        return NotificationDelivery::create([
            'organization_id' => (int) $this->org->id,
            'user_id' => $this->makeUser()->id,
            'event' => 'lesson_reminder',
            'channel' => 'whatsapp',
            'payload' => ['title' => 'تذكير', 'body' => 'نص'],
            'status' => 'sent',
            'attempts' => 1,
            'provider' => 'WhatsAppBusinessAdapter',
            'external_id' => $externalId,
            'sent_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function statusPayload(string $externalId, string $status): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => 'WA-ACCOUNT-1',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'statuses' => [[
                            'id' => $externalId,
                            'status' => $status,
                            'timestamp' => '1761680000',
                            'recipient_id' => '201000000000',
                            'errors' => [[
                                'code' => 131047,
                                'title' => 'Re-engagement message',
                            ]],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload, string $signedWith = 'test-app-secret'): \Illuminate\Testing\TestResponse
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $this->call('POST', '/api/webhooks/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $json, $signedWith),
        ], $json);
    }
}
