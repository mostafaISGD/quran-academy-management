<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐⭐⭐ منع تصعيد الصلاحيات — «مين يقدر يديّ صلاحيات أكثر من اللي عنده».
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة اللي الاختبار ده بيثبتها
 *
 * `EmployeeController` كان بيقبل `role_id` من الـ request وبيعيّن
 * الدور مباشرة على حساب المستخدم الجديد:
 *
 *     $user->assignRole(Role::find($roleId));
 *
 * ⭐ مفيش أي فحص:
 *   • مفيش `users.roles.assign`
 *   • مفيش مقارنة بين صلاحيات اللي بيعيّن والدور اللي بيعيّنه
 *   • مفيش منع لإعطاء دور `admin`
 *
 * فموظف استقبال — دوره بياخد `students.edit` — كان يقدر يبعت
 * `create_account=true&role_id=<id of admin>` ويعمل **حساب مدير**.
 *
 * ⭐ والأسوأ في `RoleController::updatePermissions`:
 *
 *     $role->syncPermissions($data['permissions']);
 *
 * مفيش حاجة خالص. أي حد عنده `settings.manage` يقدر:
 *   • ياخد صلاحيات من دور المدير
 *   • يدّي دور `teacher` كل صلاحيات النظام
 *   • يعدّل دور `admin` وهو نفسه مش إداري
 *
 * ولا في تسجيل في `audit_logs` لأي من دول.
 */
class PrivilegeEscalationTest extends TestCase
{
    /** صلاحية سِمة شرط التصعيد — موجودة في المصدر الرسمي */
    private const ASSIGN = 'users.roles.assign';

    // ⭐⭐ `makeRole()` و `permission()` اتنقلوا لـ`tests/BuildsFixtures.php`.
    //
    // ⭐ السبب: كان في نسخة تانية في `AccountInvitationTest` سلوكها
    // مختلف — دي بتعمل الصلاحيات لو مش موجودة، التانية كانت
    // بتتوقع موجودة. الاختلاف ده كان كافي يخلّي اختبار تصعيد
    // يعدّي والـ`assertCanAssignRole` ما تشتغلش أصلاً.
    //
    // @see \Tests\BuildsFixtures::makeRole

    // ═══════════════════════════════════════════════════════════════
    // ① EmployeeController — تعيين الدور
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ حالة الاستغلال الأساسية اللي في الخارطة.
     *
     * موظف استقبال (`students.edit` بس — وده اللي الراوت بتطلبه)
     * بيحاول يعمل حساب بدور المدير.
     */
    public function test_a_receptionist_cannot_create_an_employee_with_the_admin_role(): void
    {
        $receptionist = $this->makeUserWithRole('receptionist', [
            'students.update', // ⭐ دي اللي الراوت بتطلبها — ومفيش غيرها
        ]);

        $adminRole = $this->makeRole('admin', [], isSystem: true);

        $this->postJsonAs('/api/employees', [
            'name' => 'موظف للتصعيد',
            'email' => 'sneaky@quran-academy.com',
            'create_account' => true,
            'role_id' => $adminRole->id,
        ], $receptionist)->assertForbidden();
    }

    /**
     * ⭐⭐ والأهم من الـ 403: **محدش اتعمل له حساب أصلاً.**
     *
     * `EmployeeController::store` بيعمل الـ employee والـ user جوه
     * transaction واحدة — فلو الـ role check بره، لازم يتأكد إن
 * ال‏ـ رول‏ـ فحقيلةيا بره،لازم إن‏ـتكبيل،لازم متنفِذت عناجها البداب ديه،والسجلة هينالدل.
     */
    public function test_the_rejected_request_creates_nothing_at_all(): void
    {
        $receptionist = $this->makeUserWithRole('receptionist', ['students.update']);
        $adminRole = $this->makeRole('admin', [], isSystem: true);

        $this->postJsonAs('/api/employees', [
            'name' => 'محاولة تصعيد',
            'email' => 'escalate@quran-academy.com',
            'create_account' => true,
            'role_id' => $adminRole->id,
        ], $receptionist)->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalate@quran-academy.com']);
        $this->assertSame(0, DB::table('employees')->count());

        // ⭐ مش 0 — الـ fixture بيعيّن دور «receptionist» على نفسه،
        // فالمفروض يفضل **1** (دوره هو) وخلاص. مفيش أي حد تاني
        // اتعيّن عليه دور.
        $this->assertSame(1, DB::table('model_has_roles')->count());
        $this->assertSame(0, DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'admin')
            ->count());
    }

    /**
     * ⭐ حد عنده `users.roles.assign` بس **مش** كل الصلاحيات،
     * يقدر يديّ دور فيه صلاحيات هو مش ماسكها.
     *
     * ⭐ ده هو الفحص اللي بيمنع التصعيد **تدريجياً**: لو الأدوار
     * بتتوسّع تدريجياً، محدش يقدر يسلّم دور أقوى من اللي معاه.
     */
    public function test_a_user_cannot_assign_a_role_stronger_than_their_own(): void
    {
        $this->permission('students.view');
        $this->permission('students.update');

        // ⭐⭐ `finance.reverse` هي اللي المانح **مش** ماسكها —
        // وهي أقوى صلاحية مالية. من غيرها الـsubset بيمرّ والاختبار
        // بيفضّل يسقط لسبب غلط.
        $this->permission('finance.movements.reverse');

        $stronger = $this->makeRole('chief_receptionist', [
            'students.view', 'students.update', 'finance.movements.reverse',
        ]);

        // ⭐ الموظف عنده التصريح بس **مش** الصلاحيات اللي في الدور
        $grantor = $this->makeUserWithRole('supervisor', [
            self::ASSIGN,
            // students.edit is what the ROUTE requires - without it the
            // route blocks the request before escalation logic is reached,
            // and the test passes for the wrong reason.
            'students.update',
            'students.view',
        ]);

        $this->postJsonAs('/api/employees', [
            'name' => 'محاولة ترقية',
            'email' => 'promote@quran-academy.com',
            'create_account' => true,
            'role_id' => $stronger->id,
        ], $grantor)->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'promote@quran-academy.com']);
    }

    /**
     * ⭐ ودور `admin` تحديداً — ممنوع يتسلّم لحد مش إداري، حتى
     * لو كان ماسك كل الصلاحيات. القاعدة: `admin` للمديرين بس.
     */
    public function test_the_admin_role_cannot_be_granted_to_a_non_admin_even_with_every_permission(): void
    {
        $this->permission('students.view');
        $adminRole = $this->makeRole('admin', [], isSystem: true);

        // ⭐ عنده التصريح **و** الصلاحية — بس مش إداري
        $grantor = $this->makeUserWithRole('almost_admin', [
            self::ASSIGN, 'students.update', 'students.view',
        ]);

        $this->postJsonAs('/api/employees', [
            'name' => 'مش هينفع',
            'email' => 'not-admin@quran-academy.com',
            'create_account' => true,
            'role_id' => $adminRole->id,
        ], $grantor)->assertForbidden();
    }

    /**
     * ⭐ الاتجاه الصح — الموظف اللي عنده التصريح **و** الصلاحيات
     * يقدر يسجّل موظف بدور أضعف من دوره. من غير الاختبار ده،
     * الإصلاح ممكن يقفل functionality بالكامل.
     */
    public function test_a_user_with_the_permission_can_assign_a_weaker_role(): void
    {
        $this->permission('students.view');
        $weakRole = $this->makeRole('junior', ['students.view']);

        $grantor = $this->makeUserWithRole('supervisor', [
            self::ASSIGN, 'students.update', 'students.view',
        ]);

        $this->postJsonAs('/api/employees', [
            'name' => 'موظف جديد',
            'email' => 'newbie@quran-academy.com',
            'create_account' => true,
            'role_id' => $weakRole->id,
        ], $grantor)->assertCreated();

        $user = User::where('email', 'newbie@quran-academy.com')->firstOrFail();

        $this->assertTrue(
            $user->hasRole('junior'),
            'الموظف اللي عنده التصريح والصلاحيات لازم يقدر يسجّل بدور أضعف'
        );
    }

    /**
     * ⭐ من غير `role_id` خالص — إنشاء موظف عادي لازم يفضل شغّال.
     * الفحص لازم يبقى على **وجود** الدور في الطلب، مش على المسار
     * نفسه — وإلا هنقفل إنشاء الموظفين كلهم.
     */
    public function test_creating_an_employee_without_a_role_still_works(): void
    {
        $receptionist = $this->makeUserWithRole('receptionist', ['students.update']);

        $this->postJsonAs('/api/employees', [
            'name' => 'موظف عادي',
        ], $receptionist)->assertCreated();
    }

    /**
     * ⭐ نفس الفحص على `update` — الكود المكرر كان فيه نفس الباج.
     */
    public function test_updating_an_employees_role_is_guarded_too(): void
    {
        $this->permission('students.view');
        $adminRole = $this->makeRole('admin', [], isSystem: true);

        $receptionist = $this->makeUserWithRole('receptionist', ['students.update']);
        $employee = $this->makeEmployee(['name' => 'موظف موجود']);

        $this->putJsonAs("/api/employees/{$employee->id}", [
            'role_id' => $adminRole->id,
        ], $receptionist)->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // ② RoleController::updatePermissions
    // ═══════════════════════════════════════════════════════════════

    public function test_the_admin_role_permissions_cannot_be_modified(): void
    {
        $this->permission('students.view');

        // ⭐ لازم يكون عنده صلاحية **قبل** التعديل — من غيرها الـ
        // assertion التانية هتفضّل حتى لو الكود مسح الدور كله.
        $adminRole = $this->makeRole('admin', ['students.view'], isSystem: true);

        $attacker = $this->makeUserWithRole('attacker', ['settings.update', self::ASSIGN]);

        // ⚡ PR 1.9: نأكد كلمة المرور أولاً — عشان الـ403 ده يبقى من
        // قاعدة «أدوار النظام مقفولة» مش من إعادة التأكيد

        $this->putJsonAs("/api/roles/{$adminRole->id}/permissions", [
            'permissions' => [$this->permission('students.view')->id],
        ], $attacker)->assertForbidden();

        $this->assertNotEmpty(
            $adminRole->fresh()->permissions,
            'دور admin اتفقدّ صلاحياته — ممنوع تعديل دور النظام'
        );
    }

    public function test_update_permissions_rejects_granting_more_than_the_grantor_holds(): void
    {
        $this->permission('students.view');
        $this->permission('students.delete');

        $target = $this->makeRole('junior', []);

        // ⭐ ماسك `students.view` بس، وبيحاول يديّ `students.delete`
        $grantor = $this->makeUserWithRole('supervisor', [
            'settings.update', self::ASSIGN, 'students.view',
        ]);

        $deleteId = Permission::where('name', 'students.delete')->value('id');

        // ⚡ PR 1.9: نأكد أولاً — عشان الرفض ده يجي من قاعدة «ممنوع
        // تسلّم اللي مش ماسكه» مش من إعادة التأكيد

        $this->putJsonAs("/api/roles/{$target->id}/permissions", [
            'permissions' => [$deleteId],
        ], $grantor)->assertForbidden();

        $this->assertSame(
            0,
            $target->fresh()->permissions()->count(),
            'الدور اتغيّر رغم الرفض — الـ transaction لازم يرجع كل حاجة'
        );
    }

    /**
     * ⭐ الاتجاه الصح — دور مش نظام، وبصلاحيات داخل اللي معاه.
     */
    public function test_updating_a_normal_role_within_the_grantor_limits_works(): void
    {
        $this->permission('students.view');
        $this->permission('students.update');

        $target = $this->makeRole('junior', []);

        $grantor = $this->makeUserWithRole('supervisor', [
            'settings.update', self::ASSIGN, 'students.view', 'students.update',
        ]);

        $viewId = Permission::where('name', 'students.view')->value('id');


        $this->putJsonAs("/api/roles/{$target->id}/permissions", [
            'permissions' => [$viewId],
        ], $grantor)->assertOk();

        $this->assertSame(1, $target->fresh()->permissions()->count());
    }

    /**
     * ⭐⭐ تسجيل العملية — «مين غيّر إيه، وإزاي».
     */
    public function test_every_permission_change_is_written_to_the_audit_log(): void
    {
        $this->permission('students.view');
        $this->permission('students.update');

        $target = $this->makeRole('junior', []);

        $grantor = $this->makeUserWithRole('supervisor', [
            'settings.update', self::ASSIGN, 'students.view',
        ]);

        $viewId = Permission::where('name', 'students.view')->value('id');


        $this->putJsonAs("/api/roles/{$target->id}/permissions", [
            'permissions' => [$viewId],
        ], $grantor)->assertOk();

        $log = DB::table('audit_logs')
            ->where('action', 'update')
            ->where('entity_type', 'role')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'تغيير صلاحيات دور لازم يتسجل في audit_logs');

        $this->assertSame($grantor->id, $log->user_id, 'مين عمل التغيير لازم يتسجل');

        // ⭐ قبل وبعد — عشان نعرف إيه اللي اتغيّر بالظبط
        $this->assertNotNull($log->old_value, 'الحالة قبل التغيير لازم تتسجل');
        $this->assertNotNull($log->new_value, 'الحالة بعد التغيير لازم تتسجل');

        $this->assertStringContainsString('junior', json_encode($log->new_value));
    }

    /**
     * «حد حاول يعدّل، وما نجحش».
     * من غير كده مقدرش نعرف إن في حد بيحاول يصعّد.
     */
    public function test_a_rejected_change_is_also_logged(): void
    {
        $this->permission('students.view');
        $this->permission('students.delete');

        $target = $this->makeRole('junior', []);
        $grantor = $this->makeUserWithRole('supervisor', [
            'settings.update', self::ASSIGN, 'students.view',
        ]);

        $deleteId = Permission::where('name', 'students.delete')->value('id');

        // قاعدة التصعيد نفسها (denied audit) مش من إعادة التأكيد

        $this->putJsonAs("/api/roles/{$target->id}/permissions", [
            'permissions' => [$deleteId],
        ], $grantor)->assertForbidden();

        $this->assertTrue(
            DB::table('audit_logs')->where('action', 'denied')->exists(),
            'محاولة التعديل المرفوضة لازم تتسجل — عشان نعرف حد بيحاول يصعّد'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ RoleController::store
    // ═══════════════════════════════════════════════════════════════

    public function test_a_duplicate_role_slug_is_rejected(): void
    {
        $this->makeRole('head_of_students', []);

        $admin = $this->makeUserWithRole('admin', ['settings.update', self::ASSIGN]);


        $this->postJsonAs('/api/roles', [
            'name' => 'مسؤول طلاب آخر',
            'slug' => 'head_of_students',
        ], $admin)->assertStatus(422);
    }

    /**
     * ⭐⭐ اسم دور نظام جديد = تصعيد متخفّي.
     *
     * لو حد عمل دور slug=`admin` واسمه «مسؤول الطلاب»، الـ API
     * هيرجّعه كأنه `admin` في أي مقارنة بالاسم.
     */
    public function test_a_system_role_name_cannot_be_reused_by_a_new_role(): void
    {
        $this->makeRole('admin', [], isSystem: true);

        $admin = $this->makeUserWithRole('admin', ['settings.update', self::ASSIGN]);


        $this->postJsonAs('/api/roles', [
            'name' => 'admin',
            'slug' => 'admin',
        ], $admin)->assertStatus(422);

        $this->postJsonAs('/api/roles', [
            'name' => 'مسؤول الطلاب',
            'slug' => 'admin-lookalike',
        ], $admin)->assertCreated();

        // ⭐ بس لازم يفضل **مش** نظام — عشان متقدرش تعدّل صلاحياته بعدين
        //
        // ⚠️ `value()` بترجّع `0` مش `false` من SQLite،
        // و`assertFalse(0)` بيفشل. بن cast صريح.
        $this->assertNotTrue(
            (bool) Role::where('name', 'مسؤول الطلاب')->value('is_system'),
            'دور جديد لازم ما يبقاش نظام — وإلا بقى محمي من التعديل زي الأدوار الفعلية'
        );
    }

    public function test_a_normal_role_can_still_be_created(): void
    {
        $admin = $this->makeUserWithRole('admin', ['settings.update', self::ASSIGN]);


        $this->postJsonAs('/api/roles', [
            'name' => 'مشرف الفرقة',
            'slug' => 'team_lead',
        ], $admin)->assertCreated();
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ البوابة نفسها موجودة في المصدر الرسمي
    // ═══════════════════════════════════════════════════════════════

    public function test_the_assignment_permission_is_in_the_official_catalog(): void
    {
        $this->assertContains(
            self::ASSIGN,
            array_column(\Database\Seeders\SeedRolesPermissions::permissions(), 'name'),
            'صلاحية users.roles.assign لازم تكون متعرّفة في المصدر الرسمي'
        );
    }
}
