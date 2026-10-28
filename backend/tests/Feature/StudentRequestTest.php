<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\Notification;
use App\Models\ParentModel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **طلبات ولي الأمر (PR 4.5).**
 *
 * ⭐ ولي الأمر بيعمل طلب (إلغاء حصة / تأجيلها / تعليق اشتراك) لو
 * `can_request_changes` مفتوح. الطلب بيدخل **صندوق الإدارة**،
 * والقرار (موافقة/رفض) بيتسجّل في `audit_logs` وبيوصل إشعار للولي.
 *
 * ⭐ **قرار التصميم:** الموافقة **بتسجّل القرار بس** — مش بتطبّق
 * الإلغاء/التأجيل/التعليق تلقائياً. التطبيق بيفضل على المسارات
 * الإدارية المراجَعة (`LessonService` / `SubscriptionPauseService`)
 * عشان ما يبقاش في طريقين لتغيير حالة الحصة. ده موثّق في
 * `ROADMAP.md`.
 */
class StudentRequestTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① إنشاء الطلب
    // ═══════════════════════════════════════════════════════════════

    public function test_a_request_requires_can_request_changes(): void
    {
        [$user, , $student] = $this->family(['can_request_changes' => false]);
        $lesson = $this->lessonFor($student);

        $this->postJsonAs(
            "/api/parent/children/{$student->id}/requests",
            $this->payload($lesson),
            $user
        )->assertForbidden('الطلب اتقبل رغم can_request_changes=false');
    }

    public function test_a_cancellation_request_reaches_the_administration_inbox(): void
    {
        [$user, , $student] = $this->family();
        $admin = $this->admin();
        $lesson = $this->lessonFor($student);

        $created = $this->postJsonAs(
            "/api/parent/children/{$student->id}/requests",
            $this->payload($lesson),
            $user
        )->assertCreated();

        $id = $created->json('request.id');
        $this->assertSame('pending', $created->json('request.status'));
        $this->assertSame('cancel', $created->json('request.type'));

        // ⭐ قبول الـroadmap: الطلب بيظهر في صندوق الإدارة
        $inbox = $this->getJsonAs('/api/student-requests', $admin)->assertOk();
        $row = collect($inbox->json('data'))->firstWhere('id', $id);

        $this->assertNotNull($row, 'طلب الإلغاء مش ظاهر في صندوق الإدارة');
        $this->assertSame('cancel', $row['type']);
        $this->assertSame($student->id, $row['student']['id']);

        // ⭐ والإدارة اتنبّهت من غير ما تفتح الصفحة
        $this->assertTrue(
            Notification::where('user_id', $admin->id)
                ->where('event_type', 'student_request_created')
                ->exists(),
            'مافيش إشعار للإدارة بطلب جديد'
        );
    }

    public function test_a_request_cannot_point_at_another_childs_lesson(): void
    {
        [$user, , $student] = $this->family();
        [, , $other] = $this->family();

        $foreign = $this->lessonFor($other);

        $this->postJsonAs(
            "/api/parent/children/{$student->id}/requests",
            $this->payload($foreign),
            $user
        )->assertStatus(422);

        $this->assertDatabaseCount('student_requests', 0);
    }

    public function test_a_non_parent_cannot_raise_a_request(): void
    {
        [, , $student] = $this->family();
        $staff = $this->admin();
        $lesson = $this->lessonFor($student);

        $this->postJsonAs(
            "/api/parent/children/{$student->id}/requests",
            $this->payload($lesson),
            $staff
        )->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // ② قرار الإدارة
    // ═══════════════════════════════════════════════════════════════

    public function test_approving_records_the_decision_notifies_the_parent_and_is_audited(): void
    {
        [$user, , $student] = $this->family();
        $admin = $this->admin();
        $id = $this->raise($user, $student);

        $approved = $this->postJsonAs(
            "/api/student-requests/{$id}/approve",
            ['admin_notes' => 'تم التواصل مع المعلم'],
            $admin
        )->assertOk();

        $this->assertSame('approved', $approved->json('request.status'));
        $this->assertNotNull($approved->json('request.reviewed_at'));

        $this->assertDatabaseHas('student_requests', [
            'id' => $id,
            'status' => 'approved',
            'reviewed_by' => $admin->id,
        ]);

        $this->assertTrue(
            Notification::where('user_id', $user->id)
                ->where('event_type', 'student_request_approved')
                ->exists(),
            'الولي ما اتبعتلهوش إشعار بالقرار'
        );

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => 'student_request',
            'entity_id' => $id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_rejecting_requires_a_reason_and_notifies_the_parent(): void
    {
        [$user, , $student] = $this->family();
        $admin = $this->admin();
        $id = $this->raise($user, $student);

        $this->postJsonAs("/api/student-requests/{$id}/reject", [], $admin)
            ->assertStatus(422);

        $this->postJsonAs(
            "/api/student-requests/{$id}/reject",
            ['admin_notes' => 'الحصة اتلغت بالفعل من الأكاديمية'],
            $admin
        )->assertOk()->assertJsonPath('request.status', 'rejected');

        $this->assertTrue(
            Notification::where('user_id', $user->id)
                ->where('event_type', 'student_request_rejected')
                ->exists()
        );
    }

    public function test_a_decision_can_only_be_taken_once(): void
    {
        [$user, , $student] = $this->family();
        $admin = $this->admin();
        $id = $this->raise($user, $student);

        $this->postJsonAs("/api/student-requests/{$id}/approve", [], $admin)->assertOk();
        $this->postJsonAs("/api/student-requests/{$id}/reject", ['admin_notes' => 'تغيير رأي'], $admin)
            ->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ السحب والصلاحيات
    // ═══════════════════════════════════════════════════════════════

    public function test_a_parent_can_withdraw_only_their_own_pending_request(): void
    {
        [$user, , $student] = $this->family();
        [$otherUser] = $this->family();
        $id = $this->raise($user, $student);

        $this->postJsonAs("/api/parent/requests/{$id}/withdraw", [], $otherUser)
            ->assertForbidden('ولي أمر تاني سحب طلب مش بتاعه');

        $this->postJsonAs("/api/parent/requests/{$id}/withdraw", [], $user)
            ->assertOk()
            ->assertJsonPath('request.status', 'withdrawn');

        // ⭐ السحب بعد السحب مرفوض — الحالة النهائية ما بتتغيّرش
        $this->postJsonAs("/api/parent/requests/{$id}/withdraw", [], $user)
            ->assertStatus(422);
    }

    public function test_viewing_the_inbox_needs_view_but_deciding_needs_manage(): void
    {
        [$user, , $student] = $this->family();
        $viewer = $this->makeUserWithRole('request-viewer', ['student_requests.view']);
        $admin = $this->admin();
        $id = $this->raise($user, $student);

        $this->getJsonAs('/api/student-requests', $viewer)->assertOk();
        $this->postJsonAs("/api/student-requests/{$id}/approve", [], $viewer)
            ->assertForbidden('صلاحية العرض كفت للموافقة');
    }

    public function test_the_inbox_is_organization_scoped(): void
    {
        [$user, , $student] = $this->family();
        $admin = $this->admin();
        $this->raise($user, $student);

        $outsider = $this->makeUserWithRole('outsider-manager', ['student_requests.view']);

        // ⭐ مستخدم من مؤسسة تانية بنفس الصلاحية — لازم يشوف صندوقه هو
        $otherOrg = \App\Models\Organization::create([
            'name' => 'مؤسسة تانية',
            'slug' => 'other-org-'.uniqid(),
        ]);
        DB::table('users')->where('id', $outsider->id)->update(['organization_id' => $otherOrg->id]);

        $this->getJsonAs('/api/student-requests', $outsider->fresh())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJsonAs('/api/student-requests', $admin)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * @return array{0: User, 1: ParentModel, 2: Student}
     */
    private function family(array $pivot = []): array
    {
        $user = $this->makeUser();
        $parent = $this->makeParentProfile($user);
        $student = $this->makeStudent();

        $student->parents()->attach($parent->id, array_merge([
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
            'can_view_finance' => true,
            'can_view_progress' => true,
            'can_request_changes' => true,
        ], $pivot));

        return [$user->fresh(), $parent, $student];
    }

    /** إداري عنده الصلاحيتين — وبيستقبل إشعارات الصندوق. */
    private function admin(): User
    {
        return $this->makeUserWithRole('request-admin', [
            'student_requests.view',
            'student_requests.manage',
        ]);
    }

    private function lessonFor(Student $student): Lesson
    {
        return $this->makeLesson(
            $this->makeProgram(),
            $student,
            $this->makeTeacher(),
            ['scheduled_start_at' => now()->addDays(4)->setTime(17, 0)]
        );
    }

    private function payload(Lesson $lesson): array
    {
        return [
            'type' => 'cancel',
            'lesson_id' => $lesson->id,
            'reason' => 'ظرف عائلي طارئ يمنع الحضور',
        ];
    }

    private function raise(User $parent, Student $student): int
    {
        return $this->postJsonAs(
            "/api/parent/children/{$student->id}/requests",
            $this->payload($this->lessonFor($student)),
            $parent
        )->assertCreated()->json('request.id');
    }
}
