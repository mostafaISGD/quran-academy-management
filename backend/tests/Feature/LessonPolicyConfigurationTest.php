<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonPolicy;
use App\Models\User;
use Database\Seeders\LessonPolicySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.2 — سياسات الحصص القابلة للضبط + لقطة السياسة.**
 *
 * القاعدة: الإدارة تتحكم في كل مرة. بس **التاريخ ما يتغيّرش**:
 * كل حصة بتحمل **لقطة** (`policy_snapshot`) للسياسة المطبَّقة وقت
 * تحويلها — فتعديل السياسة بعدين ما يغيّرش إلا اللي فات.
 *
 * - `lesson_policies`: صف لكل (نتيجة × نطاق: global أو program).
 * - `LessonPolicyService::resolve()` — الـprogram يسبق الـglobal.
 * - `PUT /api/lesson-policy/{policy}` بصلاحية `lessons.policies.manage`
 *   + تسجيل القديم/الجديد في `audit_logs`.
 */
class LessonPolicyConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ⭐ الافتراضات مزروعة من seeder آمن — زي الإنتاج.
        $this->seed(LessonPolicySeeder::class);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول: تعديل السياسة ما يأثّرش على ما تمّ
    // ═══════════════════════════════════════════════════════════════

    public function test_changing_the_policy_does_not_touch_a_lesson_that_is_already_finished(): void
    {
        $manager = $this->makeUserWithRole('policy-manager', ['lessons.update', 'lessons.policies.manage']);

        $lesson = $this->makeLessonWithStatus('in_progress');

        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'completed'], $manager)
            ->assertOk();

        $snapshot = $lesson->fresh()->policy_snapshot;

        $this->assertIsArray($snapshot, 'مفيش لقطة سياسة على الحصة المكتملة');
        $this->assertTrue($snapshot['deduct_credit'], 'الافتراضي: الحصة المكتملة بتخصم من الطالب');

        // ⭐ الإدارة بتغيّر السياسة...
        $this->putJsonAs(
            "/api/lesson-policy/{$this->policyFor('completed')->id}",
            ['deduct_credit' => false],
            $manager,
        )->assertOk();

        // ⭐... والحصة اللي اتقبلت خلاص ما بتتغيرش.
        $this->assertSame(
            $snapshot,
            $lesson->fresh()->policy_snapshot,
            'تعديل السياسة غيّر لقطة حصة اتقبلت قبل التعديل — التاريخ لازم يفضل زي ما هو'
        );
    }

    public function test_a_new_lesson_follows_the_updated_policy(): void
    {
        $manager = $this->makeUserWithRole('policy-manager', ['lessons.update', 'lessons.policies.manage']);

        $this->putJsonAs(
            "/api/lesson-policy/{$this->policyFor('completed')->id}",
            ['deduct_credit' => false],
            $manager,
        )->assertOk();

        $lesson = $this->makeLessonWithStatus('in_progress');

        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'completed'], $manager)
            ->assertOk();

        $snapshot = $lesson->fresh()->policy_snapshot;

        $this->assertIsArray($snapshot);
        $this->assertFalse($snapshot['deduct_credit'], 'الحصة الجديدة لازم تاخد السياسة الجديدة');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ اللقطة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_policy_is_snapshotted_on_the_lesson_at_transition_time(): void
    {
        $manager = $this->makeUserWithRole('policy-manager', ['lessons.update', 'lessons.policies.manage']);

        $lesson = $this->makeLessonWithStatus('scheduled');

        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'confirmed'], $manager)
            ->assertOk();

        $snapshot = $lesson->fresh()->policy_snapshot;

        $this->assertIsArray($snapshot);
        $this->assertSame('confirmed', $snapshot['outcome']);
        $this->assertSame('global', $snapshot['scope']);
        $this->assertArrayHasKey('deduct_credit', $snapshot);
        $this->assertArrayHasKey('pay_teacher', $snapshot);
        $this->assertArrayHasKey('notify_parent', $snapshot);
    }

    public function test_a_program_scope_wins_over_the_global_scope(): void
    {
        $manager = $this->makeUserWithRole('policy-manager', ['lessons.update', 'lessons.policies.manage']);

        $program = $this->makeProgram();

        LessonPolicy::create([
            'organization_id' => $this->org->id,
            'outcome' => 'completed',
            'scope' => 'program',
            'program_id' => $program->id,
            'deduct_credit' => false,
            'pay_teacher' => false,
            'allow_makeup' => false,
            'makeup_limit' => 0,
            'makeup_window_days' => 0,
            'notify_parent' => false,
            'late_cancel_notice_hours' => 0,
        ]);

        $lesson = $this->makeLessonWithStatus('in_progress', ['program_id' => $program->id]);

        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'completed'], $manager)
            ->assertOk();

        $snapshot = $lesson->fresh()->policy_snapshot;

        $this->assertSame('program', $snapshot['scope'], 'النطاق الخاص بالبرنامج لازم يغلب العام');
        $this->assertFalse($snapshot['deduct_credit']);
        $this->assertFalse($snapshot['pay_teacher']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ سجل التعديلات
    // ═══════════════════════════════════════════════════════════════

    public function test_editing_a_policy_records_the_old_and_new_values(): void
    {
        $manager = $this->makeUserWithRole('policy-manager', ['lessons.policies.manage']);

        $policy = $this->policyFor('completed');

        $this->putJsonAs("/api/lesson-policy/{$policy->id}", ['deduct_credit' => false], $manager)
            ->assertOk();

        $log = \App\Models\AuditLog::where('entity_type', 'lesson_policy')
            ->where('entity_id', $policy->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'التعديل ما اتسجّلش في سجل التدقيق');

        // ⭐ `AuditLogService` بيكتب `json_encode(...)` (نص) والـcast
        // `'array'` بيلفّه تاني — فالقيمة في الموديل **نص** مش مصفوفة.
        $old = json_decode((string) $log->old_value, true) ?? [];
        $new = json_decode((string) $log->new_value, true) ?? [];

        $this->assertTrue($old['deduct_credit'] ?? null, 'القيمة القديمة مش مسجّلة: '.json_encode($old, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($new['deduct_credit'] ?? null, 'القيمة الجديدة مش مسجّلة: '.json_encode($new, JSON_UNESCAPED_UNICODE));
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ الصلاحيات
    // ═══════════════════════════════════════════════════════════════

    public function test_only_a_manager_can_edit_the_policies(): void
    {
        $supervisor = $this->makeUserWithRole('policy-supervisor', ['lessons.policies.view']);

        $this->putJsonAs(
            "/api/lesson-policy/{$this->policyFor('completed')->id}",
            ['deduct_credit' => false],
            $supervisor,
        )->assertForbidden();

        $this->assertTrue($this->policyFor('completed')->fresh()->deduct_credit);
    }

    public function test_a_user_without_the_permission_cannot_read_the_policies(): void
    {
        $user = $this->makeUser();

        $this->getJsonAs('/api/lesson-policy', $user)->assertForbidden();
    }

    public function test_the_policies_are_readable_with_the_view_permission(): void
    {
        $viewer = $this->makeUserWithRole('policy-viewer', ['lessons.policies.view']);

        $r = $this->getJsonAs('/api/lesson-policy', $viewer);

        $r->assertOk();
        $this->assertNotEmpty($r->json('data.policies'), 'مفيش سياسات مرجّعة');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ الافتراضات المزروعة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_defaults_are_seeded_for_every_outcome(): void
    {
        $outcomes = LessonPolicy::where('organization_id', $this->org->id)
            ->where('scope', 'global')
            ->pluck('outcome')
            ->all();

        foreach (['completed', 'student_absent', 'teacher_absent', 'cancelled_by_academy', 'cancelled_late'] as $outcome) {
            $this->assertContains($outcome, $outcomes, "الافتراضي ناقص للتيجة «{$outcome}»");
        }

        // ⭐ الافتراض الآمن: غياب الطالب ما بيخصمش، والمعلم مش بياخد.
        $absent = $this->policyFor('student_absent');
        $this->assertFalse($absent->deduct_credit);
        $this->assertFalse($absent->pay_teacher);
        $this->assertTrue($absent->allow_makeup);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    private function policyFor(string $outcome): LessonPolicy
    {
        return LessonPolicy::where('organization_id', $this->org->id)
            ->where('outcome', $outcome)
            ->where('scope', 'global')
            ->firstOrFail();
    }

    private function makeLessonWithStatus(string $status, array $attrs = []): Lesson
    {
        $lesson = $this->makeLesson(
            $this->makeProgram(),
            $this->makeStudent(),
            $this->makeTeacher(),
            $attrs,
        );

        $lesson->update(['status' => $status]);

        return $lesson->fresh();
    }
}
