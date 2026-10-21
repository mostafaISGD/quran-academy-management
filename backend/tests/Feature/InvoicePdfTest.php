<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 2.17 — مستندات PDF العربية: فاتورة الطالب.**
 *
 * `GET /api/invoices/{invoice}/pdf` — مستند رسمي قابل للطباعة:
 *
 * - نص عربي حقيقي (RTL) من الإعدادات + الأرقام اللاتينية.
 * - رقم الفاتورة التسلسلي `INV-YYYY-####`.
 * - QR اختياري (متجه من mPDF — بلا GD).
 *
 * الحماية: الـراوت **بلا `permission:`** لأن ولي الأمر يصله —
 * الحكم كله في `InvoicePolicy::viewPdf`:
 *
 * - موظف مالي (`finance.payments.view` + بلا هوامش) → مسموح.
 * - ولي أمر → ابنه فقط، وبشرط `can_view_finance`.
 * - ولي آخر → **403** (المواصفة).
 *
 * ⭐ إثبات العربي والأرقام داخل الملف نفسه: mPDF يكتب الـmetadata
 * (‎/Title‎) بترميز UTF-16BE غيار الضغط — فسلسلة `فاتورة` ورقم
 * الفاتورة **بايتات قابلة للبحث** في الرد الخام. أما شكل الملف
 * فـ`FontFile2` = خط TrueType مضمّن (مش خطوط PDF الأساسية
 * التي لا ترسم العربي أصلاً).
 */
class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // الموظف المالي — التدفّق الإيجابي الكامل
    // ═══════════════════════════════════════════════════════════════

    public function test_the_finance_staff_renders_an_arabic_invoice_pdf_with_latin_digits(): void
    {
        [$invoice, $number] = $this->makeInvoice();

        $staff = $this->makeUserWithRole('pdf-financier', ['finance.payments.view']);

        $r = $this->getJsonAs("/api/invoices/{$invoice->id}/pdf", $staff);

        $r->assertOk();

        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));

        $pdf = (string) $r->getContent();

        // ⭐ ملف PDF حقيقي (مش صفحة خطأ HTML موسخة 200).
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf), 'الـPDF فاضٍ تقريباً — التمبلت ما اترسمش');

        // ⭐ خط مضمّن — دليل الرسم الحقيقي للعربي (مش tofu).
        $this->assertGreaterThanOrEqual(
            1,
            substr_count($pdf, 'FontFile2'),
            'مفيش خط TrueType مضمّن — العربي مش بيتكتب بديلاً عن هيكل غايب'
        );

        // ⭐ النص العربي موجود **بايتاته** في metadata المستند.
        $this->assertStringContainsString($this->utf16('فاتورة'), $pdf);

        // ⭐ الرقم التسلسلي بأرقام لاتينية ظاهر في الملف نفسه.
        $this->assertStringContainsString($this->utf16($number), $pdf);

        // ⭐ تفاصيل الأكاديمية (الاسم) جاية من الإعدادات — مش بولّه.
        $this->assertStringContainsString($this->utf16('مركز النور للتحفيظ'), $pdf);

        // ⭐ اسم الملف = رقم الفاتورة.
        $this->assertStringContainsString($number, (string) $r->headers->get('Content-Disposition'));
    }

    // ═══════════════════════════════════════════════════════════════
    // ولي الأمر — حقّه بشرط `can_view_finance`
    // ═══════════════════════════════════════════════════════════════

    public function test_the_parent_with_can_view_finance_downloads_the_child_invoice(): void
    {
        [$invoice] = $this->makeInvoice();

        $parent = $this->makeVerifiedParent($invoice->student, ['can_view_finance' => true]);

        $this->getJsonAs("/api/invoices/{$invoice->id}/pdf", $parent)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_parent_without_can_view_finance_is_forbidden(): void
    {
        [$invoice] = $this->makeInvoice();

        $parent = $this->makeVerifiedParent($invoice->student, ['can_view_finance' => false]);

        $this->getJsonAs("/api/invoices/{$invoice->id}/pdf", $parent)->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ قبول المواصفة: ولي آخر = 403
    // ═══════════════════════════════════════════════════════════════

    public function test_another_parent_gets_403_on_a_foreign_child_invoice(): void
    {
        [$myInvoice] = $this->makeInvoice();

        $otherStudent = $this->makeStudent(['first_name' => 'سارة', 'last_name' => 'الغريبة']);
        $otherParent = $this->makeVerifiedParent($otherStudent, ['can_view_finance' => true]);

        $r = $this->getJsonAs("/api/invoices/{$myInvoice->id}/pdf", $otherParent);

        $r->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * فاتورة برقم تسلسلي حقيقي من `InvoiceService::nextNumber`
     * (الترانزاكشن بتتراجع مع الـRefreshDatabase — فالعداد صافي).
     *
     * @return array{0: Invoice, 1: string}
     */
    private function makeInvoice(array $attrs = []): array
    {
        // ⭐ تفاصيل الأكاديمية من **الإعدادات المؤسسية** — المصدر الرسمي.
        Setting::set('academy_name', 'مركز النور للتحفيظ', $this->org->id);
        Setting::set('academy_phone', '0212345678', $this->org->id);
        Setting::set('academy_email', 'info@alnoor.test', $this->org->id);

        $student = $this->makeStudent(['first_name' => 'محمد', 'last_name' => 'أحمد']);
        $number = app(InvoiceService::class)->nextNumber($this->org->id);

        $invoice = Invoice::create(array_merge([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'invoice_number' => $number,
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'subtotal' => 800,
            'discount' => 0,
            'tax' => 0,
            'total' => 800,
            'paid_amount' => 0,
            'balance_due' => 800,
            'credited_amount' => 0,
            'list_amount' => 800,
            'currency' => 'EGP',
            'status' => 'issued',
        ], $attrs));

        return [$invoice, $number];
    }

    /**
     * ولي أمر بربط مؤكد (PR 1.11) + علم محدد — نفس نمط
     * `RelationshipPoliciesTest::linkParent` بس بصلاحيات محايدة
     * (المسار ده **بلا permission** — الحكم في الـpolicy).
     */
    private function makeVerifiedParent(object $student, array $pivot): User
    {
        $user = $this->makeUser();
        $profile = $this->makeParentProfile($user);

        $student->parents()->attach($profile->id, $pivot + [
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        return $user->fresh();
    }

    /**
     * ⭐ بايتات UTF-16BE **بلا BOM** (بداية ميتاداتا mPDF فقط
     * يسبقها BOM — قارئ منتصف السلسلة لا يجب أن يضمّنه) + تهريب
     * PDF-string: حرف «ة» بايته السفلي 0x29 نفسه ")"
     * فيترجم داخل الملف إلى `\)` — فالقارئ لازم يولّد بايتات
     * مُتهرَبة مطابقة، والترتيب مهم: `\` أولًا (مزدوجة) ثم الأقواس.
     */
    private function utf16(string $value): string
    {
        $bytes = mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');

        return str_replace(
            ['\\', "\x28", "\x29"],
            ['\\\\', '\\(', '\\)'],
            $bytes,
        );
    }
}
