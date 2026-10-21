<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonStatusHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.1 — آلة حالات الحصة (Lesson State Machine).**
 *
 * القاعدة: **ما فيش تغيير حالة من غير قاعدة** — الكاتب الوحيد
 * للحالة هو `LessonService::transition`، وكل تحويل يتسجَّل في
 * `lesson_status_history`.
 *
 * - `POST /api/lessons/{lesson}/transition` — القناة الوحيدة.
 * - `PUT /api/lessons/{lesson}` — يرفض `status` (مش "يتجاهله"):
 *   التجاهل الصامت بيخلي العميل يظن إن الحالة اتغيّرت.
 * - تحويل ممنوع = **422** (مش 500، مش 403 — التحويل مرفوض من
 *   القاعدة نفسها لا من الصلاحية).
 *
 * ⭐ القبول: اختبار يعبر **كل** أزواج (من، إلى) — 9×9.
 */
class LessonStateMachineTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⭐ كل الحالات التسع من المواصفة + جدول التحويلات
     * (المصدر: `LessonStatus::allowedTransitions`).
     */
    private const STATES = [
        'scheduled', 'confirmed', 'in_progress', 'completed',
        'student_absent', 'teacher_absent', 'cancelled_by_academy',
        'cancelled_late', 'rescheduled',
    ];

    private const ALLOWED = [
        'scheduled' => ['confirmed', 'in_progress', 'completed', 'cancelled_by_academy', 'cancelled_late', 'rescheduled'],
        'confirmed' => ['in_progress', 'completed', 'student_absent', 'teacher_absent', 'cancelled_by_academy', 'cancelled_late', 'rescheduled'],
        'in_progress' => ['completed', 'student_absent', 'teacher_absent', 'cancelled_by_academy', 'cancelled_late'],
        'completed' => [],
        'student_absent' => [],
        'teacher_absent' => [],
        'cancelled_by_academy' => [],
        'cancelled_late' => [],
        'rescheduled' => [],
    ];

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول: كل الأزواج (من، إلى)
    // ═══════════════════════════════════════════════════════════════

    public function test_every_pair_of_states_follows_the_transition_table(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update']);

        foreach (self::STATES as $from) {
            foreach (self::STATES as $to) {
                $lesson = $this->makeLessonWithStatus($from);
                $allowed = in_array($to, self::ALLOWED[$from], true);

                $r = $this->postJsonAs(
                    "/api/lessons/{$lesson->id}/transition",
                    // ⭐ سبب مع كل طلب: الإلغاء (عموماً) لازم سبب —
                    // وسبب «الإلغاء» نفسه حالة جدولStates.
                    ['to' => $to, 'reason' => 'سبب اختباري'],
                    $staff,
                );

                if ($allowed) {
                    $r->assertOk();
                    $this->assertSame(
                        $to,
                        $lesson->fresh()->status,
                        "التحويل {$from} → {$to} مسموح — بس الحالة ما اتغيّرتش"
                    );
                } else {
                    $r->assertStatus(422);
                    $this->assertSame(
                        $from,
                        $lesson->fresh()->status,
                        "التحويل {$from} → {$to} ممنوع — بس الحالة اتغيّرت!"
                    );
                }
            }
        }
    }

    public function test_a_disallowed_transition_is_refused_with_422(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update']);
        $lesson = $this->makeLessonWithStatus('completed');

        $r = $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'scheduled'], $staff);

        $r->assertStatus(422);
        $this->assertSame('completed', $lesson->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // السجل
    // ═══════════════════════════════════════════════════════════════

    public function test_every_transition_is_recorded_in_the_history(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update']);
        $lesson = $this->makeLessonWithStatus('scheduled');

        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/transition",
            ['to' => 'confirmed', 'reason' => 'تأكيد من الإدارة'],
            $staff,
        )->assertOk();

        $history = LessonStatusHistory::where('lesson_id', $lesson->id)->first();

        $this->assertNotNull($history, 'التحويل ما اتسجّلش في السجل');
        $this->assertSame('scheduled', $history->from_status);
        $this->assertSame('confirmed', $history->to_status);
        $this->assertSame($staff->id, $history->actor_id);
        $this->assertSame('تأكيد من الإدارة', $history->reason);
    }

    public function test_a_refused_transition_is_not_recorded(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update']);
        $lesson = $this->makeLessonWithStatus('completed');

        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'scheduled'], $staff)
            ->assertStatus(422);

        $this->assertSame(0, LessonStatusHistory::where('lesson_id', $lesson->id)->count());
    }

    public function test_a_cancellation_without_a_reason_is_refused(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update']);
        $lesson = $this->makeLessonWithStatus('scheduled');

        // ⭐ الإلغاء قرار بيحتاج سبب — من غيره السجل ناقص.
        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'cancelled_by_academy'], $staff)
            ->assertStatus(422);

        $this->assertSame('scheduled', $lesson->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ `update` ما يغيّرش الحالة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_update_endpoint_refuses_to_change_the_status(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update']);
        $lesson = $this->makeLessonWithStatus('scheduled');

        $r = $this->putJsonAs("/api/lessons/{$lesson->id}", ['status' => 'completed'], $staff);

        $r->assertStatus(422);
        $this->assertSame('scheduled', $lesson->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ المسارات القديمة بقت تمرّ بالآلة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_cancel_endpoint_goes_through_the_state_machine(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update', 'lessons.cancel']);
        $lesson = $this->makeLessonWithStatus('scheduled');

        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/cancel",
            ['cancellation_reason' => 'طلب ولي الأمر'],
            $staff,
        )->assertOk();

        $this->assertSame('cancelled_by_academy', $lesson->fresh()->status);

        $history = LessonStatusHistory::where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($history, 'الإلغاء ما اتسجّلش في السجل');
        $this->assertSame('cancelled_by_academy', $history->to_status);
        $this->assertSame('طلب ولي الأمر', $history->reason);
    }

    public function test_a_cancellation_after_the_lesson_started_is_late(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update', 'lessons.cancel']);
        $lesson = $this->makeLessonWithStatus('confirmed', [
            'scheduled_start_at' => now()->subMinutes(10),
            'scheduled_end_at' => now()->addMinutes(20),
        ]);

        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/cancel",
            ['cancellation_reason' => 'ظرف طارئ'],
            $staff,
        )->assertOk();

        $this->assertSame('cancelled_late', $lesson->fresh()->status);
    }

    public function test_the_complete_endpoint_goes_through_the_state_machine(): void
    {
        $staff = $this->makeUserWithRole('lesson-admin', ['lessons.update']);
        $lesson = $this->makeLessonWithStatus('in_progress');

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $staff)->assertOk();

        $this->assertSame('completed', $lesson->fresh()->status);

        $history = LessonStatusHistory::where('lesson_id', $lesson->id)->first();
        $this->assertNotNull($history, 'الإكمال ما اتسجّلش في السجل');
        $this->assertSame('completed', $history->to_status);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ من غير صلاحية ولا Polciy
    // ═══════════════════════════════════════════════════════════════

    public function test_a_teacher_cannot_transition_a_lesson_of_another_teacher(): void
    {
        $lesson = $this->makeLessonWithStatus('scheduled');

        // ⭐ معلم **ثانٍ** — عنده الدور والصلاحية، بس الحصة دي
        // بتاعة معلم تاني.
        $otherUser = $this->makeUserWithRole('other-teacher', ['lessons.update']);
        $this->makeTeacher($otherUser);

        $r = $this->postJsonAs(
            "/api/lessons/{$lesson->id}/transition",
            ['to' => 'confirmed'],
            $otherUser->fresh(),
        );

        $r->assertForbidden();
        $this->assertSame('scheduled', $lesson->fresh()->status);
    }

    public function test_a_user_without_the_permission_cannot_transition(): void
    {
        $lesson = $this->makeLessonWithStatus('scheduled');
        $user = $this->makeUser();

        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'confirmed'], $user)
            ->assertForbidden();

        $this->assertSame('scheduled', $lesson->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    private function makeLessonWithStatus(string $status, array $attrs = []): Lesson
    {
        $lesson = $this->makeLesson(
            $this->makeProgram(),
            $this->makeStudent(),
            $this->makeTeacher(),
            $attrs,
        );

        // ⚡ تهيئة الاختبار: نحط الحالة مباشرة (الكاتب الوحيد في
        // الإنتاج هو الخدمة، لكن الاختبار لازم يبدأ من أي حالة).
        $lesson->update(['status' => $status]);

        return $lesson->fresh();
    }
}
