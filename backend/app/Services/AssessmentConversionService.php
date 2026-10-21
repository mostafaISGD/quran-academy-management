<?php

namespace App\Services;

use App\Exceptions\AssessmentAlreadyConverted;
use App\Models\Assessment;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐⭐ **PR 3.9 — تحويل التقييم إلى اشتراك (آمن).**
 *
 * ⭐ **قبل PR ده** التحويل كان في الـcontroller مباشرةً:
 *
 * - أنشأ **طالب** جديد كل مرة ⇒ مرتين = طالبان لنفس الـlead.
 * - كان **مش ذرّي** ⇒ فشل في النص بيسيب طالب بلا اشتراك.
 * - كان **بيتجاوز الخدمات** ⇒ فاتورة ودفع من غير رصيد ولا حركة
 *   مالية.
 *
 * ⭐ **دلوقتي** كله في `DB::transaction` واحد و**عبر الخدمات**:
 *
 * - `SubscriptionActivationService::activate()` ⇒ فاتورة + **حساب
 *   رصيد** + جدولة الحصص.
 * - `PaymentService::record()` ⇒ الدفع + التوزيع + **دفتر المال**.
 *
 * ⭐ **الحماية من التحويل المزدوج**: قفل الصف + فحص
 * `student_id` + **قيد فريد** على `assessments.student_id` (في
 * الميجريشن) ⇒ التحويل مرتين: الثاني **مرفوض 422** وما فيش
 * طالب تاني.
 */
final class AssessmentConversionService
{
    public function __construct(
        private readonly SubscriptionActivationService $activation,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @return array{student: Student, subscription: Subscription, invoice: ?Invoice, payment: mixed}
     */
    public function convert(Assessment $assessment, array $data, User $actor): array
    {
        return DB::transaction(function () use ($assessment, $data, $actor): array {
            // ⭐ القفل: طلبان متزامنان على نفس التقييم ⇒ واحد بس.
            $locked = Assessment::query()->lockForUpdate()->findOrFail($assessment->id);

            if ($locked->student_id !== null) {
                throw new AssessmentAlreadyConverted($locked);
            }

            $lead = $locked->lead;

            if ($lead === null) {
                throw new \RuntimeException('لا يوجد Lead مرتبط');
            }

            // ⭐⭐⭐ **PR 3.9 — بِغ:** كان بينشئ `first_name` بس
            // (الاسم كامل في خانة واحدة) ⇒ `last_name` NOT NULL
            // ⇒ الـINSERT كان **بيقع 500 دايمًا** ⇒ التحويل
            // ما كانش أصلاً شغّال. دلوقتي: الاسم مقسوم صح.
            $fullName = trim((string) $lead->full_name);
            $parts = preg_split('/\s+/u', $fullName) ?: [];
            $firstName = $parts[0] ?? 'طالب';
            $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '—';

            $student = Student::create([
                'organization_id' => $actor->organization_id,
                'lead_id' => $lead->id,
                'student_code' => 'STU-'.strtoupper(uniqid()),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'country_code' => $lead->country_code,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'status' => 'active',
            ]);

            $plan = SubscriptionPlan::findOrFail($data['plan_id']);

            $subscription = Subscription::create([
                'organization_id' => $actor->organization_id,
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

            // ⭐⭐ **الخدمات — مش الـmodels.** التفعيل بيفتح:
            // الفاتورة + **حساب الرصيد** + جدولة الحصص.
            $this->activation->actingAs($actor)->activate($subscription, [
                'weekdays' => $plan->schedule_weekdays ?? [],
                'start_time' => $plan->schedule_start_time ?? '16:00',
                'duration_minutes' => $plan->lesson_duration_minutes ?? 30,
            ]);

            $invoice = Invoice::where('subscription_id', $subscription->id)
                ->latest('id')
                ->first();

            // ⭐⭐ **الدفع عبر `PaymentService`** (دفع + توزيع + دفتر).
            $payment = null;

            if ($invoice !== null && (float) $data['payment_amount'] > 0) {
                [$payment] = $this->payments->record([
                    'student_id' => $student->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $data['payment_amount'],
                    'currency' => $plan->currency,
                    'payment_method' => $data['payment_method'],
                    'status' => 'completed',
                    'paid_at' => now(),
                    // ⭐ مفتاح تكرار — نفس التحويل مرتين ما يعملش دفعتين.
                    'idempotency_key' => 'assessment-convert:'.$locked->id,
                ], $actor);
            }

            $locked->forceFill([
                'student_id' => $student->id,
                'converted_at' => now(),
                'converted_subscription_id' => $subscription->id,
            ])->save();

            $lead->update(['status' => 'converted']);

            return [
                'student' => $student,
                'subscription' => $subscription,
                'invoice' => $invoice,
                'payment' => $payment,
            ];
        });
    }
}
