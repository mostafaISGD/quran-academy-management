<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Services\InvoiceService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ PR 2.6 — `InvoiceService` هو **الكاتب الوحيد** لحالة الفاتورة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة 9 من `AGENTS.md`:
 *   «`paid_amount` / `balance_due` / `status` بتكتبها
 *   `InvoiceService` وحدها».
 *
 * ⭐ وليه؟ لأن قبل كده كان فيه **5 مواقع** بتكتبهم
 * (`SubscriptionActivationService`, `DailySubscriptionService`,
 * `GroupEnrollmentService`, `AssessmentController`,
 * `PaymentService`). وكل واحد بيحسب بطريقته:
 *
 *     max(0, price - paid)   ← هنا
 *     round(price - paid, 2) ← هناك
 *     paid >= price ? 'paid' : 'partially_paid'
 *
 * ⭐ فبيبقى في فاتورة حالتها **غلط** من غير ما حد عدّل حاجة —
 * والـbug بيظهر بعد شهور في تقرير.
 */
class InvoiceServiceTest extends TestCase
{
    private function invoice(float $total = 1000.00, string $status = 'issued'): Invoice
    {
        return Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $this->makeStudent()->id,
            'invoice_number' => 'INV-SVC-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'EGP',
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance_due' => $total,
            'status' => $status,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // ① الترقيم التسلسلي
    // ═══════════════════════════════════════════════════════════

    public function test_invoice_numbers_are_sequential_with_no_gaps(): void
    {
        $service = app(InvoiceService::class);

        $numbers = [];

        for ($i = 0; $i < 5; $i++) {
            $numbers[] = $service->nextNumber($this->org->id);
        }

        $this->assertCount(5, array_unique($numbers), 'مفيش رقم مكرر');

        // ⭐ تسلسلي: آخر 4 أرقام بتزيد واحد واحد
        $suffixes = array_map(fn ($n) => (int) substr($n, -4), $numbers);

        $this->assertSame(
            $suffixes[0] + 1,
            $suffixes[1],
            'الترقيم لازم يكون تسلسلي'
        );
        $this->assertSame($suffixes[0] + 4, $suffixes[4], '5 أرقام من غير فجوة');
    }

    public function test_the_number_contains_the_year(): void
    {
        $number = app(InvoiceService::class)->nextNumber($this->org->id);

        $this->assertStringContainsString(now()->year, $number);
    }

    public function test_the_number_is_prefixed_with_inv(): void
    {
        $number = app(InvoiceService::class)->nextNumber($this->org->id);

        $this->assertStringStartsWith('INV-', $number);
    }

    // ═══════════════════════════════════════════════════════════
    // ② التطبيق — الكاتب الوحيد للحالة
    // ═══════════════════════════════════════════════════════════

    public function test_applying_a_full_payment_marks_it_paid(): void
    {
        $invoice = $this->invoice(1000.00);
        $payment = $this->makePayment($invoice, 1000.00);

        app(InvoiceService::class)->applyPayment($invoice, $payment);

        $invoice->refresh();

        $this->assertEquals(1000.00, (float) $invoice->paid_amount);
        $this->assertEquals(0.00, (float) $invoice->balance_due);
        $this->assertSame('paid', $invoice->status);
    }

    public function test_applying_a_partial_payment_leaves_it_partially_paid(): void
    {
        $invoice = $this->invoice(1000.00);
        $payment = $this->makePayment($invoice, 400.00);

        app(InvoiceService::class)->applyPayment($invoice, $payment);

        $invoice->refresh();

        $this->assertEquals(400.00, (float) $invoice->paid_amount);
        $this->assertEquals(600.00, (float) $invoice->balance_due);
        $this->assertSame('partially_paid', $invoice->status);
    }

    public function test_a_full_payment_takes_precedence_over_a_partial_one(): void
    {
        $invoice = $this->invoice(1000.00);

        // ⭐⭐ الترتيب: لو service حسب الحالة قبل ما يجمع، أول
        // دفعة هتعمله `paid` وال余额 هيبقى سالب.
        app(InvoiceService::class)->applyPayment($invoice, $this->makePayment($invoice, 400.00));
        app(InvoiceService::class)->applyPayment($invoice, $this->makePayment($invoice, 600.00));

        $invoice->refresh();

        $this->assertSame('paid', $invoice->status, 'المبلغ كامل = paid');
        $this->assertEquals(0.00, (float) $invoice->balance_due);
    }

    public function test_the_balance_is_never_negative(): void
    {
        $invoice = $this->invoice(1000.00);

        // ⭐ دفع زيادة عن الفاتورة — الـbalance يفضل 0 مش سالب
        app(InvoiceService::class)->applyPayment($invoice, $this->makePayment($invoice, 1500.00));

        $invoice->refresh();

        $this->assertEquals(0.00, (float) $invoice->balance_due);
        $this->assertSame('paid', $invoice->status);
    }

    // ═══════════════════════════════════════════════════════════
    // ③ العكسي
    // ═══════════════════════════════════════════════════════════

    public function test_reversing_a_payment_restores_the_state(): void
    {
        $invoice = $this->invoice(1000.00);
        $payment = $this->makePayment($invoice, 400.00);

        $service = app(InvoiceService::class);
        $service->applyPayment($invoice, $payment);
        $service->reversePayment($invoice, $payment);

        $invoice->refresh();

        $this->assertEquals(0.00, (float) $invoice->paid_amount);
        $this->assertEquals(1000.00, (float) $invoice->balance_due);
        $this->assertSame('issued', $invoice->status);
    }

    // ═══════════════════════════════════════════════════════════
    // ④ `overdue` محسوب — مش مكتوب
    // ═══════════════════════════════════════════════════════════

    public function test_overdue_is_computed_not_stored(): void
    {
        $invoice = $this->invoice(1000.00);
        $invoice->update(['due_date' => now()->subDay()->toDateString()]);

        $invoice->refresh();

        // ⭐ الحالة المخزّنة لسه `issued` — `overdue` **محسوب**
        // من `due_date` مش مكتوب.
        $this->assertSame('issued', $invoice->fresh()->status);
        $this->assertTrue($invoice->fresh()->isOverdue());
    }

    public function test_a_paid_invoice_is_never_overdue(): void
    {
        $invoice = $this->invoice(1000.00, 'paid');
        $invoice->update(['due_date' => now()->subDay()->toDateString()]);

        $this->assertFalse($invoice->fresh()->isOverdue(), 'مدفوعة مش متأخرة');
    }

    // ═══════════════════════════════════════════════════════════
    // ⑤ الحمايات
    // ═══════════════════════════════════════════════════════════

    public function test_a_void_invoice_refuses_payments(): void
    {
        $invoice = $this->invoice(1000.00, 'void');
        $payment = $this->makePayment($invoice, 100.00);

        $this->expectException(\RuntimeException::class);

        app(InvoiceService::class)->applyPayment($invoice, $payment);
    }

    public function test_a_draft_invoice_cannot_be_marked_paid_by_api(): void
    {
        $invoice = $this->invoice(1000.00, 'draft');

        // ⭐⭐ الثغرة: `PUT /invoices/{id}` بـ`status: "paid"` كان
        // بيقبلها → فاتورة «مدفوعة» و`balance_due` لسه 1000.
        $response = $this->putJsonAs(
            "/api/invoices/{$invoice->id}",
            ['status' => 'paid'],
            $this->makeUserWithRole('staff', ['finance.payments.update']),
        );

        $this->assertSame(422, $response->status());

        // ⭐ والحالة ما اتغيّرتش
        $this->assertSame('draft', $invoice->fresh()->status);
    }

    public function test_due_date_is_still_editable(): void
    {
        $invoice = $this->invoice(1000.00, 'draft');

        $response = $this->putJsonAs(
            "/api/invoices/{$invoice->id}",
            ['due_date' => now()->addDays(45)->toDateString()],
            $this->makeUserWithRole('staff', ['finance.payments.update']),
        );

        $this->assertSame(200, $response->status());
        $this->assertSame(
            now()->addDays(45)->toDateString(),
            $invoice->fresh()->due_date->toDateString()
        );
    }

    public function test_a_paid_invoice_cannot_be_deleted(): void
    {
        $invoice = $this->invoice(1000.00, 'paid');

        $response = $this->deleteJsonAs(
            "/api/invoices/{$invoice->id}",
            $this->makeUserWithRole('staff', ['finance.payments.update']),
        );

        $this->assertSame(422, $response->status());
    }

    public function test_an_issued_invoice_can_only_be_voided_not_deleted(): void
    {
        $invoice = $this->invoice(1000.00, 'issued');

        $staff = $this->makeUserWithRole('staff', ['finance.payments.update']);

        // ⭐ الحذف مرفوض — الاعتماد قرار قدام الطالب
        $this->assertSame(
            422,
            $this->deleteJsonAs("/api/invoices/{$invoice->id}", $staff)->status()
        );

        // ⭐ لكن الإلغاء شغال
        $this->assertSame(
            200,
            $this->postJsonAs("/api/invoices/{$invoice->id}/void", [], $staff)->status()
        );

        $this->assertSame('void', $invoice->fresh()->status);
    }

    public function test_a_draft_invoice_can_be_deleted(): void
    {
        $invoice = $this->invoice(1000.00, 'draft');

        $response = $this->deleteJsonAs(
            "/api/invoices/{$invoice->id}",
            $this->makeUserWithRole('staff', ['finance.payments.update']),
        );

        $this->assertSame(200, $response->status());
    }

    private function makePayment(Invoice $invoice, float $amount): Payment
    {
        return Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'received_by' => $this->makeUserWithRole('accountant', ['finance.view'])->id,
            'paid_at' => now(),
        ]);
    }
}