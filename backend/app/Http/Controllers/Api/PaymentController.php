<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\StudentLedgerEntry;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::query()->with(['student', 'invoice', 'receivedBy'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->string('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('paid_at');

        $countsQuery = Payment::query()
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->string('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return $this->paginatedWithCounts(
            $query,
            $countsQuery,
            $request,
            ['status', 'payment_method'],
            ['amount'],
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'parent_id' => 'nullable|exists:parents,id',
            'invoice_id' => 'nullable|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string|size:3',
            'payment_method' => 'required|in:cash,bank_transfer,wallet,online_payment,card,other',
            'transaction_reference' => 'nullable|string',
            'status' => 'nullable|in:pending,completed,failed,voided',
            'paid_at' => 'required|date',

            /**
             * ⭐ الحساب اللي دخلت فيه الفلوس.
             *
             * ⭐ **اختياري عن قصد:** لو مبعوتش، الخدمة بتختار حسب
             * طريقة الدفع (محفظة → محفظة، بنك → بنك)، والافتراضي
             * خزينة. فالExisting flows مش هتتغيّر.
             */
            'financial_account_id' => 'nullable|integer',

            'notes' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['received_by'] = $request->user()->id;

        $payment = Payment::create($data);

        if ($payment->invoice) {
            $invoice = $payment->invoice;
            $newPaid = (float) $invoice->paid_amount + (float) $payment->amount;
            $newBalance = max(0, (float) $invoice->total - $newPaid);
            $status = $newBalance <= 0 ? 'paid' : 'partially_paid';

            $invoice->update(['paid_amount' => $newPaid, 'balance_due' => $newBalance, 'status' => $status]);

            StudentLedgerEntry::create([
                'student_id' => $payment->student_id,
                'invoice_id' => $invoice->id,
                'payment_id' => $payment->id,
                'type' => 'payment',
                'credit' => $payment->amount,
                'balance_after' => $newBalance,
                'currency' => $payment->currency,
                'description' => 'دفعة للفاتورة ' . $invoice->invoice_number,
                'reference_type' => 'payment',
                'reference_id' => $payment->id,
                'created_by' => $request->user()->id,
                'created_at' => now(),
            ]);
        }

        app(\App\Services\AuditLogService::class)->logCreate(
            'payment',
            $payment->id,
            [
                'amount' => $payment->amount,
                'student_id' => $payment->student_id,
                'invoice_id' => $payment->invoice_id,
                'method' => $payment->method,
            ],
            $request,
        );

        /**
         * ⭐⭐ الحركة المالية — «فين الفلوس دي».
         *
         * ⚠️ **النقطة المهمة:** الدفعة **بتتحفظ** سواء اتسجلت
         * الحركة أو لأ. الفلوس دخلت فعلاً — اللي بيفشل هو
         * **التسجيل**، مش التحصيل. فلو رمينا exception هنا،
         * العميل واقف في البوابة ومااتسجلش عنده حاجة.
         *
         * ⭐ لو مفيش حساب ولا الفترة مقفولة، بنرجّع **تحذير
         * صريح** في الرد — عشان حد يعرف إن في دفعة من غير حركة.
         */
        $ledger = app(\App\Services\FinancialLedgerService::class);

        [$account, $pickReason] = $ledger->resolveAccount(
            $request->user()->organization_id,
            $data['financial_account_id'] ?? null,
            $ledger->accountTypeForPaymentMethod((string) $payment->payment_method),
        );

        $warning = null;
        $movement = null;

        if ($account) {
            /**
             * ⭐ `status` = `pending`/`failed`/`voided` **مش** تحصيل.
             *
             * الدفعة المعلّقة مش فلوس دخلت. لو سجّلناها
             * `posted` كان الرصيد هيبقى أعلى من الحقيقي، والتقارير
             * بتقول «دخل» والمفيش.
             */
            if (in_array($payment->status, ['completed'], true)) {
                $res = $ledger->recordFor(
                    account: $account,
                    direction: 'in',
                    category: 'income',
                    amount: (float) $payment->amount,
                    referenceType: 'payment',
                    referenceId: $payment->id,
                    movementDate: (string) $payment->paid_at,
                    /**
                     * ⭐⭐ **الملاحظة البشرية الأول،المرجع بعده.**
                     *
                     * السبب: `notes` في الدفتر هي «اللي كتبه موظف
                     * الاستقبال» — زي «دفعت Capitol — الكاشير محمد».
                     * دي المعلومة اللي بتقنع لما تبصّ على السطر بعد
                     * سنة.
                     *
                     * ولو ملّيناها بـ `transaction_reference` (رقم
                     * آلي) كان الدفتر كله أرقام، والأرقام موجودة
                     * أصلاً في وصف السطر («تحصيل من أحمد — فاتورة
                     * INV-9001»).
                     *
                     * فالمرجع **fallback** مش أولوية.
                     */
                    notes: $payment->notes ?: $payment->transaction_reference,
                    user: $request->user(),
                );
                $movement = $res['movement'];
                $warning = $res['warning'];
            }
        } else {
            $warning = 'مفيش حساب مالي نشط — الحركة المالية ماتسجلتش';
        }

        return response()->json([
            ...$payment->toArray(),
            // ⭐ الحركة المالية — عشان الواجهة تقدر تعرض
            // «اتسجلت» أو «لسه لأ»
            'ledger' => [
                'recorded' => $movement !== null,
                'movement_id' => $movement?->id,
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'account_pick' => $pickReason,
            ],
            // ⚠️ التحذير **بcernب** لو الحركة ماتسجلتش
            'warning' => $warning,
        ], 201);
    }

    public function show(Payment $payment)
    {
        return response()->json($payment->load(['student', 'invoice', 'refunds', 'receivedBy']));
    }

    public function refund(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'method' => 'nullable|string',
            // ⭐ الحساب اللي اترجعت منه الفلوس — اختياري
            'financial_account_id' => 'nullable|integer',
        ]);

        $remaining = (float) $payment->amount - $payment->totalRefunded();
        if ($data['amount'] > $remaining) {
            return response()->json(['message' => "مبلغ الاسترجاع أكبر من المتبقي ({$remaining})."], 422);
        }

        $refund = Refund::create([
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'amount' => $data['amount'],
            'currency' => $payment->currency,
            'reason' => $data['reason'],
            'method' => $data['method'] ?? null,
            'status' => 'processed',
            'processed_by' => $request->user()->id,
            'processed_at' => now(),
        ]);

        if ($payment->isFullyRefunded()) {
            $payment->update(['status' => 'voided']);
        }

        app(\App\Services\AuditLogService::class)->log(
            'refund',
            'payment',
            $payment->id,
            null,
            [
                'amount' => $data['amount'],
                'reason' => $data['reason'],
                'fully_refunded' => $payment->isFullyRefunded(),
            ],
            $request,
        );

        /**
         * ⭐⭐ الاسترداد = فلوس **رجعت** من الخزينة.
         *
         * ⚠️ تخيّل من غير السطر ده: الخزينة نقصت 1000 عند
         * التحصيل، وبعدين رجعنا للطالب 1000 من غير ما نكتب
         * حاجة. فالمصروف الش/net بينجب **بارتفاع**.
         *
         * ⭐ قرار الإدارة: الاسترداد **نقدي** — فالخروج من
         * الخزينة، مش رصيد دائن للطالب.
         */
        $ledger = app(\App\Services\FinancialLedgerService::class);

        [$account, $pickReason] = $ledger->resolveAccount(
            $request->user()->organization_id,
            $data['financial_account_id'] ?? null,
        );

        $warning = null;
        $movement = null;

        if ($account) {
            $res = $ledger->recordFor(
                account: $account,
                direction: 'out',
                // ⭐ `refund` مش `expense` — التقرير يفرّق بين
                // «استرداد» و«مصروف تشغيلي»
                category: 'refund',
                amount: (float) $data['amount'],
                referenceType: 'refund',
                referenceId: $refund->id,
                movementDate: (string) ($refund->processed_at ?? now()->toDateString()),
                notes: $data['reason'],
                user: $request->user(),
            );
            $movement = $res['movement'];
            $warning = $res['warning'];
        } else {
            $warning = 'مفيش حساب مالي نشط — الحركة المالية ماتسجلتش';
        }

        return response()->json([
            ...$refund->toArray(),
            'ledger' => [
                'recorded' => $movement !== null,
                'movement_id' => $movement?->id,
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'account_pick' => $pickReason,
            ],
            'warning' => $warning,
        ], 201);
    }

    public function refunds(Payment $payment)
    {
        return response()->json($payment->refunds()->with('processedBy')->get());
    }
}
