<?php

namespace Tests\Feature;

use App\Mail\NotificationMail;
use App\Models\NotificationDelivery;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 4.3 — البريد العربي وتفضيلات المستخدم.**
 *
 * ⭐ **قبل PR ده** البريد كان `Mail::raw` بنص عادي بلا هوية ولا
 * اتجاه، ومافيش واجهة للمستخدم يوقف قناة بنفسه من غير تدخل الإدارة.
 *
 * ⭐ **القبول:**
 *
 * ① الإيميل يخرج بقالب عربي RTL واحد بألوان الهوية.
 * ② المستخدم يقرأ/يعدّل تفضيلاته الذاتية، والخدمة تحترمها.
 *
 * (واتساب وwebhook في `WhatsAppChannelTest` — تقسيم الملفات
 * الجديد تحت سقف الـ400 سطر.)
 */
class NotificationChannelsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ⭐ حسم الجدولة في الاختبار — المحاولة تُستدعى صراحةً.
        Queue::fake();
    }

    // ═══════════════════════════════════════════════════════════════
    // ① البريد العربي — القالب الموحّد
    // ═══════════════════════════════════════════════════════════════

    public function test_email_delivery_sends_the_arabic_rtl_branded_mailable(): void
    {
        Mail::fake();

        $user = $this->makeUser('منى عبد الله');
        $user->update(['email' => 'mona@example.test']);

        $this->service()->dispatch('lesson_reminder', $user, [
            'title' => 'تذكير بحصة',
            'message' => 'حصة التجويد تبدأ الساعة ٥ مساءً بتوقيتك.',
        ], ['email']);

        $delivery = NotificationDelivery::query()->sole();

        $this->assertTrue($this->service()->attemptDelivery($delivery->id));

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($user): bool {
            return $mail->hasTo('mona@example.test')
                && $mail->title === 'تذكير بحصة'
                && str_contains($mail->messageBody, 'حصة التجويد');
        });

        $this->assertSame('sent', $delivery->fresh()->status);
    }

    public function test_the_email_template_is_rtl_arabic_with_brand_colors(): void
    {
        $html = (new NotificationMail(
            title: 'فاتورة جديدة صدرت',
            messageBody: "صدرت فاتورتك بمبلغ ١٢٥٠ جنيهاً.\nيرجى السداد قبل ٥ نوفمبر.",
            recipientName: 'منى عبد الله',
            organizationName: 'أكاديمية الاختبار',
        ))->render();

        $this->assertStringContainsString('dir="rtl"', $html);
        $this->assertStringContainsString('lang="ar"', $html);
        $this->assertStringContainsString('فاتورة جديدة صدرت', $html);
        $this->assertStringContainsString('صدرت فاتورتك', $html);
        $this->assertStringContainsString('أكاديمية الاختبار', $html);
        // ⭐ ألوان الهوية نفسها المستخدمة في مستندات الـPDF.
        $this->assertStringContainsString('#0f766e', $html);
    }

    // ═══════════════════════════════════════════════════════════════
    // ② التفضيلات الذاتية
    // ═══════════════════════════════════════════════════════════════

    public function test_preferences_are_listed_with_defaults_and_channel_availability(): void
    {
        $user = $this->makeUser('سارة');

        $response = $this->getJsonAs('/api/notification-preferences', $user)->assertOk();

        $channels = collect($response->json('channels'))->keyBy('channel');

        $this->assertSame(['email', 'whatsapp', 'sms'], array_keys($channels->all()));
        $this->assertTrue($channels['email']['is_enabled']);
        $this->assertTrue($channels['email']['available']);
        // ⭐ واتساب/SMS بلا مزوّد مفعّل — التفضيل يُحفظ لا يُرسل.
        $this->assertFalse($channels['whatsapp']['available']);
        $this->assertFalse($channels['sms']['available']);
        $this->assertNotEmpty($channels['whatsapp']['note']);
    }

    public function test_user_can_disable_a_channel_and_dispatch_respects_it(): void
    {
        $user = $this->makeUser('سارة');

        $response = $this->putJsonAs('/api/notification-preferences', [
            'preferences' => [
                ['channel' => 'email', 'is_enabled' => false],
            ],
        ], $user)->assertOk();

        $channels = collect($response->json('channels'))->keyBy('channel');
        $this->assertFalse($channels['email']['is_enabled']);

        $this->assertDatabaseHas('notification_preferences', [
            'user_id' => $user->id,
            'channel' => 'email',
            'is_enabled' => false,
        ]);

        $this->service()->dispatch('lesson_reminder', $user, [
            'title' => 'تذكير',
            'message' => 'نص',
        ], ['email']);

        $this->assertSame(
            0,
            NotificationDelivery::where('channel', 'email')->count(),
            'قناة عطّلها المستخدم بنفسه لازم تتخطى',
        );
    }

    public function test_preferences_reject_a_channel_outside_the_self_service_list(): void
    {
        $user = $this->makeUser('سارة');

        $this->putJsonAs('/api/notification-preferences', [
            'preferences' => [
                ['channel' => 'database', 'is_enabled' => false],
            ],
        ], $user)->assertStatus(422)->assertJsonValidationErrors('preferences.0.channel');
    }

    private function service(): NotificationService
    {
        return app(NotificationService::class);
    }
}
