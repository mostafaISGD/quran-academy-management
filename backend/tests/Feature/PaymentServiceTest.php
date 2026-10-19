<?php

namespace Tests\Feature;

use App\Models\AccountMovement;
use App\Models\Invoice;
use App\Models\PaymentAllocation;
use App\Models\StudentCredit;
use App\Models\StudentLedgerEntry;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ⭐ PR 2.7 — دفعة صح: توزيع، idempotency، والحساب.
 *
 * ⭐⭐ المشكلتين الحقيقيتين قبله:
 *
 *   ① `PaymentController::store` كان بيعمل **كل حاجة inline**:
 *      بيدفع، بيوزّع، بيعدّل الفاتورة، بيكتب ledger، بيسجّل
 *      الحركة — كل ده **من غير transaction ولا lockForUpdate**.
 *      دفعتين متزامنين على فاتورة واحدة بتحسب «المتبقي» من
 *      قراءة قديمة، واحدة بترجع فوق التانية، والفلوس بتتضيع.
 *
 *   ② **التحقق عليهم كان فاضل**: الدفعة بتاعت طالب ممكن
 *      تتوزّع على فاتورة **طالب تاني** بعملة **مختلفة** —
 *      والـvalidation كان بيقول `exists:invoices,id` وخلاص.
 */
class PaymentServiceTest extends TestCase
{
    /**
     * مجموع التوزيعات النشطة للدفعة.
     *
     * ⭐ `sum(closure)` على الـbuilder بيبعت الـclosure كـcolumn
     * — لازم نجيب الـmodels وبعدين نجمع الـattributes.
     */
    private function allocatedOf($payment): float
    {
        return (float) $payment->allocations()->get()->sum(fn ($a) => $a->amount);
    }

    // ═══════════════════════════════════════════════════════════
    // ① التدفق الكامل جوه transaction
    // ═══════════════════════════════════════════════════════════

    public function test_the_service_records_payment_allocation_ledger_and_movement(): void
    {
        $account = $this->makeAccount();
        $staff = $this->payingUser();
        $invoice = $this->invoice(1000.00);

        [$payment] = app(PaymentService::class)->record(
            [
                'student_id' => $invoice->student_id,
                'invoice_id' => $invoice->id,
                'amount' => 1000.00,
                'currency' => 'EGP',
                'payment_method' => 'cash',
                'paid_at' => now()->toDateString(),
            ],
            $staff,
        );

        // ⭐ التوزيع اتعمل
        $this->assertEquals(1000.00, $this->allocatedOf($payment));

        // ⭐ الفاتورة paid
        $invoice->refresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0.00, (float) $invoice->balance_due);

        // ⭐ سطر دفتر الطالب
        $this->assertSame(
            1,
            StudentLedgerEntry::where('payment_id', $payment->id)->count(),
        );

        // ⭐ حركة دخل في الدفتر
        $movement = AccountMovement::where('reference_type', 'payment')
            ->where('reference_id', $payment->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('in', $movement->direction);
        $this->assertSame('income', $movement->category);

        // ⭐ الحساب مسجّل على الدفعة نفسها
        $this->assertSame($account->id, $payment->fresh()->account_id);

        // ⭐ ومفيش رصيد دائن
        $this->assertSame(0, StudentCredit::count());
    }

    // ═══════════════════════════════════════════════════════════
    // ② التحقق — ملكية الفاتورة والعملة
    // ═══════════════════════════════════════════════════════════

    public function test_a_charge_on_an_invoice_of_another_student_is_rejected(): void
    {
        $other = $this->invoice(500.00);
        $payer = $this->makeStudent();
        $payment = app(PaymentService::class);

        $this->expectException(ValidationException::class);

        $payment->record([
            'student_id' => $payer->id,
            'invoice_id' => $other->id,
            'amount' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->payingUser());
    }

    public function test_a_charge_in_a_different_currency_is_rejected(): void
    {
        $invoice = $this->invoice(500.00);
        $payment = app(PaymentService::class);

        $this->expectException(ValidationException::class);

        $payment->record([
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'amount' => 500.00,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->payingUser());
    }

    public function test_a_charge_on_a_void_invoice_is_rejected(): void
    {
        $invoice = $this->invoice(500.00);
        $payment = app(PaymentService::class);

        $invoice->update(['status' => 'void']);

        $this->expectException(ValidationException::class);

        $payment->record([
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'amount' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->payingUser());
    }

    // ═══════════════════════════════════════════════════════════
    // ③ الفائض — رصيد دائن، مش تقطّيع صامت
    // ═══════════════════════════════════════════════════════════

    public function test_an_excess_becomes_a_credit_balance(): void
    {
        $this->makeAccount();
        $service = app(PaymentService::class);
        $staff = $this->payingUser();
        $invoice = $this->invoice(1000.00);
        $studentId = $invoice->student_id;

        // ⭐ أول دفعة 600 — المتبقي بقى 400
        $service->record([
            'student_id' => $studentId,
            'invoice_id' => $invoice->id,
            'amount' => 600.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $staff);

        // ⭐⭐ دفعة تانية 600 على فاتورة متبقيها 400:
        // قبلها كانت بتعمل توزيع كامل 600 → paid_amount بيبقى
        // أعلى من total بصمت. دلوقتي: توزيع 400 + رصيد 200.
        [$second] = $service->record([
            'student_id' => $studentId,
            'invoice_id' => $invoice->id,
            'amount' => 600.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $staff);

        $invoice->refresh();

        // ⭐ الفاتورة paid والمتبقي صفر — من غير ما يتجاوَز
        $this->assertSame('paid', $invoice->status);
        $this->assertEquals(0.00, (float) $invoice->balance_due);
        $this->assertEquals(
            1000.00,
            (float) $invoice->allocations()->get()->sum(fn ($a) => $a->amount),
        );

        // ⭐⭐ الباقي رصيد دائن — مش اختفى
        $credit = StudentCredit::where('student_id', $studentId)->first();
        $this->assertNotNull($credit, 'الفائض لازم يتسجل رصيد دائن');
        $this->assertEquals(200.00, (float) $credit->total);
        $this->assertSame('payment', $credit->source_type);
        $this->assertSame($second->id, (int) $credit->source_id);
        $this->assertSame('active', $credit->status);
    }

    public function test_an_exact_payment_creates_no_credit(): void
    {
        $this->makeAccount();
        $service = app(PaymentService::class);
        $invoice = $this->invoice(1000.00);

        $service->record([
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'amount' => 400.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->payingUser());

        $service->record([
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'amount' => 600.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->payingUser());

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0, StudentCredit::count(), 'مفيش فائض — مفيش رصيد');
    }

    // ═══════════════════════════════════════════════════════════
    // ④ الـidempotency — إرسال مكرر ما بيدبلش
    // ═══════════════════════════════════════════════════════════

    public function test_resubmitting_the_same_idempotency_key_returns_the_same_payment(): void
    {
        $this->makeAccount();
        $service = app(PaymentService::class);
        $staff = $this->payingUser();
        $invoice = $this->invoice(1000.00);
        $studentId = $invoice->student_id;

        $payload = [
            'student_id' => $studentId,
            'invoice_id' => $invoice->id,
            'amount' => 1000.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'idempotency_key' => 'clash-abc-123',
        ];

        [$first] = $service->record($payload, $staff);
        [$second] = $service->record($payload, $staff);

        // ⭐ نفس الدفعة — مش دفعة جديدة
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, \App\Models\Payment::count());

        // ⭐ وحركة واحدة — مش حركتين (double-click)
        $this->assertSame(
            1,
            AccountMovement::where('reference_type', 'payment')->count(),
        );
    }

    public function test_idempotency_keys_are_scoped_to_the_organization(): void
    {
        $this->makeAccount();
        $service = app(PaymentService::class);
        $invoice = $this->invoice(1000.00);
        $studentId = $invoice->student_id;

        // ⭐ دفعة من غير فاتورة — عشان المؤسسة التانية والإجازة
        // (تحذر الملكية) متفتحش.
        $payload = [
            'student_id' => $studentId,
            'amount' => 1000.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'idempotency_key' => 'same-key-orgs',
        ];

        $service->record($payload, $this->payingUser());

        // ⭐ مؤسسة تانية — نفس الـkey ده دفعة **جديدة**
        $otherOrgUser = $this->makeUserWithRole('staff', ['finance.payments.create']);
        $otherOrgUser->update(['organization_id' => \App\Models\Organization::create([
            'name' => 'أكاديمية تانية',
            'slug' => 'other-academy',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ])->id]);

        [$second] = $service->record($payload, $otherOrgUser);

        $this->assertNotSame(null, $second);
        $this->assertSame(2, \App\Models\Payment::count());
    }

    // ═══════════════════════════════════════════════════════════
    // ⑤ الحالة العميلية — مش مديرة للفلوس
    // ═══════════════════════════════════════════════════════════

    public function test_the_client_cannot_submit_failed_or_voided(): void
    {
        $service = app(PaymentService::class);
        $invoice = $this->invoice(1000.00);

        $this->expectException(ValidationException::class);

        // ⭐ `failed`/`voided` حالات دورة حياة **الدفع الإلكتروني**
        // الداخلي — العميل من الـAPI العام ماعندوش حاجة يلعبها.
        $service->record([
            'student_id' => $invoice->student_id,
            'amount' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'status' => 'failed',
        ], $this->payingUser());
    }

    public function test_a_pending_payment_records_no_movement(): void
    {
        $account = $this->makeAccount();
        $service = app(PaymentService::class);
        $invoice = $this->invoice(1000.00);

        [$payment] = $service->record([
            'student_id' => $invoice->student_id,
            'amount' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'status' => 'pending',
        ], $this->payingUser());

        $this->assertSame('pending', $payment->status);
        $this->assertSame(0, AccountMovement::count());
        $this->assertEquals(0.0, $account->fresh()->balance());
    }

    // ═══════════════════════════════════════════════════════════
    // ⑥ العمولة — fee_amount محفوظ
    // ═══════════════════════════════════════════════════════════

    public function test_the_fee_is_persisted_and_both_movements_are_recorded(): void
    {
        $account = $this->makeAccount();
        $service = app(PaymentService::class);
        $invoice = $this->invoice(1000.00);

        [$payment] = $service->record([
            'student_id' => $invoice->student_id,
            'amount' => 1000.00,
            'net_amount' => 970.00,
            'currency' => 'EGP',
            'payment_method' => 'online_payment',
            'paid_at' => now()->toDateString(),
        ], $this->payingUser());

        // ⭐ العمولة محفوظة على الدفعة — مش محسوبة تشياً
        $this->assertEquals(30.00, (float) $payment->fee_amount);
        $this->assertEquals(970.00, (float) $payment->net_amount);
        $this->assertSame($account->id, $payment->fresh()->account_id);

        // ⭐ حركتين: دخل 970 وعمولة 30 خارجة
        $movements = AccountMovement::where('reference_id', $payment->id)
            ->orderBy('category')->get();
        $this->assertSame(2, $movements->count());
        $this->assertEquals(970.0, (float) $movements->firstWhere('category', 'income')->amount);
        $this->assertEquals(30.0, (float) $movements->firstWhere('category', 'fee')->amount);
    }

    // ═══════════════════════════════════════════════════════════
    // ⑦ الـAPI — نفس الوظايف من الراوتر
    // ═══════════════════════════════════════════════════════════

    public function test_the_api_returns_the_same_payment_for_the_same_key(): void
    {
        $this->makeAccount();
        $staff = $this->makeUserWithRole('staff', ['finance.payments.create']);
        $invoice = $this->invoice(1000.00);
        $studentId = $invoice->student_id;

        $payload = [
            'student_id' => $studentId,
            'invoice_id' => $invoice->id,
            'amount' => 1000,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'idempotency_key' => 'api-key-1',
        ];

        $first = $this->postJsonAs('/api/payments', $payload, $staff);

        $first->assertCreated();
        $firstId = $first->json('id');

        $second = $this->postJsonAs('/api/payments', $payload, $staff);

        $second->assertOk();
        $this->assertSame($firstId, $second->json('id'), 'نفس الـkey = نفس الدفعة');
        $this->assertTrue($second->json('idempotent'));

        $this->assertSame(1, \App\Models\Payment::count());
        $this->assertSame(1, AccountMovement::where('reference_type', 'payment')->count());
    }

    public function test_the_api_rejects_a_foreign_invoice(): void
    {
        $this->makeAccount();
        $staff = $this->makeUserWithRole('staff', ['finance.payments.create']);
        $invoice = $this->invoice(500.00);
        $payer = $this->makeStudent();

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $payer->id,
            'invoice_id' => $invoice->id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $staff);

        $r->assertStatus(422);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_the_api_cannot_mark_a_payment_failed(): void
    {
        $this->makeAccount();
        $staff = $this->makeUserWithRole('staff', ['finance.payments.create']);
        $invoice = $this->invoice(500.00);

        $r = $this->postJsonAs('/api/payments', [
            'student_id' => $invoice->student_id,
            'amount' => 500,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
            'status' => 'voided',
        ], $staff);

        $r->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════
    // ⑧ التزامن — الدفعتين مش بيضيعا (PgSQL)
    // ═══════════════════════════════════════════════════════════

    #[\PHPUnit\Framework\Attributes\Group('pgsql')]
    public function test_two_clerks_at_the_same_second_both_get_recorded(): void
    {
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('التزامن الحقيقي يحتاج PostgreSQL');
        }

        $this->makeAccount();
        $service = app(PaymentService::class);
        $invoice = $this->invoice(1000.00);
        $studentId = $invoice->student_id;

        $clerkA = $this->payingUser();
        $clerkB = $this->payingUser();

        $base = [
            'student_id' => $studentId,
            'invoice_id' => $invoice->id,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ];

        // ⭐ موظفين مختلفين في نفس اللحظة — الـlockForUpdate جوه
        // transaction بيخلي «المتبقي» يتقري **بعد** ما ولّيه
        // قبلها يخلص، فالتوزيع إيك دقيق.
        [$first] = $service->record($base + ['amount' => 600.00],
            $clerkA);
        [$second] = $service->record($base + ['amount' => 600.00],
            $clerkB);

        $this->assertEquals(1000.00,
            (float) $invoice->allocations()->get()->sum(fn ($a) => $a->amount));

        $this->assertSame('paid', $invoice->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════
    // ⑨ بدون فاتورة — الدفعة بتتحفظ كالموزّعة لاحقاً
    // ═══════════════════════════════════════════════════════════

    public function test_a_payment_without_an_invoice_is_saved_alone(): void
    {
        $this->makeAccount();
        $invoice = $this->invoice(1000.00);

        [$payment] = app(PaymentService::class)->record([
            'student_id' => $invoice->student_id,
            'amount' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $this->payingUser());

        // ⭐ من غير فاتورة: مفيش توزيع ولا رصيد دائن — الدفعة
        // بتتحفظ «غير موزّعة» والتوزيع بعدين من /allocate.
        $this->assertSame(0, $payment->allocations()->count());
        $this->assertEquals(500.00, $payment->unallocatedAmount());
        $this->assertSame(0, StudentCredit::where('source_id', $payment->id)->count());
    }

    // ═══════════════════════════════════════════════════════════
    // ⑩ `/allocate` — نفس التحقق على التوزيع اللاحق
    // ═══════════════════════════════════════════════════════════

    public function test_allocating_via_the_api_rejects_a_foreign_invoice(): void
    {
        $this->makeAccount();
        $staff = $this->makeUserWithRole('staff', ['finance.payments.update']);
        $invoice = $this->invoice(500.00);
        $payer = $this->makeStudent();

        [$payment] = app(PaymentService::class)->record([
            'student_id' => $payer->id,
            'amount' => 500.00,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'paid_at' => now()->toDateString(),
        ], $staff);

        $r = $this->postJsonAs("/api/payments/{$payment->id}/allocate", [
            'allocations' => [
                ['invoice_id' => $invoice->id, 'amount' => 500],
            ],
        ], $staff);

        $r->assertStatus(422);
        $this->assertSame(0, PaymentAllocation::count());
    }

    // ═══════════════════════════════════════════════════════════
    // fixtures
    // ═══════════════════════════════════════════════════════════

    private function invoice(float $total): Invoice
    {
        return Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $this->makeStudent()->id,
            'invoice_number' => 'INV-PS-'.uniqid(),
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
    }

    private function payingUser()
    {
        return $this->makeUserWithRole('staff', ['finance.payments.create']);
    }
}
