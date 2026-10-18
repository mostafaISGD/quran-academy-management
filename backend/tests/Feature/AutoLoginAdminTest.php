<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

/**
 * ⭐ وضع المستخدم الواحد — الدخول تلقائي للأدمن.
 *
 * القرار: برنامج بمستخدم واحد هو الأدمن. مافيش صفحة دخول، مافيش
 * دعوات، مافيش استعادة كلمة، مافيش 2FA.
 *
 * ⭐ والاختبارات دي بتثبت إن القرار ده **مكسّرش** حاجة قائمة:
 *
 *   ① كل راو محمي بيرجّع 200 من غير أي جلسة ولا توكن.
 *   ② المستخدم الداخل هو الأدمن، وصلاحياته كل حاجة.
 *   ③ لو القاعدة فاضية، الـmiddleware بيعمل الأدمن لوحده.
 */
class AutoLoginAdminTest extends TestCase
{
    public function test_a_protected_route_answers_without_any_session(): void
    {
        // ⭐ الـfixture بيعمل الدور بصلاحيات **صريحة** (مش '*' زي
        // الـseeder)، فبنمرّر اللي هنحتاجه بالاسم.
        $admin = $this->makeUserWithRole('admin', [
            'programs.view', 'settings.manage', 'finance.movements.reverse',
        ]);

        // ⭐ مافيش `authHeaders` — مافيش توكن، مافيش جلسة
        $this->getJson('/api/programs')->assertOk();

        $r = $this->getJson('/api/auth/me');

        $r->assertOk();
        $this->assertSame($admin->id, $r->json('id'));
        $this->assertSame('admin', $r->json('roles.0'));
    }

    public function test_the_authenticated_user_is_the_admin_with_all_permissions(): void
    {
        $this->makeUserWithRole('admin', [
            'programs.view', 'settings.manage', 'finance.movements.reverse',
        ]);

        $r = $this->getJson('/api/auth/me');

        $r->assertOk();

        // ⭐ دور `admin` = '*' يعني كل الصلاحيات (SeedRolesPermissions)
        $this->assertContains('programs.view', (array) $r->json('permissions'));
        $this->assertContains('settings.manage', (array) $r->json('permissions'));
        $this->assertContains('finance.movements.reverse', (array) $r->json('permissions'));
    }

    public function test_the_first_user_is_the_one_who_gets_logged_in(): void
    {
        $first = $this->makeUser('الأول', 'first@example.test');

        $this->makeUserWithRole('someone', ['programs.view']);

        $r = $this->getJson('/api/auth/me');

        $r->assertOk();

        $this->assertSame($first->id, $r->json('id'));
        $this->assertSame('first@example.test', $r->json('email'));
    }

    /**
     * ⭐ اللي **مش** بتغطيه الاختبارات دي: مصفوفة الصلاحيات
     * نفسها. دي شغالة في `RouteProtectionTest` (كل راو جوه
     * `auth:sanctum` لازم عليه `permission:`)، ومعاها شغالة عادي
     * — الدخول التلقائي بيملا `request->user()` بس.
     *
     * ⚠️ ومستخدم واحد بـ`admin` (كل الصلاحيات) فطبيعي إن **كل**
     * الراوتات تفتح. لو بعّد اللي فوق —Roles بعد كده، الفحص
     * بيرجع يشتغل زي أوله من غير تعديل.
     */
public function test_the_admin_can_reach_a_settings_route(): void
    {
        $this->makeUserWithRole('admin', ['settings.view']);

        // ⭐ قبل الكسر ده كان 403 لمحد مش ماسك الصلاحية — بعد
        // الوضع الجديد، الأدمن ماسك كل حاجة فبيعدّي عادي.
        $this->getJson('/api/settings')->assertOk();
    }

    public function test_an_empty_database_still_gets_one_admin(): void
    {
        // ⭐ مافيش seeder اتشغّل — الـmiddleware نفسه يعمل
        // المستخدم الوحيد عشان الـAPI ما يبقاش 401 على كل حاجة.
        $this->assertSame(0, User::count());

        $r = $this->getJson('/api/auth/me');

        $r->assertOk();
        $r->assertJsonPath('email', 'admin@academy.test');
        $r->assertJsonPath('roles.0', 'admin');

        $this->assertSame(1, User::count());
    }
}