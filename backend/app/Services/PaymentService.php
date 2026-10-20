<?php

namespace App\Services;

use App\Casts\MoneyCast;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\PeriodLock;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\StudentCredit;
use App\Models\StudentLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PR 2.7 - الدفعة الصح.
 *
 * المشكلتان اللتان عالجتهما قبلاً في `PaymentController::store`:
 *
 *   1) كل حاجة inline من غير transaction: الدفع، التوزيع،
 *      إعادة حساب الفاتورة، دفتر الطالب، الحركة المالية —
 *      خمس خطوات. لو خطوة فشلت في النص، الدفعة اتسجلت
 *      وتوزيعها لأ = محاسبة نص نص.
 *
 *   2) مفيش تحقق: دفعة طالب ممكن تتوزع على فاتورة طالب
 *      تاني، أو بعملة مختلفة — الـvalidation كان بيقول
 *      "exists:invoices,id" وخلاص.
 *
 * الخطوات هنا كلها جوه DB::transaction واحدة، والفاتورة
 * مقفولة بـ lockForUpdate — فالطلبات المتزامنة بتحسب
 * "المتبقي" بعد ما ترانزاكشن الاخر يخلص (القاعدة 8).
 */
class PaymentService
{
    public function __construct(private readonly FinancialLedgerService $ledger)
    {
    }

    /**
     * الدفعة الكاملة: دفع + توزيع + دفاتر — transaction واحد.
     *
     * @return array{0: Payment, 1: ?FinancialAccount, 2: ?string,
     *               3: ?object, 4: ?object, 5: ?string, 6: bool}
     *         [دفعة، حساب، سبب الاختيار، الحركة، حركة العمولة،
     *          تحذير، مكرر؟]
     */
    public function record(array $data, $user): array
    {
        // ═══════════════════════════════════════════════════
        // 1) الـidempotency — نفس المفتاح = نفس الدفعة.
        //
        // السيناريو الحقيقي: العميل داس "ادفع" مرتين (شبكة
        // ضعيفة أو دبل-كليك). قبلها النتيجة كانت دفعتين
        // كاملتين.
        // ═══════════════════════════════════════════════════
        if (! empty($data['idempotency_key'])) {
            $existing = Payment::query()
                ->where('organization_id', $user->organization_id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing !== null) {
                return [$existing, null, null, null, null, null, true];
            }
        }

        // ═══════════════════════════════════════════════════
        // ⭐ القفل الوظيفي (PR 2.13) — الدفعة في فترة مقفولة
        //    = مرفوضة 422، مش بتتحفظ وحركة بتضيع مع تحذير.
        //
        // قبل كده: الدفعة بتتكتب والحركة المالية بتمنع جوه
        // `assertNotLocked` وترجع تحذير — التقرير المالي
        // مكانش بيشوف فلوس اتضربت فعلاً.
        // جاي بعد الـidempotency عشان مفتاح مشترك ما يتمنعش
        // من إعادة النداء.
        // ═══════════════════════════════════════════════════
        PeriodLock::assertOpen(
            (string) ($data['paid_at'] ?? now()->toDateString()),
            'payments',
        );

        // ═══════════════════════════════════════════════════
        // 2) الحالة — failed/voided مش إدخال عميل.
        //
        // حالات دورة حياة الدفع الإلكتروني الداخلي. العميل
        // كان بيبعت "status":"voided" ويلغي دفعة من الفراغ.
        // ═══════════════════════════════════════════════════
        $status = $data['status'] ?? 'completed';

        if (! in_array($status, ['pending', 'completed'], true)) {
            throw ValidationException::withMessages([
                'status' => 'status ماينفعش كده. failed/voided بيديرها النظام بس.',
            ]);
        }

        $amount = (float) $data['amount'];

        $netAmount = isset($data['net_amount']) && $data['net_amount'] !== null
            ? (float) $data['net_amount']
            : null;

        if ($netAmount !== null && $netAmount > $amount) {
            throw ValidationException::withMessages([
                'net_amount' => 'المبلغ الواصل أكبر من المبلغ الاسمي — لازم اقل أو يساوي.',
            ]);
        }

        return DB::transaction(function () use ($data, $user, $status, $amount, $netAmount) {
            $organizationId = $user->organization_id;

            // ═════════════════════════════════════════════
            // 3) الفاتورة — الملكية والعملة والقفل
            // ═════════════════════════════════════════════
            $invoice = null;
            $remaining = null;

            if (! empty($data['invoice_id'])) {
                // القفل — الطلب المتزامن يحسب "المتبقي" بعد ما يخلص اللي قبله
                $invoice = Invoice::whereKey($data['invoice_id'])->lockForUpdate()->first();

                if (! $invoice) {
                    throw ValidationException::withMessages(['invoice_id' => 'الفاتورة مش موجودة']);
                }

                if ((string) $invoice->organization_id !== (string) $organizationId) {
                    throw ValidationException::withMessages(['invoice_id' => 'الفاتورة مش من مؤسستك']);
                }

                if ((string) $invoice->student_id !== (string) ($data['student_id'] ?? '')) {
                    throw ValidationException::withMessages(['invoice_id' => 'الفاتورة مش بتاعة الطالب']);
                }

                if ($invoice->currency !== $data['currency']) {
                    throw ValidationException::withMessages([
                        'currency' => 'العملة مش متطابقة — الفاتورة بـ'.$invoice->currency,
                    ]);
                }

                if ($invoice->status === 'void') {
                    throw ValidationException::withMessages(['invoice_id' => 'الفاتورة ملغاة']);
                }

                $remaining = $this->remainingBalance($invoice);
            }

            // ═════════════════════════════════════════════
            // 4) الحساب المالي وباقي البيانات
            // ═════════════════════════════════════════════
            [$account, $pickReason] = $this->ledger->resolveAccount(
                $organizationId,
                ! empty($data['financial_account_id']) ? (int) $data['financial_account_id'] : null,
                $this->ledger->accountTypeForPaymentMethod((string) ($data['payment_method'] ?? 'cash')),
            );

            $fee = $netAmount !== null ? max(0.0, $amount - $netAmount) : 0.0;

            // ═════════════════════════════════════════════
            // 5) الدفعة نفسها — لو الـtransaction وقعت، الدفعة وقعت معاها
            // ═════════════════════════════════════════════
            try {
                $payment = Payment::create([
                    'organization_id' => $organizationId,
                    'student_id' => $data['student_id'],
                    'parent_id' => $data['parent_id'] ?? null,
                    'invoice_id' => $data['invoice_id'] ?? null,
                    'amount' => $amount,
                    'fee_amount' => $fee,
                    'currency' => $data['currency'],
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'transaction_reference' => $data['transaction_reference'] ?? null,
                    // الدفعة بتتحفظ مهما حصل — الفلوس دخلت فعلاً
                    'status' => $status,
                    'paid_at' => $data['paid_at'],
                    'received_by' => $user->id,
                    // الحساب محفوظ على الدفعة — حتى لو الحركة فشلت
                    'account_id' => $account?->id,
                    'notes' => $data['notes'] ?? null,
                    'net_amount' => $netAmount,
                    'idempotency_key' => $data['idempotency_key'] ?? null,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // سباق الـidempotency: طلبين بنفس المفتاح في نفس
                // اللحظة — الـunique حمايتنا. الوثاني يرجع الدفعة
                // اللي للسه تفعلاً مش دفعة جديدة.
                $message = $e->getMessage();

                if (
                    str_contains($message, '23505')
                    || str_contains($message, 'unique')
                    || str_contains($message, 'UNIQUE')
                ) {
                    $existing = Payment::query()
                        ->where('organization_id', $organizationId)
                        ->where('idempotency_key', $data['idempotency_key'])
                        ->first();

                    if ($existing) {
                        return [$existing, $account, $pickReason, null, null, null, true];
                    }
                }

                throw $e;
            }

            $movement = null;
            $feeMovement = null;
            $warning = null;
            $credit = 0.0;

            if ($invoice !== null) {
                // ═══════════════════════════════════════════
                // 6) التوزيع + الفائض رصيد دائن
                // ═══════════════════════════════════════════
                // cap على المتبقي — paid_amount ما يتجاوزش total
                $toAllocate = round(min($amount, max(0.0, $remaining)), 2);

                if ($toAllocate > 0) {
                    PaymentAllocation::create([
                        'organization_id' => $organizationId,
                        'payment_id' => $payment->id,
                        'invoice_id' => $invoice->id,
                        'amount' => $toAllocate,
                        'status' => 'active',
                        'created_by' => $user->id,
                    ]);
                }

                // الفاتورة بتحسب من التوزيعات — الخدمة الوحيدة (قاعدة 9)
                $freshInvoice = app(InvoiceService::class)
                    ->recalculateFromAllocations($invoice);

                $credit = round($amount - $toAllocate, 2);

                if ($credit > 0) {
                    StudentCredit::create([
                        'organization_id' => $organizationId,
                        'student_id' => $payment->student_id,
                        'currency' => $payment->currency,
                        'total' => $credit,
                        'reason' => 'دفعة زيادة عن فاتورة '.$invoice->invoice_number,
                        'source_type' => 'payment',
                        'source_id' => $payment->id,
                        'created_by' => $user->id,
                    ]);
                }

                // ═══════════════════════════════════════════
                // 7) دفتر الطالب — ⭐⭐ PR 2.9: كتابة من
                //    `StudentLedgerService` برصيد **تجميعي**
                //    (القديم كان يكتب `balance_due` بتاع الفاتورة —
                //    دفعتين على فاتورات مختلفين كانوا بياخدوا
                //    رصيدين مش سلسلة واحدة).
                // ═══════════════════════════════════════════
                app(\App\Services\StudentLedgerService::class)->write([
                    'student_id' => $payment->student_id,
                    'invoice_id' => $invoice->id,
                    'payment_id' => $payment->id,
                    'type' => 'payment',
                    'credit' => $amount,
                    'currency' => $payment->currency,
                    'description' => 'دفعة للفاتورة '.$invoice->invoice_number,
                    'reference_type' => 'payment',
                    'reference_id' => $payment->id,
                    'created_by' => $user->id,
                ]);
            }

            // ═════════════════════════════════════════════
            // 8) الحركات المالية — نفس شروط اليوم:
            //    completed فقط + حساب موجود. pending = فلوس
            //    مش دخلت الخزينة بعد.
            // ═════════════════════════════════════════════
            if ($account === null) {
                $warning = 'مفيش حساب مالي نشط — الحركة المالية ماتسجلتش';
            }

            if ($account !== null && $status === 'completed') {
                // المبلغ الواصل — الطالب دفع 1000 بس الخزينة استلمت 970
                $result = $this->ledger->recordFor(
                    account: $account,
                    direction: 'in',
                    category: 'income',
                    amount: (float) ($payment->net_amount ?: $payment->amount),
                    referenceType: 'payment',
                    referenceId: $payment->id,
                    movementDate: (string) $payment->paid_at,
                    notes: $payment->notes ?: $payment->transaction_reference,
                    user: $user,
                );
                $movement = $result['movement'];
                $warning = $result['warning'];

                // العمولة بتتسجل كمصروف منفصل
                if ($fee > 0) {
                    $feeResult = $this->ledger->recordFor(
                        account: $account,
                        direction: 'out',
                        category: 'fee',
                        amount: $fee,
                        referenceType: 'payment_fee',
                        referenceId: $payment->id,
                        movementDate: (string) $payment->paid_at,
                        notes: 'عمولة الدفع على دفعة #'.$payment->id,
                        user: $user,
                    );

                    $feeMovement = $feeResult['movement'];

                    if (! $feeResult['movement'] && ! $warning) {
                        $warning = $feeResult['warning'];
                    }
                }
            }

            return [$payment, $account, $pickReason, $movement, $feeMovement, $warning, false];
        });
    }

    /**
     * المتبقي للتوزيع.
     *
     * = الإجمالي - التوزيعات النشطة - الرصيد الدائن المطبق عليها
     *
     * بيحسب من التوزيعات مباشرة — الدقة مش مربوطة بحالة
     * عمود مجهّز. نفس الصيغة اللي بتستخدم في إعادة الحساب.
     */
    private function remainingBalance(Invoice $invoice): float
    {
        $paid = (float) MoneyCast::fromAggregate(
            $invoice->allocations()->active()->sum('amount')
        );

        $credited = (float) MoneyCast::fromAggregate(
            $invoice->creditApplications()->active()->sum('amount')
        );

        return max(0.0, (float) $invoice->total - $paid - $credited);
    }
}
