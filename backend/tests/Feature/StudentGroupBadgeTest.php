<?php

namespace Tests\Feature;

use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\Student;
use Tests\TestCase;

/**
 * ⭐ شارة «طالب مجموعة» في صفحة الطلاب.
 *
 * ليه مهمة؟
 *
 * الـ Student مالوش حالة «في مجموعة». الحالة في
 * `group_members.status`. فلو ما bindناهاش، صفحة الطلاب مش
 * هتعرف مين فصل ومين مجموعة — والأدمن بيفتكر إن كل الطلاب
 * خاص وبيدفع فاتورتين.
 *
 * ⭐ الاختبارات دي بتقفل حاجتين:
 *  ① اللي **داخل** group'sها في الرد
 *  ② اللي **خرج** ما يظهرش — دي اللي كنت هغلط فيها
 */
class StudentGroupBadgeTest extends TestCase
{
    private function admin()
    {
        return $this->makeUserWithRole('admin', ['students.view', 'groups.manage', 'groups.view']);
    }

    /** ⭐ لازم يبقى في الـ responseShape اللي الواجهة بتقراه */
    private function findStudentInResponse($res, int $studentId): ?array
    {
        foreach ($res->json('data') as $row) {
            if ((int) ($row['id'] ?? 0) === $studentId) {
                return $row;
            }
        }

        return null;
    }

    public function test_a_student_in_a_group_is_flagged_with_the_group_name(): void
    {
        $group = $this->makeGroup();
        $student = $this->makeStudent();
        GroupMember::admit($group, $student, 'manual');

        $r = $this->getJsonAs('/api/students?per_page=100', $this->admin());
        $r->assertOk();

        $row = $this->findStudentInResponse($r, $student->id);

        $this->assertNotNull($row);
        $this->assertTrue($row['is_group_student']);
        $this->assertSame($group->id, $row['group_id']);
        $this->assertSame($group->name, $row['group_name']);
    }

    public function test_a_private_student_is_not_flagged(): void
    {
        $student = $this->makeStudent();

        $r = $this->getJsonAs('/api/students?per_page=100', $this->admin());
        $row = $this->findStudentInResponse($r, $student->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['is_group_student']);
        $this->assertNull($row['group_name']);
    }

    public function test_a_student_who_left_the_group_is_not_flagged(): void
    {
        // ⭐⭐ الأهم. السطر بيفضل في الجدول (تاريخ)، بس الطالب
        // **مش** في المجموعة. لو ما فلترناش `status = active`
        // كان هيظهر عليه «في مجموعة» وهو خرج من شهر.
        $group = $this->makeGroup();
        $student = $this->makeStudent();

        $member = GroupMember::admit($group, $student, 'manual');
        $member->markLeft('مش مناسب');

        $r = $this->getJsonAs('/api/students?per_page=100', $this->admin());
        $row = $this->findStudentInResponse($r, $student->id);

        $this->assertFalse($row['is_group_student'], 'الطالب اللي خرج ما ينفعش يبقى عليه شارة');
        $this->assertNull($row['group_name']);

        // ⭐ بس الستار نفسه بيفضل — التاريخ محفوظ
        $this->assertDatabaseHas('group_members', [
            'id' => $member->id,
            'status' => 'left',
        ]);
    }

    public function test_a_student_who_came_back_is_flagged_again(): void
    {
        $group = $this->makeGroup();
        $student = $this->makeStudent();

        $member = GroupMember::admit($group, $student, 'manual');
        $member->markLeft();
        GroupMember::admit($group, $student, 'manual');

        $r = $this->getJsonAs('/api/students?per_page=100', $this->admin());
        $row = $this->findStudentInResponse($r, $student->id);

        $this->assertTrue($row['is_group_student']);
    }

    public function test_the_flag_shows_the_group_they_are_in_right_now(): void
    {
        // ⭐ نقل student من مجموعة للتانية — الشارة لازم تيجي على
        // التانية، مش تفضل على الأولى.
        $from = $this->makeGroup();
        $to = $this->makeGroup();
        $student = $this->makeStudent();

        $member = GroupMember::admit($from, $student, 'manual');
        $member->markLeft('نقل');
        GroupMember::admit($to, $student, 'manual');

        $r = $this->getJsonAs('/api/students?per_page=100', $this->admin());
        $row = $this->findStudentInResponse($r, $student->id);

        $this->assertTrue($row['is_group_student']);
        $this->assertSame($to->id, $row['group_id']);
        $this->assertSame($to->name, $row['group_name']);
    }

    public function test_the_badge_does_not_need_the_groups_permission(): void
    {
        // ⭐ `students.view` بس. الشارة جزء من بيانات الطالب
        // نفسها — لو احنا شِلناها، الموظف في الاستقبال مش هيعرف
        // مين طالب مجموعة.
        $group = $this->makeGroup();
        $student = $this->makeStudent();
        GroupMember::admit($group, $student, 'manual');

        $user = $this->makeUserWithRole('receptionist', ['students.view']);

        $r = $this->getJsonAs('/api/students?per_page=100', $user);

        $r->assertOk();
        $row = $this->findStudentInResponse($r, $student->id);
        $this->assertTrue($row['is_group_student']);
    }

    public function test_the_flag_survives_the_student_search(): void
    {
        $group = $this->makeGroup();
        $student = $this->makeStudent(['first_name' => 'مميز']);
        GroupMember::admit($group, $student, 'manual');
        $this->makeStudent(['first_name' => 'عادي']);

        $r = $this->getJsonAs('/api/students?search='.urlencode('مميز'), $this->admin());

        $r->assertOk();
        $r->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.is_group_student', true);
        $r->assertJsonPath('data.0.group_name', $group->name);
    }

    /**
     * ⭐⭐ ترتيب `toArray()` والـ transform.
     *
     * كان الترتيب **معكوس**: `toArray()` بتاخد نسخة قبل ما
     * الـ `transform` يعدّل الموديلات. فكل الحقول المحسوبة
     * كانت بتضيع — مش الشارة بس، كمان `parent_name` و
     * `primary_phone` من زمان وصفحة الطلاب بتعرضهم فاضيين.
     *
     * الاختبار ده بيقفل الترتيب عشان مترجعش تاني.
     */
    public function test_the_computed_fields_really_reach_the_response(): void
    {
        $group = $this->makeGroup();
        $student = $this->makeStudent();
        GroupMember::admit($group, $student, 'manual');

        $r = $this->getJsonAs('/api/students?per_page=100', $this->admin());
        $row = $this->findStudentInResponse($r, $student->id);

        $this->assertNotNull($row);

        foreach (['is_group_student', 'group_name', 'group_id', 'parent_name', 'primary_phone'] as $key) {
            $this->assertArrayHasKey($key, $row, "الحقل «{$key}» مش في الرد");
        }

        // ⭐ `primary_phone` بيبقى **object** (العلاقة `StudentPhone`)
        // مش نص — فبنتحقق إنه موجود، إما object أو null
        $this->assertTrue(
            is_null($row['primary_phone']) || is_array($row['primary_phone']),
            'primary_phone المفروض object أو null'
        );
    }

    public function test_the_counts_block_survives_too(): void
    {
        // ⭐ `counts` كانت بتتحسب قبل الـ transform كمان.
        $this->makeStudent(['status' => 'active']);
        $this->makeStudent(['status' => 'active']);
        $this->makeStudent(['status' => 'paused']);

        $r = $this->getJsonAs('/api/students?per_page=100', $this->admin());

        $r->assertOk();
        $r->assertJsonStructure(['data', 'counts']);
        $r->assertJsonPath('counts.active', 2);
        $r->assertJsonPath('counts.paused', 1);
    }
}