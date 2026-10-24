<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Student;
use App\Services\NotificationService;
use App\Support\DeliveryDeferredException;
use App\Support\DeliveryRetryableException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 4.1 — البنية التحتية للإشعارات: طابور ومزودون حقيقة.**
 *
 * ⭐ **قبل PR ده** مافيش `NotificationService` ولا جداول
 * القوالب/التسليمات، و`bulkNotify` كان يكتب إشعارات بـ
 * `sent_at = now()` من غير ما يُرسل شيء فعلياً — تقرير
 * «تم الإرسال» كان كُذبة.
 *
 * ⭐ **القبول:**
 *
 * ① `sent_at` في التسليمات لا يُكتب إلا بعد إرسال فعلي.
 * ② فشل المزود يترك الحالة `failed` مع السبب، والإعادة تنجح
 *    حين يصحّ المزود.
 * ③ `bulkNotify` يعمل عبر الطابور ويصحو على نطاق المؤسسة.
 */
class NotificationInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ⭐ الطابور مزيف في كل الاختبارات — الجوب لا ينفَّذ إلا
        // بمحاولة يدوية مقصودة (determinism تحت sync driver).
        Queue::fake();
    }

    // ═══════════════════════════════════════════════════════════════
    // ① dispatch — الطابور أولاً، والساعة فقط بعد الإرسال
    // ═══════════════════════════════════════════════════════════════

    public function test_dispatch_queues_channels_without_fabricating_sent_at(): void
    {
        Queue::fake();

        $student = $this->makeStudent();

        $service = app(NotificationService::class);

        $service->dispatch('lesson_reminder', $student, [
            'title' => 'تذكير',
            'message' => 'عندك درس غداً',
            'student_id' => $student->id,
        ], ['email']);

        // ⭐ قناة email في الطابور — لا إرسال ولا ساعة مزيفة.
        $delivery = NotificationDelivery::query()->sole();

        $this->assertSame('queued', $delivery->status);
        $this->assertNull(
            $delivery->sent_at,
            'تم تسجيل ساعة إرسال بلا تسليم فعلي — نفس علة bulkNotify القديمة',
        );
        $this->assertSame(0, (int) $delivery->attempts);
        $this->assertSame('email', $delivery->channel);
        $this->assertSame('lesson_reminder', $delivery->event);

        Queue::assertPushed(\App\Jobs\DeliverNotification::class);
    }

    public function test_the_database_channel_writes_the_inbox_without_any_delivery_lie(): void
    {
        $student = $this->makeStudent();
        $user = $this->makeUserWithRole('student-db-user', []);
        $student->update(['user_id' => $user->id]);

        $service = app(NotificationService::class);
        $service->dispatch('lesson_reminder', $student, [
            'title' => 'أهلاً',
            'message' => 'درس غد',
        ], ['database']);

        // ⭐ قناة database = كتابة الصندوق (الإشعار نفسه) — بلا صف
        // تسليم خارجي.
        $this->assertSame(1, Notification::count());
        $this->assertNotNull(
            Notification::first()->sent_at,
            'كتابة الصندوق نفسها تسليم — ساعتها صادقة',
        );
        $this->assertSame(0, NotificationDelivery::count());
    }

    // ═══════════════════════════════════════════════════════════════
    // ② المزود يفشل → failed بسببه — والإعادة تنجح حين يصحّ
    // ═══════════════════════════════════════════════════════════════

    public function test_a_provider_failure_marks_failed_with_a_reason_and_a_retry_heals_it(): void
    {
        config(['notifications.providers.email' => \App\Services\NotificationProviders\FailingThenHealingProvider::class]);

        $student = $this->makeStudent();

        $service = app(NotificationService::class);
        $service->dispatch('lesson_reminder', $student, [
            'title' => 'تنبيه',
            'message' => 'يرجى الاطلاع',
        ], ['email']);

        $delivery = NotificationDelivery::query()->sole();

        // ⭐ المحاولة الأولى: المزود فاشل → استثناء قابل لإعادة
        // الطابور، والصف أُسجل بـ failed وسببه.
        try {
            $service->attemptDelivery($delivery->id);
            $this->fail('مرت الخدمة فوق فشل المزود بلا استثناء');
        } catch (DeliveryRetryableException) {
            // السلوك المنتظر.
        }

        $delivery->refresh();
        $this->assertSame('failed', $delivery->status);
        $this->assertNull($delivery->sent_at, 'فشل المزود ما يفتحش ساعة إرسال');
        $this->assertNotNull($delivery->error, 'فشل بلا سبب مسجل');
        $this->assertSame(1, (int) $delivery->attempts);

        // ⭐ المزود اتصحّ — إعادة المحاولة ترسل.
        \App\Services\NotificationProviders\FailingThenHealingProvider::$healthy = true;

        $this->assertTrue($service->attemptDelivery($delivery->id));

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at, 'الإرسال الصحيح يفتح الساعة');
        $this->assertNotNull($delivery->external_id);
    }

    public function test_attempt_budget_stops_disaster_after_the_cap(): void
    {
        config(['notifications.providers.email' => \App\Services\NotificationProviders\AlwaysBrokenProvider::class]);

        $student = $this->makeStudent();
        $service = app(NotificationService::class);
        $service->dispatch('lesson_reminder', $student, ['title' => 'x', 'message' => 'y'], ['email']);
        $delivery = NotificationDelivery::query()->sole();

        // ⭐ لكل محاولة (سقف) يُرمى استثناء قابل للإعادة؛ بعد
        // استنفاد السقف تتوقف الرمي (المحاولة صارت نهائية).
        $triesSoFar = 0;
        $caught = 0;
        while ($triesSoFar < NotificationService::MAX_ATTEMPTS + 1) {
            $triesSoFar++;
            try {
                $service->attemptDelivery($delivery->id);
            } catch (DeliveryRetryableException) {
                $caught++;
                continue;
            }
            // بلا استثناء = نهائية.
            break;
        }

        $this->assertSame(
            NotificationService::MAX_ATTEMPTS,
            (int) $delivery->fresh()->attempts,
            'المحاولات اتبدت تتجاوز السقف',
        );
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    // ═══════════════════════════════════════════════════════════════
    // تفضيلات المستخدم + ساعات الهدوء
    // ═══════════════════════════════════════════════════════════════

    public function test_user_preferences_filter_disabled_channels(): void
    {
        $student = $this->makeStudent();
        $user = $this->makeUserWithRole('student-pref-user', []);
        $student->update(['user_id' => $user->id]);

        NotificationPreference::create([
            'organization_id' => (int) $this->org->id,
            'user_id' => $user->id,
            'channel' => 'email',
            'is_enabled' => false,
        ]);

        $service = app(NotificationService::class);
        $service->dispatch('lesson_reminder', $student, ['title' => 'x', 'message' => 'y'], ['database', 'email']);

        // ⭐ الإيميل مقفول لهذا المستخدم — لا صف تسليم إيميل له.
        $this->assertSame(1, Notification::count(), 'الصندوق هو صف قناة database');
        $this->assertSame(
            0,
            NotificationDelivery::where('channel', 'email')->count(),
            'قناة معطلة من تفضيلات المستخدم اتنقلت للتسليم',
        );
    }

    public function test_quiet_hours_defer_non_database_channels(): void
    {
        Carbon::setTestNow('2026-10-24 15:00:00');

        $student = $this->makeStudent();
        $orgId = (int) $this->org->id;

        Setting::set('notifications.quiet_hours_start', '14:00', $orgId, null);
        Setting::set('notifications.quiet_hours_end', '16:00', $orgId, null);

        $service = app(NotificationService::class);
        $service->dispatch('lesson_reminder', $student, ['title' => 'x', 'message' => 'y'], ['email']);

        $delivery = NotificationDelivery::query()->sole();

        // ⭐ في ساعات الهدوء: لا محاولة، ولا عدّاد تأكل، بانتظار الفتح.
        try {
            $service->attemptDelivery($delivery->id);
            $this->fail('الإرسال تجاهل ساعات الهدوء');
        } catch (DeliveryDeferredException) {
            // المنتظر.
        }

        $delivery->refresh();
        $this->assertSame('queued', $delivery->status);
        $this->assertNull($delivery->sent_at);
        $this->assertSame(0, (int) $delivery->attempts);

        // ⭐ بعد انتهاء الهدوء: الإرسال يجري.
        Carbon::setTestNow('2026-10-24 17:00:00');
        config(['notifications.providers.email' => \App\Services\NotificationProviders\ObedientProvider::class]);

        $this->assertTrue($service->attemptDelivery($delivery->id));
        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertNotNull($delivery->sent_at);
    }

    // ═══════════════════════════════════════════════════════════════
    // القوالب — إحلال القيم على القالب العربي
    // ═══════════════════════════════════════════════════════════════

    public function test_templates_render_arabic_placeholders_into_the_delivery(): void
    {
        $student = $this->makeStudent(['first_name' => 'عبدالله', 'last_name' => 'الخمار']);

        NotificationTemplate::create([
            'organization_id' => (int) $this->org->id,
            'event' => 'lesson_reminder',
            'channel' => 'email',
            'language' => 'ar',
            'title_template' => 'تذكير لـ :student_name',
            'body_template' => 'عندك درس من :student_name — الحقة قبل :deadline',
        ]);

        $service = app(NotificationService::class);
        $service->dispatch('lesson_reminder', $student, [
            'student_name' => $student->full_name,
            'deadline' => '2026-10-25 16:00',
        ], ['email']);

        $delivery = NotificationDelivery::query()->sole();
        $payload = $delivery->payload;

        // ⭐ التوقعات مبنية على accessor الاسم الحقيقي (full_name)
        // حتى لا تنكسر الاختبارات على غموض مسافاته الداخلية.
        $this->assertSame(
            'تذكير لـ '.$student->full_name,
            $payload['title'],
            'إحلال القالب العربي لم يحدث',
        );
        $this->assertSame(
            'عندك درس من '.$student->full_name.' — الحقة قبل 2026-10-25 16:00',
            $payload['body'],
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ bulkNotify — الطابور + عزل المؤسسة (قبول المواصفة)
    // ═══════════════════════════════════════════════════════════════

    public function test_bulk_notify_queues_external_channels_without_fabricating_sent_at(): void
    {
        $staff = $this->makeUserWithRole('bulk-staff', ['students.update']);
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        // مستلمون بالحسابات — للصندوق سجل قراءة حقيقي.
        $studentA->update(['user_id' => $this->makeUserWithRole('student-bulk-a', [])->id]);
        $studentB->update(['user_id' => $this->makeUserWithRole('student-bulk-b', [])->id]);

        $r = $this->postJsonAs('/api/students/bulk/notify', [
            'student_ids' => [$studentA->id, $studentB->id],
            'title' => 'تنبيه الصرف',
            'message' => 'صرف نقدية غداً',
            'channel' => 'email',
        ], $staff)->assertOk();

        $payload = $r->json();
        $this->assertSame(2, $payload['sent_count']);
        $this->assertSame(2, $payload['requested_count']);

        // ⭐ الصندوق يتكتب (سجل للإدارة) وتسليمات القناة الخارجية
        // في الطابور — بلا ساعة مزيفة.
        $this->assertSame(2, Notification::count());

        NotificationDelivery::query()->get()->each(
            fn ($d) => $this->assertNull($d->sent_at, "صف تسليم قناة {$d->channel} عليه ساعتا إرجاع مزيفة"),
        );
        $this->assertSame(2, NotificationDelivery::where('status', 'queued')->count());
    }

    public function test_bulk_notify_rejects_students_outside_the_caller_organization(): void
    {
        $staff = $this->makeUserWithRole('bulk-owner', ['students.update']);
        $ours = $this->makeStudent();

        $otherOrg = Organization::create([
            'name' => 'أكاديمية الغرب',
            'slug' => 'western-academy-41',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);
        $foreign = Student::create([
            'organization_id' => (int) $otherOrg->id,
            'first_name' => 'هارون',
            'last_name' => 'الغريب',
            'student_code' => 'WEST-001',
            'status' => 'active',
        ]);

        $r = $this->postJsonAs('/api/students/bulk/notify', [
            'student_ids' => [$ours->id, $foreign->id],
            'message' => 'اجتماع غداً',
        ], $staff)->assertStatus(422);

        $message = $r->json('message');
        $this->assertIsString($message);
        // الرسالة عربية وتبيّن وجود طلاب خارج النطاق.
        $this->assertStringContainsString('نطاق', $message);

        // ⭐ صفر كتابة لكليهما (الطلب مرفوض كله — لا إرسال جزئي صامت).
        $this->assertSame(0, Notification::count());
        $this->assertSame(0, NotificationDelivery::count());
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ الأمر المجدول — `notifications:retry-queued`
    // ═══════════════════════════════════════════════════════════════

    public function test_the_scheduled_retry_command_sends_queued_work_and_leaves_deferred_channels(): void
    {
        config(['notifications.providers.email' => \App\Services\NotificationProviders\ObedientProvider::class]);

        $student = $this->makeStudent();
        $service = app(NotificationService::class);
        $service->dispatch('lesson_reminder', $student, [
            'title' => 'x',
            'message' => 'y',
        ], ['email', 'whatsapp']);

        $this->assertSame(2, NotificationDelivery::where('status', 'queued')->count());

        $this->artisan('notifications:retry-queued')->assertExitCode(0);

        $this->assertSame(
            1,
            NotificationDelivery::where('channel', 'email')->where('status', 'sent')->count(),
            'الأمر المجدول لم يُرسل صف قناة الإيميل',
        );
        $this->assertSame(
            1,
            NotificationDelivery::where('channel', 'whatsapp')->where('status', 'queued')->count(),
            'القناة المؤجلة لا بد أن تبقى على الطابور بلا محاولة',
        );
        $this->assertSame(0, (int) NotificationDelivery::where('channel', 'whatsapp')->value('attempts'));
    }
}
