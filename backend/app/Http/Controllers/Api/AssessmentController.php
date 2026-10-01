<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Models\Lead;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Http\Request;

class AssessmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Assessment::query()->with(['lead', 'student', 'teacher'])
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('result'), fn ($q) => $q->where('result', $request->string('result')))
            ->orderByDesc('scheduled_at');

        $countsQuery = Assessment::query()
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('result'), fn ($q) => $q->where('result', $request->string('result')));

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['result']);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['lead_id' => 'required|exists:leads,id', 'teacher_id' => 'required|exists:teachers,id', 'scheduled_at' => 'required|date']);
        $assessment = Assessment::create($data);
        Lead::where('id', $data['lead_id'])->update(['status' => 'trial_booked']);
        return response()->json($assessment, 201);
    }

    public function updateResult(Request $request, Assessment $assessment)
    {
        $data = $request->validate([
            'reading_score' => 'nullable|integer|min:1|max:5',
            'tajweed_score' => 'nullable|integer|min:1|max:5',
            'memorization_score' => 'nullable|integer|min:1|max:5',
            'recommended_level' => 'nullable|string',
            'notes' => 'nullable|string',
            'result' => 'required|in:ready_to_subscribe,needs_follow_up,not_suitable',
        ]);
        $assessment->update($data);
        if ($assessment->lead_id) {
            $newStatus = $data['result'] === 'ready_to_subscribe' ? 'trial_completed' : 'contacted';
            Lead::where('id', $assessment->lead_id)->update(['status' => $newStatus]);
        }
        return response()->json($assessment->fresh());
    }

    public function convert(Request $request, Assessment $assessment)
    {
        $data = $request->validate([
            'plan_id' => 'required|exists:subscription_plans,id',
            'teacher_id' => 'required|exists:teachers,id',
            'payment_amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,bank_transfer,wallet,online_payment,card,other',
        ]);

        $lead = $assessment->lead;
        if (!$lead) return response()->json(['message' => 'لا يوجد Lead مرتبط'], 422);

        $student = Student::create([
            'organization_id' => $request->user()->organization_id,
            'lead_id' => $lead->id,
            'student_code' => 'STU-' . strtoupper(uniqid()),
            'first_name' => $lead->full_name,
            'country_code' => $lead->country_code,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'status' => 'active',
        ]);

        $plan = SubscriptionPlan::findOrFail($data['plan_id']);
        $subscription = Subscription::create([
            'organization_id' => $request->user()->organization_id,
            'student_id' => $student->id,
            'plan_id' => $plan->id,
            'program_id' => $plan->program_id,
            'teacher_id' => $data['teacher_id'],
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays($plan->duration_days ?? 30)->toDateString(),
            'billing_type' => $plan->billing_type,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'lesson_duration_minutes' => $plan->lesson_duration_minutes,
            'lessons_included' => $plan->lessons_count,
            'status' => 'active',
        ]);

        $invoice = Invoice::create([
            'organization_id' => $request->user()->organization_id,
            'student_id' => $student->id,
            'subscription_id' => $subscription->id,
            'invoice_number' => 'INV-' . strtoupper(uniqid()),
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'subtotal' => $plan->price,
            'total' => $plan->price,
            'balance_due' => $plan->price,
            'currency' => $plan->currency,
            'status' => 'issued',
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => $plan->name,
            'quantity' => 1,
            'unit_price' => $plan->price,
            'total' => $plan->price,
        ]);

        $payment = Payment::create([
            'organization_id' => $request->user()->organization_id,
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => $data['payment_amount'],
            'currency' => $plan->currency,
            'payment_method' => $data['payment_method'],
            'status' => 'completed',
            'paid_at' => now(),
            'received_by' => $request->user()->id,
        ]);

        $invoice->update([
            'paid_amount' => $data['payment_amount'],
            'balance_due' => max(0, (float) $plan->price - $data['payment_amount']),
            'status' => $data['payment_amount'] >= (float) $plan->price ? 'paid' : 'partially_paid',
        ]);

        $assessment->update(['student_id' => $student->id]);
        $lead->update(['status' => 'converted']);

        return response()->json(['student' => $student, 'subscription' => $subscription, 'invoice' => $invoice, 'payment' => $payment], 201);
    }
}
