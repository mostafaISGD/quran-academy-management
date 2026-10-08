<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
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

            /**
             * ⭐ المبلغ الواصل الفعلي بعد خصم عمولة بوابة الدفع.
             *
             * ⭐ **ليه ده مهم؟** لو الطالب دفع 1000 وبوابة الدفع
             * خصمت 30، فتحساب الأكاديمية دخل 970. لو سجّلنا
             * 1000، الرصيد هيبقى أعلى من الحقيقي والفرق 30
             * بيختفي من التقارير.
             *
             * فلو `net_amount` مبعوت: الي دخل = `net_amount`،
             * والفرق (عمولة) بيتسجل كمصروف تلقائي.
             */
            'net_amount' => 'nullable|numeric|min:0|max:100000',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['received_by'] = $request->user()->id;

        $payment = Payment::create($data);

        if ($payment->invoice) {
            $invoice = $payment->invoice;

            /**
             * ⭐⭐⭐ **هنا اللي بتتلعّب القاعدة 5.**
             *
             * بدل ما نعدّل `paid_amount` و `balance_due` مباشرةً
             * كاأرقام مستقلة، بنعمل سطر توزيع في
             * `payment_allocations` — وبعدين الفاتورة بتحسب
             * المدفوع والمتبقي من التوزيعات دي.
             *
             * يعني:
             *   - الدفعة الواحدة بدفعة 500 → سطر توزيع بمبلغ 500
             *   - لو جت دفعة تانية بمبلغ 300 → سطر توزيع تاني 300
             *   - المدفوع = 800، المتبقي = 1200 — **محسوب**
             *
             * ولو دفعة كبيرة انقسمت على فاتورتين، بنتكتب
             * سطرين توزيع — وده بقى منفصل.
             */
            \App\Models\PaymentAllocation::create([
                'organization_id' => $request->user()->organization_id,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $payment->amount,
                'status' => 'active',
                'created_by' => $request->user()->id,
            ]);

            // ⭐ الفاتورة بتحسب المدفوع والمتبقي من التوزيعات
            $invoice->recalculateFromAllocations();
            $newBalance = (float) $invoice->fresh()->balance_due;

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
                    /**
                     * ⭐⭐ تسجيل **المبلغ الواصل** مش المبلغ الاسمي.
                     *
                     * لو فيه عمولة (net_amount < amount)، الرصيد
                     * الحقيقي بتاع الحساب هو net_amount.
                     */
                    amount: (float) ($payment->net_amount ?: $payment->amount),
                    referenceType: 'payment',
                    referenceId: $payment->id,
                    movementDate: (string) $payment->paid_at,
                    notes: $payment->notes ?: $payment->transaction_reference,
                    user: $request->user(),
                );
                $movement = $res['movement'];
                $warning = $res['warning'];

                /**
                 * ⭐ لو فيه عمولة، بتتسجل كمصروف عمولة.
                 *
                 * ⭐ السبب: الدفتر لازم يبيّن عَفْو أيه 500 - 30 = 470
                 * مش بس «470 دخل». فلو مُسَجَّلت 470 فقط، المصروف
                 * التشغيلي ناقص — والتقارير بتقول «الربحerranta
                 * أكتر من الحقيقي» بـ30.
                 */
                if ($payment->net_amount && $payment->net_amount < $payment->amount) {
                    $commission = (float) $payment->amount - (float) $payment->net_amount;

                    $feeRes = $ledger->recordFor(
                        account: $account,
                        direction: 'out',
                        category: 'fee',
                        amount: $commission,
                        referenceType: 'payment_fee',
                        referenceId: $payment->id,
                        movementDate: (string) $payment->paid_at,
                        notes: 'عمولة بوابة الدفع على دفعة #'.$payment->id,
                        user: $request->user(),
                    );

                    if (! $feeRes['movement'] && ! $warning) {
                        $warning = $feeRes['warning'];
                    }
                }
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
        return response()->json($payment->load(['student', 'invoice', 'refunds', 'receivedBy', 'allocations.invoice']));
    }

    /**
     * ⭐⭐ توزيع دفعة على فواتير.
     *
     * ⭐ **ليه الدالة دي موجودة؟**
     *
     * في الحياة الواقعية، الطالب ممكن يدفع مبلغ 1000 ومعاه
     * فاتورتين: واحدة بتاعة يناير بـ 600 وواحدة بتاعة فبراير
     * بـ 400. فبدل ما نسجل الدفعة على فاتورة واحدة، بنقسمها.
     *
     * ⚠️ **القاعدة:**
     *
     * مجموع التوزيعات النشطة **مش ممكن يزيد** عن مبلغ الدفعة.
     * فلو الطالب دفع 1000 وبنقسمها على 600 + 600 = 1200، ده
     * يعني فيه 200 مش ح grounded — فالطلب بيترفض.
     *
     * والتوزيع **المراجَع** (reversed) ما بيتحسبش في المبلغ
     * المتوزع — بيتحسب من `active` فقط.
     */
    public function allocate(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'allocations' => 'required|array|min:1',
            'allocations.*.invoice_id' => 'required|exists:invoices,id',
            'allocations.*.amount' => 'required|numeric|min:0.01',
        ], [
            'allocations.required' => 'بعت توزيع الدفعة على إيه',
            'allocations.min' => 'لازم يكون فيه توزيع واحد على الأقل',
        ]);

        $totalAllocated = collect($data['allocations'])->sum('amount');

        // ⭐ المتوزع الفعال الحالي — لو الحساب ح grounded
        $alreadyAllocated = $payment->allocatedAmount();

        if ($alreadyAllocated + $totalAllocated > (float) $payment->amount) {
            return response()->json([
                'message' => "التوزيعات بتعدي مبلغ الدفعة: المتاح للتوزيع "
                    . ($payment->amount - $alreadyAllocated),
            ], 422);
        }

        $created = [];

        foreach ($data['allocations'] as $row) {
            $allocation = PaymentAllocation::create([
                'organization_id' => $request->user()->organization_id,
                'payment_id' => $payment->id,
                'invoice_id' => $row['invoice_id'],
                'amount' => $row['amount'],
                'status' => 'active',
                'created_by' => $request->user()->id,
            ]);

            // ⭐ الفاتورة بتحسب المدفوع/المتبقي من التوزيعات
            Invoice::find($row['invoice_id'])->recalculateFromAllocations();

            $created[] = $allocation;
        }

        app(\App\Services\AuditLogService::class)->log(
            'allocate_payment',
            'payment',
            $payment->id,
            null,
            [
                'allocations' => collect($data['allocations'])
                    ->map(fn ($a) => ['invoice_id' => $a['invoice_id'], 'amount' => $a['amount']])
                    ->toArray(),
            ],
            $request,
        );

        return response()->json([
            'message' => 'اتسجل التوزيع',
            'data' => [
                'payment_id' => $payment->id,
                'allocated' => $payment->allocatedAmount(),
                'unallocated' => $payment->unallocatedAmount(),
                'allocations' => $created,
            ],
        ], 201);
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

            // ⭐⭐ الاسترداد بيلغي التوزيعات — الفاتورة لازم تحسب
            // من جديد إن المدفوع بتاعها قلّ.
            //
            // ⭐ **ما بنشطبش السطر** — بنعلّمه `reversed`، عشان
            // نفضل نعرف التوزيع كان على أي فاتورة، ونسجل ليه
            // اتلغي.
            $payment->allocations()->update(['status' => 'reversed']);

            if ($payment->invoice) {
                $payment->invoice->recalculateFromAllocations();
            }
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
