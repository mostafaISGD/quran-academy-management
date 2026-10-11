<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **حارس هوية المستخدم — «المعلم» مقابل «فيه سجل معلم».**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ المشكلة اللي existed
 *
 * في `AuthController` كان مكتوب:
 *
 *     'is_teacher' => $user->teacher !== null,
 *
 * ⭐ و`is_teacher` معناها في الواجهة «المستخدم **بيشتغل** معلم»:
 *
 *     const isTeacherOnly = Boolean(user?.is_teacher && user?.teacher);
 *     if (isTeacherOnly && pathname !== homeFor) router.replace(homeFor);
 *
 * ⭐ يعني أي `user_id` مربوط بسجل `teachers` كان بيحسب معلم.
 *
 * ⭐⭐⭐ النتيجة الفعلية في القاعدة:
 *
 *     admin@quran-academy.com → مربوط بمعلم رقم 1
 *
 * ⭐ النتيجة: **حساب المدير كان بيتحوّل لواجهة المعلم** عند أي
 * رابط إداري — مش بيوصل /students ولا /accounts ولا /payroll.
 * وميشوفش الـ sidebar أصلاً.
 *
 * ⭐ والأسوأ إن ده **صامت**: الصفحة بتفتح بس على المسار الغلط،
 * ومفيش أي رسالة خطأ.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ القاعدة الصح
 *
 * **الدور** هو اللي بيقول الشخص ده معلم ولا إداري. ربط السجل
 * في `teachers` بيقول «فيه بيانات عنه» بس — مش وظيفته.
 *
 * @see \App\Http\Controllers\Api\AuthController
 */
class AuthIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function loginAs(\App\Models\User $user): array
    {
        $res = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $res->assertOk();

        return $res->json();
    }

    // ============================================================
    // ① ⭐⭐⭐ الاختبار اللي بيحمي من الـ redirect
    // ============================================================

    public function test_an_admin_linked_to_a_teacher_record_is_not_a_teacher(): void
    {
        /**
         * ⭐⭐⭐ **الاختبار اللي كان ناقص.**
         *
         * الحالة الحقيقية: المدير مربوط بمعلم. قبل الإصلاح كان
         * is_teacher = true → الواجهة بتوديه /my-schedule →
         * **مش بيوصل أي صفحة إدارية**.
         */
        $admin = $this->makeUserWithRole('admin');

        // ⭐ الربط موجود فعلاً — زي القاعدة بالظبط
        $this->makeTeacher($admin);

        $body = $this->loginAs($admin);

        $this->assertFalse(
            $body['user']['is_teacher'],
            'المدير مربوط بمعلم بس هو إداري — مش معلم'
        );

        $isTeacherOnly = $body['user']['is_teacher'] && $body['user']['teacher'] !== null;

        $this->assertFalse($isTeacherOnly, 'الواجهة لازم تشوفه إداري مش معلم');
    }

    public function test_an_admin_keeps_the_teacher_payload_but_not_the_identity(): void
    {
        /**
         * ⭐ نقطة دقيقة: الإصلاح مسّ is_teacher بس.
         *
         * بيانات المعلم (teacher) لسه بتتبعت — عشان صفحة المعلمين
         * تعرض الصورة والاسم صح لو المدير فتح ملف معلم.
         *
         * ⭐ لو شلنا الـ payload كمان، صورة المعلم هتختفي من
         * الشاشة. الفصل لازم يكون في **الهوية** بس.
         */
        $admin = $this->makeUserWithRole('admin');
        $teacher = $this->makeTeacher($admin);

        $body = $this->loginAs($admin);

        $this->assertFalse($body['user']['is_teacher']);
        $this->assertNotNull($body['user']['teacher'], 'بيانات المعلم لسه بتيجي');
        $this->assertSame($teacher->id, $body['user']['teacher']['id']);
    }

    // ============================================================
    // ② المعلم الحقيقي لسه معلم
    // ============================================================

    public function test_a_teacher_without_admin_role_is_still_a_teacher(): void
    {
        /**
         * ⭐⭐⭐ **الاختبار المقابل — نتأكد إننا مش كسرنا المعلم.**
         *
         * الإصلاح كان && !$user->hasRole('admin'). فلو شلنا
         * الشرط ده بالغلط، المعلم الحقيقي هيخسر واجهته.
         */
        $user = $this->makeUserWithRole('teacher');
        $teacher = $this->makeTeacher($user);

        $body = $this->loginAs($user);

        $this->assertTrue($body['user']['is_teacher'], 'المعلم لسه معلم');
        $this->assertNotNull($body['user']['teacher'], 'بيانات المعلم موجودة');
        $this->assertSame($teacher->id, $body['user']['teacher']['id']);
    }

    public function test_a_teacher_record_without_a_user_account_is_irrelevant(): void
    {
        /**
         * ⭐ معلم مش مربوط بحساب أصلاً — الـ API بيحكي عن
         * **المستخدم** مش عن المعلمين، فمفيش أثر.
         */
        $this->makeTeacher(null, ['full_name' => 'معلم بلا حساب']);

        $admin = $this->makeUserWithRole('admin');

        $body = $this->loginAs($admin);

        $this->assertFalse($body['user']['is_teacher']);
    }

    // ============================================================
    // ③ باقي الأدوار
    // ============================================================

    public function test_a_staff_account_is_neither_teacher_nor_parent(): void
    {
        $staff = $this->makeUserWithRole('receptionist');

        $body = $this->loginAs($staff);

        $this->assertFalse($body['user']['is_teacher']);
        $this->assertFalse($body['user']['is_parent']);
    }

    public function test_a_parent_is_a_parent_but_not_a_teacher(): void
    {
        $parent = $this->makeUserWithRole('parent');
        $parent->update(['is_parent' => true]);

        $body = $this->loginAs($parent);

        $this->assertTrue($body['user']['is_parent']);
        $this->assertFalse($body['user']['is_teacher']);
    }
}