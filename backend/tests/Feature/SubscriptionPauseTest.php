<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Subscription;
use App\Models\SubscriptionPause;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.8 — إيقاف الاشتراك واستئنافه (بشكل حقيقي).**
 *
 * ⭐ قبل PR ده: `subscription_pauses` كان **جدول فاضي** (لا حد
 * بيكتب فيه) و`PUT /subscriptions/{id}` كان بيغيّر `status`
 * مباشرةً ⇒ إيقاف بلا أثر: الحصص بتفضل محجوزة، و`end_date`
 * ما بيتزحزحش، ومفيش أثر ولا إشعار.
 *
 * ⭐ القبول: إيقاف أسبوعين ⇒ النهاية **بتتزحزح أسبوعين** والحصص
 * جوه النافذة **بتتلغى**؛ والاستئناف **بيعيد توليد** الحصص **بلا
 * تكرار**.
 */
class SubscriptionPauseTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول
    // ═══════════════════════════════════════════════════════════════

    public function test_a_two_week_pause_shifts_the_end_by_two_weeks_and_cancels_the_lessons_in_the_window(): void
    {
        $staff = $this->makeUserWithRole('pause-admin', ['students.update']);
        $subscription = $this->readySubscription();

        $start = now()->addDays(1)->startOfDay();
        $end = $start->copy()->addDays(13);

        // ⭐ النهاية الأصلية (قبل الإيقاف) — للمقارنة.
        $originalEnd = $subscription->end_date->copy();

        $r = $this->postJsonAs("/api/subscriptions/{$subscription->id}/pause", [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'reason' => 'ظرف خاص',
        ], $staff);

        $r->assertOk();

        $subscription->refresh();

        // ⭐ الحالة بقت «موقوفة».
        $this->assertSame('paused', $subscription->status);

        // ⭐ النهاية اتزحزحت بمدة الإيقاف (14 يوم) — نقارن
        // **بالنهاية الأصلية** (المحفوظة قبل الإيقاف).
        $this->assertSame(
            $originalEnd->copy()->addDays(14)->toDateString(),
            $subscription->end_date->toDateString(),
        );

        // ⭐ سطر الإيقاف مسجَّل بالمدة والسبب.
        $pause = SubscriptionPause::where('subscription_id', $subscription->id)->first();
        $this->assertNotNull($pause, 'مفيش سطر إيقاف');
        $this->assertSame(14, (int) $pause->days);
        $this->assertSame('ظرف خاص', $pause->reason);
    }

    public function test_the_lessons_inside_the_pause_window_are_cancelled(): void
    {
        $staff = $this->makeUserWithRole('pause-admin', ['students.update']);
        $subscription = $this->readySubscription();

        $inside = $this->makeLessonFor($subscription, now()->addDays(3));
        $outside = $this->makeLessonFor($subscription, now()->addDays(40));

        $start = now()->addDays(1)->startOfDay();

        $this->postJsonAs("/api/subscriptions/{$subscription->id}/pause", [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(13)->toDateString(),
            'reason' => 'ظرف خاص',
        ], $staff)->assertOk();

        $this->assertSame(
            'cancelled_by_academy',
            $inside->fresh()->status,
            'الحصة جوه نافذة الإيقاف لازم تتلغى'
        );

        $this->assertSame(
            'scheduled',
            $outside->fresh()->status,
            'الحصة بره النافذة ما تأثّرتش'
        );
    }

    public function test_resuming_regenerates_the_lessons_without_duplication(): void
    {
        $staff = $this->makeUserWithRole('pause-admin', ['students.update']);
        $subscription = $this->readySubscription();

        $cancelled = $this->makeLessonFor($subscription, now()->addDays(3));
        $kept = $this->makeLessonFor($subscription, now()->addDays(40));

        $start = now()->addDays(1)->startOfDay();

        $this->postJsonAs("/api/subscriptions/{$subscription->id}/pause", [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(13)->toDateString(),
            'reason' => 'ظرف خاص',
        ], $staff)->assertOk();

        $this->postJsonAs("/api/subscriptions/{$subscription->id}/resume", [], $staff)->assertOk();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);

        // ⭐ سطر الإيقاف اتقفل.
        $this->assertSame('ended', SubscriptionPause::where('subscription_id', $subscription->id)->first()->status);

        // ⭐ الحصة الملغاة اتعوضت بحصة جديدة — **من غير تكرار**.
        $lessons = Lesson::where('subscription_id', $subscription->id)->get();
        $active = $lessons->where('status', 'scheduled');

        $this->assertGreaterThanOrEqual(
            2,
            $active->count(),
            'الاستئناف لازم يعيد توليد الحصة اللي اتلغت'
        );

        // ⭐ مفيش تكرار: كل حصة **نشطة** في وقت مختلف.
        // (الحصة الملغاة ممكن تشارك الوقت مع حصة جديدة — وده
        // مقصود: الإلغاء بيفتح الوقت للتجديد.)
        $activeStarts = $active->map(fn (Lesson $l) => $l->scheduled_start_at->toDateTimeString())->all();
        $this->assertSame(
            count($activeStarts),
            count(array_unique($activeStarts)),
            'فيه حصة مكرّرة'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ منع تغيير الحالة مباشرة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_status_cannot_be_changed_directly(): void
    {
        $staff = $this->makeUserWithRole('pause-admin', ['students.update']);
        $subscription = $this->readySubscription();

        $r = $this->putJsonAs("/api/subscriptions/{$subscription->id}", ['status' => 'paused'], $staff);

        $r->assertStatus(422);
        $this->assertSame('active', $subscription->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ التوثيق والإشعار
    // ═══════════════════════════════════════════════════════════════

    public function test_the_pause_is_logged_and_the_parent_is_notified(): void
    {
        $staff = $this->makeUserWithRole('pause-admin', ['students.update']);
        $subscription = $this->readySubscription();

        $parent = $this->linkedParent($subscription->student_id);

        $start = now()->addDays(1)->startOfDay();

        $this->postJsonAs("/api/subscriptions/{$subscription->id}/pause", [
            'start_date' => $start->toDateString(),
            'end_date' => $start->copy()->addDays(13)->toDateString(),
            'reason' => 'ظرف خاص',
        ], $staff)->assertOk();

        $log = ActivityLog::where('subject_type', 'subscription')
            ->where('subject_id', $subscription->id)
            ->where('action', 'subscription_paused')
            ->first();

        $this->assertNotNull($log, 'الإيقاف ما اتسجّلش في سجل النشاط');

        $notification = Notification::where('user_id', $parent->id)
            ->where('event_type', 'subscription_paused')
            ->latest('id')
            ->first();

        $this->assertNotNull($notification, 'ولي الأمر ما وصلّهش إشعار بالإيقاف');
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    private function readySubscription(): Subscription
    {
        $student = $this->makeStudent();
        $program = $this->makeProgram();

        return $this->makeSubscription($program, $student, [
            'lessons_included' => 8,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->startOfMonth()->copy()->addMonth()->toDateString(),
            // ⭐ مواعيد السلسلة — بدونها مفيش إعادة توليد.
            'schedule_weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'schedule_start_time' => '16:00',
        ], $this->makeTeacher());
    }

    private function makeLessonFor(Subscription $subscription, $date): Lesson
    {
        return Lesson::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => $subscription->student_id,
            'teacher_id' => $subscription->teacher_id,
            'subscription_id' => $subscription->id,
            'program_id' => $subscription->program_id,
            'lesson_type' => 'regular',
            'scheduled_start_at' => $date->copy()->setTime(16, 0),
            'scheduled_end_at' => $date->copy()->setTime(16, 30),
            'duration_minutes' => 30,
            'status' => 'scheduled',
        ]);
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
