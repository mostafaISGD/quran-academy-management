<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Lesson;
use App\Models\LessonCreditTransaction;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\TeacherEarning;
use App\Models\User;
use App\Services\LessonCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.4 — خط أنابيب إتمام الحصة (idempotent).**
 *
 * ⭐ **القبول**: `complete` مرتين = **مستحق واحد** و**خصم واحد**؛
 * والتصحيح بعدين بيعكس **بالضبط**.
 *
 * ⭐ **الآثار الأربعة** لكل إتمام (كلها في نفس الـtransaction):
 *
 * ① تحويل الحالة + سجل التحويلات (PR 3.1)
 * ② استهلاك الرصيد حسب لقطة السياسة (PR 3.3)
 * ③ مستحق المعلم بسعر **اللحظة** (`rate_snapshot`)
 * ④ سجل نشاط + حدث إشعار لولي الأمر
 *
 * ⭐ **التصحيح** (بعد الإتمام) = **حركات عكسية** (رصيد + مستحق)،
 * مش تعديل — القاعدة 7.
 */
class LessonCompletionPipelineTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول: مرتين = أثر واحد
    // ═══════════════════════════════════════════════════════════════

    public function test_completing_twice_leaves_one_earning_and_one_deduction(): void
    {
        // ⭐ `makeup` مساره permission:lessons.create — فالمستخدم
        // لازم يكون عنده التنين.
        $manager = $this->makeUserWithRole('pipeline-manager', ['lessons.update', 'lessons.create']);
        [$lesson, $subscription, $account] = $this->readyLesson($manager);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();
        // ⭐ النداء التاني = نفس الحالة، **بلا أثر جديد**.
        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();

        $this->assertSame(
            1,
            TeacherEarning::where('lesson_id', $lesson->id)->count(),
            'مستحقين لمعلم واحد — التكرار عمل مستحق تاني'
        );

        $deductions = LessonCreditTransaction::where('credit_account_id', $account->id)
            ->where('type', 'lesson_used')
            ->count();

        $this->assertSame(1, $deductions, 'الخصم اتكرر — الرصيد نزل مرتين');
        $this->assertEquals(
            7.0,
            (float) app(LessonCreditService::class)->balance($account->id)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ الأثر الثالث: مستحق بسعر اللحظة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_earning_keeps_the_rate_of_the_moment(): void
    {
        $manager = $this->makeUserWithRole('pipeline-manager', ['lessons.update']);
        [$lesson] = $this->readyLesson($manager);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();

        $earning = TeacherEarning::where('lesson_id', $lesson->id)->firstOrFail();

        $this->assertEquals(50.0, (float) $earning->amount);
        $this->assertIsArray($earning->rate_snapshot, 'مفيش لقطة سعر على المستحق');
        $this->assertEquals(50.0, (float) $earning->rate_snapshot['amount']);
        $this->assertArrayHasKey('contract_id', $earning->rate_snapshot);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ الأثر الرابع: سجل نشاط + إشعار
    // ═══════════════════════════════════════════════════════════════

    public function test_completion_writes_an_activity_log_entry(): void
    {
        $manager = $this->makeUserWithRole('pipeline-manager', ['lessons.update']);
        [$lesson] = $this->readyLesson($manager);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();

        $log = ActivityLog::where('subject_type', 'lesson')
            ->where('subject_id', $lesson->id)
            ->where('action', 'lesson_completed')
            ->first();

        $this->assertNotNull($log, 'مفيش سطر في سجل النشاط');
        $this->assertSame($manager->id, (int) $log->user_id);
    }

    public function test_completion_notifies_the_parent(): void
    {
        $manager = $this->makeUserWithRole('pipeline-manager', ['lessons.update']);
        [$lesson, $subscription] = $this->readyLesson($manager);

        $parent = $this->linkedParent($lesson->student_id);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();

        $notification = Notification::where('user_id', $parent->id)
            ->where('event_type', 'lesson_completed')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification, 'ولي الأمر ما وصلّهش إشعار بإتمام الحصة');
        $this->assertSame($lesson->id, (int) $notification->lesson_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ التصحيح = حركات عكسية
    // ═══════════════════════════════════════════════════════════════

    public function test_correcting_a_completed_lesson_reverses_the_deduction_and_the_earning(): void
    {
        $manager = $this->makeUserWithRole('pipeline-manager', ['lessons.update']);
        [$lesson, $subscription, $account] = $this->readyLesson($manager);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();

        $this->assertEquals(7.0, (float) app(LessonCreditService::class)->balance($account->id));

        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/correct",
            ['to' => 'teacher_absent', 'reason' => 'المعلم ماحضرش بالحقيقة'],
            $manager,
        )->assertOk();

        // ⭐ الرصيد رجع زي ما كان.
        $this->assertEquals(
            8.0,
            (float) app(LessonCreditService::class)->balance($account->id),
            'التصحيح ما رجّعش الحصة للطالب'
        );

        // ⭐ المستحق اتعكس (مش اتمسح).
        $earning = TeacherEarning::where('lesson_id', $lesson->id)->firstOrFail();
        $this->assertSame('cancelled', $earning->status);
        $this->assertNotNull($earning->notes);

        // ⭐ والحالة اتصححت + اتسجّلت.
        $this->assertSame('teacher_absent', $lesson->fresh()->status);

        $log = ActivityLog::where('subject_type', 'lesson')
            ->where('subject_id', $lesson->id)
            ->where('action', 'lesson_corrected')
            ->first();

        $this->assertNotNull($log, 'التصحيح ما اتسجّلش في سجل النشاط');
    }

    public function test_correcting_a_paid_earning_is_refused(): void
    {
        $manager = $this->makeUserWithRole('pipeline-manager', ['lessons.update']);
        [$lesson] = $this->readyLesson($manager);

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();

        $earning = TeacherEarning::where('lesson_id', $lesson->id)->firstOrFail();
        $earning->update(['status' => 'paid']);

        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/correct",
            ['to' => 'teacher_absent', 'reason' => 'تصحيح بعد الصرف'],
            $manager,
        )->assertStatus(422);

        $this->assertSame('completed', $lesson->fresh()->status, 'الحصة اتغيرت رغم رفض التصحيح');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ الحصة التعويضية: الأصل ما يتغيّرش قبل نجاح الحجز الجديد
    // ═══════════════════════════════════════════════════════════════

    public function test_makeup_leaves_the_original_alone_when_the_new_booking_fails(): void
    {
        $manager = $this->makeUserWithRole('pipeline-manager', ['lessons.update', 'lessons.create']);
        [$lesson] = $this->readyLesson($manager);

        // ⭐ موعد مشغول بالمعلم نفسه ⇒ الحجز الجديد بيفشل.
        $conflict = $this->makeLesson(
            $this->makeProgram(),
            $this->makeStudent(),
            $lesson->teacher,
            [
                'scheduled_start_at' => now()->addDay()->setTime(16, 0),
                'scheduled_end_at' => now()->addDay()->setTime(16, 30),
            ],
        );
        $r = $this->postJsonAs(
            "/api/lessons/{$lesson->id}/makeup",
            ['scheduled_start' => now()->addDay()->setTime(16, 0)->toDateTimeString()],
            $manager,
        );

        // ⭐⭐⭐ **PR 3.6 — تعارض الجدولة بقى 422** (مش 409):
        // المواصفة بتقول «حجز متعارض = 422» — لأن الطلب صح في
        // صيغته، بس مستحيل في هذا الوقت.
        $r->assertStatus(422);
        $this->assertSame('in_progress', $lesson->fresh()->status, 'الأصل اتغيّر رغم فشل الحجز الجديد');
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ حصة جاهزة للإتمام: معلم متعاقد عليه بسعر، واشتراك
     * برصيد 8.
     *
     * @return array{0: Lesson, 1: Subscription, 2: \App\Models\LessonCreditAccount}
     */
    private function readyLesson(User $manager): array
    {
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);

        $account = \App\Models\LessonCreditAccount::create([
            'student_id' => $student->id,
            'subscription_id' => $subscription->id,
            'credit_type' => 'regular',
            'current_balance' => 8,
            'status' => 'active',
        ]);

        LessonCreditTransaction::create([
            'credit_account_id' => $account->id,
            'student_id' => $student->id,
            'subscription_id' => $subscription->id,
            'type' => 'initial',
            'quantity' => 8,
            'balance_after' => 8,
            'reason' => 'رصيد الباقة',
            'created_at' => now(),
        ]);

        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher);
        $this->makeRate($teacher, ['amount' => 50]);

        $lesson = $this->makeLesson(
            $this->makeProgram(),
            $student,
            $teacher,
            ['subscription_id' => $subscription->id, 'status' => 'in_progress'],
        );

        return [$lesson, $subscription, $account];
    }

    private function linkedParent(int $studentId): User
    {
        $user = $this->makeUser();
        $profile = $this->makeParentProfile($user);

        \App\Models\Student::findOrFail($studentId)->parents()->attach($profile->id, [
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        return $user->fresh();
    }
}
