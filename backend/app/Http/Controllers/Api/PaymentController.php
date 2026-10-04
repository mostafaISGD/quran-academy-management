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

        return response()->json($payment, 201);
    }

    public function show(Payment $payment)
    {
        return response()->json($payment->load(['student', 'invoice', 'refunds', 'receivedBy']));
    }

    public function refund(Request $request, Payment $payment)
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'reason' => 'required|string|max:255', 'method' => 'nullable|string']);

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

        return response()->json($refund, 201);
    }

    public function refunds(Payment $payment)
    {
        return response()->json($payment->refunds()->with('processedBy')->get());
    }
}
