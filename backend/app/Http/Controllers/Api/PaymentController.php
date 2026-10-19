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
            /**
             * ⭐⭐ PR 2.8 — نوع الاسترداد:
             *   cash نقدي (حركة من الحساب) | credit_note (رصيد
             *   دائن بيتخصم من اشتراك جاي).
             */
            'mode' => 'nullable|in:cash,credit_note',
        ]);

        /**
         * ⭐⭐⭐ المداخلة كلها في `RefundService` — transaction
         * واحدة، السقف مقفول، والاسترداد الجزئي بيظبط الفاتورة.
         * فوق سقف الإعدادات = `pending_approval` واستنى موافقة
         * تانية (صلاحية `finance.refunds.approve`).
         */
        [$refund, $account, $pickReason, $movement, $warning] =
            app(\App\Services\RefundService::class)->refund(
                $payment,
                (float) $data['amount'],
                $data['mode'] ?? 'cash',
                $data['reason'],
                $data['method'] ?? null,
                $data['financial_account_id'] ?? null,
                $request->user(),
            );

        return response()->json([
            ...$refund->toArray(),
            'pending_approval' => $refund->status === 'pending_approval',
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

    /**
     * ⭐⭐ PR 2.8 — عكس دفعة خاطئة (مش استرداد).
     *
     * دي للخطأ في **التسجيل** نفسه: دفعة مالوش فلوس حقيقية.
     * الفرق عن refund: عكس الحركات بالسطر (قاعدة 7) والدفع
     * بتاخد `reversed` مش `voided`.
     */
    public function reverse(Request $request, Payment $payment)
    {
        $data = $request->validate([
            'reason' => 'required|string|max:255',
        ]);

        $reversed = app(\App\Services\RefundService::class)
            ->reverse($payment, $data['reason'], $request->user());

        return response()->json([
            'message' => 'عُكست الدفعة — سجل جديد لو دفعة تصحيحية',
            'data' => $reversed->only(['id', 'student_id', 'amount', 'currency', 'status']),
        ]);
    }

    /**
     * ⭐⭐ PR 2.8 — الموافقة التانية على استرداد فوق السقف.
     *
     * دي الفعّالة دي الحقيقية: الموافقة بتشغل نفس دفعة
     * `settleRefund` — الحركة والتوزيعات والفاتورة.
     */
    public function approveRefund(Request $request, \App\Models\Refund $refund)
    {
        [$refund, $account, $pickReason, $movement, $warning] =
            app(\App\Services\RefundService::class)
                ->approve($refund, $request->user(), $request->integer('financial_account_id') ?: null);

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
        ]);
    }

    public function pendingApprovalRefunds(Request $request)
    {
        $refunds = \App\Models\Refund::query()
            ->where('status', 'pending_approval')
            ->with(['payment.student', 'requester'])
            ->orderByDesc('id')
            ->get();

        return response()->json($refunds);
    }

    public function refunds(Payment $payment)
    {
        return response()->json($payment->refunds()->with('processedBy')->get());
    }
}
