<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Student;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * PR 2.16 — أمر تنبيهات الفواتير المتأخرة.
 *
 * ══════════════════════════════════════════════════════════════
 * المواصفة طلبت أمر `invoices:mark-overdue` «ما يخزنش حالة — بس
 * يشغّل تنبيهات». الموجود فعلاً (المشروع متقدم على النص):
 * `invoices:notify-overdue` — تنبيهات في 3/7/14 يوم متأخرة،
 * **بدون تغيير أي status** (الحالة بتتحسب من التاريخ)، لكن كان
 * **غير مسجّل في الجدولة اليومية** — تعليق الملف يزعم أنه
 * «يشتغل يومياً» وكل ما التسجيل مكانش.
 *
 * فالاختبار هنا يمسك الحاجات الثلاثة:
 * ① الأمر ما يغيّرش status أي فاتورة (بند «ما يخزنش»)
 * ② التكرار محفوظ: تنبيه واحد لكل (فاتورة، فترة)
 * ③ الأمر مسجّل فالجدولة اليومية 09:00 مع حماية التداخل
 * ④ ⭐ واضافتين: علاقة `parent` على `Student` كانت **غير
 *    موجودة** فالأمر كان يرمي RelationNotFoundException مع أول
 *    فاتورة لها طالب — والصحيح الآن `parents()` بالربط المؤكد.
 * ══════════════════════════════════════════════════════════════
 */
class OverdueAlertsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoiceDue(int $daysAgo, string $invoiceNumber, string $status = 'issued', ?Student $student = null): Invoice
    {
        $today = Carbon::create(2026, 10, 20, 10, 0, 0);

        return Invoice::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => ($student ?? $this->makeStudent())->id,
            'invoice_number' => $invoiceNumber,
            'issue_date' => $today->copy()->subDays(30)->toDateString(),
            'due_date' => $today->copy()->subDays($daysAgo)->toDateString(),
            'list_amount' => 500,
            'subtotal' => 500,
            'discount' => 0,
            'tax' => 0,
            'total' => 500,
            'paid_amount' => 0,
            'balance_due' => 500,
            'credited_amount' => 0,
            'currency' => 'EGP',
            'status' => $status,
        ]);
    }

    public function test_the_command_fires_alerts_without_storing_the_status(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        // مستلم واحد بدور admin — الأمر بيتبعت للإدارة، وهنا واحد
        $this->makeUserWithRole('admin', ['reports.view']);

        // ⭐ ولي أمر مربوط مؤكد بس عنده user — بيستلم التنبيه هو كمان
        $parentUser = $this->makeUser();
        $parentProfile = $this->makeParentProfile($parentUser);
        $student3d = $this->makeStudent();
        $student3d->parents()->attach($parentProfile->id, [
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        $this->invoiceDue(3, 'INV-OD-3D', student: $student3d);  // فترة 3
        $this->invoiceDue(14, 'INV-OD-14D');                     // فترات 3 → 7 → 14
        $this->invoiceDue(1, 'INV-OD-1D');                       // فترة مش في القوائم
        $this->invoiceDue(3, 'INV-OD-PAID', 'paid');             // مدفوعة

        $exitCode = \Artisan::call('invoices:notify-overdue', ['--date' => '2026-10-20']);
        $this->assertSame(0, $exitCode);

        $notified = Notification::query()->where('event_type', 'invoice_overdue')->count();
        // ⭐ دلالة الأمر: تنبيه واحد **لكل فترة بالضبط** في يومها
        // (فاتورة متأخرة 14 يوم = تنبيه فترة 14 only — الشهر الماضي
        // كانت هتبعت فالجري اليومي في يوم الـ3 ويوم الـ7). فالتشغيل
        // الوحيد هنا: INV-OD-3D → فترة 3 (ولي الأمر + الإدارة = 2) +
        // INV-OD-14D → فترة 14 (الإدارة = 1) = 3
        $this->assertSame(3, $notified, 'التنبيهات: 3D×2 + 14D×1');

        // ⭐ ولي الأمر وصلته تنبيته — بعد إصلاح علاقة parents()
        $this->assertDatabaseHas('notifications', [
            'user_id' => $parentUser->id,
            'event_type' => 'invoice_overdue',
        ]);

        // ⭐ «ما يخزنش حالة» — كل الفواتير زي ما هي
        foreach (Invoice::query()->whereIn('invoice_number', ['INV-OD-3D', 'INV-OD-14D', 'INV-OD-1D'])->get() as $inv) {
            $this->assertSame('issued', $inv->status, 'الحالة اتغيّرت — والأمر ما يخزنش حالة');
        }

        // ⭐ التكرار — الفترة بتنبعت مرة واحدة لكل فاتورة
        $exitAgain = \Artisan::call('invoices:notify-overdue', ['--date' => '2026-10-20']);
        $this->assertSame(0, $exitAgain);
        $this->assertSame(3, Notification::query()->where('event_type', 'invoice_overdue')->count(), 'التشغيل التاني زوّد تنبيهات');
    }

    public function test_the_dry_run_sends_nothing(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->makeUserWithRole('admin', ['reports.view']);
        $this->invoiceDue(3, 'INV-OD-DRY');

        $exit = $this->artisan('invoices:notify-overdue', ['--date' => '2026-10-20', '--dry-run' => true]);
        $exit->assertExitCode(0);

        $this->assertSame(0, Notification::query()->where('event_type', 'invoice_overdue')->count(), 'الdry-run بعت تنبيهات');
    }

    // ⭐ ③ الأمر في الجدولة اليومية — كان معمول مكتوب في التعليق
    //    «يشتغل يومياً» من غير أي تسجيل فعلي. الفحص هنا على الملف
    //    (البيانات السلوكية للأمر نفسه فوق).
    public function test_the_command_is_scheduled_daily(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('invoices:notify-overdue', $bootstrap, 'الأمر مش مسجّل في الجدولة');
        $this->assertStringContainsString("dailyAt('09:00')", $bootstrap, 'الجدولة مش يومية 09:00');
        $this->assertStringContainsString('withoutOverlapping', $bootstrap, 'الجدولة بلا حماية التداخل');
    }
}
