<?php

namespace Tests\Feature;

use App\Models\ParentModel;
use App\Models\Student;
use App\Services\ParentChildScope;
use App\Services\ParentLinker;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ PR 1.11 — هوية ولي الأمر والربط بالطلاب.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ الثغرة: نفس الرقم = نفس ولي الأمر **بدون تأكيد**
 *
 * الكود كان بيعمل ده: `findOrCreate()` بيدوّر على رقم في `parents`،
 * فلما لقى واحد **بيربطه بالطالب فوراً** وبيمنحه `can_manage` و
 * `can_pay` = true.
 *
 * ⭐⭐⭐ وده باب مفتوح: أي حد يقدر يدخل رقم **أي** ولي أمر عند
 * الأكاديمية في صفحة «أرقام الطالب»، فيصير **أب وأم وإخوة** ذلك
 * الطالب — ويقدر يعدّل بياناته ويسدد بدل عنه. الرقم **علامة عامة**،
 * ومالكش حق في أخذ الهوية.
 *
 * ⭐ الـacceptance بتاع الـroadmap:
 * «رقم مكرر مامنحش وصول قبل التأكيد» + «ولي الأمر مش بيشوف طالب
 * من غير رابط `verified`».
 */
class ParentIdentityTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════
    // ① الرقم المكرر
    // ═══════════════════════════════════════════════════════════

    public function test_a_duplicate_phone_does_not_grant_access_before_confirmation(): void
    {
        $linker = app(ParentLinker::class);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        // ⭐ رقم واحد = ولي أمر واحد (الأم فاطمة)
        $linker->findOrCreate($studentA->id, '01011111111', 'فاطمة');

        // ⭐ حد تاني دخل **نفس الرقم** في صفحة طالب تاني
        $result = $linker->findOrCreate($studentB->id, '01011111111', 'أحمد');

        $this->assertFalse(
            $result['created'],
            'الرقم المكرر لازم يرجّع ولي الأمر الموجود — مش يعمل واحد جديد'
        );

        $row = DB::table('student_parents')
            ->where('student_id', $studentB->id)
            ->where('parent_id', $result['parent_id'])
            ->first();

        $this->assertNotNull($row, 'الربط لازم يتسجل — بس كـ«بانتظار التأكيد»');
        $this->assertSame('pending_verification', $row->status);

        // ⭐⭐ الأهم: الرابط المعلّق **مالوش** صلاحيات
        $this->assertFalse((bool) $row->can_manage, '(can_manage = true) قبل التأكيد');
        $this->assertFalse((bool) $row->can_pay, 'can_pay = true قبل التأكيد');
    }

    public function test_a_new_parent_is_linked_as_verified(): void
    {
        $linker = app(ParentLinker::class);

        $student = $this->makeStudent();

        // ⭐ رقم جديد — الموظف بيسجل ولي أمر جديد من أول مرة
        $result = $linker->findOrCreate($student->id, '01022222222', 'خالد');

        $this->assertTrue($result['created']);

        $row = DB::table('student_parents')
            ->where('student_id', $student->id)
            ->where('parent_id', $result['parent_id'])
            ->first();

        $this->assertSame('verified', $row->status, 'ولي أمر جديد من الموظف = مؤكد');
        $this->assertTrue((bool) $row->can_manage);
    }

    // ═══════════════════════════════════════════════════════════
    // ② التأكيد
    // ═══════════════════════════════════════════════════════════

    public function test_staff_can_confirm_a_pending_link_and_it_then_grants_access(): void
    {
        $linker = app(ParentLinker::class);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $first = $linker->findOrCreate($studentA->id, '01033333333', 'مريم');
        $linker->findOrCreate($studentB->id, '01033333333', 'شخص ثاني');

        // ⭐ الطالب الأصلي مرتبط فعلاً (رقم جديد ⇒ مؤكد)، واللي ات-bind
        // بالرقم المكرر **لسه مش موجود** في القائمة.
$this->assertContains(
            $studentA->id,
            $this->parentStudentIds($first['parent_id'])
        );

        $this->assertNotContains(
            $studentB->id,
            $this->parentStudentIds($first['parent_id']),
            'قبل التأكيد: الطالب المرتبط بالرقم المكرر ظهر في القائمة'
        );

        $staff = $this->makeUserWithRole('staff', ['students.update']);

        $linker->verify($studentB->id, $first['parent_id'], $staff);

        $this->assertCount(
            2,
            $this->parentStudentIds($first['parent_id']),
            'بعد التأكيد: لازم يبقى في طالبين (الأصلي + اللي اتأكّد)'
        );

        $this->assertContains(
            $studentB->id,
            $this->parentStudentIds($first['parent_id'])
        );

        $row = DB::table('student_parents')
            ->where('student_id', $studentB->id)
            ->where('parent_id', $first['parent_id'])
            ->first();

        $this->assertSame('verified', $row->status);
        $this->assertTrue((bool) $row->can_manage, 'can_manage لازم ييجي مع التأكيد');
        $this->assertNotNull($row->verified_at);
        $this->assertSame($staff->id, (int) $row->verified_by);
    }

    public function test_staff_can_reject_a_pending_link(): void
    {
        $linker = app(ParentLinker::class);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $first = $linker->findOrCreate($studentA->id, '01044444444', 'سارة');
        $linker->findOrCreate($studentB->id, '01044444444', 'شخص تاني');

        $staff = $this->makeUserWithRole('staff', ['students.update']);

        $linker->reject($studentB->id, $first['parent_id'], $staff);

        $this->assertSame(0, DB::table('student_parents')
            ->where('student_id', $studentB->id)
            ->where('parent_id', $first['parent_id'])
            ->count());
    }

    // ═══════════════════════════════════════════════════════════
    // ③ كل قارئ لازم يحترم الحالة
    // ═══════════════════════════════════════════════════════════

    public function test_the_student_list_and_flags_ignore_pending_links(): void
    {
        $linker = app(ParentLinker::class);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $first = $linker->findOrCreate($studentA->id, '01055555555', 'هدى');
        $linker->findOrCreate($studentB->id, '01055555555', 'حد تاني');

        // ⭐ قارئ واحد من غير استثناء: `ParentChildScope` — ده اللي
        // بيحمي كل الـendpoints من خلالPolicies (PR 1.8).
        $user = $this->makeParentUser($first['parent_id']);

        $allowed = ParentChildScope::allowedStudentIds($user);

        $this->assertNotContains($studentB->id, (array) $allowed, 'طالب برابط معلّق ظهر في القائمة');
        $this->assertContains($studentA->id, (array) $allowed);

        $this->assertFalse(
            ParentChildScope::flag($user, $studentB->id, 'can_view_finance'),
            'العلم المالي بيتقري لطالب برابط معلّق'
        );
        $this->assertTrue(
            ParentChildScope::flag($user, $studentA->id, 'can_view_finance'),
            'العلم المالي لازم يشتغل للطالب المرتبط'
        );
    }

    public function test_the_parent_of_a_pending_link_sees_no_student(): void
    {
        $linker = app(ParentLinker::class);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $first = $linker->findOrCreate($studentA->id, '01066666666', 'نجلاء');
        $linker->findOrCreate($studentB->id, '01066666666', 'حد تاني');

        $user = $this->makeParentUser($first['parent_id']);

        $this->assertNotContains(
            $studentB->id,
            (array) ParentChildScope::allowedStudentIds($user)
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ④ الأرقام التالفة
    // ═══════════════════════════════════════════════════════════

    public function test_an_unknown_student_throws_instead_of_defaulting_to_organization_one(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        // ⭐ الكود القديم كان `Student::find($id)?->organization_id ?? 1`
        // — يعني طالب مش موجود كان بيعمل ولي أمر في «منظمة 1»
        // من غير ما حد يبقى موجود أصلاً.
        app(ParentLinker::class)->findOrCreate(999999, '01077777777', 'مفيش طالب');
    }

    public function test_the_staff_endpoint_confirms_a_pending_link(): void
    {
        $linker = app(ParentLinker::class);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $first = $linker->findOrCreate($studentA->id, '01088888888', 'ريهام');
        $linker->findOrCreate($studentB->id, '01088888888', 'حد تاني');

        $staff = $this->makeUserWithRole('staff', ['parents.create', 'parents.view']);

        // ⭐ الشاشة بتجيب المعلّقات مع اسم ولي الأمر الحقيقي
        $list = $this->getJsonAs("/api/students/{$studentB->id}/parents", $staff);

        $list->assertOk();
        $list->assertJsonPath('pending.0.parent_id', $first['parent_id']);
        $list->assertJsonPath('pending.0.name', 'ريهام');

        $this->postJsonAs(
            "/api/students/{$studentB->id}/parents/{$first['parent_id']}/verify",
            [],
            $staff
        )->assertOk();

        $this->assertSame(
            'verified',
            DB::table('student_parents')
                ->where('student_id', $studentB->id)
                ->where('parent_id', $first['parent_id'])
                ->value('status')
        );
    }

    public function test_a_user_without_the_permission_cannot_confirm_a_link(): void
    {
        $linker = app(ParentLinker::class);

        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $first = $linker->findOrCreate($studentA->id, '01099999999', 'منى');
        $linker->findOrCreate($studentB->id, '01099999999', 'حد تاني');

        // ⭐ أكتر صلاحية متبقية غير الـpermission دي — ومتخليش
        // التأكيد ينجح من غيرها.
        $reception = $this->makeUserWithRole('reception', ['students.view']);

        $this->postJsonAs(
            "/api/students/{$studentB->id}/parents/{$first['parent_id']}/verify",
            [],
            $reception
        )->assertForbidden();

        $this->assertSame(
            'pending_verification',
            DB::table('student_parents')
                ->where('student_id', $studentB->id)
                ->where('parent_id', $first['parent_id'])
                ->value('status')
        );
    }

    // ═══════════════════════════════════════════════════════════
    // أدوات
    // ═══════════════════════════════════════════════════════════

    /** @return array<int, int> أرقام الطلاب اللي الربط **مؤكد** بس */
    private function parentStudentIds(int $parentId): array
    {
        return DB::table('student_parents')
            ->where('parent_id', $parentId)
            ->where('status', 'verified')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function makeParentUser(int $parentId)
    {
        $parent = ParentModel::findOrFail($parentId);

        $user = $this->makeUser('ولي أمر', 'parent.'.$parentId.'@example.test');

        $parent->update(['user_id' => $user->id]);

        return $user->fresh();
    }
}