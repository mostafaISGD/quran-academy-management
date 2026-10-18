<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **مصفوفة العلاقات (PR 1.8).**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة اللي existed
 *
 * الصلاحيات العامة (`students.view`) كانت **اللي تحدد كل حاجة**:
 * أي واحد ياخد `students.view` بيشوف **كل** الطلبة — حتى لو هو
 * ولي أمر، أو معلم. الـroadmap بيقول: **المعلم طلابه، وولي الأمر
 * عياله، والطالب نفسه** — يعني العلاقة هي اللي تحدد، مش
 * الصلاحية العامة لوحدها.
 *
 * ⭐ إزاي الاختبار ده بيحكم
 *
 * مصفوفة: (معلم، ولي أمر، طالب) × (اللي تبعه / تبع حد تاني) ×
 * (قراءة / تعديل) = النتيجة المتوقعة.
 *
 *   ✅ قراءة تبعه   → مسموحة
 *   ❌ قراءة تبع حد تاني → 403
 *   ❌ تعديل بدون `can_manage` → 403
 *   ❌ أموال بدون `can_view_finance` → 403 / مخفية من البورتال
 *
 * ⭐ الـ`parent.only` والـ`permission:` ما زالهما شغالين —
 * الـPolicies طبقة **فوق** الصلاحيات العامة، مش بديل عنها.
 */
class RelationshipPoliciesTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① المعلم — طلابه بس
    // ═══════════════════════════════════════════════════════════════

    public function test_a_teacher_lists_only_their_own_students(): void
    {
        [$ownTeacher, $otherTeacher, $ownStudent, $otherStudent, $user] = $this->twoTeachers();

        $own = $this->getJsonAs("/api/teachers/{$ownTeacher->id}/students", $user);
        $own->assertOk();
        $ids = collect($own->json('students'))->pluck('id');
        $this->assertTrue($ids->contains($ownStudent->id), 'المعلم لازم يشوف طالبه');
        $this->assertFalse($ids->contains($otherStudent->id), 'مافيش طلبة معلم تاني في قايمة المعلم');

        $this->getJsonAs("/api/teachers/{$otherTeacher->id}/students", $user)->assertForbidden();
    }

    public function test_a_teacher_cannot_read_another_teachers_schedule(): void
    {
        [$ownTeacher, $otherTeacher, , , $user] = $this->twoTeachers();

        $this->getJsonAs("/api/teachers/{$ownTeacher->id}/schedule", $user)->assertOk();
        $this->getJsonAs("/api/teachers/{$otherTeacher->id}/schedule", $user)->assertForbidden();
    }

    public function test_a_teacher_cannot_complete_another_teachers_lesson(): void
    {
        [$ownTeacher, $otherTeacher, $ownStudent, $otherStudent, $user] = $this->twoTeachers();

        $foreign = Teacher::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            // ⭐ معلم بدون user - مفيش أي علاقة بالمستخدم ده
            'user_id' => null,
            'teacher_code' => 'TCH-'.strtoupper(uniqid()),
            'display_name' => 'معلم خارجي',
            'phone' => '01500000000',
            'status' => 'active',
        ]);

        $program = $this->makeProgram();
        $ownLesson = $this->makeLesson($program, $ownStudent, $ownTeacher);
        $foreignLesson = $this->makeLesson($program, $otherStudent, $foreign);

        $this->postJsonAs("/api/lessons/{$ownLesson->id}/complete", [], $user)->assertOk();
        $this->postJsonAs("/api/lessons/{$foreignLesson->id}/complete", [], $user)->assertForbidden();

        // ⭐ حصة المعلم التاني ما اتحطتش completed
        $this->assertSame('scheduled', $foreignLesson->fresh()->status);
    }

    public function test_a_students_list_is_scoped_for_a_teacher(): void
    {
        [, , $ownStudent, $otherStudent, $user] = $this->twoTeachers();

        $r = $this->getJsonAs('/api/students', $user);
        $r->assertOk();
        $ids = collect($r->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($ownStudent->id));
        $this->assertFalse(
            $ids->contains($otherStudent->id),
            'معلم مع students.view بيشوف طلابه بس — مش كل النظام'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ② ولي الأمر — عياله بس + أعلام الـpivot
    // ═══════════════════════════════════════════════════════════════

    public function test_a_parent_reads_their_child(): void
    {
        [$childA, , $userA] = $this->twoFamilies();

        $this->getJsonAs("/api/students/{$childA->id}", $userA)->assertOk();
    }

    public function test_a_parent_gets_403_on_a_foreign_child(): void
    {
        [, $childB, $userA] = $this->twoFamilies();

        $this->getJsonAs("/api/students/{$childB->id}", $userA)->assertForbidden();
    }

    public function test_a_students_list_is_scoped_for_a_parent(): void
    {
        [$childA, $childB, $userA] = $this->twoFamilies();

        $r = $this->getJsonAs('/api/students', $userA);
        $r->assertOk();
        $ids = collect($r->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($childA->id));
        $this->assertFalse(
            $ids->contains($childB->id),
            'ولي الأمر بيشوف عياله بس - حتى لو معاه students.view'
        );
    }

    public function test_a_parent_cannot_edit_their_child_without_can_manage(): void
    {
        [$childA, , $userA] = $this->twoFamilies([], ['can_manage' => false]);

        // ⭐ الصلاحية العامة موجودة (students.update) - العلاقة هي اللي بترفض
        $this->putJsonAs("/api/students/{$childA->id}", ['notes' => 'x'], $userA)->assertForbidden();
    }

    public function test_a_parent_with_can_manage_can_edit_their_child(): void
    {
        [$childA, , $userA] = $this->twoFamilies([], ['can_manage' => true]);

        $this->putJsonAs("/api/students/{$childA->id}", ['notes' => 'ملاحظة ولي الأمر'], $userA)->assertOk();
    }

    public function test_can_view_finance_gates_the_child_payments(): void
    {
        [$childA, , $userA] = $this->twoFamilies([], ['can_view_finance' => true]);

        $this->getJsonAs("/api/students/{$childA->id}/payments", $userA)->assertOk();

        $this->flipParentFlag($userA, $childA->id, 'can_view_finance', false);

        $this->getJsonAs("/api/students/{$childA->id}/payments", $userA)->assertForbidden();
    }

    public function test_the_portal_hides_money_when_can_view_finance_is_false(): void
    {
        [$childA, , $userA] = $this->twoFamilies([], ['can_view_finance' => true]);

        $this->invoiceFor($childA);

        $with = $this->getJsonAs('/api/parent/children', $userA);
        $with->assertOk();
        $this->assertNotNull(
            $with->json('children.0.outstanding'),
            'المال ظاهر لما can_view_finance=true'
        );

        $this->flipParentFlag($userA, $childA->id, 'can_view_finance', false);

        $without = $this->getJsonAs('/api/parent/children', $userA);
        $without->assertOk();
        $this->assertNull(
            $without->json('children.0.outstanding'),
            '⭐⭐ المال لازم يختفي لما can_view_finance=false — ده اللي كان ناقص قبل PR 1.8'
        );
        $this->assertNull(
            $without->json('children.0.lesson_credit'),
            'رصيد الحصص ده مال كمان - بيختفي بنفس العلم'
        );
    }

    public function test_the_portal_hides_lessons_when_can_view_progress_is_false(): void
    {
        [$childA, , $userA] = $this->twoFamilies([], [], withLesson: true);

        $with = $this->getJsonAs('/api/parent/children', $userA);
        $with->assertOk();
        $this->assertNotEmpty($with->json('children.0.upcoming_lessons'));

        $this->flipParentFlag($userA, $childA->id, 'can_view_progress', false);

        $without = $this->getJsonAs('/api/parent/children', $userA);
        $without->assertOk();
        $this->assertSame(
            [],
            $without->json('children.0.upcoming_lessons'),
            'الحصص القادمة مخفية لما can_view_progress=false'
        );
    }

    public function test_a_parent_cannot_view_a_foreign_invoice(): void
    {
        [$childA, $childB, $userA] = $this->twoFamilies();

        $own = $this->invoiceFor($childA);
        $foreign = $this->invoiceFor($childB);

        // ⭐ can_view_finance الافتراضي = true (نفس سلوك البورتال الحالي)
        $this->getJsonAs("/api/invoices/{$own->id}", $userA)->assertOk();
        $this->getJsonAs("/api/invoices/{$foreign->id}", $userA)->assertForbidden();
    }

    public function test_can_pay_gates_paying_for_the_child(): void
    {
        [$childA, , $userA] = $this->twoFamilies([], ['can_pay' => true]);

        $invoice = $this->invoiceFor($childA);

        $this->assertTrue($userA->can('pay', $invoice), 'can_pay=true → يقدر يدفع فاتورة عيله');

        $this->flipParentFlag($userA, $childA->id, 'can_pay', false);

        $this->assertFalse(
            $userA->can('pay', $invoice),
            'can_pay=false → مسموحش يدفع — العلم ده كان موجود ومحدش بيقراه'
        );
    }

    public function test_a_parent_can_view_their_own_subscription(): void
    {
        [$childA, $childB, $userA] = $this->twoFamilies();

        $subscriptionA = $this->makeSubscription($this->makeProgram(), $childA);
        $subscriptionB = $this->makeSubscription($this->makeProgram(), $childB);

        $this->getJsonAs("/api/subscriptions/{$subscriptionA->id}", $userA)->assertOk();
        $this->getJsonAs("/api/subscriptions/{$subscriptionB->id}", $userA)->assertForbidden();
    }

    public function test_a_parent_cannot_view_a_lesson_without_can_view_progress(): void
    {
        [$childA, , $userA] = $this->twoFamilies([], ['can_view_progress' => false], withLesson: true);

        $lesson = Lesson::where('student_id', $childA->id)->first();
        $this->assertNotNull($lesson);

        // ⭐ قراءة تفاصيل الحصة = بيانات تقدم دراسي - العلم بيتفعل
        $this->assertFalse(
            $userA->can('view', $lesson),
            'قراءة الحصة تحتاج can_view_progress=true'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الطالب — نفسه بس
    // ═══════════════════════════════════════════════════════════════

    public function test_a_student_sees_only_themselves(): void
    {
        [$own, $other, $user] = $this->twoStudents();

        $this->getJsonAs("/api/students/{$own->id}", $user)->assertOk();
        $this->getJsonAs("/api/students/{$other->id}", $user)->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ الموظف بدون بروفايلات - الصلاحية العامة تكفي
    // ═══════════════════════════════════════════════════════════════

    public function test_a_plain_staff_user_is_not_scoped(): void
    {
        [$childA, $childB] = $this->twoFamilies();

        $staff = $this->makeUserWithRole('desk', ['students.view', 'finance.payments.view']);

        $r = $this->getJsonAs('/api/students', $staff);
        $r->assertOk();
        $ids = collect($r->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($childA->id));
        $this->assertTrue($ids->contains($childB->id), 'الموظف بدون علاقات بيشوف الكل');

        $this->getJsonAs("/api/students/{$childA->id}/payments", $staff)->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // Fixtures
    // ═══════════════════════════════════════════════════════════════

    /**
     * معلمان، لكل واحد طالب، والـuser بتاع معلم الأول معيّن
     * role موظف بعدد صلاحيات ضروري عشان نوصل للـpolicies والـ
     * `permission:` middleware — العلاقة هي اللي بتحدد النطاق.
     *
     * @return array{0: Teacher, 1: Teacher, 2: \App\Models\Student, 3: \App\Models\Student, 4: User}
     */
    private function twoTeachers(): array
    {
        $own = $this->makeTeacher();
        $other = $this->makeTeacher();

        $ownStudent = $this->makeStudent();
        $otherStudent = $this->makeStudent();
        $program = $this->makeProgram();

        $this->makeSubscription($program, $ownStudent, [], $own);
        $this->makeSubscription($program, $otherStudent, [], $other);

        $user = User::findOrFail($own->user_id);
        $this->assignRole($user, 'teacher-linked', [
            'teachers.view', 'students.view', 'lessons.update', 'programs.view',
        ]);

        return [$own, $other, $ownStudent, $otherStudent, $user->fresh()];
    }

    /**
     * عيلتان: parent A × child A، و parent B × child B.
     *
     * @return array{0: \App\Models\Student, 1: \App\Models\Student, 2: User, 3: Teacher|null}
     */
    private function twoFamilies(array $childAAttrs = [], array $pivotA = [], bool $withLesson = false): array
    {
        $childA = $this->makeStudent(array_merge($childAAttrs, ['last_name' => 'A']));
        $childB = $this->makeStudent(['last_name' => 'B']);

        $userA = $this->linkParent($childA, array_merge([
            'can_manage' => true,
            'can_view_finance' => true,
            'can_view_progress' => true,
        ], $pivotA));

        $this->linkParent($childB, ['can_manage' => false]);

        if ($withLesson) {
            $this->makeLesson($this->makeProgram(), $childA, $this->makeActiveTeacher());
        }

        return [$childA, $childB, $userA, null];
    }

    private function linkParent($student, array $pivot = []): User
    {
        $user = $this->makeUser();
        $parent = $this->makeParentProfile($user);

        // ⚠️ `status = verified` **مطلوب** من PR 1.11: الربط اللي
        // محددش أكده = `pending_verification`، والـ`ParentChildScope`
        // بيقفل على المؤكد بس. والـfixture هنا بتمثّل **ربط موظف
        // أكّده** — مش رقم مكرر حد أرسله.
        $student->parents()->attach($parent->id, $pivot + [
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        $this->assignRole($user, 'parent-linked', [
            'students.view', 'students.update', 'finance.payments.view', 'programs.view',
        ]);

        return $user->fresh();
    }

    /**
     * ⭐ دور مستغير من ثابتة: role تُنشأ 1-مرة و تُعيّن للمستخدم.
     *
     * `makeRole` تخلي الصلاحيات المستقرة، الترتيب نفس الذي
     * تعتمده الاختبارات الموجودة (Home، مثال `RoleIsolationTest`).
     */
    private function assignRole(User $user, string $roleName, array $permissions): void
    {
        $this->makeRole($roleName, $permissions);
        $user->assignRole(\Spatie\Permission\Models\Role::where('name', $roleName)->firstOrFail());
    }

    private function twoStudents(): array
    {
        $user = $this->makeUser();
        $own = $this->makeStudent(['user_id' => $user->id]);
        $other = $this->makeStudent();

        $this->assignRole($user, 'student-linked', ['students.view']);

        return [$own, $other, $user->fresh()];
    }

    private function flipParentFlag(User $user, int $studentId, string $flag, bool $value): void
    {
        DB::table('student_parents')
            ->where('parent_id', function ($q) use ($user) {
                $q->select('id')->from('parents')->where('user_id', $user->id);
            })
            ->where('student_id', $studentId)
            ->update([$flag => $value]);
    }

    private function invoiceFor($student): Invoice
    {
        return Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-'.\Illuminate\Support\Str::random(8),
            'total' => 400,
            'paid_amount' => 0,
            'balance_due' => 400,
            'currency' => 'EGP',
            'status' => 'issued',
            'issue_date' => now()->toDateString(),
        ]);
    }
}
