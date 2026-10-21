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
use App\Models\PeriodLock;
use App\Models\Payment;
use Carbon\Carbon;
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
        $data = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'teacher_id' => 'required|exists:teachers,id',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:15|max:240',
        ]);

        $lead = Lead::findOrFail($data['lead_id']);
        $duration = (int) ($data['duration_minutes'] ?? 30);
        $start = Carbon::parse($data['scheduled_at']);
        $end = $start->copy()->addMinutes($duration);

        /**
         * ⭐⭐⭐ **PR 3.9 — الحصة التجريبية بتتسجَّل فعلاً.**
         *
         * ⭐ قبل PR ده كان التقييم بيتسجَّل وخلاص (من غير حصة) ⇒
         * مفيش حصة تجريبية ولا فحص تعارض ولا رابط بالحصة.
         *
         * ⭐ دلوقتي: الحصة بتتشcreating عبر `LessonScheduler`
         * (فحص التعارض + المنطقة الزمنية + التخزين UTC)،
         * و`student_id` بيفضل **null** (الحصة تجريبية قبل التحول
         * لطالب).
         */
        $lesson = app(\App\Services\LessonScheduler::class)->createLesson([
            'organization_id' => $request->user()->organization_id,
            'student_id' => null,
            'teacher_id' => $data['teacher_id'],
            'program_id' => $lead->interested_program_id,
            'lesson_type' => 'trial',
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $end,
            'duration_minutes' => $duration,
            'status' => 'scheduled',
        ]);

        $assessment = Assessment::create($data + ['lesson_id' => $lesson->id]);
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

        /**
         * ⭐⭐⭐ **PR 3.9 — التحويل بقى في خدمة واحدة ذرّية.**
         *
         * ⭐ قبل PR ده كان كله هنا: طالب جديد + اشتراك + فاتورة
         * + دفع — **يدويًا** (من غير الخدمات) و**من غير
         * transaction** ⇒ التحويل مرتين = طالبان، وفشل في النص
         * بيسيب طالب بلا اشتراك.
         */
        $result = app(\App\Services\AssessmentConversionService::class)->convert(
            $assessment,
            $data,
            $request->user(),
        );

        return response()->json([
            'student' => $result['student'],
            'subscription' => $result['subscription'],
            'invoice' => $result['invoice'],
            'payment' => $result['payment'],
        ], 201);
    }
}
