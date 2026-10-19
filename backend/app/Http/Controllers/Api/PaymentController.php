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

            /**
             * ⭐⭐ PR 2.7 — الحالة من العميل: نقبل `completed` و
             * `pending` بس (الـ`pending` للدفعة المعلّقة اللي
             * بتحفظ من غير حركة). `failed`/`voided` حالات دورة
             * حياة الدفع الإلكتروني الداخلي — النظام بس اللي
             * بيكتبها.
             */
            'status' => 'nullable|in:pending,completed',
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
             * خصمت 30، فحساب الأكاديمية دخل 970. لو سجّلنا
             * 1000، الرصيد هيبقى أعلى من الحقيقي والفرق 30
             * بيختفي من التقارير.
             *
             * ⭐⭐ **`lte:amount`** — الواصل أكبر من الاسمي =
             * خطأ إدخال (كان هيبقى عمولة **سالب**).
             */
            'net_amount' => 'nullable|numeric|min:0|max:100000|lte:amount',

            /**
             * ⭐⭐⭐ PR 2.7 — مفتاح منع التكرار.
             *
             * العميل أداس «ادفع» مرتين = دفعتين. المفتاح ده
             * بيخلي إعادة الإرسال رجّع نفس الدفعة مش دفعة
             * جديدة — والحركة المالية بيتسجل مرة واحدة.
             */
            'idempotency_key' => 'nullable|string|max:64',
        ]);

        /**
         * ⭐⭐⭐ **الدماغ كلها في `PaymentService::record()`** —
         * حرفياً كل خطوة في transaction واحدة، الفاتورة
         * مقفولة، والتحقق تاع الملكية والعملة محكي هناك.
         */
        [$payment, $account, $pickReason, $movement, $feeMovement, $warning, $idempotent] =
            app(\App\Services\PaymentService::class)->record($data, $request->user());

        if (! $idempotent) {
            app(\App\Services\AuditLogService::class)->logCreate(
                'payment',
                $payment->id,
                [
                    'amount' => $payment->amount,
                    'student_id' => $payment->student_id,
                    'invoice_id' => $payment->invoice_id,
                    'method' => $payment->payment_method,
                ],
                $request,
            );
        }

        return response()->json([
            ...$payment->toArray(),
            // ⭐ الحركة المالية — عشان الواجهة تقدر تعرض
            // «اتسجلت» أو «لسه لأ»
            'ledger' => [
                'recorded' => $movement !== null,
                'movement_id' => $movement?->id,
                'fee_movement_id' => $feeMovement?->id,
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'account_pick' => $pickReason,
            ],
            // ⚠️ التحذير **جاي** لو الحركة ماتسجلتش
            'warning' => $warning,
            // ⭐ مكرر = "هذي دفعة موجودة، أو أعيد حملها نفسها"
            'idempotent' => $idempotent,
        ], $idempotent ? 200 : 201);
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

        /**
         * ⭐⭐ PR 2.7 — نفس التحويل بيتطبق هنا، وبتوقيع القفل.
         *
         * قبلها التوزيع المتأخر كان بيخلق أي فاتورة أي واحد.
         * الان يتم التحقق + القفل جوه transaction.
         */
        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $payment, $request, &$created) {
            foreach ($data['allocations'] as $row) {
                /** ⭐ القفل — توزيعين متزامنين */
                $invoice = Invoice::whereKey($row['invoice_id'])->lockForUpdate()->first();

                if (! $invoice) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'allocations' => 'فاتورة #'.$row['invoice_id'].' مش موجودة',
                    ]);
                }

                if ((string) $invoice->organization_id !== (string) $request->user()->organization_id) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'allocations' => 'فاتورة #'.$row['invoice_id'].' مش من مؤسستك',
                    ]);
                }

                if ((string) $invoice->student_id !== (string) $payment->student_id) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'allocations' => 'فاتورة #'.$row['invoice_id'].' مش بتاعة الطالب',
                    ]);
                }

                if ($invoice->currency !== $payment->currency) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'allocations' => 'عملة الفاتورة #'.$row['invoice_id'].' مش متطابقة',
                    ]);
                }

                if ($invoice->status === 'void') {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'allocations' => 'فاتورة #'.$row['invoice_id'].' ملغاة',
                    ]);
                }

                $allocation = PaymentAllocation::create([
                    'organization_id' => $request->user()->organization_id,
                    'payment_id' => $payment->id,
                    'invoice_id' => $row['invoice_id'],
                    'amount' => $row['amount'],
                    'status' => 'active',
                    'created_by' => $request->user()->id,
                ]);

                // ⭐ الفاتورة بتحسب المدفوع/المتبقي من التوزيعات
                $invoice->recalculateFromAllocations();

                $created[] = $allocation;
            }
        });

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
