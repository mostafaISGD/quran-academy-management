<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * حدود الصلاحيات على شاشة البرامج.
 *
 * ⚠️ ليه الملف ده حسّاس؟
 *
 * قبل شغل شاشات البرامج، البرامج كلها كانت على `settings.manage`
 * = مدير النظام بس. يعني **المعلم** اللي بيشوف جدول حصصه — واللي
 * الحصة دي في برنامج — لو ضغط على اسم البرنامج كان هيلاقي 403.
 *
 * والأسوأ: المعلمين وأولياء الأمور ماكانوش عندهم **أي دور** أصلاً،
 * فكانوا محجوبين عن كل route عليه `permission:` — وده ماكانش خطأ
 * في أي سطر، مجرد|data ناقصة في الـ seed.
 *
 * الاختبارات دي بتحمي الشكل الصح: **قراءة مفتوحة، كتابة مقفولة**.
 */
class ProgramPermissionTest extends TestCase
{
    /** أدمن بكل حاجة */
    private function admin()
    {
        return $this->makeUserWithRole('admin', [
            'programs.view', 'lessons.view', 'lessons.edit', 'settings.manage',
        ]);
    }

    /** معلم — دوره الحقيقي في النظام: programs.view بس */
    private function teacher()
    {
        return $this->makeUserWithRole('teacher', ['programs.view']);
    }

    /** ولي أمر — دوره الحقيقي: programs.view بس */
    private function parent()
    {
        return $this->makeUserWithRole('parent', ['programs.view']);
    }

    /** موظف استقبال — يشوف البرامج عشان يرد على أولياء الأمور */
    private function receptionist()
    {
        return $this->makeUserWithRole('receptionist', [
            'students.view', 'programs.view',
        ]);
    }

    // ============================================================
    // الأدمن
    // ============================================================

    public function test_admin_can_do_everything(): void
    {
        $admin = $this->admin();
        $program = $this->makeProgram();

        $this->getJsonAs('/api/programs', $admin)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}", $admin)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}/levels", $admin)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}/students", $admin)->assertOk();
        $this->getJsonAs('/api/program-categories', $admin)->assertOk();

        $this->postJsonAs('/api/programs', ['name' => 'برنامج جديد'], $admin)->assertCreated();
        $this->putJsonAs("/api/programs/{$program->id}", ['name' => 'معدّل'], $admin)->assertOk();
        $this->deleteJsonAs("/api/programs/{$program->id}", $admin)->assertOk();
    }

    // ============================================================
    // المعلم — يقرأ، ما بيكتبش
    // ============================================================

    public function test_teacher_can_read_programs(): void
    {
        // السبب: المعلم لازم يعرف بيحضّر إيه. لو ما قدرش يفتح
        // البرنامج، بيانات المعلم نفسه تبقى ناقصة.
        $teacher = $this->teacher();
        $program = $this->makeProgram();
        $this->makeLevel($program);
        $this->linkTeacher($program, $this->makeTeacher());

        $this->getJsonAs('/api/programs', $teacher)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}", $teacher)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}/teachers", $teacher)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}/levels", $teacher)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}/students", $teacher)->assertOk();
        $this->getJsonAs('/api/program-categories', $teacher)->assertOk();
    }

    public function test_teacher_cannot_write_programs(): void
    {
        $teacher = $this->teacher();
        $program = $this->makeProgram();

        $this->postJsonAs('/api/programs', ['name' => 'محظور'], $teacher)->assertForbidden();
        $this->putJsonAs("/api/programs/{$program->id}", ['name' => 'محظور'], $teacher)->assertForbidden();
        $this->deleteJsonAs("/api/programs/{$program->id}", $teacher)->assertForbidden();

        $this->postJsonAs("/api/programs/{$program->id}/levels", ['name' => 'محظور'], $teacher)->assertForbidden();
        $this->postJsonAs("/api/programs/{$program->id}/teachers", ['teacher_id' => 1], $teacher)->assertForbidden();
        $this->postJsonAs("/api/programs/{$program->id}/teachers/link-missing", [], $teacher)->assertForbidden();
        $this->postJsonAs("/api/programs/{$program->id}/teachers/unlink-idle", [], $teacher)->assertForbidden();

        $this->postJsonAs('/api/program-categories', ['name' => 'محظور'], $teacher)->assertForbidden();
    }

    /**
     * ⚠️ حارس ضدRegression خطير.
     *
     * لو حد غيّر مسارات القراءة لـ `lessons.view` بدل
     * `programs.view` عشان «يوفّر صلاحية جديدة»، المعلم هياخد
     * 403 على البرنامج تاني. الاختبار ده بيقفل الباب ده.
     */
    public function test_program_reads_do_not_require_the_lessons_permission(): void
    {
        $teacher = $this->teacher();
        $program = $this->makeProgram();

        // لو مسار القراءة بقى على lessons.view، دي هترجّع 403
        $this->getJsonAs('/api/programs', $teacher)->assertOk();

        // وحتى ما نسيناش: المعلم ما عندوش lessons.view، فلو
        // استعملناها للقراءة، كل الـ endpoint هتقفل عليه
        $teacher->revokePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'lessons.view', 'guard_name' => 'web'],
            ['slug' => 'lessons-view', 'module' => 'lessons', 'guard_name' => 'web'],
        ));
        $teacher->load('roles.permissions');

        $this->getJsonAs("/api/programs/{$program->id}", $teacher)->assertOk(
            'مسار القراءة لازم يعتمد على programs.view بس'
        );
    }

    // ============================================================
    // ولي الأمر
    // ============================================================

    public function test_parent_can_read_programs_but_not_write(): void
    {
        $parent = $this->parent();
        $program = $this->makeProgram();

        // ولي الأمر محتاج يشوف المتاح للتسجيل وبكام
        $this->getJsonAs('/api/programs', $parent)->assertOk();
        $this->getJsonAs("/api/programs/{$program->id}", $parent)->assertOk();

        // بس ما بيعدّلش
        $this->postJsonAs('/api/programs', ['name' => 'محظور'], $parent)->assertForbidden();
        $this->putJsonAs("/api/programs/{$program->id}", ['name' => 'محظور'], $parent)->assertForbidden();
        $this->deleteJsonAs("/api/programs/{$program->id}", $parent)->assertForbidden();
    }

    // ============================================================
    // موظف الاستقبال
    // ============================================================

    public function test_receptionist_can_read_but_not_write(): void
    {
        $receptionist = $this->receptionist();
        $program = $this->makeProgram();

        $this->getJsonAs('/api/programs', $receptionist)->assertOk();
        $this->postJsonAs('/api/programs', ['name' => 'محظور'], $receptionist)->assertForbidden();
    }

    // ============================================================
    // مفيش صلاحية = ممنوع
    // ============================================================

    public function test_user_without_any_role_cannot_read_programs(): void
    {
        $nobody = $this->makeUser();

        $this->getJsonAs('/api/programs', $nobody)->assertForbidden();
        $this->getJsonAs('/api/program-categories', $nobody)->assertForbidden();
    }

    /**
     * الدرس اللي اتعلّمناه: دور ناقص = النظام مكسور بصمت.
     *
     * لو الـ seeder نسي يعمل دور `teacher`، كل الـ endpoints
     * هترجّع 403 من غير أي رسالة واضحة. الاختبار ده بيقول
     * «المعلم لازم يكون عنده دور» صراحةً.
     */
    public function test_teachers_and_parents_have_roles_in_the_real_seed(): void
    {
        $seeder = new \Database\Seeders\SeedRolesPermissions();

        // في الـ seeder ده بيشتغل على الداتابيز، فالمهم نتحقق إنه
        // بيعمل الدورين أصلاً
        $source = file_get_contents(database_path('seeders/SeedRolesPermissions.php'));

        $this->assertStringContainsString(
            "'teacher', 'guard_name' => 'web'",
            $source,
            'الـ seeder مابيشتغلش دور teacher — المعلم هيبقى محجوب عن كل حاجة'
        );
        $this->assertStringContainsString(
            "'parent', 'guard_name' => 'web'",
            $source,
            'الـ seeder مابيشتغلش دور parent — ولي الأمر هيبقى محجوب'
        );

        $this->assertInstanceOf(\Database\Seeders\SeedRolesPermissions::class, $seeder);
    }
}