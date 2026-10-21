<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 2.17 — مستندات PDF العربية: إيصال استلام.**
 *
 * `GET /api/payments/{payment}/receipt` — إيصال رسمي:
 *
 * - عربية الحرف + أرقام لاتينية (قبول المواصفة).
 * - **رقم إيصال** مشتق بثبات من الدفعة نفسها: `RCP-YYYY-######`
 *   (بدون رصّاً ياكل أرقام تسلسل على كل استراحة للطباعة — الرقم
 *   دالة صرفة في `id` + سنة `paid_at`، تسلسلي بشكل طبيعي).
 * - الحماية مثيلتها الفاتورة: `PaymentPolicy::viewPdf` — موظف
 *   مالي، طالب (إيصاله)، ولي (`can_view_finance`)، وولي آخر 403.
 */
class PaymentReceiptTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // الموظف المالي
    // ═══════════════════════════════════════════════════════════════

    public function test_the_finance_staff_renders_an_arabic_receipt_pdf(): void
    {
        [$payment, $receiptNumber] = $this->makePayment();

        $staff = $this->makeUserWithRole('receipt-financier', ['finance.payments.view']);

        $r = $this->getJsonAs("/api/payments/{$payment->id}/receipt", $staff);

        $r->assertOk();

        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));

        $pdf = (string) $r->getContent();

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));

        $this->assertGreaterThanOrEqual(
            1,
            substr_count($pdf, 'FontFile2'),
            'مفيش خط TrueType مضمّن — العربي مش بيتكتب'
        );

        // ⭐ «إيصال استلام» كعنوان عربي بالبايتات.
        $this->assertStringContainsString($this->utf16('إيصال استلام'), $pdf);

        // ⭐ رقم الإيصال المشتق تسلسلياً وأرقامه لاتينية في الملف نفسه.
        $this->assertStringContainsString($this->utf16($receiptNumber), $pdf);

        // ⭐ اسم الملف = رقم الإيصال.
        $this->assertStringContainsString($receiptNumber, (string) $r->headers->get('Content-Disposition'));
    }

    // ═══════════════════════════════════════════════════════════════
    // ولي الأمر — حقّه بشرط `can_view_finance`... والغريب محظور
    // ═══════════════════════════════════════════════════════════════

    public function test_the_parent_with_can_view_finance_downloads_the_child_receipt(): void
    {
        [$payment] = $this->makePayment();

        $parent = $this->makeVerifiedParent($payment->student, ['can_view_finance' => true]);

        $this->getJsonAs("/api/payments/{$payment->id}/receipt", $parent)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_another_parent_gets_403_on_a_foreign_child_receipt(): void
    {
        [$payment] = $this->makePayment();

        $otherStudent = $this->makeStudent(['first_name' => 'سارة', 'last_name' => 'الغريبة']);
        $otherParent = $this->makeVerifiedParent($otherStudent, ['can_view_finance' => true]);

        $this->getJsonAs("/api/payments/{$payment->id}/receipt", $otherParent)
            ->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * دفعة نقدية مكتملة على فاتورة — وتوقع رقم الإيصال **بنفس
     * قاعدة** `PdfDocumentService` (دالة صرفة: RCP-سنة-id).
     *
     * @return array{0: Payment, 1: string}
     */
    private function makePayment(): array
    {
        [$invoice] = $this->makeInvoice();

        $payment = Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'amount' => 300,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => now(),
            'received_by' => $this->receiver()->id,
            'transaction_reference' => 'TRX-55',
        ]);

        $receiptNumber = sprintf('RCP-%s-%06d', date('Y', strtotime((string) $payment->paid_at)), $payment->id);

        return [$payment, $receiptNumber];
    }

    /** @return array{0: Invoice, 1: string} */
    private function makeInvoice(): array
    {
        Setting::set('academy_name', 'مركز النور للتحفيظ', $this->org->id);

        $student = $this->makeStudent(['first_name' => 'محمد', 'last_name' => 'أحمد']);
        $number = app(\App\Services\InvoiceService::class)->nextNumber($this->org->id);

        $invoice = Invoice::create([
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
            'paid_amount' => 300,
            'balance_due' => 500,
            'credited_amount' => 0,
            'list_amount' => 800,
            'currency' => 'EGP',
            'status' => 'partially_paid',
        ]);

        return [$invoice, $number];
    }

    /** مستلم الدفعة — موظف عادي (حقل `received_by`). */
    private function receiver(): User
    {
        return $this->makeUser();
    }

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
     * PDF-string (من غير مقابلة بديلة تعاود).
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
