<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\Refund;
use App\Models\TeacherEarning;
use App\Models\TeacherPayment;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PR 2.15 — التقرير المالي كان ساكتاً من التجاهل.
 *
 * الوضع قبل كده (financial()):
 *
 * ① **حمّل السجلات في الذاكرة** (`->get()`) وجمع في PHP —
 *    مئات الآلاف من الفواتير/الدفعات = OOM. التقرير لازم aggregates
 *    في SQL (`SUM`/`GROUP BY`) ومعمم «مافيش تحميل موديلات».
 * ② **خلطة وحدات**: المحصّل والمصروفات والمرتبات كانت بتتجمع
 *    صرفاً بين الجنيه والدولار (100 + 100 = 200!). المواصفة:
 *    **لكل عملة لوحدها**، ودفعة المحوّلة بسعر الصرف **المسجّل
 *    وقت العملية** (سعر مارس mfش سعر اليوم).
 * ③ **تسريب منظمات**: مافيش أي `organization_id` على أي استعلام —
 *    تقرير أكاديمية كان بيعدّ فواتير أكاديمية تانية!
 * ④ الفلترتين `branch`/`program`/`teacher` مكانش لهم وجود.
 * ⑤ `void` (لغت) كانت بتعد في المفوتَر، والمعلق في المرتبات —
 *    أرقام مزيفة صرف.
 */
class FinancialReportTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // 1) الأرقام تظبط يدوياً — لكل عملة + المحوّل بسعر وقتها
    // ═══════════════════════════════════════════════════════════
    //
    //   أسعار الصرف: USD→EGP = 50 من أول فبراير، و 80 من 20 مارس
    //   ═══ كل عملية بتحوّل بسعر **يومها** حتى لو جوه نفس الشهر ══
    //
    //   فواتير:        EGP 1000 (مخصوم 200 → إجمالي 800) + USD 100 ← محوّل 5000
    //   مفوتَر محوّل:  800 + 5000 = 5800
    //   دفعات:         EGP 600 + USD 10 (×50 = 500) + USD 20 (×80 = 1600)
    //   محصّل محوّل:   600 + 500 + 1600 = 2700
    //   مسترد:         EGP 50
    //   مصروفات:       300 + 50 = 350 (المرفوض/المسود مختفي)
    //   مرتبات معلمين: 120 (المعلق 300 مختفي)
    //   صافي تشغيل:    2700 − 350 − 120 = 2230
    //   خارج الشهر:    دفعة أبريل 70 + دفعة فبراير 50 — مختفية

    public function test_the_report_sums_the_pipeline_per_currency_and_converts_at_operation_time(): void
    {
        $reader = $this->makeUserWithRole('reader15', ['reports.view']);

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'EGP', 'rate' => 50, 'valid_date' => '2026-02-01']);
        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'EGP', 'rate' => 80, 'valid_date' => '2026-03-20']);

        $student = $this->makeStudent();

        // فاتورة EGP بمخصوم ترويجي 80 من إجمالي 200 (يدوي 120)
        $invoice = Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-R15-EGP',
            'issue_date' => '2026-03-05',
            'due_date' => '2026-03-30',
            'list_amount' => 1000,
            'subtotal' => 1000,
            'discount' => 200,
            'tax' => 0,
            'total' => 800,
            'paid_amount' => 0,
            'balance_due' => 800,
            'credited_amount' => 0,
            'currency' => 'EGP',
            'status' => 'issued',
        ]);

        $promo = PromoCode::create([
            'organization_id' => $this->org->id,
            'code' => 'R15P',
            'name' => 'عرض peek',
            'type' => 'fixed',
            'value' => 80,
            'is_active' => true,
        ]);
        PromoCodeUsage::create([
            'organization_id' => $this->org->id,
            'promo_code_id' => $promo->id,
            'invoice_id' => $invoice->id,
            'student_id' => $student->id,
            'discount_amount' => 80,
            'original_amount' => 1000,
            'final_amount' => 800,
        ]);

        Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-R15-USD',
            'issue_date' => '2026-03-10',
            'list_amount' => 100,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 0,
            'total' => 100,
            'paid_amount' => 0,
            'balance_due' => 100,
            'credited_amount' => 0,
            'currency' => 'USD',
            'status' => 'issued',
        ]);

        // فاتورة لغت — لازم تختفي من التقرير
        Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-R15-VOID',
            'issue_date' => '2026-03-12',
            'list_amount' => 500,
            'subtotal' => 500,
            'discount' => 0,
            'tax' => 0,
            'total' => 500,
            'paid_amount' => 0,
            'balance_due' => 500,
            'credited_amount' => 0,
            'currency' => 'EGP',
            'status' => 'void',
        ]);

        $payment600 = Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'amount' => 600,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => '2026-03-05 10:00:00',
            'received_by' => $reader->id,
        ]);
        // 10 دولار يوم 5 مارس → سعر 50 (قبل تغيير 20 مارس)
        Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'amount' => 10,
            'currency' => 'USD',
            'payment_method' => 'card',
            'status' => 'completed',
            'paid_at' => '2026-03-05 10:00:00',
            'received_by' => $reader->id,
        ]);
        // 20 دولار يوم 25 مارس → سعر 80 (السعر الجديد وقت العملية)
        Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'amount' => 20,
            'currency' => 'USD',
            'payment_method' => 'card',
            'status' => 'completed',
            'paid_at' => '2026-03-25 12:00:00',
            'received_by' => $reader->id,
        ]);
        // خارج النافذة (أبريل) + معلق
        Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'amount' => 70,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => '2026-04-02 10:00:00',
            'received_by' => $reader->id,
        ]);
        Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'amount' => 999,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'pending',
            'paid_at' => '2026-03-06 10:00:00',
            'received_by' => $reader->id,
        ]);

        Refund::create([
            'payment_id' => $payment600->id,
            'amount' => 50,
            'currency' => 'EGP',
            'reason' => 'خصم جزئي',
            'status' => 'processed',
            'processed_by' => $reader->id,
            'processed_at' => '2026-03-08 11:00:00',
        ]);
        // معلق → مكانش مسترد فعلياً
        Refund::create([
            'payment_id' => $payment600->id,
            'amount' => 30,
            'currency' => 'EGP',
            'reason' => 'معلق',
            'status' => 'pending',
            'processed_by' => $reader->id,
            'processed_at' => '2026-03-09 11:00:00',
        ]);

        $category = \App\Models\ExpenseCategory::create([
            'organization_id' => $this->org->id,
            'name' => 'إيجار',
            'slug' => 'rent',
            'status' => 'active',
        ]);
        \App\Models\Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'amount' => 300,
            'currency' => 'EGP',
            'expense_date' => '2026-03-01',
            'description' => 'إيجار',
            'status' => 'approved',
            'created_by' => $reader->id,
        ]);
        \App\Models\Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'amount' => 50,
            'currency' => 'EGP',
            'expense_date' => '2026-03-15',
            'description' => 'كهرباء',
            'status' => 'paid',
            'created_by' => $reader->id,
        ]);
        \App\Models\Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $category->id,
            'amount' => 999,
            'currency' => 'EGP',
            'expense_date' => '2026-03-20',
            'description' => 'مرفوضة',
            'status' => 'rejected',
            'created_by' => $reader->id,
        ]);

        $teacher = $this->makeTeacher();
        TeacherEarning::create([
            'teacher_id' => $teacher->id,
            'amount' => 120,
            'currency' => 'EGP',
            'earning_date' => '2026-03-20',
            'status' => 'approved',
        ]);
        TeacherEarning::create([
            'teacher_id' => $teacher->id,
            'amount' => 300,
            'currency' => 'EGP',
            'earning_date' => '2026-03-21',
            'status' => 'pending',
        ]);
        TeacherPayment::create([
            'teacher_id' => $teacher->id,
            'amount' => 120,
            'currency' => 'EGP',
            'status' => 'completed',
            'paid_at' => '2026-03-25 09:00:00',
            'processed_by' => $reader->id,
        ]);

        $queries = [];
        $capturing = true;
        DB::listen(function ($q) use (&$queries, &$capturing) {
            if ($capturing) {
                $queries[] = $q->sql;
            }
        });

        $r = $this->getJsonAs('/api/reports/financial?from=2026-03-01&to=2026-03-31', $reader);
        $capturing = false;

        $r->assertOk();

        // ⭐⭐ الحقيبة المحوّلة (الجنيه) — بسعر **وقت العملية**
        $this->assertEquals(5800.0, (float) $r->json('converted.totals.invoiced'), 'مفوتَر محوّل: 800 + 100د×50');
        $this->assertEquals(2700.0, (float) $r->json('converted.totals.collected'), 'محصّل: 600 + 10د×50 + 20د×80');
        $this->assertEquals(50.0, (float) $r->json('converted.totals.refunded'));
        $this->assertEquals(350.0, (float) $r->json('converted.totals.expenses'));
        $this->assertEquals(120.0, (float) $r->json('converted.totals.payroll'), 'المعتمد/المدفوع بس — المعلق 300 مختفي');
        $this->assertEquals(2230.0, (float) $r->json('converted.totals.net_operating'), '2700 − 350 − 120');

        // ⭐ لكل عملة لوحدها — مافيش خلطة وحدات
        $this->assertEquals(800.0, (float) $r->json('currencies.EGP.invoiced'), 'EGP: الإجمالي بعد الخصم');
        $this->assertEquals(600.0, (float) $r->json('currencies.EGP.collected'));
        $this->assertEquals(50.0, (float) $r->json('currencies.EGP.refunded'));
        $this->assertEquals(200.0, (float) $r->json('currencies.EGP.discounts'));
        $this->assertEquals(350.0, (float) $r->json('currencies.EGP.expenses'));
        $this->assertEquals(120.0, (float) $r->json('currencies.EGP.payroll'));
        $this->assertEquals(100.0, (float) $r->json('currencies.USD.invoiced'));
        $this->assertEquals(30.0, (float) $r->json('currencies.USD.collected'), '10 + 20 دولار — من غير تحويل');
        $this->assertEquals(30.0, (float) $r->json('currencies.USD.net_operating'), 'USD: 30 − 0');

        // الخصوم: ترويجي 80 + يدوي 120 = 200
        $this->assertEquals(80.0, (float) $r->json('discounts.promo'));
        $this->assertEquals(120.0, (float) $r->json('discounts.manual'));
        $this->assertEquals(200.0, (float) $r->json('discounts.total'));

        // المفاتيح القديمة (JWT Contract) — لوحدة عربية واحدة هي نفس التحويل
        $this->assertEquals(5800.0, (float) $r->json('invoiced_vs_collected.invoiced'));
        $this->assertEquals(2700.0, (float) $r->json('invoiced_vs_collected.collected'));
        $this->assertEquals(5800.0, (float) $r->json('invoiced_vs_collected.outstanding'), 'الرصيد المتبقي على الفواتير (void مختفي)');
        $this->assertEquals(350.0, (float) $r->json('expenses.total'));
        $this->assertEquals(120.0, (float) $r->json('teacher_costs.gross_earnings'));
        $this->assertEquals(0.0, (float) $r->json('alerts.unpaid_teacher_dues'));

        // ⭐ مافيش «select *» على جداول التقرير — مواصفة 2.15:
        // كل استعلامات التقرير aggregates (الـ`select * from users`
        // جاي من login الـ`getJsonAs` نفسه — مش من التقرير).
        $reportTables = 'invoices|payments|refunds|expenses|expense_categories|'
            .'teacher_earnings|teacher_payments|exchange_rates|subscriptions|'
            .'promo_codes|promo_code_usages|organizations|students';
        $starQueries = array_values(array_filter(
            $queries,
            fn ($sql) => (bool) (
                preg_match('/^select\s+\*/i', (string) $sql)
                && preg_match('/from\s+"?(?:'.$reportTables.')"?/i', (string) $sql)
            ),
        ));
        $this->assertEmpty($starQueries, 'التقرير حمّل موديلات: '.implode(' | ', array_slice($starQueries, 0, 3)));
    }

    // ═══════════════════════════════════════════════════════════
    // 2) الفلاتر — فرع/برنامج/معلم (كل سلسلة بيتأثر بحسب علاقتها)
    // ═══════════════════════════════════════════════════════════

    public function test_the_filters_narrow_the_series_that_supports_them(): void
    {
        $reader = $this->makeUserWithRole('reader15', ['reports.view']);

        ExchangeRate::create(['from_currency' => 'USD', 'to_currency' => 'EGP', 'rate' => 50, 'valid_date' => '2026-01-01']);

        $branchA = Branch::create(['organization_id' => $this->org->id, 'name' => 'فرع أ', 'code' => 'RA']);
        $branchB = Branch::create(['organization_id' => $this->org->id, 'name' => 'فرع ب', 'code' => 'RB']);

        $studentA = $this->makeStudent(['branch_id' => $branchA->id]);
        $studentB = $this->makeStudent(['branch_id' => $branchB->id]);

        foreach ([[$studentA, $branchA, 'A', 300], [$studentB, $branchB, 'B', 700]] as [$student, $branch, $label, $amount]) {
            Invoice::create([
                'organization_id' => $this->org->id,
                'branch_id' => $branch->id,
                'student_id' => $student->id,
                'invoice_number' => 'INV-F15-'.$label,
                'issue_date' => '2026-03-05',
                'list_amount' => $amount,
                'subtotal' => $amount,
                'discount' => 0,
                'tax' => 0,
                'total' => $amount,
                'paid_amount' => 0,
                'balance_due' => $amount,
                'credited_amount' => 0,
                'currency' => 'EGP',
                'status' => 'issued',
            ]);
        }

        Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $studentA->id,
            'amount' => 200,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => '2026-03-06 10:00:00',
            'received_by' => $reader->id,
        ]);
        Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $studentB->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => '2026-03-07 10:00:00',
            'received_by' => $reader->id,
        ]);

        $category = \App\Models\ExpenseCategory::create([
            'organization_id' => $this->org->id,
            'name' => 'مشتريات',
            'slug' => 'purchases',
            'status' => 'active',
        ]);
        \App\Models\Expense::create([
            'organization_id' => $this->org->id,
            'branch_id' => $branchA->id,
            'category_id' => $category->id,
            'amount' => 100,
            'currency' => 'EGP',
            'expense_date' => '2026-03-02',
            'description' => 'فرع أ',
            'status' => 'approved',
            'created_by' => $reader->id,
        ]);
        \App\Models\Expense::create([
            'organization_id' => $this->org->id,
            'branch_id' => $branchB->id,
            'category_id' => $category->id,
            'amount' => 40,
            'currency' => 'EGP',
            'expense_date' => '2026-03-03',
            'description' => 'فرع ب',
            'status' => 'approved',
            'created_by' => $reader->id,
        ]);

        $teacher1 = $this->makeTeacher();
        $teacher2 = $this->makeTeacher();
        TeacherEarning::create([
            'teacher_id' => $teacher1->id,
            'amount' => 60,
            'currency' => 'EGP',
            'earning_date' => '2026-03-10',
            'status' => 'approved',
        ]);
        TeacherEarning::create([
            'teacher_id' => $teacher2->id,
            'amount' => 40,
            'currency' => 'EGP',
            'earning_date' => '2026-03-11',
            'status' => 'approved',
        ]);

        // ⭐ الفرع أ: فواتيره 300 + دفعات طلابه 200 + مصروفاته 100؛
        // المرتبات مالهاش فرع — بتفضل كاملة (100) بفلتر الفرع.
        $r = $this->getJsonAs("/api/reports/financial?from=2026-03-01&to=2026-03-31&branch_id={$branchA->id}", $reader);
        $r->assertOk();
        $this->assertEquals(300.0, (float) $r->json('converted.totals.invoiced'), 'فلتر الفرع على الفواتير');
        $this->assertEquals(200.0, (float) $r->json('converted.totals.collected'), 'فلتر الفرع على دفعات طلاب الفرع');
        $this->assertEquals(100.0, (float) $r->json('converted.totals.expenses'), 'فلتر الفرع على المصروفات');
        $this->assertEquals(100.0, (float) $r->json('converted.totals.payroll'), 'المرتبات مالهاش فرع — كاملة');

        // ⭐ المعلم 1: مرتباته 60 بس؛ الفواتير والدفعات مستصفية من الفلتر.
        $r = $this->getJsonAs("/api/reports/financial?from=2026-03-01&to=2026-03-31&teacher_id={$teacher1->id}", $reader);
        $r->assertOk();
        $this->assertEquals(60.0, (float) $r->json('converted.totals.payroll'), 'فلتر المعلم على المرتبات');
        $this->assertEquals(1000.0, (float) $r->json('converted.totals.invoiced'), 'الفواتير مش بفلتر معلم');
        $this->assertEquals(700.0, (float) $r->json('converted.totals.collected'), 'الدفعات مش بفلتر معلم');
    }

    // ═══════════════════════════════════════════════════════════
    // 3) لوحة التحكم — تقرير المنظمة للمنظمة بس (كان تسريب ساكتاً)
    // ═══════════════════════════════════════════════════════════

    public function test_the_dashboard_summary_counts_only_this_organization(): void
    {
        $reader = $this->makeUserWithRole('reader15', ['reports.view']);

        $otherOrg = Organization::create([
            'name' => 'أكاديمية تاني بدء',
            'slug' => 'other-academy-215',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);

        $otherStudent = $this->makeStudent();
        $otherStudent->update(['organization_id' => $otherOrg->id]);

        Payment::create([
            'organization_id' => $otherOrg->id,
            'student_id' => $otherStudent->id,
            'amount' => 5000,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => now(),
            'received_by' => $reader->id,
        ]);

        $r = $this->getJsonAs('/api/dashboard/summary', $reader);
        $r->assertOk();

        // المنظمة دي ماعندها مفيش دفعات — إيراد الشهر = 0 مهما رسمت بدء تانية
        $this->assertEquals(0.0, (float) $r->json('totals.monthly_revenue'), 'إيراد منظمة تانية بسرب لنا');
    }
}
