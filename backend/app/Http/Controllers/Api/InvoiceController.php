<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PeriodLock;
use App\Models\StudentLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvoiceController extends Controller
{
    public function index(Request $request)
    {
        $query = Invoice::query()->with(['student', 'subscription', 'items'])
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->string('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByDesc('issue_date');

        // نفس الفلاتر لكن بدون eager loading — للعدّ
        $countsQuery = Invoice::query()
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->string('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')));

        return $this->paginatedWithCounts(
            $query,
            $countsQuery,
            $request,
            ['status'],
            ['total', 'paid_amount', 'balance_due'],
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'parent_id' => 'nullable|exists:parents,id',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'issue_date' => 'required|date',
            'due_date' => 'nullable|date',
            'items' => 'required|array|min:1',
            'items.*.description' => 'required|string',
            'items.*.quantity' => 'required|numeric|min:1',
            'items.*.unit_price' => 'required|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',

            // ⭐ كود ترويجي اختياري
            'promo_code' => 'nullable|string|max:50|exists:promo_codes,code',
        ]);

        // ⭐⭐ PR 2.13 — فاتورة بتتسجل في فترة مقفولة = مرفوض 422
        PeriodLock::assertOpen($data['issue_date'], 'invoices');

        $data['organization_id'] = $request->user()->organization_id;
        $data['invoice_number'] = 'INV-' . strtoupper(uniqid());
        $data['currency'] = $data['currency'] ?? 'EGP';
        $data['status'] = 'draft';

        $subtotal = collect($data['items'])->sum(fn ($item) => $item['quantity'] * $item['unit_price']);
        $discount = $data['discount'] ?? 0;
        $tax = $data['tax'] ?? 0;
        $total = $subtotal - $discount + $tax;

        // ⭐ تطبيق الكود الترويجي لو موجود
        $promoDiscount = 0;
        $promoCode = null;

        if (! empty($data['promo_code'])) {
            $promo = \App\Models\PromoCode::where('organization_id', $request->user()->organization_id)
                ->where('code', $data['promo_code'])
                ->first();

            if ($promo && $promo->isValidNow()) {
                // ننشئ فاتورة مؤقتة للتحقق من appliesTo
                $tempInvoice = new Invoice(['program_id' => $data['subscription_id'] ? \App\Models\Subscription::find($data['subscription_id'])?->program_id : null]);
                if ($promo->appliesTo($tempInvoice)) {
                    $promoDiscount = $promo->calculateDiscount($total - $discount);
                    $promoCode = $promo;
                }
            }
        }

        $data['discount'] = $discount + $promoDiscount;
        $total = $subtotal - $data['discount'] + $tax;

        $data['subtotal'] = $subtotal;
        $data['total'] = $total;
        $data['balance_due'] = $total;

        $invoice = Invoice::create($data);

        // ⭐ تسجيل استخدام الكود
        if ($promoCode) {
            $promoCode->incrementUsage();

            \App\Models\PromoCodeUsage::create([
                'organization_id' => $request->user()->organization_id,
                'promo_code_id' => $promoCode->id,
                'invoice_id' => $invoice->id,
                'student_id' => $invoice->student_id,
                'discount_amount' => $promoDiscount,
                'original_amount' => $total + $promoDiscount,
                'final_amount' => $total,
                'created_by' => $request->user()->id,
            ]);
        }

        foreach ($data['items'] as $item) {
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total' => $item['quantity'] * $item['unit_price'],
            ]);
        }

        /**
         * ⭐⭐ PR 2.9 — الكتابة من `StudentLedgerService`:
         * رصيد تجميعي لكل عمولة (`balance_after` مش المبلغ
         * نفسه — الفاتورة التانية للطالب تكمل من رصيده).
         */
        app(\App\Services\StudentLedgerService::class)->write([
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'type' => 'invoice',
            'debit' => $total,
            'currency' => $invoice->currency,
            'description' => 'فاتورة '.$invoice->invoice_number,
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'created_by' => $request->user()->id,
        ]);

        app(\App\Services\AuditLogService::class)->logCreate(
            'invoice',
            $invoice->id,
            [
                'invoice_number' => $invoice->invoice_number,
                'student_id' => $invoice->student_id,
                'total' => $invoice->total,
                'promo_code' => $promoCode?->code,
                'promo_discount' => $promoDiscount,
            ],
            $request,
        );

        return response()->json($invoice->load('items'), 201);
    }

    public function show(Invoice $invoice)
    {
        // ⚡ سياسة العلاقة (PR 1.8): ولي الأمر فاتورة ابنه فقط
        // وبشرط `can_view_finance`، والطالب فواتيره، والموظف بالصلاحية.
        Gate::authorize('view', $invoice);

        return response()->json($invoice->load(['student', 'items', 'payments']));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'لا يمكن تعديل فاتورة مدفوعة'], 422);
        }

        /**
         * ⭐⭐⭐ **`status` مش قابلة للتعديل من الـAPI** (PR 2.6).
         *
         * ═══════════════════════════════════════════════════
         * ⭐ قبل كده الـvalidation كان بيقبل:
         *
         *     'in:draft,issued,partially_paid,paid,overdue,void'
         *
         * يعني `PUT /invoices/5 {"status":"paid"}` كان **بيعتمد
         * فاتورة مدفوعة من غير ولا جنيه**. و`balance_due` بيفضل
         * زي ما هو = فاتورة «مدفوعة» وعليها فلوس.
         *
         * ⭐ **محدش كان بيلاحظ** لأن التقارير بتجمع `balance_due`:
         * الفاتورة said مدفوعة والحساب قال «لسه مدفوعةاش».
         *
         * ⭐ **`status` بتتتقفل من 3 أبواب** — ما فيش غيرها (قاعدة 9):
         *   ① `InvoiceService::writeState()` — بعد دفعة
         *   ② `IssueController`/الـactions الصريحة
         *   ③ `overdue` **مش حالة أصلاً** — محسوب (قاعدة 10)
         *
         * ⭐ والتغيير بقى **أفعال صريحة**:
         * `POST /invoices/{id}/issue` و `/void`.
         */
        if ($request->has('status')) {
            return response()->json([
                'message' => 'حالة الفاتورة مش بتتغيّر من هنا. '
                    .'استخدم: POST /invoices/'.$invoice->id.'/issue أو /void',
            ], 422);
        }

        $data = $request->validate([
            'due_date' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $old = $invoice->only(array_keys($data));

        $invoice->update($data);

        app(\App\Services\AuditLogService::class)->logUpdate(
            'invoice',
            $invoice->id,
            $old,
            $invoice->only(array_keys($data)),
            $request,
        );

        return response()->json($invoice->fresh());
    }

    public function destroy(Request $request, Invoice $invoice)
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'لا يمكن حذف فاتورة مدفوعة'], 422);
        }

        /**
         * ⭐⭐ الحذف **لـ`draft` بس** (PR 2.6).
         *
         * فاتورة `issued` (أو فيها دفعات) معناهاligationAncient
         * قديم — مسحها بيخلي الرقم ثابت على «فاتورة مفقودة».
         *
         * ⭐ والطريقة الصح: `void` — بتبقي محفوظة + بتظهر في
         * التقارير كـ`void`، بدل ما تختفي خالص.
         */
        if ($invoice->status !== 'draft') {
            return response()->json([
                'message' => 'الحذف مسموح للفاتورة المسودة بس. '
                    .'الفاتورة دي معتمدة — استخدم POST /invoices/'.$invoice->id.'/void',
            ], 422);
        }

        if ($invoice->allocations()->exists()) {
            return response()->json([
                'message' => 'الفاتورة عليها توزيعات — ما ينفعش تتحذف. استخدم void.',
            ], 422);
        }

        $old = $invoice->only(['invoice_number', 'student_id', 'total', 'status']);

        $invoice->delete();

        app(\App\Services\AuditLogService::class)->logDelete('invoice', $invoice->id, $old, $request);

        return response()->json(['message' => 'تم حذف الفاتورة']);
    }

    /**
     * ⭐⭐ اعتماد الفاتورة (`draft` → `issued`) — **فعل صريح**.
     *
     * ⭐ ليه فعل مش تعديل حقل؟ لأن الاعتماد **قرار** له أثر:
     * الفاتورة بقت مطالبة على الطالب. فالـUI لازم يعملها
     * consciously، والـaudit لازم يسجّلها.
     */
    public function issue(Request $request, Invoice $invoice)
    {
        if ($invoice->status !== 'draft') {
            return response()->json([
                'message' => "الاعتماد للفاتورة المسودة بس (الحالية: {$invoice->status})",
            ], 422);
        }

        $invoice->update([
            'status' => 'issued',
            'issue_date' => $request->date('issue_date') ?? now()->toDateString(),
        ]);

        app(\App\Services\AuditLogService::class)->log(
            'invoice_issued',
            'invoice',
            $invoice->id,
            ['status' => 'draft'],
            ['status' => 'issued'],
            $request,
        );

        return response()->json($invoice->fresh());
    }

    /**
     * ⭐⭐ إلغاء الفاتورة — **مش حذف**.
     *
     * ⭐ الفاتورة الملغاة بتفضل محفوظة عشان:
     *   ① الرقم ما يتعادش-used (لو رجع لـdraft وتصدر تاني، الرقم
     *      يبقى مرتبط بمعاملتين).
     *   ② التقرير يقدر يقول «فيه فاتورة اتلغت في مارس» — رقم
     *      فيه فجوة من غير تفسير = شك.
     */
    public function void(Request $request, Invoice $invoice)
    {
        if ($invoice->status === 'void') {
            return response()->json(['message' => 'الفاتورة ملغاة بالفعل'], 422);
        }

        if ($invoice->status === 'paid') {
            return response()->json([
                'message' => 'ما ينفعش تلغي فاتورة مدفوعة — اعكس الدفعة الأول',
            ], 422);
        }

        $previous = $invoice->status;

        $invoice->update(['status' => 'void']);

        app(\App\Services\AuditLogService::class)->log(
            'invoice_voided',
            'invoice',
            $invoice->id,
            ['status' => $previous],
            ['status' => 'void'],
            $request,
        );

        return response()->json($invoice->fresh());
    }
}
