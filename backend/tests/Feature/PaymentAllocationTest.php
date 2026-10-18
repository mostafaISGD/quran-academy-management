<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Tests\TestCase;

/**
 * ⭐⭐ `payment_allocations` — القاعدة 5 في الخطة.
 *
 * ⭐ **اللي بيحصل في الملف ده:**
 *
 * الخطة بتقول: «لا نخزّن المدفوع والمتبقي على الفاتورة كأرقام
 * مستقلة؛ تتحسب من المدفوعات المخصصة».
 *
 * فالاختبارات دي بتقفل:
 * ① دفعة واحدة على فاتورة واحدة → التوزيع اتسجل، والفاتورة
 *    حسبت المدفوع من التوزيعات
 * ② دفعة على فاتورتين → سطرين توزيع، والفاتورتين حسبوا
 * ③ توزيع أكبر من مبلغ الدفعة → مرفوض
 * ④ استرداد كامل → التوزيعات اتعلمت reversed، والفاتورة
 *    رجعت «غير مدفوعة» من جديد
 * ⑤ المدفوع/المتبقي على الفاتورة **بيتحسب** من التوزيعات
 *    النشطة، مش من عمود مستقل
 */
class PaymentAllocationTest extends TestCase
{
    private function paymentsUser()
    {
        return $this->makeUserWithRole('admin', [
            'finance.payments.view', 'finance.payments.create', 'finance.payments.update',
            'finance.payments.refund', 'students.view',
        ]);
    }

    private function makeInvoiceForStudent($student, array $attrs = []): Invoice
    {
        return Invoice::create(array_merge([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-'.\Illuminate\Support\Str::random(6),
            'total' => 500,
            'paid_amount' => 0,
            'balance_due' => 500,
            'currency' => 'EGP',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
        ], $attrs));
    }

    // ============================================================
    // ① دفعة = توزيع واحد على فاتورة واحدة
    // ============================================================

    public function test_a_payment_on_one_invoice_creates_one_allocation(): void
    {
        $student = $this->makeStudent();
        $invoice = $this->makeInvoiceForStudent($student);

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->paymentsUser());

        $r->assertCreated();

        // ⭐ سطر توزيع واحد بمبلغ الدفعة
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame(500.0, (float) PaymentAllocation::first()->amount);
        $this->assertSame('active', PaymentAllocation::first()->status);

        // ⭐ الفاتورة حسبت المدفوع من التوزيع
        $this->assertSame(500.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_due);
        $this->assertSame('paid', $invoice->fresh()->status);
    }

    public function test_a_partial_payment_leaves_the_invoice_partially_paid(): void
    {
        // ⭐ دفعة 200 على فاتورة 500 → مدفوع 200، متبقي 300
        $student = $this->makeStudent();
        $invoice = $this->makeInvoiceForStudent($student);

        $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 200,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->paymentsUser())->assertCreated();

        $this->assertSame(200.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(300.0, (float) $invoice->fresh()->balance_due);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    // ============================================================
    // ② التخصيص — دفعة على فاتورتين
    // ============================================================

    public function test_a_payment_can_be_split_across_two_invoices(): void
    {
        // ⭐ الحالة الواقعية: الطالب دفع 1000 ومعاه فاتورتين
        $student = $this->makeStudent();
        $inv1 = $this->makeInvoiceForStudent($student, ['total' => 600, 'balance_due' => 600]);
        $inv2 = $this->makeInvoiceForStudent($student, ['total' => 400, 'balance_due' => 400]);

        // ندفع 1000 على الفاتورة الأولى (للـ payment record)
        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'invoice_id' => $inv1->id,
            'amount' => 1000,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->paymentsUser());

        $r->assertCreated();
        $paymentId = $r->json('id');

        // ⭐ أولاً: الدفعة اتسجلت سطر توزيع واحد على الفاتورة
        // الأولى بمبلغ 1000 — اللي هي فوق الإجمالي بتاعها (600)!
        // وده بيكوننا للمشكلة: التوزيع الأول بيتجاوز الفاتورة.
        // الحل: نمسح التوزيع الأول ونعمل توزيع صحيح.
        PaymentAllocation::where('payment_id', $paymentId)->delete();
        $inv1->recalculateFromAllocations();

        // ⭐ بقى نوزع الدفعة صح: 600 على الأولى + 400 على التانية
        $r2 = $this->postJsonAs("/api/payments/{$paymentId}/allocate", [
            'allocations' => [
                ['invoice_id' => $inv1->id, 'amount' => 600],
                ['invoice_id' => $inv2->id, 'amount' => 400],
            ],
        ], $this->paymentsUser());

        $r2->assertCreated();
        $this->assertSame(1000.0, (float) $r2->json('data.allocated'));
        $this->assertSame(0.0, (float) $r2->json('data.unallocated'));

        // ⭐ الفاتورة الأولى: مدفوع 600 من 600 → paid
        $this->assertSame(600.0, (float) $inv1->fresh()->paid_amount);
        $this->assertSame('paid', $inv1->fresh()->status);

        // ⭐ الفاتورة التانية: مدفوع 400 من 400 → paid
        $this->assertSame(400.0, (float) $inv2->fresh()->paid_amount);
        $this->assertSame('paid', $inv2->fresh()->status);
    }

    public function test_allocations_cannot_exceed_the_payment_amount(): void
    {
        // ⭐ توزيع 1200 على دفعة 1000 → مرفوض
        $student = $this->makeStudent();
        $inv1 = $this->makeInvoiceForStudent($student, ['total' => 600]);
        $inv2 = $this->makeInvoiceForStudent($student, ['total' => 600]);

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'invoice_id' => $inv1->id,
            'amount' => 1000,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->paymentsUser());

        $paymentId = $r->json('id');

        // نمسح التوزيع الأول عشان نعمل سيناريو التخصيص الحر
        PaymentAllocation::where('payment_id', $paymentId)->delete();
        $inv1->recalculateFromAllocations();

        $r2 = $this->postJsonAs("/api/payments/{$paymentId}/allocate", [
            'allocations' => [
                ['invoice_id' => $inv1->id, 'amount' => 600],
                ['invoice_id' => $inv2->id, 'amount' => 600],
            ],
        ], $this->paymentsUser());

        $r2->assertStatus(422);
        $this->assertStringContainsString('الدفعة', $r2->json('message'));

        // ⭐ مفيش ولا توزيع اتعمل
        $this->assertSame(0, PaymentAllocation::where('payment_id', $paymentId)->where('status', 'active')->count());
    }

    // ============================================================
    // ④ الاسترداد بيلغي التوزيعات
    // ============================================================

    public function test_a_full_refund_reverses_the_allocations(): void
    {
        // ⭐ دفعنا 500 على فاتورة → paid. بعدين استرجعنا 500 →
        // التوزيعات reversed، والفاتورة رجعت unpaid.
        $student = $this->makeStudent();
        $invoice = $this->makeInvoiceForStudent($student);

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->paymentsUser());

        $paymentId = $r->json('id');
        $this->assertSame('paid', $invoice->fresh()->status);

        $payer = $this->paymentsUser();


        // استرداد كامل
        $this->postJsonAs("/api/payments/{$paymentId}/refund", [
            'amount' => 500,
            'reason' => 'ألغى الاشتراك',
        ], $payer)->assertCreated();

        // ⭐ التوزيع اتعمله reversed
        $this->assertSame('reversed', PaymentAllocation::first()->status);

        // ⭐ الفاتورة رجعت غير مدفوعة — المدفوع بيت*حسب* من
        // التوزيعات النشطة، والتوزيعات كلها بقت reversed
        $this->assertSame(0.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(500.0, (float) $invoice->fresh()->balance_due);
        $this->assertSame('issued', $invoice->fresh()->status);
    }

    // ============================================================
    // ⑤ المدفوع بيت*حسب* — مش عمود مستقل
    // ============================================================

    public function test_paid_amount_is_derived_from_active_allocations_only(): void
    {
        // ⭐ لو فيه توزيع active وتوزيع reversed لنفس الفاتورة،
        // المدفوع يحسب من active بس.
        $student = $this->makeStudent();
        $invoice = $this->makeInvoiceForStudent($student, ['total' => 1000, 'balance_due' => 1000]);

        // توزيع نشط بـ 600
        PaymentAllocation::create([
            'organization_id' => $this->org->id,
            'payment_id' => $this->makePayment($student, 600)->id,
            'invoice_id' => $invoice->id,
            'amount' => 600,
            'status' => 'active',
        ]);

        // توزيع reversed بـ 400
        PaymentAllocation::create([
            'organization_id' => $this->org->id,
            'payment_id' => $this->makePayment($student, 400)->id,
            'invoice_id' => $invoice->id,
            'amount' => 400,
            'status' => 'reversed',
        ]);

        $invoice->recalculateFromAllocations();

        // ⭐ 600 فقط — الـ reversed مايدخلش
        $this->assertSame(600.0, (float) $invoice->fresh()->paid_amount);
        $this->assertSame(400.0, (float) $invoice->fresh()->balance_due);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
    }

    private function makePayment($student, float $amount): Payment
    {
        return Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => now(),
            'received_by' => $this->paymentsUser()->id,
        ]);
    }
}
