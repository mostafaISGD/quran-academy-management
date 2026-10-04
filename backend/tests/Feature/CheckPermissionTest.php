<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * الـ middleware نفسه — سلوكه مع الصلاحيات الناقصة.
 *
 * الباج ده اتكتشف بالاختبارات: `hasPermissionTo()` بترمي استثناء
 * لو الصلاحية مش موجودة في جدول permissions، فالمسار كان بيرجّع
 * **500** بدل 403.
 *
 * ليه ده مهم في الإنتاج: لو حد أضاف route بـ صلاحية جديدة ونسي
 * يضيفها للـ seeder، **كل المستخدمين** يشوفوا Internal Server Error
 * بدل «غير مصرح» — والخطأ في اللوج بيتكلم عن permission مفقودة،
 * مش عن مشكلة في صلاحيات المستخدم.
 */
class CheckPermissionTest extends TestCase
{
    private function program()
    {
        return $this->makeProgram();
    }

    public function test_missing_permission_in_the_database_returns_403_not_500(): void
    {
        $user = $this->makeUserWithRole('nobody', ['programs.view']);

        // مسار الكتابة محمي بـ settings.manage — وهي **غير موجودة**
        // في داتابيز الاختبار (Fixtures بتعمل اللي الطلبه بس)
        $this->assertDatabaseMissing('permissions', ['name' => 'settings.manage']);

        $this->postJsonAs('/api/programs', ['name' => 'محظور'], $user)->assertForbidden();
    }

    public function test_missing_permission_does_not_block_valid_reads(): void
    {
        $user = $this->makeUserWithRole('viewer', ['programs.view']);
        $this->makeProgram();

        // القراءة لازم تفضل شغالة حتى لو في صلاحيات ناقصة في الداتابيز
        $this->getJsonAs('/api/programs', $user)->assertOk();
    }

    /**
     * حد مش مسجّل = 401 (مش 403).
     *
     * الـ 403 معناها «عارفك بس مش مسموح». الـ 401 معناها «مش عارفك».
     * وLaravel بيرجّع 401 فعلاً بشرط الـ `Accept: application/json` —
     * من غيره بيحاول يوجّه لـ `login` وديدور مش معرّف، فيبقى 500.
     */
    public function test_unauthenticated_is_401_json_not_a_redirect(): void
    {
        $r = $this->getJson('/api/programs');

        $r->assertStatus(401);
        $this->assertSame('Unauthenticated.', $r->json('message'));

        // وده اللي مهم: من غير Accept: application/json، الوسيط
        // بيحاول يوجّه لـ login — وده بيولّد 500 في تطبيق API
        $html = $this->get('/api/programs', ['Accept' => 'text/html']);
        $html->assertStatus(500);
    }

    public function test_role_without_the_permission_is_denied(): void
    {
        $user = $this->makeUserWithRole('viewer', ['programs.view']);

        $this->postJsonAs('/api/programs', ['name' => 'محظور'], $user)->assertForbidden();
    }

    public function test_permission_grows_with_the_role(): void
    {
        $user = $this->makeUserWithRole('editor', ['programs.view']);
        $this->assertFalse($user->fresh()->can('lessons.edit'));

        \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'lessons.edit', 'guard_name' => 'web'],
            ['slug' => 'lessons-edit', 'module' => 'lessons', 'guard_name' => 'web'],
        );
        $user->roles->first()->givePermissionTo('lessons.edit');

        $this->assertTrue($user->fresh()->can('lessons.edit'), 'الصلاحية الجديدة لازم تشتغل فوراً');
    }
}