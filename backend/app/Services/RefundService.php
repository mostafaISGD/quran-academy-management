<?php

namespace App\Services;

use App\Casts\MoneyCast;
use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\StudentCredit;
use App\Models\StudentLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PR 2.8 - الاستردادات والعكس والتقارير.
 *
 * المشكلات القديمة في `PaymentController::refund`:
 * كل شيء inline بدون transaction ولا قفل، والاسترداد الجزئي
 * لا يأثر على الفاتورة، ولا يوجد موافقة ثانية للمبالغ الكبيرة.
 *
 * القواعد هنا:
 *
 *   1) كل السطور المالية في transaction واحدة (قاعدة 8).
 *   2) التوزيعات immutable — الجزء المسترد يعاد «reversed»
 *      وسطر جديد يبقّي الباقي نشطاً (تصحيح = سطر جديد، قاعدة 7).
 *   3) الفاتورة تتحدث من الخدمة الوحيدة InvoiceService (قاعدة 9).
 *   4) الطلب المعلّق (pending_approval) يحجز من سقف الاسترداد —
 *      الرفع يحدّث وقت الطلب مش وقت الاعتماد.
 */
class RefundService
{
    public function __construct(
        private readonly FinancialLedgerService $ledger,
        private readonly InvoiceService $invoices,
    ) {
    }

    /**
     * تسجيل استرداد — تحت السقف ينفذ فوراً، فوق السقف يتوقف
     * على موافقة تانية.
     *
     * @return array{0: Refund, 1: ?FinancialAccount, 2: ?string,
     *               3: ?AccountMovement, 4: ?string}
     */
    public function refund(
        Payment $payment,
        float $amount,
        string $mode,
        ?string $reason,
        ?string $method,
        ?int $financialAccountId,
        $user,
    ): array {
        if (! in_array($mode, ['cash', 'credit_note'], true)) {
            throw ValidationException::withMessages(['mode' => 'mode يقبل cash أو credit_note بس']);
        }

        return DB::transaction(function () use ($payment, $amount, $mode, $reason, $method, $financialAccountId, $user) {
            // قفل صف الدفعة — طلبان متزامنان يحسبان السقف صح
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if (! in_array($locked->status, ['completed'], true)) {
                throw ValidationException::withMessages([
                    'payment_id' => 'استرداد دفعة '.$locked->status.' — المجمعة فقط',
                ]);
            }

            $refundable = $this->refundableAmount($locked);

            if ($amount > $refundable + 0.009) {
                throw ValidationException::withMessages([
                    'amount' => 'المبلغ أكبر من المتبقي ('.round($refundable, 2).').',
                ]);
            }

            [$account, $pickReason] = $this->ledger->resolveAccount(
                $payment->organization_id,
                $financialAccountId,
            );

            $threshold = (float) Setting::get(
                'finance.refund_approval_threshold',
                0,
                $payment->organization_id,
            );

            $needsApproval = $threshold > 0 && $amount > $threshold;

            if ($needsApproval) {
                // الطلب بدون أثر — الفاتورة والحساب ما يتلمسوش
                $refund = Refund::create($this->refundData(
                    $locked, $amount, $mode, $reason, $method, $user, 'pending_approval',
                ));

                app(\App\Services\AuditLogService::class)->log(
                    'refund_requested',
                    'refund',
                    $refund->id,
                    null,
                    ['amount' => $amount, 'reason' => $reason],
                    null,
                    $user->id,
                );

                return [$refund, $account, $pickReason, null, null];
        }

            $refund = Refund::create($this->refundData(
                $locked, $amount, $mode, $reason, $method, $user, 'pending',
            ));

            [$refund, $account, $pickReason, $movement, $warning] =
                $this->settleRefund($locked, $refund, $account, $pickReason, $user);

            return [$refund, $account, $pickReason, $movement, $warning];
        });
    }

    /**
     * الموافقة الثانية — صلاحية `finance.refunds.approve`،
     * وطالب الطلب ممنوع يوافق على نفسه.
     *
     * @return array{0: Refund, 1: ?FinancialAccount, 2: ?string,
     *               3: ?AccountMovement, 4: ?string}
     */
    public function approve(Refund $refund, $approver, ?int $financialAccountId): array
    {
        return DB::transaction(function () use ($refund, $approver, $financialAccountId) {
            // قفل — طلبين بنفس اللحظة = تنفيذ مرة واحدة
            $lockedRefund = Refund::whereKey($refund->id)->lockForUpdate()->first();

            if ($lockedRefund->status !== 'pending_approval') {
                throw ValidationException::withMessages([
                    'status' => 'الطلب مش منتظر موافقة (حالته: '.$lockedRefund->status.')',
                ]);
            }

            // طالب الطلب مش موافِق — فصل الأربعة عيون
            if ((int) $lockedRefund->processed_by === (int) $approver->id) {
                throw ValidationException::withMessages([
                    'approved_by' => 'لي طلبـه الاسترداد ما يوافقش عليه.',
                ]);
            }

            $payment = Payment::whereKey($lockedRefund->payment_id)
                ->lockForUpdate()->first();

            [$account, $pickReason] = $this->ledger->resolveAccount(
                $payment->organization_id,
                $financialAccountId,
            );

            [$refund, $account, $pickReason, $movement, $warning] =
                $this->settleRefund($payment, $lockedRefund, $account, $pickReason, $approver);

            app(\App\Services\AuditLogService::class)->log(
                'refund_approved',
                'refund',
                $refund->id,
                ['status' => 'pending_approval'],
                ['status' => 'processed', 'approved_by' => $approver->id],
                null,
                $approver->id,
            );

            return [$refund, $account, $pickReason, $movement, $warning];
        });
    }

    /**
     * عكس دفعة خاطئة — status = reversed + سطور عكسية.
     *
     *  1) كل حركة (دخل/عمولة) ليها سطر عكسي عبر
     *     FinancialLedgerService::reverse (قاعدة 7: تصحيح = سجل
     *     عكسي ثم سجل جديد).
     *  2) التوزيعات النشطة تتعلّم reversed، والفواتير تُعاد
     *     حسباً من الخدمة الوحيدة.
     *  3) سطر عكسي في دفتر الطالب.
     *
     * ⚠️ العكس بيخضع لقفل الفترة — لو الشهر مقفول، الطلب
     * بيحصل rollback كامل. القاعدة: أرقام اتفقنا
     * عليها ما بفتحش من هنا.
     */
    public function reverse(Payment $payment, string $reason, $user): Payment
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'سبب العكس إجباري']);
        }

        return DB::transaction(function () use ($payment, $reason, $user) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status !== 'completed') {
                throw ValidationException::withMessages([
                    'status' => 'عكس دفعة '.$locked->status.' — المجمعة فقط',
                ]);
            }

            // عكس الحركات — اللي ليها عكس أصلاً بتتجاوز
            $movements = AccountMovement::query()
                ->whereIn('reference_type', ['payment', 'payment_fee'])
                ->where('reference_id', $locked->id)
                ->whereDoesntHave('reversedBy')
                ->get();

            foreach ($movements as $movement) {
                $this->ledger->reverse($movement, $reason, $user);
            }

            // عكس التوزيعات
            $invoiceIds = collect();

            $locked->allocations()->active()->get()->each(function (PaymentAllocation $allocation) use (&$invoiceIds) {
                $allocation->update(['status' => 'reversed']);
                $invoiceIds->push($allocation->invoice_id);
            });

            $invoiceIds->unique()->each(function ($invoiceId) {
                $invoice = Invoice::find($invoiceId);

                if ($invoice) {
                    $this->invoices->recalculateFromAllocations($invoice);
                }
            });

            // سطر عكسي في دفتر الطالب — ⭐⭐ PR 2.9: الكتابة من
            // الخدمة الموحدة برصيد تجميعي (القديم كان يكتب صفر)
            app(\App\Services\StudentLedgerService::class)->write([
                'student_id' => $locked->student_id,
                'payment_id' => $locked->id,
                'type' => 'refund',
                'debit' => (float) $locked->amount,
                'currency' => $locked->currency,
                'description' => 'عكس دفعة: '.$reason,
                'reference_type' => 'payment_reversal',
                'reference_id' => (string) $locked->id,
                'created_by' => $user->id,
            ]);

            $locked->update(['status' => 'reversed']);

            app(\App\Services\AuditLogService::class)->log(
                'payment_reversed',
                'payment',
                $locked->id,
                ['status' => 'completed'],
                ['status' => 'reversed', 'reason' => $reason],
                null,
                $user->id,
            );

            return $locked->fresh();
        });
    }

    /**
     * المتبقي الاسترداد = المبلغ - (المنفّذ + المعلّق).
     *
     * المعلّق (pending_approval) بيحجز من السقف — الرفض
     * بيحصل وقت الطلب مش وقت الاعتماد.
     */
    public function refundableAmount(Payment $payment): float
    {
        $reserved = (float) MoneyCast::fromAggregate(
            $payment->refunds()
                ->whereIn('status', ['processed', 'pending_approval', 'pending'])
                ->sum('amount')
        );

        return max(0.0, (float) $payment->amount - $reserved);
    }

    // ============================================================
    // تنفيذ الاسترداد — مشترك بين الفوري والمعتمد من الإدارة
    // ============================================================

    private function settleRefund(
        Payment $payment,
        Refund $refund,
        ?FinancialAccount $account,
        ?string $pickReason,
        $user,
    ): array {
        $amount = (float) $refund->amount;
        $isCash = $refund->mode === 'cash';
        $movement = null;
        $warning = null;

        // 1) عكس التوزيع — الجزء المسترد يتعلّم reversed،
        //    وسطر جديد نشط يبقّي الباقي (سطر جديد = تصحيح).
        $toReverse = $amount;
        $invoiceIds = collect();

        $payment->allocations()->active()->orderBy('id')->get()
            ->each(function (PaymentAllocation $allocation) use (&$toReverse, &$invoiceIds) {
                if ($toReverse <= 0) {
                    return;
                }

                $take = min((float) $allocation->amount, $toReverse);

                $allocation->update(['status' => 'reversed']);

                if ($take < (float) $allocation->amount) {
                    PaymentAllocation::create([
                        'organization_id' => $allocation->organization_id,
                        'payment_id' => $allocation->payment_id,
                        'invoice_id' => $allocation->invoice_id,
                        'amount' => round((float) $allocation->amount - $take, 2),
                        'status' => 'active',
                        'created_by' => $allocation->created_by,
                    ]);
                }

                $toReverse -= $take;
                $invoiceIds->push($allocation->invoice_id);
            });

        // 2) الفواتير الملموسة تُعاد حسباً من الخدمة الوحيدة (قاعدة 9)
        $invoiceIds->unique()->each(function ($invoiceId) {
            $invoice = Invoice::find($invoiceId);

            if ($invoice) {
                $this->invoices->recalculateFromAllocations($invoice);
            }
        });

        // 3) دفتر الطالب — مدين (فلوس رجعت للطالب)
        // ⭐⭐ PR 2.9: كتابة من الخدمة الموحدة برصيد تجميعي
        app(\App\Services\StudentLedgerService::class)->write([
            'student_id' => $payment->student_id,
            'invoice_id' => $invoiceIds->isNotEmpty() ? $invoiceIds->first() : null,
            'payment_id' => $payment->id,
            'type' => 'refund',
            'debit' => $amount,
            'currency' => $payment->currency,
            'description' => 'استرداد'.($payment->invoice ? ' من '.$payment->invoice->invoice_number : '').': '.($refund->reason ?? ''),
            'reference_type' => 'refund',
            'reference_id' => (string) $refund->id,
            'created_by' => $user->id,
        ]);

        // 4) الأثر المالي
        if ($isCash) {
            if ($account !== null) {
                $result = $this->ledger->recordFor(
                    account: $account,
                    direction: 'out',
                    category: 'refund',
                    amount: $amount,
                    referenceType: 'refund',
                    referenceId: $refund->id,
                    movementDate: (string) ($refund->processed_at ?? now()->toDateString()),
                    notes: $refund->reason,
                    user: $user,
                );
                $movement = $result['movement'];
                $warning = $result['warning'];
            } else {
                $warning = 'مفيش حساب مالي نشط — الحركة المالية ماتسجلتش';
            }
        } else {
            // 5) credit_note — رصيد دائن بيخصم من اشتراك جاي
            StudentCredit::create([
                'organization_id' => $payment->organization_id,
                'student_id' => $payment->student_id,
                'currency' => $payment->currency,
                'total' => $amount,
                'reason' => $refund->reason ?? 'استرداد',
                'source_type' => 'credit_note',
                'source_id' => $refund->id,
                'created_by' => $user->id,
            ]);
        }

        // 6) الحالة = واقعية: منفّذ. المعتاد من الطلل — approved_by.
        $cameFromApproval = $refund->status === 'pending_approval';

        $refund->update([
            'status' => 'processed',
            'processed_at' => now(),
            'approved_by' => $cameFromApproval ? $user->id : $refund->approved_by,
            'approved_at' => $cameFromApproval ? now() : $refund->approved_at,
        ]);

        // ⭐⭐ قاعدة 3 — الاسترداد المنفَذ عملية حساسة: audit دايماً،
        // فوري أو معتمد. المعتمد بيكمل تفصيل في `refund_approved`.
        app(\App\Services\AuditLogService::class)->log(
            'refund',
            'payment',
            $payment->id,
            null,
            [
                'refund_id' => $refund->id,
                'amount' => $amount,
                'mode' => $refund->mode,
                'reason' => $refund->reason,
                'fully_refunded' => $payment->fresh()->isFullyRefunded(),
            ],
            null,
            $user->id,
        );

        // 7) الاسترداد الكامل = الدفع ملغات (زي-day سلوック)
        if ($payment->fresh()->isFullyRefunded()) {
            $payment->update(['status' => 'voided']);
        }

        return [$refund->fresh(), $account, $pickReason, $movement, $warning];
    }

    /**
     * بيانات سجل الاسترداد.
     */
    private function refundData(
        Payment $payment,
        float $amount,
        string $mode,
        ?string $reason,
        ?string $method,
        $user,
        string $status,
    ): array {
        return [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'amount' => $amount,
            'currency' => $payment->currency,
            'reason' => $reason ?? '',
            'method' => $method,
            'mode' => $mode,
            'status' => $status,
            'processed_by' => $user->id,
            'processed_at' => now(),
        ];
    }
}
