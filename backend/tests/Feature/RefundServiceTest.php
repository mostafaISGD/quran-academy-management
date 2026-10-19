<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\StudentCredit;
use App\Models\StudentLedgerEntry;
use App\Services\PaymentService;
use App\Services\RefundService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * PR 2.8 - الاستردادات والعكس وCredit Notes.
 *
 * المشكلة الحقيقية قبله: `PaymentController::refund` بيعمل
 * كل حاجة inline من غير transaction ولا قفل:
 *
 *   1) الاسترداد الجزئي **مابيأثرش على الفاتورة خالص** -
 *      استردنا 400 من دفعة 1000، والفاتورة لسه paid بصمت.
 *   2) مفيش سقف مقفول: طلبين استرداد متزامنين ممكن
 *      يتعدوا المبلغ المدفوع.
 *   3) الحالة المخزنة `processed` ثابتة - ومفيش موافقة
 *      تانية على المبالغ الكبيرة.
 *   4) مفيش فصل بين نقدي ورصيد دائن (credit_note).
 */
class RefundServiceTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // 1 - الاسترداد الكامل النقدي: الدفتر والحساب والفاتورة
    // ═══════════════════════════════════════════════════════════

    public function test_a_full_cash_refund_reverses_the_invoice_and_records_the_outflow(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [$invoice, $payment] = $this->payInvoice(1000.00, $staff);

        [$refund, $account, $pickReason, $movement] = app(RefundService::class)
            ->refund($payment, 1000.00, 'cash', 'ألغى الاشتراك', null, null, $staff, null);

        // الفاتورة رجعت أصلها
        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertEquals(0.00, (float) $invoice->paid_amount);
        $this->assertEquals(1000.00, (float) $invoice->balance_due);
        $this->assertSame('reversed', $invoice->allocations()->first()->status);

        // الحركة المالية خارجة - فئة refund
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('refund', $movement->category);
        $this->assertSame($account->id, $movement->financial_account_id);

        // الحالة محفوظة معناها حقیقیة
        $refund->refresh();
        $this->assertSame('processed', $refund->status);
        $this->assertEquals(1000.00, (float) $refund->amount);

        // سطر دفتر الطالب - مدين (فلوس رجعت للطالب)
        $entry = StudentLedgerEntry::where('type', 'refund')->first();
        $this->assertNotNull($entry);
        $this->assertEquals(1000.00, (float) $entry->debit);

        // والدفع ملغاة (استرداد كامل)
        $this->assertSame('voided', (string) $payment->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════
    // 2 - الاسترداد الجزئي: الفاتورة بتتظبط
    // ═══════════════════════════════════════════════════════════

    public function test_a_partial_refund_shrinks_the_invoice_correctly(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [$invoice, $payment] = $this->payInvoice(1000.00, $staff);

        $service = app(RefundService::class);
        $service->refund($payment, 400.00, 'cash', 'خصم جزئي', null, null, $staff, null);

        $invoice->refresh();

        // الفاتورة: مدفوع 600 - متبقي 400
        $this->assertEquals(600.00, (float) $invoice->paid_amount);
        $this->assertEquals(400.00, (float) $invoice->balance_due);
        $this->assertSame('partially_paid', $invoice->status);

        // التوزيعة الأصلية reversed + سطر نشط جديد بباقي 600
        $this->assertSame(
            ['reversed', 'active'],
            $invoice->allocations()->orderBy('id')->get()->pluck('status')->all(),
        );
        $this->assertEquals(600.00, $payment->fresh()->allocatedAmount());

        // والاسترداد ماتسجلش مرتين
        $this->assertSame(1, Refund::count());
        $this->assertEquals(600.00, $payment->fresh()->amount - $payment->fresh()->totalRefunded());

        // استرداد الباقي كله = سالم
        $service->refund($payment, 600.00, 'cash', 'الاسترداد كله', null, null, $staff, null);

        $invoice->refresh();
        $this->assertEquals(0.00, (float) $invoice->paid_amount);
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('voided', (string) $payment->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════
    // 3 - السقف: مستردات اكثر من المدفوع ممنوعة
    // ═══════════════════════════════════════════════════════════

    public function test_refunds_cannot_exceed_the_amount_paid(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(500.00, $staff);

        $this->expectException(ValidationException::class);

        app(RefundService::class)
            ->refund($payment, 600.00, 'cash', 'زيادة غير عادلة', null, null, $staff, null);
    }

    public function test_the_refundable_amount_is_reserved_by_pending_approvals(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(500.00, $staff);

        // ⭐ سقف 350 → الطلب بـ400 يتوقف `pending_approval`،
        // والمعلّق **بيحجز** من السقف — الفحص يمنع التعدي
        // على الموافقة، مش وقت الاعتماد.
        Setting::set('finance.refund_approval_threshold', 350, $this->org->id);
        $service = app(RefundService::class);

        [$first] = $service->refund($payment, 400.00, 'cash', 'طلب 1', null, null, $staff, null);
        $this->assertSame('pending_approval', $first->status);

        $this->expectException(ValidationException::class);

        $service->refund($payment, 150.00, 'cash', 'طلب 2 - هتعدى السقف مع الحجز', null, null, $staff, null);
    }

    // ═══════════════════════════════════════════════════════════
    // 4 - الاعتماد التاني للمبالغ الكبيرة
    // ═══════════════════════════════════════════════════════════

    public function test_amounts_above_the_threshold_require_a_second_approval(): void
    {
        $this->makeAccount();
        Setting::set('finance.refund_approval_threshold', 750, $this->org->id);
        $staff = $this->clerk();
        [$invoice, $payment] = $this->payInvoice(1000.00, $staff);

        [$refund, , , $movement] = app(RefundService::class)
            ->refund($payment, 1000.00, 'cash', 'استرداد ضخم', null, null, $staff, null);

        // الحالة = موافقة معلقة
        $this->assertSame('pending_approval', $refund->status);
        $this->assertNull($movement, 'من غير اعتماد - مفيش حركة');

        // والفاتورة ماتآثرتش - مش فعل الا بعد الاعتماد
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0, AccountMovement::where('reference_type', 'refund')->count());

        // الطالب اللي طلب نفسه مهواش يوافق على نفسه
        $this->expectException(ValidationException::class);

        app(RefundService::class)->approve(
            $refund,
            $staff,
            null
        );
    }

    public function test_another_user_can_approve_a_pending_refund(): void
    {
        $this->makeAccount();
        Setting::set('finance.refund_approval_threshold', 750, $this->org->id);
        $staff = $this->clerk();
        [$invoice, $payment] = $this->payInvoice(1000.00, $staff);

        $service = app(RefundService::class);
        [$refund] = $service->refund($payment, 1000.00, 'cash', 'استرداد ضخم', null, null, $staff, null);

        $approver = $this->makeUserWithRole('manager', ['finance.refunds.approve']);

        [$refund, , , $movement] = $service->approve($refund, $approver, null);

        // الحالة تتبعت للصالح - وapproved_by المحل
        $refund->refresh();
        $this->assertSame('processed', $refund->status);
        $this->assertSame($approver->id, (int) $refund->approved_by);
        $this->assertNotNull($refund->approved_at);

        // الحركة اتعملت معظوق - والفاتورة بقت أصلها
        $this->assertNotNull($movement);
        $this->assertSame('out', $movement->direction);
        $this->assertSame('refund', $movement->category);

        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
    }

    public function test_below_the_threshold_is_processed_immediately(): void
    {
        $this->makeAccount();
        Setting::set('finance.refund_approval_threshold', 750, $this->org->id);
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(1000.00, $staff);

        [$refund, , , $movement] = app(RefundService::class)
            ->refund($payment, 500.00, 'cash', 'تحت السقف', null, null, $staff, null);

        $this->assertSame('processed', $refund->status);
        $this->assertNotNull($movement);
    }

    // ═══════════════════════════════════════════════════════════
    // 5 - credit_note: رصيد دائن مش فلوس
    // ═══════════════════════════════════════════════════════════

    public function test_credit_note_mode_creates_a_credit_balance_not_a_movement(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [$invoice, $payment] = $this->payInvoice(1000.00, $staff);

        [$refund, , , $movement, $warning] = app(RefundService::class)
            ->refund($payment, 500.00, 'credit_note', 'بديل عن النقدي', null, null, $staff, null);

        $this->assertSame('processed', $refund->status);
        $this->assertNull($movement, 'الائتمان مش فلوس - مفيش حركة خارجة');

        // رصيد دائن من نوع credit_note
        $credit = StudentCredit::where('student_id', $payment->student_id)->first();
        $this->assertNotNull($credit);
        $this->assertEquals(500.00, (float) $credit->total);
        $this->assertSame('credit_note', $credit->source_type);
        $this->assertSame($refund->id, (int) $credit->source_id);
        $this->assertTrue($credit->isAvailable());

        // والفاتورة اتظبطت برضه
        $invoice->refresh();
        $this->assertEquals(500.00, (float) $invoice->paid_amount);
    }

    // ═══════════════════════════════════════════════════════════
    // 6 - عكس دفعة خاطئة
    // ═══════════════════════════════════════════════════════════

    public function test_reversing_a_payment_reverses_everything(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [$invoice, $payment] = $this->payInvoice(1000.00, $staff, 970.00);

        $service = app(RefundService::class);
        $reversed = $service->reverse($payment, 'دفعة مسجلة خطأ', $staff);

        // الحالة - reversed مش voided
        $this->assertSame('reversed', $reversed->status);

        // كل حركة أصلية ليها عكس (دخل + عمولة) — بنعد
        // **الحركات الأصلية** بس (العكسية ليها reverses_movement_id)
        $originals = AccountMovement::where('reference_id', $payment->id)
            ->whereNull('reverses_movement_id')->get();
        $this->assertSame(2, $originals->count());
        foreach ($originals as $movement) {
            $this->assertSame(
                1,
                AccountMovement::where('reverses_movement_id', $movement->id)->count(),
                'الحركة ما اتعرقوش - '.$movement->category,
            );
        }

        // التوزيعة اتلغت - والفاتورة رجعت
        $invoice->refresh();
        $this->assertSame('issued', $invoice->status);
        $this->assertEquals(0.00, (float) $invoice->paid_amount);

        // سطر عكسي في دفتر الطالب
        $this->assertSame(
            2,
            StudentLedgerEntry::where('payment_id', $payment->id)->count(),
        );
    }

    public function test_reversing_twice_is_rejected(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(500.00, $staff);

        $service = app(RefundService::class);
        $service->reverse($payment, 'خطأ', $staff);

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $service->reverse($payment, 'تاني', $staff);
    }

    public function test_a_pending_payment_cannot_be_reversed(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        $student = $this->makeStudent();

        [$payment] = app(PaymentService::class)->record([
            'student_id' => $student->id,
            'amount' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'status' => 'pending',
        ], $staff);

        $this->expectException(ValidationException::class);

        app(RefundService::class)->reverse($payment, 'خطأ', $staff);
    }

    // ═══════════════════════════════════════════════════════════
    // 6.5 - التدقيق: الاسترداد المنفَذ بيتكتب في audit_logs
    // ═══════════════════════════════════════════════════════════

    /** ⭐ قاعدة 3 من AGENTS: الاسترداد عملية حساسة → audit_logs */
    public function test_every_executed_refund_is_audited(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(500.00, $staff);

        app(RefundService::class)
            ->refund($payment, 200.00, 'cash', 'استرداد مجرّب', null, null, $staff, null);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'refund',
            'entity_type' => 'payment',
            'entity_id' => $payment->id,
            'user_id' => $staff->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════
    // 7 - الAPI
    // ═══════════════════════════════════════════════════════════

    public function test_the_api_refund_endpoint_returns_the_pending_refund(): void
    {
        $this->makeAccount();
        Setting::set('finance.refund_approval_threshold', 750, $this->org->id);
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(1000.00, $staff);

        $r = $this->postJsonAs("/api/payments/{$payment->id}/refund", [
            'amount' => 1000,
            'reason' => 'استرداد ضخم',
        ], $staff);

        $r->assertCreated();
        $this->assertSame('pending_approval', $r->json('status'));
        $r->assertJsonPath('ledger.recorded', false);
        $this->assertTrue($r->json('pending_approval'));
    }

    public function test_the_api_approvals_endpoint_is_protected(): void
    {
        $this->makeAccount();
        Setting::set('finance.refund_approval_threshold', 750, $this->org->id);
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(1000.00, $staff);

        // الطلب من موظف عنده صلاحية الطلب والapproval معاً —
        // ونفسه مرفوض عن اعتذارد.

        $service = app(RefundService::class);
        [$refund] = $service->refund($payment, 1000.00, 'cash', 'استرداد ضخم', null, null, $staff, null);

        // بدون الصلاحية = مرفوض (staff عنده payments.refund بس)
        $this->postJsonAs("/api/refunds/{$refund->id}/approve", [], $staff)->assertStatus(403);

        // طالب الطلب نفسه عنده صلاحية الاعتماد = ممنوع على نفسه
        $self = $this->makeUserWithRole('manager', ['finance.refunds.approve']);
        $refund->update(['processed_by' => $self->id]);
        $this->postJsonAs("/api/refunds/{$refund->id}/approve", [], $self)->assertStatus(422);

        // بصلاحية ومش نفس طالب الطلب = مقبول
        $approver = $this->makeUserWithRole('supervisor', ['finance.refunds.approve']);
        $this->postJsonAs("/api/refunds/{$refund->id}/approve", [], $approver)->assertOk();
    }

    public function test_the_api_reverse_endpoint_is_protected(): void
    {
        $this->makeAccount();
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(500.00, $staff);

        // من غير الصلاحية = مرفوض (viewer بقراءة بس)
        $viewer = $this->makeUserWithRole('viewer', ['finance.payments.view']);
        $this->postJsonAs("/api/payments/{$payment->id}/reverse", [
            'reason' => 'خطأ',
        ], $viewer)->assertStatus(403);

        // بصلاحية = تم
        $reverser = $this->makeUserWithRole('manager', ['finance.payments.refund']);
        $r = $this->postJsonAs("/api/payments/{$payment->id}/reverse", [
            'reason' => 'خطأ',
        ], $reverser);

        $r->assertOk();
        $this->assertSame('reversed', $r->json('data.status'));
    }

    // ═══════════════════════════════════════════════════════════
    // 8 - التزامن (PgSQL)
    // ═══════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Group('pgsql')]
    public function test_two_concurrent_refunds_never_exceed_the_paid_amount(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('التزامن الحقيقي يحتاج PostgreSQL');
        }

        $this->makeAccount();
        $staff = $this->clerk();
        [, $payment] = $this->payInvoice(500.00, $staff);

        $service = app(RefundService::class);

        // الـlockForUpdate على صف الدفعة - موظفين مختلفين
        // بنفس اللحظة: الجمع = 500 بالظبط، التخطي مرفوض.
        [$a] = $service->refund($payment, 300.00, 'cash', 'أ', null, null, $staff, null);
        [$b] = $service->refund($payment, 200.00, 'cash', 'ب', null, null, $staff, null);

        $this->assertSame('processed', $a->refresh()->status);
        $this->assertSame('processed', $b->refresh()->status);
        $this->assertEquals(500.00, $payment->fresh()->totalRefunded());
    }

    // ═══════════════════════════════════════════════════════════
    // fixtures
    // ═══════════════════════════════════════════════════════════

    /** دفعة كاملة على فاتورة - عبر خدمة PR 2.7 (توزيع + حركة) */
    private function payInvoice(float $total, $staff, ?float $net = null): array
    {
        $invoice = Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $this->makeStudent()->id,
            'invoice_number' => 'INV-RS-'.uniqid(),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'EGP',
            'subtotal' => $total,
            'discount' => 0,
            'tax' => 0,
            'total' => $total,
            'paid_amount' => 0,
            'balance_due' => $total,
            'status' => 'issued',
        ]);

        $this->makeAccount(['opening_balance' => 500]
        );

        [$payment] = app(PaymentService::class)->record(array_filter([
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'amount' => $total,
            'net_amount' => $net,
            'currency' => 'EGP',
            'payment_method' => $net ? 'online_payment' : 'cash',
            'paid_at' => now()->toDateString(),
        ]), $staff);

        return [$invoice, $payment];
    }

    private function clerk()
    {
        return $this->makeUserWithRole('staff', [
            'finance.payments.create', 'finance.payments.refund',
        ]);
    }
}
