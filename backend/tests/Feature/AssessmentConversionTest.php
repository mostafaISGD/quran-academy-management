<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\LessonCreditAccount;
use App\Models\Payment;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.9 — التحويل الآمن (من غير طلاب مكرّرين).**
 *
 * ⭐ قبل PR ده: `AssessmentController::convert` كان بينشئ **طالب
 * جديد كل مرة** و**من غير transaction** ومن غير الخدمات ⇒
 * التحويل مرتين = طالبان، وفشل في النص بيسيب طالب بلا اشتراك.
 *
 * ⭐ القبول:
 *
 * ① التحويل مرتين ⇒ الثاني **مرفوض** ومفيش طالب تاني.
 * ② فشل في النص ⇒ **مفيش طالب بلا اشتراك** (ذرّية).
 * ③ `store` بينشئ **حصة تجريبية** (مع فحص التعارض).
 */
class AssessmentConversionTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ① التحويل مرتين
    // ═══════════════════════════════════════════════════════════════

    public function test_converting_twice_is_rejected_and_creates_no_second_student(): void
    {
        $staff = $this->makeUserWithRole('assessment-admin', ['students.update', 'students.create', 'leads.update']);
        $assessment = $this->readyAssessment();

        $payload = [
            'plan_id' => $this->readyPlan()->id,
            'teacher_id' => $this->makeTeacher()->id,
            'payment_amount' => 100,
            'payment_method' => 'cash',
        ];

        $this->postJsonAs("/api/assessments/{$assessment->id}/convert", $payload, $staff)
            ->assertStatus(201);

        $studentsAfterFirst = Student::count();

        // ⭐ التحويل التاني مرفوض.
        $this->postJsonAs("/api/assessments/{$assessment->id}/convert", $payload, $staff)
            ->assertStatus(422);

        // ⭐ ومفيش طالب تاني.
        $this->assertSame(
            $studentsAfterFirst,
            Student::count(),
            'التحويل مرتين عمل طالب تاني — ده اللي المواصفة بتمنعه'
        );
        $this->assertSame(1, Subscription::count());
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الذرّية: فشل في النص ⇒ مفيش طالب بلا اشتراك
    // ═══════════════════════════════════════════════════════════════

    public function test_a_failure_midway_leaves_no_student_without_a_subscription(): void
    {
        $staff = $this->makeUserWithRole('assessment-admin', ['students.update', 'students.create', 'leads.update']);
        $assessment = $this->readyAssessment();

        // ⭐ قفل الفترة المالية ⇒ الفاتورة مرفوضة جوه العملية.
        \App\Models\PeriodLock::create([
            'organization_id' => $this->org->id,
            'scope' => 'all',
            'period_start' => now()->toDateString(),
            'period_end' => now()->addDay()->toDateString(),
            'reason' => 'إقفال اختبار',
        ]);

        $r = $this->postJsonAs("/api/assessments/{$assessment->id}/convert", [
            'plan_id' => $this->readyPlan()->id,
            'teacher_id' => $this->makeTeacher()->id,
            'payment_amount' => 100,
            'payment_method' => 'cash',
        ], $staff);

        $r->assertStatus(422);

        // ⭐ مفيش طالب معلّق (ولا اشتراك ولا فاتورة).
        $this->assertSame(0, Student::count(), 'فشل العملية ساب طالب بلا اشتراك');
        $this->assertSame(0, Subscription::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الحصة التجريبية
    // ═══════════════════════════════════════════════════════════════

    public function test_the_assessment_books_a_trial_lesson(): void
    {
        $staff = $this->makeUserWithRole('assessment-admin', ['students.update', 'students.create', 'leads.update']);

        $r = $this->postJsonAs('/api/assessments', [
            'lead_id' => $this->readyLead()->id,
            'teacher_id' => $this->makeTeacher()->id,
            'scheduled_at' => now()->addDays(2)->setTime(17, 0)->toDateTimeString(),
        ], $staff);

        $r->assertStatus(201);

        $assessment = Assessment::findOrFail($r->json('id'));

        $this->assertNotNull($assessment->lesson_id, 'مفيش رابط بالحصة التجريبية');

        $lesson = Lesson::findOrFail($assessment->lesson_id);
        $this->assertSame('trial', $lesson->lesson_type);
        $this->assertNull($lesson->student_id, 'الحصة التجريبية قبل التحويل مالها طالب');
    }

    public function test_the_trial_lesson_respects_the_teacher_conflict(): void
    {
        $staff = $this->makeUserWithRole('assessment-admin', ['students.update', 'students.create', 'leads.update']);
        $teacher = $this->makeTeacher();
        $program = $this->makeProgram();
        $student = $this->makeStudent();

        $start = now()->addDays(3)->setTime(17, 0);

        $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => $start,
            'scheduled_end_at' => $start->copy()->addMinutes(30),
        ]);

        $this->postJsonAs('/api/assessments', [
            'lead_id' => $this->readyLead()->id,
            'teacher_id' => $teacher->id,
            'scheduled_at' => $start->toDateTimeString(),
        ], $staff)->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ التحويل يمرّ بالخدمات (فاتورة + رصيد + دفتر)
    // ═══════════════════════════════════════════════════════════════

    public function test_the_conversion_goes_through_the_services(): void
    {
        $staff = $this->makeUserWithRole('assessment-admin', ['students.update', 'students.create', 'leads.update']);
        $assessment = $this->readyAssessment();

        $r = $this->postJsonAs("/api/assessments/{$assessment->id}/convert", [
            'plan_id' => $this->readyPlan()->id,
            'teacher_id' => $this->makeTeacher()->id,
            'payment_amount' => 100,
            'payment_method' => 'cash',
        ], $staff);

        $r->assertStatus(201);

        $subscription = Subscription::findOrFail($r->json('subscription.id'));

        // ⭐ فاتورة للاشتراك.
        $this->assertSame(1, Invoice::where('subscription_id', $subscription->id)->count());

        // ⭐ حساب رصيد الحصص (من `SubscriptionActivationService`).
        $this->assertSame(
            1,
            LessonCreditAccount::where('subscription_id', $subscription->id)->count(),
            'مفيش حساب رصيد — التحويل اتخطّى خدمة التفعيل'
        );

        // ⭐ وسجل التحويل.
        $assessment->refresh();
        $this->assertNotNull($assessment->converted_at);
        $this->assertSame($subscription->id, (int) $assessment->converted_subscription_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    private function readyAssessment(): Assessment
    {
        return Assessment::create([
            'lead_id' => $this->readyLead()->id,
            'teacher_id' => $this->makeTeacher()->id,
            'scheduled_at' => now()->addDays(2),
        ]);
    }

    private function readyLead(): Lead
    {
        return Lead::create([
            'organization_id' => $this->org->id,
            'full_name' => 'خالد أحمد',
            'phone' => '0100000000',
            'status' => 'qualified',
            // ⭐ الحصة التجريبية محتاجة برنامج (عمود NOT NULL).
            'interested_program_id' => $this->makeProgram()->id,
        ]);
    }

    private function readyPlan(): SubscriptionPlan
    {
        $program = $this->makeProgram();

        return SubscriptionPlan::create([
            'organization_id' => $this->org->id,
            'program_id' => $program->id,
            'name' => 'باقة التحويل',
            'billing_type' => 'monthly',
            'price' => 100,
            'currency' => 'EGP',
            'duration_days' => 30,
            'lessons_count' => 4,
            'lesson_duration_minutes' => 30,
            'status' => 'active',
        ]);
    }
}
