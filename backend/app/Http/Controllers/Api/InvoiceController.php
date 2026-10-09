<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\StudentLedgerEntry;
use Illuminate\Http\Request;

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

        StudentLedgerEntry::create([
            'student_id' => $invoice->student_id,
            'invoice_id' => $invoice->id,
            'type' => 'invoice',
            'debit' => $total,
            'balance_after' => $total,
            'currency' => $invoice->currency,
            'description' => 'فاتورة ' . $invoice->invoice_number,
            'reference_type' => 'invoice',
            'reference_id' => $invoice->id,
            'created_by' => $request->user()->id,
            'created_at' => now(),
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
        return response()->json($invoice->load(['student', 'items', 'payments']));
    }

    public function update(Request $request, Invoice $invoice)
    {
        if ($invoice->status === 'paid') {
            return response()->json(['message' => 'لا يمكن تعديل فاتورة مدفوعة'], 422);
        }
        $data = $request->validate(['due_date' => 'nullable|date', 'status' => 'sometimes|in:draft,issued,partially_paid,paid,overdue,void', 'notes' => 'nullable|string']);

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

        $old = $invoice->only(['invoice_number', 'student_id', 'total', 'status']);

        $invoice->delete();

        app(\App\Services\AuditLogService::class)->logDelete('invoice', $invoice->id, $old, $request);

        return response()->json(['message' => 'تم حذف الفاتورة']);
    }
}
