<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\FinancialLedgerService;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * PR 2.16 — التقارير اليومية للمتابعة: أعمار الديون، أرصدة
 * الخزائن، التزامات المعلمين، مصادر الإيراد، والتصدير.
 *
 * ══════════════════════════════════════════════════════════════
 * قبل الـPR كان مافيش أي حاجة من دول:
 *
 * ① أعمار الديون (0–30 / 31–60 / 61–90 / +90) — المدير كان
 *    يفتح كل فاتورة ويعد بالعين. العمر يقاس من **تاريخ
 *    الاستحقاق** (مش الإصدار) ومقسمة لكل طالب×عملة.
 * ② أرصدة الخزائن كتقرير: رصيد = الانفرج + مجموع الحركات
 *    **المنشورة** (مش المعلق) — نفس دلالة
 *    `FinancialAccount::balance()` بس في aggregates SQL.
 * ③ التزامات المعلمين: المعتمد − المدفوع لكل معلم.
 * ④ مصادر الإيراد حسب البرنامج والمعلم — عن طريق اشتراك الفاتورة
 *    (فواتير مالهاش اشتراك تخرج).
 * ⑤ التصدير CSV مع **BOM UTF-8** (الإكسل بيفتح العربية سليمة)
 *    + صلاحية `reports.export` + تسجيل في اللوق والـaudit،
 *    وصفوف الإجمالي = مجاميع التقرير نفسها (المقبولية:
 *    مجاميع التقرير = مجاميع الملف).
 *
 * ⚠️ openspout/maatwebsite محتاجين `ext-zip` مش موجود
 *    في الشغل — فالتصدير CSV/BOM دلوقتي، والـXLSX 422
 *    برسالة واضحة (ودر CI بيفعل ext-zip تخفص).
 */
class FollowupReportsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeInvoice(string $due, string $currency, float $balance, string $status = 'issued', array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => $this->makeStudent()->id,
            'invoice_number' => 'INV-R16-'.uniqid(),
            'issue_date' => '2026-10-01',
            'due_date' => $due,
            'list_amount' => $balance,
            'subtotal' => $balance,
            'discount' => 0,
            'tax' => 0,
            'total' => $balance,
            'paid_amount' => $balance >= 0.0001 ? 0 : 0,
            'balance_due' => $balance,
            'credited_amount' => 0,
            'currency' => $currency,
            'status' => $status,
        ], $overrides));
    }

    // ═══════════════════════════════════════════════════════════
    // 1) أعمار الديون — صف لكل (طالب، عملة) + مجاميع لكل عملة
    //    اليوم = 2026-10-20:
    //    2026-08-01 → 80 يوم  → d61_90
    //    2026-09-10 → 40 يوم  → d31_60
    //    2026-10-01 → 19 يوم  → d0_30
    //    2026-06-01 → 141 يوم → d90_plus
    //    2026-12-01 → مستقبلي → not_due
    // ═══════════════════════════════════════════════════════════

    public function test_the_aging_buckets_match_the_manual_data(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $reader = $this->makeUserWithRole('reader16', ['reports.view']);

        $student1 = $this->makeStudent();
        $student2 = $this->makeStudent();
        $student3 = $this->makeStudent();

        $this->makeInvoice('2026-08-01', 'EGP', 1000, overrides: ['student_id' => $student1->id]);
        $this->makeInvoice('2026-10-01', 'EGP', 300, overrides: ['student_id' => $student1->id]);
        $this->makeInvoice('2026-06-01', 'EGP', 700, overrides: ['student_id' => $student1->id]);
        $this->makeInvoice('2026-12-01', 'EGP', 900, overrides: ['student_id' => $student1->id]);
        $this->makeInvoice('2026-09-10', 'EGP', 500, overrides: ['student_id' => $student2->id]);

        // USD لنفس الطالب الثاني — عملة تانية = صف تاني من غير خلطة
        $this->makeInvoice('2026-09-10', 'USD', 200, overrides: ['student_id' => $student2->id]);

        // مدفوعة (balance 0) + لغت + من منظمة تانية — كلهم يختفوا
        $this->makeInvoice('2026-08-05', 'EGP', 0, overrides: ['student_id' => $student3->id]);
        $this->makeInvoice('2026-07-01', 'EGP', 999, 'void', ['student_id' => $student3->id]);

        $otherOrg = Organization::create(['name' => 'أرشيف 216', 'slug' => 'other-216', 'default_currency' => 'EGP', 'default_timezone' => 'Africa/Cairo', 'status' => 'active']);
        $otherStudent = $this->makeStudent();
        $otherStudent->update(['organization_id' => $otherOrg->id]);
        $this->makeInvoice('2026-08-01', 'EGP', 5000, overrides: [
            'organization_id' => $otherOrg->id,
            'student_id' => $otherStudent->id,
            'branch_id' => null,
        ]);

        $r = $this->getJsonAs('/api/reports/aging', $reader);
        $r->assertOk();

        // طالب 1 — أربع فواتير EGP
        $rows = collect($r->json('students'));
        $s1 = $rows->first(fn ($row) => $row['student_id'] === $student1->id);
        $this->assertNotNull($s1, 'طالب 1 مفقود');
        $this->assertSame(900.0, (float) $s1['not_due'], 'مستقبلي 900 في not_due');
        $this->assertSame(300.0, (float) $s1['d0_30'], '19 يوم → 0-30');
        $this->assertSame(0.0, (float) $s1['d31_60']);
        $this->assertSame(1000.0, (float) $s1['d61_90'], '80 يوم → 61-90');
        $this->assertSame(700.0, (float) $s1['d90_plus'], '141 يوم → +90');
        $this->assertSame(2900.0, (float) $s1['total']);

        $s2Egp = $rows->first(fn ($row) => $row['student_id'] === $student2->id && $row['currency'] === 'EGP');
        $this->assertSame(500.0, (float) $s2Egp['d31_60'], '40 يوم → 31-60');
        $s2Usd = $rows->first(fn ($row) => $row['student_id'] === $student2->id && $row['currency'] === 'USD');
        $this->assertNotNull($s2Usd, 'عملة USD لطالب 2 مفقودة — خلطة عملات');
        $this->assertSame(200.0, (float) $s2Usd['d31_60']);

        $this->assertNull($rows->first(fn ($row) => $row['student_id'] === $student3->id), 'مدفوعة/لغتة رجعت في التقرير');

        // ⭐ المنظمة التانية بـ5000 — لو سربت كان مجموعنا زاد
        $egp = $r->json('totals.EGP');
        $this->assertSame(3400.0, (float) $egp['total']);
        $this->assertSame(1000.0, (float) $egp['d61_90']);
        $usd = $r->json('totals.USD');
        $this->assertSame(200.0, (float) $usd['total']);
    }

    // ═══════════════════════════════════════════════════════════
    // 2) أرصدة الخزائن — الانفرج + الحركات المنشورة فقط
    // ═══════════════════════════════════════════════════════════

    public function test_the_account_balances_report_sums_opening_and_posted_movements(): void
    {
        $reader = $this->makeUserWithRole('reader16', ['reports.view']);

        $egp = $this->makeAccount(['currency' => 'EGP', 'opening_balance' => 1000]);
        $usd = $this->makeAccount(['currency' => 'USD', 'opening_balance' => 50]);

        $ledger = app(FinancialLedgerService::class);
        // ⭐ الدفع/التسجيل المباشر عبر `record()` — المرجع nullable هنا
        $ledger->record($egp, 'in', 'income', 400, '2026-10-10', 'EGP', 'payment', null);
        $ledger->record($egp, 'out', 'expense', 100, '2026-10-12', 'EGP', 'expense', null);
        // معلقة = فلوس لسه مش حقيقية — مكانها في التقرير مش هنا
        $ledger->record($egp, 'in', 'income', 777, '2026-10-14', 'EGP', 'income', null, null, null, 'pending');

        $r = $this->getJsonAs('/api/reports/account-balances', $reader);
        $r->assertOk();

        $accounts = collect($r->json('accounts'));
        $repEgp = $accounts->first(fn ($a) => $a['id'] === $egp->id);
        $this->assertNotNull($repEgp, 'حساب EGP مفقود');
        $this->assertSame(1300.0, (float) $repEgp['balance'], '1000 + 400 − 100 (المعلق 777 مستبعد)');
        $this->assertSame(300.0, (float) $repEgp['movements_sum'], 'مجموع الحركات المنشورة');
        $this->assertSame(1000.0, (float) $repEgp['opening_balance']);

        $repUsd = $accounts->first(fn ($a) => $a['id'] === $usd->id);
        $this->assertSame(50.0, (float) $repUsd['balance'], 'حساب بدون حركات = انفرجه');

        $this->assertSame(1300.0, (float) $r->json('totals.EGP.balance'));
        $this->assertSame(50.0, (float) $r->json('totals.USD.balance'));
    }

    // ═══════════════════════════════════════════════════════════
    // 3) التزامات المعلمين — المعتمد − المدفوع (المعلق مش التزام)
    // ═══════════════════════════════════════════════════════════

    public function test_the_teacher_liabilities_are_earnings_minus_paid(): void
    {
        $reader = $this->makeUserWithRole('reader16', ['reports.view']);

        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();

        \App\Models\TeacherEarning::create(['teacher_id' => $t1->id, 'amount' => 300, 'currency' => 'EGP', 'earning_date' => '2026-10-01', 'status' => 'approved']);
        \App\Models\TeacherPayment::create(['teacher_id' => $t1->id, 'amount' => 120, 'currency' => 'EGP', 'status' => 'completed', 'paid_at' => '2026-10-15 10:00:00', 'processed_by' => $reader->id]);
        // معلقة 500 — مش التزام صافية للحاجة
        \App\Models\TeacherEarning::create(['teacher_id' => $t2->id, 'amount' => 500, 'currency' => 'EGP', 'earning_date' => '2026-10-02', 'status' => 'pending']);

        $r = $this->getJsonAs('/api/reports/teacher-liabilities', $reader);
        $r->assertOk();

        $teachers = collect($r->json('teachers'));
        $rep1 = $teachers->first(fn ($t) => $t['teacher_id'] === $t1->id);
        $this->assertNotNull($rep1, 'معلم 1 مفقود');
        $this->assertSame(300.0, (float) $rep1['gross_earnings']);
        $this->assertSame(120.0, (float) $rep1['payments_made']);
        $this->assertSame(180.0, (float) $rep1['liability'], '300 − 120 = 180');

        $this->assertNull($teachers->first(fn ($t) => $t['teacher_id'] === $t2->id), 'استحقاق معلقة (مش معتمد) دخلت الترتبات');

        $this->assertSame(180.0, (float) $r->json('totals.EGP.liability'));
        $this->assertSame(300.0, (float) $r->json('totals.EGP.gross_earnings'));
        $this->assertSame(120.0, (float) $r->json('totals.EGP.payments_made'));
    }

    // ═══════════════════════════════════════════════════════════
    // 4) الإيراد برصيد المصادر — عبر اشتراك الفاتورة؛ فاتورة بلا
    //    اشتراك مش منسبة (موثق).
    // ═══════════════════════════════════════════════════════════

    public function test_the_revenue_sources_split_by_program_and_teacher(): void
    {
        $reader = $this->makeUserWithRole('reader16', ['reports.view']);

        $p1 = $this->makeProgram(['name' => 'برنامج حفظ صغير']);
        $p2 = $this->makeProgram(['name' => 'برنامج تلاوة']);
        $t1 = $this->makeTeacher();
        $t2 = $this->makeTeacher();

        $s1 = $this->makeStudent();
        $s2 = $this->makeStudent();
        $sub1 = $this->makeSubscription($p1, $s1, ['teacher_id' => $t1->id]);
        $sub2 = $this->makeSubscription($p2, $s2, ['teacher_id' => $t2->id]);

        $this->makeInvoice('2026-11-01', 'EGP', 350, overrides: ['student_id' => $s1->id, 'subscription_id' => $sub1->id]);
        $this->makeInvoice('2026-11-01', 'EGP', 250, overrides: ['student_id' => $s2->id, 'subscription_id' => $sub2->id]);
        // فاتورة مالهاش اشتراك — منسبة مصادرها مش معروفة
        $this->makeInvoice('2026-11-01', 'EGP', 400, overrides: ['student_id' => $this->makeStudent()->id]);

        $r = $this->getJsonAs('/api/reports/revenue-sources', $reader);
        $r->assertOk();

        $programs = collect($r->json('by_program'));
        $this->assertSame(350.0, (float) $programs->first(fn ($p) => $p['program_id'] === $p1->id)['invoiced']);
        $this->assertSame(250.0, (float) $programs->first(fn ($p) => $p['program_id'] === $p2->id)['invoiced']);

        $teachers = collect($r->json('by_teacher'));
        $this->assertSame(350.0, (float) $teachers->first(fn ($t) => $t['teacher_id'] === $t1->id)['invoiced']);
        $this->assertSame(250.0, (float) $teachers->first(fn ($t) => $t['teacher_id'] === $t2->id)['invoiced']);

        // مافيش فاتورة مش منسبة ولا خلطة عملات
        $this->assertEquals(600.0, array_sum(array_map(fn ($p) => (float) $p['invoiced'], $r->json('by_program'))), 'فاتورة بلا اشتراك دخلت المجمع');
    }

    // ═══════════════════════════════════════════════════════════
    // 5) التصدير — BOM + عربي سليم + مجموع يساوي مجموع التقرير +
    //    صلاحية + لوق + audit
    // ═══════════════════════════════════════════════════════════

    public function test_the_export_streams_csv_with_bom_and_matching_totals(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');
        $exporter = $this->makeUserWithRole('exporter16', ['reports.view', 'reports.export']);
        $viewer = $this->makeUserWithRole('viewer16', ['reports.view']);

        $invoice = $this->makeInvoice('2026-10-05', 'EGP', 1650, overrides: ['invoice_number' => 'INV-R16-EXP']);
        $this->makeInvoice('2026-12-01', 'EGP', 350, overrides: [
            'student_id' => $invoice->student_id,
            'invoice_number' => 'INV-R16-EXP2',
        ]);

        $r = $this->getJsonAs('/api/reports/export?report=aging&format=csv', $exporter);
        $r->assertOk();
        $content = $r->getContent();

        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3), 'مافيش BOM — الإكسل هيكسر العربي');
        $this->assertStringContainsString('الطالب', $content, 'العربي تكسر في الـCSV');
        $this->assertStringContainsString('INV-R16-EXP', $content, 'الفاتورة مكانها في الملف');
        // ⭐ الصف الأخير = مجاميع التقرير نفسها (المقبولية)
        $this->assertStringContainsString('2000.00', $content, 'مجموع الملف لا يساوي مجاميع التقرير');

        // من غير صلاحية التصدير → ممنوع
        $this->getJsonAs('/api/reports/export?report=aging&format=csv', $viewer)->assertForbidden();

        // XLSX مش متاح فالشغل (مافيش ext-zip) — 422 برسالة واضحة
        $this->getJsonAs('/api/reports/export?report=aging&format=xlsx', $exporter)->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // 6) تصدير الفواتير/المدفوعات/المصروفات — سجلين مالهمش تجwelcome،
    //    مسموح بالفترة، وضروري اللوق والـaudit.
    // ═══════════════════════════════════════════════════════════

    public function test_the_entity_exports_stream_rows_and_log_the_export(): void
    {
        $exporter = $this->makeUserWithRole('exporter16', ['reports.view', 'reports.export']);

        $invoice = $this->makeInvoice('2026-11-30', 'EGP', 800, overrides: ['invoice_number' => 'INV-R16-PAY']);
        Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $invoice->student_id,
            'amount' => 300,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => '2026-10-18 10:00:00',
            'received_by' => $exporter->id,
        ]);

        $r = $this->getJsonAs('/api/reports/export?report=invoices', $exporter);
        $r->assertOk();
        $content = $r->getContent();
        $this->assertSame("\xEF\xBB\xBF", substr($content, 0, 3));
        $this->assertStringContainsString('INV-R16-PAY', $content);
        $this->assertStringNotContainsString('غير موجود', $content);

        $r = $this->getJsonAs('/api/reports/export?report=payments', $exporter);
        $r->assertOk();
        $this->assertStringContainsString('300.00', (string) $r->getContent(), 'الدفعة مكانها في ملف المدفوعات');

        // ⭐ اللوق + الـaudit — التصدير نسخة بيانات خارجية لازم يتسجل
        $log = (string) @file_get_contents(storage_path('logs/laravel.log'));
        $this->assertStringContainsString('reports.export', $log, 'التصدير ما اتسجلش في اللوق');
        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'report_export',
            'action' => 'export',
            'user_id' => $exporter->id,
        ]);
    }
}
