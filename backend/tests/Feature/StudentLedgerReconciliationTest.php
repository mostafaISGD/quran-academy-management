<?php

namespace Tests\Feature;

use App\Models\FinanceAlert;
use App\Models\StudentLedgerEntry;
use App\Services\PaymentService;
use App\Services\RefundService;
use App\Services\StudentLedgerService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PR 2.9 - دفتر الطالب الموحد + التسوية اليومية.
 *
 * قبل كده `student_ledger_entries` كان في **7 كتّاب** بـ4
 * دلالات مختلفة لـ`balance_after`:
 *
 *   ① `GroupEnrollmentService` - رصيد تجميعي (من غير فلترة
 *      عملة - طالب بعملتين بيلخبط)
 *   ② `DailySubscriptionService` - نفس الفخ
 *   ③ `SubscriptionActivationService` / `InvoiceController` /
 *      `StudentController` - رصيد = المبلغ نفسه (بيبدأ من
 *      الصفر دايما - الكوادر اللي قبله تتمسح)
 *   ④ `PaymentService` / `RefundService` - رصيد الفاتورة
 *      (`balance_due`) مش رصيد الطالب
 *
 * PR 2.9 بيفرض دلالة واحدة: **رصيد تجميعي لكل عمولة** —
 * الكتابة كلها من `StudentLedgerService`، والموديل ممنوع
 * يتعديل أو يحذف (التصحيح بسطر جديد).
 *
 * وكمان `finance:reconcile` بيكشف أي انحراف: الفاتورة
 * مقابل توزيعاتها، الدفتر مقابل نفسه، الحسابات مقابل
 * مصادرها، ودفتر المعلم مقابل أرباحه.
 */
class StudentLedgerReconciliationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // 1 - الكتابة الموحدة: رصيد تجميعي لكل عمولة
    // ═══════════════════════════════════════════════════════════

    public function test_the_service_writes_a_running_balance_per_currency(): void
    {
        $student = $this->makeStudent();
        $ledger = app(StudentLedgerService::class);

        // فاتورة 1000
        $firstEntry = $ledger->write([
            'student_id' => $student->id,
            'type' => 'invoice',
            'debit' => 1000.0,
            'currency' => 'EGP',
            'description' => 'فاتورة أ',
            'reference_type' => 'invoice',
            'reference_id' => '1',
        ]);
        $this->assertEquals(1000.0, (float) $firstEntry->balance_after);

        // دفعة 600 → الرصيد نزل 400
        $secondEntry = $ledger->write([
            'student_id' => $student->id,
            'type' => 'payment',
            'credit' => 600.0,
            'currency' => 'EGP',
            'description' => 'دفعة',
        ]);
        $this->assertEquals(400.0, (float) $secondEntry->balance_after);

        // عملة تانية — مالهاش أي علاقة بعمولة الجنيه
        $usdEntry = $ledger->write([
            'student_id' => $student->id,
            'type' => 'invoice',
            'debit' => 200.0,
            'currency' => 'USD',
            'description' => 'فاتورة بالدولار',
        ]);
        $this->assertEquals(200.0, (float) $usdEntry->balance_after);
        $this->assertEquals(400.0, $ledger->balance($student->id, 'EGP'), 'العملات مالوش تختلطش');
        $this->assertEquals(200.0, $ledger->balance($student->id, 'USD'));
        $this->assertEquals(0.0, $ledger->balance($student->id, 'SAR'), 'عملة مالهاش سطور = صفر');

        // سطر جنيه جديد بيكمل من آخر سطر جنيه مش من آخر سطر
        $thirdEgp = $ledger->write([
            'student_id' => $student->id,
            'type' => 'invoice',
            'debit' => 50.0,
            'currency' => 'EGP',
            'description' => 'فاتورة ج',
        ]);
        $this->assertEquals(450.0, (float) $thirdEgp->balance_after);
    }

    // ═══════════════════════════════════════════════════════════
    // 2 - الكتّاب التجاريين عدّوا على الخدمة الموحدة
    // ═══════════════════════════════════════════════════════════

    public function test_payment_entries_carry_the_running_balance(): void
    {
        $this->makeAccount();
        $admin = $this->makeUserWithRole('admin', [
            'groups.manage', 'groups.view', 'finance.payments.create', 'finance.payments.refund',
        ]);
        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $this->makePlan($program, ['price' => 350]);
        $group = $this->makeGroup(['capacity' => 5], $program);

        // دخول الطالب: فاتورة 350 → سطر 350
        $this->postJsonAs("/api/groups/{$group->id}/members", [
            'student_id' => $student->id,
        ], $admin)->assertCreated();

        $invoice = \App\Models\Invoice::where('student_id', $student->id)->firstOrFail();
        $ledger = app(StudentLedgerService::class);
        $this->assertEquals(350.0, $ledger->balance($student->id, 'EGP'));

        // دفعة 300
        [$payment] = app(PaymentService::class)->record([
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 300.0,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $admin);

        // الاسترداد الجزئي 200 → مدين جديد
        app(RefundService::class)->refund($payment, 200.0, 'cash', 'خصم جزئي', null, null, $admin);

        // استرداد كامل بِـ؟ دفعة تانية أكبر من المتبقي: 250 متبقي
        // على الفاتورة لكن الطالب بيدفع 500 → 250 فائض → رصيد الطالب
        // (التجميعي) ينزل تحت الصفر. **الكود القديم** كان يكتب
        // `balance_after = balance_due = 0` — القيمة الموحدة لازم
        // تكون −250 (رصيد الطالب الحقيقي، مش رصيد الفاتورة).
        app(PaymentService::class)->record([
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 500.0,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $admin);

        $entries = StudentLedgerEntry::where('student_id', $student->id)
            ->orderBy('id')->get();

        // 350 | 350−300=50 | 50+200=250 | 250−500=−250
        $this->assertSame(
            ['350.00', '50.00', '250.00', '-250.00'],
            $entries->pluck('balance_after')->values()->all(),
            'الأرصدة لازم تكون تجميعية متسلسلة',
        );
        $this->assertEquals(-250.0, $ledger->balance($student->id, 'EGP'));
    }

    // ═══════════════════════════════════════════════════════════
    // 3 - السطر التاريخي: ممنوع التعديل والحذف
    // ═══════════════════════════════════════════════════════════

    public function test_ledger_entries_cannot_be_edited_or_deleted(): void
    {
        $student = $this->makeStudent();
        $entry = app(StudentLedgerService::class)->write([
            'student_id' => $student->id,
            'type' => 'invoice',
            'debit' => 500.0,
            'currency' => 'EGP',
        ]);

        $this->expectException(\RuntimeException::class);
        $entry->update(['debit' => 1.0]);
    }

    public function test_deleting_a_ledger_entry_is_rejected(): void
    {
        $student = $this->makeStudent();
        $entry = app(StudentLedgerService::class)->write([
            'student_id' => $student->id,
            'type' => 'payment',
            'credit' => 100.0,
            'currency' => 'EGP',
        ]);

        $this->expectException(\RuntimeException::class);
        $entry->delete();
    }

    // ═══════════════════════════════════════════════════════════
    // 4 - كشف الحساب
    // ═══════════════════════════════════════════════════════════

    public function test_the_statement_endpoint_returns_running_entries_and_totals(): void
    {
        $student = $this->makeStudent();
        $ledger = app(StudentLedgerService::class);
        $ledger->write(['student_id' => $student->id, 'type' => 'invoice', 'debit' => 1000.0, 'currency' => 'EGP']);
        $ledger->write(['student_id' => $student->id, 'type' => 'payment', 'credit' => 700.0, 'currency' => 'EGP']);
        $ledger->write(['student_id' => $student->id, 'type' => 'invoice', 'debit' => 50.0, 'currency' => 'USD']);

        $reader = $this->makeUserWithRole('receptionist', ['students.view']);
        $r = $this->getJsonAs("/api/students/{$student->id}/statement", $reader);

        $r->assertOk()->assertJsonStructure([
            'student_id',
            'currencies' => ['EGP' => ['balance', 'total_debit', 'total_credit']],
            'entries' => [['id', 'type', 'debit', 'credit', 'balance_after', 'currency']],
        ]);

        $this->assertEquals(300.0, $r->json('currencies.EGP.balance'));
        $this->assertEquals(50.0, $r->json('currencies.USD.balance'));
        $this->assertSame(3, count($r->json('entries')));

        // الطلب الأول معاها كشف الحساب — بس من غير الصلاحية مرفوض
        $noPerm = $this->makeUserWithRole('guest', ['lessons.view']);
        $this->getJsonAs("/api/students/{$student->id}/statement", $noPerm)->assertStatus(403);
    }

    // ═══════════════════════════════════════════════════════════
    // 5 - التسوية: انحراف مزروع = تنبيه
    // ═══════════════════════════════════════════════════════════

    public function test_an_injected_invoice_drift_generates_an_alert(): void
    {
        $this->makeAccount();
        $admin = $this->makeUserWithRole('admin', ['groups.manage', 'groups.view', 'finance.payments.create']);
        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $this->makePlan($program, ['price' => 350]);
        $group = $this->makeGroup(['capacity' => 5], $program);

        $this->postJsonAs("/api/groups/{$group->id}/members", ['student_id' => $student->id], $admin)->assertCreated();
        $invoice = \App\Models\Invoice::where('student_id', $student->id)->firstOrFail();

        app(PaymentService::class)->record([
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 350.0,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $admin);

        // محاكاة تجاوز للخدمة: حد عدّل `paid_amount` وخلاص،
        // من غير `InvoiceService` — الحساب بقى غلط والانحراف ظاهر
        $piastres = (int) DB::table('invoices')->where('id', $invoice->id)->value('paid_amount');
        DB::table('invoices')->where('id', $invoice->id)->update(['paid_amount' => $piastres + 10000]);

        $this->artisan('finance:reconcile')->assertExitCode(0);

        $alert = FinanceAlert::where('kind', 'invoice_allocation_drift')->first();
        $this->assertNotNull($alert, 'انحراف الفاتورة لازم يطلع تنبيه');
        $this->assertSame($invoice->id, (int) $alert->entity_id);
        $this->assertSame('invoice', $alert->entity_type);
        $this->assertNull($alert->resolved_at);
    }

    public function test_a_ledger_self_drift_generates_an_alert(): void
    {
        $student = $this->makeStudent();

        // سطور مقبولة: 500 مدين → رصيد 500
        app(StudentLedgerService::class)->write([
            'student_id' => $student->id, 'type' => 'invoice',
            'debit' => 500.0, 'currency' => 'EGP',
        ]);

        // سطر مزروع بيدوي — `balance_after` غلط (الصح 200)
        DB::table('student_ledger_entries')->insert([
            'student_id' => $student->id,
            'type' => 'credit',
            'debit' => 300.0,
            'balance_after' => 99999.0, // ← الانحراف
            'currency' => 'EGP',
            'description' => 'سطر مزروع',
            'created_at' => now(),
        ]);

        $this->artisan('finance:reconcile')->assertExitCode(0);

        $this->assertNotNull(
            FinanceAlert::where('kind', 'student_ledger_self_drift')->first(),
            'نظام التجميعية المكسور لازم يطلع تنبيه',
        );
    }

    public function test_a_payment_income_drift_generates_an_alert(): void
    {
        $this->makeAccount();
        $student = $this->makeStudent();
        [$payment] = app(PaymentService::class)->record([
            'student_id' => $student->id,
            'amount' => 500.0,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->makeUserWithRole('receptionist', ['finance.payments.create']));

        // حركة مزروعة زيادة على الدفعة بـ111 — مصدر الحساب ما يطابقش
        DB::table('account_movements')->insert([
            'organization_id' => $this->org->id,
            'financial_account_id' => $this->makeAccount()->id,
            'direction' => 'in',
            'category' => 'income',
            'amount' => 11100, // قرش
            'movement_date' => now()->toDateString(),
            'currency' => 'EGP',
            'reference_type' => 'payment',
            'reference_id' => $payment->id,
            'status' => 'posted',
            'created_at' => now(),
        ]);

        $this->artisan('finance:reconcile')->assertExitCode(0);

        $this->assertNotNull(
            FinanceAlert::where('kind', 'account_movement_drift')->first(),
            'حركة الإيراد المزيعت لازم تنبه',
        );
    }

    public function test_teacher_earnings_without_ledger_rows_generate_an_alert(): void
    {
        $teacher = $this->makeTeacher();
        // أرباح معتمدة 100 ولا سطر في دفتر المعلم — انحراف
        $this->makeEarning($teacher, now()->toDateString(), ['amount' => 100]);
        $this->makeEarning($teacher, now()->toDateString(), ['status' => 'cancelled']);

        $this->artisan('finance:reconcile')->assertExitCode(0);

        $this->assertNotNull(
            FinanceAlert::where('kind', 'teacher_ledger_drift')->first(),
            'أرباح معتمدة من غير سطر دفتر لازم تنبه',
        );
        $this->assertSame(
            1,
            FinanceAlert::where('kind', 'teacher_ledger_drift')->count(),
            'المنهي (cancelled) مش مصدر انحراف',
        );
    }

    // ═══════════════════════════════════════════════════════════
    // 6 - سيناريو كامل سليم = صفر تنبيهات
    // ═══════════════════════════════════════════════════════════

    public function test_full_scenario_subscription_invoice_payment_refund_has_no_alerts(): void
    {
        $this->makeAccount();
        $admin = $this->makeUserWithRole('admin', [
            'groups.manage', 'groups.view', 'finance.payments.create', 'finance.payments.refund',
        ]);
        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $this->makePlan($program, ['price' => 350]);
        $group = $this->makeGroup(['capacity' => 5], $program);

        $this->postJsonAs("/api/groups/{$group->id}/members", ['student_id' => $student->id], $admin)->assertCreated();
        $invoice = \App\Models\Invoice::where('student_id', $student->id)->firstOrFail();

        [$payment] = app(PaymentService::class)->record([
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 350.0,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $admin);

        app(RefundService::class)->refund($payment, 100.0, 'cash', 'خصم', null, null, $admin);

        $this->artisan('finance:reconcile')->assertExitCode(0);

        $this->assertSame(
            0,
            FinanceAlert::count(),
            'سيناريو سليم كامل = مفيش تنبيهات: '
            .FinanceAlert::pluck('message')->implode(' | '),
        );
    }

    // ═══════════════════════════════════════════════════════════
    // 7 - تشغيل مواعيد + منع تكرار التنبيهات + الصلاحيات
    // ═══════════════════════════════════════════════════════════

    public function test_reconcile_is_scheduled_daily(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('finance:reconcile')->assertExitCode(0);
    }

    public function test_reconcile_does_not_duplicate_open_alerts(): void
    {
        $student = $this->makeStudent();
        app(StudentLedgerService::class)->write([
            'student_id' => $student->id, 'type' => 'invoice',
            'debit' => 500.0, 'currency' => 'EGP',
        ]);
        DB::table('student_ledger_entries')->insert([
            'student_id' => $student->id,
            'type' => 'credit',
            'debit' => 300.0,
            'balance_after' => 99999.0,
            'currency' => 'EGP',
            'created_at' => now(),
        ]);

        $this->artisan('finance:reconcile')->assertExitCode(0);
        $this->artisan('finance:reconcile')->assertExitCode(0);

        $this->assertSame(
            1,
            FinanceAlert::where('kind', 'student_ledger_self_drift')->count(),
            'التنبيه المفتوح نفسه ما يتكررش كل يوم',
        );
    }

    public function test_the_alerts_endpoint_is_protected(): void
    {
        $this->makeUserWithRole('viewer', ['students.view']);
        $student = $this->makeStudent();
        app(StudentLedgerService::class)->write([
            'student_id' => $student->id, 'type' => 'invoice',
            'debit' => 100.0, 'currency' => 'EGP',
        ]);
        DB::table('student_ledger_entries')->insert([
            'student_id' => $student->id,
            'type' => 'credit',
            'debit' => 50.0,
            'balance_after' => 7.0,
            'currency' => 'EGP',
            'created_at' => now(),
        ]);
        $this->artisan('finance:reconcile')->assertExitCode(0);

        // من غير صلاحية المالية = مرفوض
        $studentViewer = $this->makeUserWithRole('receptionist', ['students.view']);
        $this->getJsonAs('/api/finance-alerts', $studentViewer)->assertStatus(403);

        // بصلاحية المالية = شغال
        $financier = $this->makeUserWithRole('finance', ['finance.payments.view']);
        $r = $this->getJsonAs('/api/finance-alerts', $financier);
        if ($r->status() !== 200) {
            fwrite(STDERR, "\n500 BODY: ".print_r($r->json(), true)."\n");
        }
        $r->assertOk();
        $this->assertGreaterThanOrEqual(1, count($r->json()));
        $alertId = $r->json('0.id');

        // الإغلاق بصلاحية أعمق — وبعدها مختفي من المفتوحين
        $updater = $this->makeUserWithRole('finance2', ['finance.payments.view', 'finance.payments.update']);
        $this->postJsonAs("/api/finance-alerts/{$alertId}/resolve", [], $studentViewer)->assertStatus(403);
        $this->postJsonAs("/api/finance-alerts/{$alertId}/resolve", [], $updater)->assertOk();
        $this->assertNotNull(FinanceAlert::find($alertId)->resolved_at);
    }

    // ═══════════════════════════════════════════════════════════
    // fixtures
    // ═══════════════════════════════════════════════════════════
}
