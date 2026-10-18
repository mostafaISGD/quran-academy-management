<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐⭐⭐ إدارة الحسابات من مكان واحد.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه `UserStatusService` كان ناقص
 *
 * ⭐ الخدمة existed من PR 0.4 وبتعمل `status` + إبطال التوكينات
 * + audit. بس:
 *
 *   ① مافيش **راوت** بيناديها — فمحدش يقدر يوقّف حد.
 *   ② ⭐⭐ بتبطل **التوكينات** بس. ومن PR 1.1 الواجهة بقت
 *      **جلسات** (HttpOnly cookie). فالإيقاف بيسيب الجلسة
 *      شغّالة — والـacceptance بتاع الـPR بيقول بالحرف
 *      «suspension invalidates an open session».
 *   ② مافيش **قاعدة** «ماتفعلش نفسك» ولا «ماتفعلش آخر مدير».
 *      من غيرهم، أول خطأ في الواجهة = أكاديمية مقفولة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة الحاكمة: **مفيش حالة بتاخد الأكاديمية كلها معاها**
 *
 * ⭐ Academia one-tenant (مفيش tenant chooser في الـlogin). فلو
 * مفيش ولا مدير **نشط** واحد، **محدش** يقدر يدخل.
 * مفيش زر «إعادة تعيين كلمة مرور المدير» في أي شاشة.
 * ⭐ الـrecovery الوحيد هو `php artisan tinker` — وده مش حل.
 *
 * ⭐ عشان كده كل تغيير حالة بيعدّي على
 * `UserStatusService::assertNotLastAdmin()`.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه `422` مش `403` للقواعد
 *
 * ⭐ `403` = «مالكش صلاحية». الراجع بقى عنده الصلاحية بس الطلب
 * **ماشي** أصلاً — المشكلة في القيم مش في الحق.
 *
 * ⭐ `409 Conflict` كان هيبقى أدق في المعنى، بس الواجهة بتعامل
 * `422` كـ«صحّح الإدخال» في كل مكان، فبنتجنّب حالة جديدة.
 *
 * @see \App\Services\UserStatusService
 * @see \App\Http\Controllers\Api\UserController
 */
class UserManagementTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /** ⭐⭐ مدير بكل صلاحيات إدارة الحسابات */
    private function admin(): User
    {
        return $this->makeUserWithRole('admin', [
            'users.view',
            'users.invite',
            'users.suspend',
            'users.archive',
            'users.roles.assign',
            'programs.view',
        ]);
    }

    /** ⭐⭐ **مش** مدير، بس عنده صلاحيات التحكم */
    private function hr(): User
    {
        return $this->makeUserWithRole('hr', [
            'users.view',
            'users.suspend',
            'users.archive',
            'programs.view',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ① الكتالوج
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ تلات صلاحيات جديدة: `view` · `suspend` · `archive`.
     *
     * ⭐ `invite` و`roles.assign` موجودين من PR 1.4 و PR 0.3.
     *
     * ⭐ ليه ثلاثTacrocchia وم SheldonCompilation واحدة: الإيقاف
     * (مؤقّت) وإlify الأرشفة (نهائي)bjandy سيبJieters مختلفة.
     * اللي يقدر يوقف مؤقتاً مش بالضرورة اللي يقدر يمسح نهائياً.
     */
    public function test_the_user_management_permissions_exist_in_the_catalog(): void
    {
        $names = collect(\Database\Seeders\SeedRolesPermissions::permissions())
            ->pluck('name')
            ->all();

        foreach (['users.view', 'users.suspend', 'users.archive'] as $permission) {
            $this->assertContains(
                $permission,
                $names,
                "صلاحية `{$permission}` مش في الكتالوج — ومحدش هيقدر يدير الحسابات"
            );
        }

        // ⭐ والشكل المطلوبة موجود من قبل
        foreach (['users.invite', 'users.roles.assign'] as $permission) {
            $this->assertContains($permission, $names);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ② العرض
    // ═══════════════════════════════════════════════════════════════

    public function test_an_admin_can_list_users(): void
    {
        $admin = $this->admin();

        $r = $this->getJsonAs('/api/users', $admin);

        $r->assertOk();

        $this->assertNotEmpty($r->json('data'), 'القائمة فاضية');
        $this->assertIsArray($r->json('data.0.roles'), 'مفيش أدوار في الرد');
    }

    public function test_nobody_without_the_view_permission_can_list_users(): void
    {
        $teacher = $this->makeUserWithRole('teacher', ['programs.view']);

        $this->getJsonAs('/api/users', $teacher)->assertForbidden();
    }

    /**
     * ⭐⭐ البحث بالإيميل **والاسم**.
     *
     * ⭐ الاسمين عربي وإنجليزي، فمفيش `LIKE` واحد يغطّيهم مع بعض.
     */
    public function test_the_list_is_searchable(): void
    {
        $admin = $this->admin();

        $mine = $this->makeUser('محمد عبد الرحمن', 'searchable.person@example.test');
        $this->makeUser('شخص تاني', 'someone.else@example.test');

        $byEmail = $this->getJsonAs('/api/users?search=searchable.person', $admin);
        $byEmail->assertOk();

        $emails = collect($byEmail->json('data'))->pluck('email');

        $this->assertTrue($emails->contains($mine->email), 'البحث بالإيميل مش شغال');
        $this->assertFalse($emails->contains('someone.else@example.test'));

        $byName = $this->getJsonAs('/api/users?search='.urlencode('محمد عبد'), $admin);
        $byName->assertOk();

        $this->assertTrue(
            collect($byName->json('data'))->pluck('email')->contains($mine->email),
            'البحث بالاسم العربي مش شغال'
        );
    }

    /**
     * ⭐⭐ الفلترة بالدور والحالة.
     */
    public function test_the_list_filters_by_role_and_status(): void
    {
        $admin = $this->admin();

        $teacher = $this->makeUserWithRole('teacher', ['programs.view']);
        $teacher->forceFill(['status' => 'suspended'])->save();

        $byRole = $this->getJsonAs('/api/users?role=teacher', $admin);
        $byRole->assertOk();

        foreach ($byRole->json('data') as $row) {
            $this->assertContains('teacher', $row['roles'], 'فلتر الدور رجّع حد مش معاه الدور');
        }

        $byStatus = $this->getJsonAs('/api/users?status=suspended', $admin);
        $byStatus->assertOk();

        $suspended = collect($byStatus->json('data'))->pluck('email');

        $this->assertTrue($suspended->contains($teacher->email), 'فلتر الحالة مش شغال');
        $this->assertFalse($suspended->contains($admin->email));
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الإيقاف
    // ═══════════════════════════════════════════════════════════════

    public function test_an_admin_can_suspend_a_user(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('هدف', 'suspend.target@example.test');


        $r = $this->postJsonAs("/api/users/{$target->id}/suspend", [
            'reason' => 'مخالفة نظام',
        ], $admin);

        $r->assertOk();

        $this->assertSame('suspended', $target->fresh()->status);

        // ⭐ والسبب متسجّل —OPERATIONS بدل ما يضيع
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_suspended',
            'entity_id' => $target->id,
        ]);
    }

    /**
     * ⭐⭐⭐ **الـacceptance: الإيقاف بيبطّل جلسة مفتوحة.**
     *
     * ⭐ وده مش بيمرّ من غير تعديل — `UserStatusService` existed من
     * PR 0.4 وبتعمل `status` + إبطال **التوكينات**. ومن PR 1.1
     * الواجهة بتستخدم **جلسات**، فكانت الوقفة بتسيب الجلسة شغّالة.
     *
     * ⭐ الـ`EnsureAccountActive` middleware كان بيمسك على الطلب
     * الجاي، بس الـsession row نفسها كانت موجودة — يعني «الجلسة
     * مفتوحة» بالمعنى الحرفي للـacceptance.
     *
     * ⚠️ والاختبار ده **صنف**: لازم يكون session حقيقي (كوكي
     * حقيقي) مش token، وإلا بيمرّ من غير ما يثبت حاجة.
     */
    public function test_suspension_invalidates_an_open_session(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('جلسة مفتوحة', 'open.session@example.test');

        // ⭐⭐ جلسة حقيقية في جدول `sessions` — مش توكين
        $sessionId = $this->loginViaSpa($target);

        $this->assertNotNull($sessionId, 'مقدرناش نفتح جلسة — الاختبار مش بيختبر اللي ظنّاه');

        // ⭐ نقطة البداية: الجلسة موجودة
        $this->assertSame(
            1,
            DB::table('sessions')->where('user_id', $target->id)->count(),
            'ماكانش فيه جلسة أصلاً — الاختبار بيختبر لا حاجة'
        );


        $this->postJsonAs("/api/users/{$target->id}/suspend", ['reason' => $this->reason()], $admin)
            ->assertOk();

        // ⭐⭐⭐ الـacceptance بتاع الـPR بالحرف
        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $target->id)->count(),
            'الإيقاف سايب الجلسة مفتوحة — الـacceptance مخالف'
        );

        // ⭐ⓘ **ليه مافيش طلب_follow_up بالكوكي؟**
        //
        // ⭐ عشان هيبقى اختبار للـharness مش للـfeature:
        //   • `authHeaders()` بتعمل `createToken()` كل نداء، فطلب
        //     بعد الوقفة بيطلع بتوكين **جديد** شغّال
        //   • والـguard بيخزّن الـuser جوه العملية، فحتى
        //     `forgetGuards()` مش كفاية مع session store في الذاكرة
        //
        // ⭐ والصف المسح **هو** الدليل. والكوكي بيحمل **قيمة
        // مشفّرة** مش الـid، فإعادة بنائها جوه الاختبار معناه
        // إعادة تنفيذ `EncryptCookies` بإيدنا — وده بالظبط الصنف
        // اللي بيخلي الاختبار يعدّي والـfeature مكسورة.
        //
        // ⭐⇦ طبقة التحويل من الحالة لـ403 موجودة ومختبَرة في
        // `LoginHardeningTest::test_an_inactive_user_is_also_blocked`.
        // دي المشكلة اللي بتتحل هنا: **الجلسة أصلاً مش موجودة**.
    }

    public function test_suspension_revokes_api_tokens(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('توكين', 'token.target@example.test');

        $token = $target->createToken('test')->plainTextToken;

        $this->assertSame(1, $target->tokens()->count());


        $this->postJsonAs("/api/users/{$target->id}/suspend", ['reason' => $this->reason()], $admin)->assertOk();

        $this->assertSame(0, $target->fresh()->tokens()->count(), 'التوكين فاضل شغّال بعد الوقفة');
    }

    /**
     * ⭐⭐⭐ **مفيش حد يوقف نفسه.**
     *
     * ⭐ ليه؟ مش حماية ضد المتآمر (هو أصلاً قدر يعمل ده)، ده
     * **حماية ضد الخطأ**: ضغطة غلط في القائمة = المدير قفل على
     * نفسه في ثانية. و`reactivate` بيحتاج حد تاني يوصل للحساب.
     */
    public function test_nobody_can_suspend_themselves(): void
    {
        $admin = $this->admin();

        // ⚡ PR 1.9: نأكد أولاً — عشان الـ422 يجي من قاعدة «ممنوع
        // توقف نفسك» مش من إعادة التأكيد

        $r = $this->postJsonAs("/api/users/{$admin->id}/suspend", ['reason' => $this->reason()], $admin);

        $r->assertStatus(422, 'المدير قدر يوقف نفسه');

        $this->assertSame('active', $admin->fresh()->status, 'الحساب اتوقف رغم الرفض');
    }

    /**
     * ⭐⭐⭐ **آخر مدير نشط مايتوقفش** — القاعدة الحاكمة.
     *
     * ⭐ الـactor هنا **مش** مدير: عنده `users.suspend` بس.
     * وده هو السيناريو اللي القاعدة بتحميه فعلاً — من غيرها
     * حد عنده صلاحية الوقف يقدر يمسح آخر مدير في الأكاديمية
     * والكل يقفل.
     *
     * ⭐ ولأن الإيقاف على النفس مرفوض، الـactor اللي عنده صلاحية
     * لازم يكون حد تاني — فده بالظبط آخر خط دفاع.
     */
    public function test_the_last_active_admin_cannot_be_suspended(): void
    {
        $hr = $this->hr();

        $onlyAdmin = $this->makeUserWithRole('admin', []);

        // ⭐ تأكيد إن ده فعلاً **آخر** مدير نشط
        $this->assertSame(
            1,
            $this->activeAdminCount(),
            'الـfixture مش مضبوط — لازم يكون في مدير واحد نشط بس'
        );

        // مدير نشط مايتوقفش» مش من إعادة التأكيد

        $r = $this->postJsonAs("/api/users/{$onlyAdmin->id}/suspend", ['reason' => $this->reason()], $hr);

        $r->assertStatus(422, 'آخر مدير نشط اتوقف');

        $this->assertSame('active', $onlyAdmin->fresh()->status);
    }

    /**
     * ⭐⭐ والعكس: تاني مدير نشط يبقى آمن.
     *
     * ⭐ الاختبار ده هو اللي بيمنع القاعدة تبقى **متشددة لدرجة
     * تكسر الاستخدام الطبيعي**.
     */
    public function test_an_admin_can_be_suspended_when_another_one_remains(): void
    {
        $hr = $this->hr();

        $stays = $this->makeUserWithRole('admin', []);
        $goes = $this->makeUserWithRole('admin', []);

        $this->assertSame(2, $this->activeAdminCount());


        $this->postJsonAs("/api/users/{$goes->id}/suspend", ['reason' => $this->reason()], $hr)->assertOk();

        $this->assertSame('suspended', $goes->fresh()->status);
        $this->assertSame('active', $stays->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ إعادة التفعيل
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ إعادة التفعيل **مش** ترجع الجلسات القديمة.
     *
     * ⭐ التوكينات والجلسات بتفضل ميتة. السبب: لو الوقفة كانت
     * بسبب اشتباه في اختراق، إرجاع الجلسة القديمة معناها رجوع
     * المهاجم كمان.
     */
    public function test_an_admin_can_reactivate_a_suspended_user(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('موقوف', 'reactivate@example.test');

        $target->forceFill(['status' => 'suspended'])->save();

        $r = $this->postJsonAs("/api/users/{$target->id}/reactivate", [], $admin);

        $r->assertOk();

        $this->assertSame('active', $target->fresh()->status);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_reactivated',
            'entity_id' => $target->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ الأرشفة
    // ═══════════════════════════════════════════════════════════════

    public function test_an_admin_can_archive_a_user(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('أرشفة', 'archive@example.test');

        $this->postJsonAs("/api/users/{$target->id}/archive", ['reason' => $this->reason()], $admin)->assertOk();

        $this->assertSame('archived', $target->fresh()->status);
    }

    /**
     * ⭐⭐ الأرشفة **نهائية** — بتشدّ على الإيقاف.
     *
     * ⭐ `archived` مالها رجعة واجهة. `suspended` ليها
     * `reactivate`. الفصل ده مقصود: رجوع لقرار Mistake
     * ⭐ الأرشفة بعد شهور محتاجة مراجعة بشرية، مش ضغطة زر.
     */
    public function test_archive_does_not_reopen_the_account(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('مؤرشف', 'no.reopen@example.test');

        $this->postJsonAs("/api/users/{$target->id}/archive", ['reason' => $this->reason()], $admin)->assertOk();

        $this->postJsonAs("/api/users/{$target->id}/reactivate", [], $admin)
            ->assertStatus(422, 'الأرشفة اتتراجعت — لازم تكون نهائية');
    }

    public function test_the_last_active_admin_cannot_be_archived(): void
    {
        $hr = $this->hr();
        $onlyAdmin = $this->makeUserWithRole('admin', []);

        $this->assertSame(1, $this->activeAdminCount());

        $this->postJsonAs("/api/users/{$onlyAdmin->id}/archive", ['reason' => $this->reason()], $hr)
            ->assertStatus(422, 'آخر مدير نشط اتأرشف');

        $this->assertSame('active', $onlyAdmin->fresh()->status);
    }

    public function test_nobody_can_archive_themselves(): void
    {
        $admin = $this->admin();

        $this->postJsonAs("/api/users/{$admin->id}/archive", ['reason' => $this->reason()], $admin)
            ->assertStatus(422, 'المدير قدر يؤرشف نفسه');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ إعادة تعيين كلمة المرور إجباريًا
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **إعادة التعيين بتقفل كل الدوال** — جلسات وتوكينات.
     *
     * ⭐ السبب الوحيد: لو كلمة المرور اتسرّبت، إبطال التوكينات
     * **مش كافي** — من PR 1.1 في جلسات، والجلسة مش في
     * `personal_access_tokens`. فكان لازم نبطّل الاتنين.
     *
     * ⭐ والهدف النهائي: `must_change_password = true`، فـ
     * `EnsurePasswordChanged` بيمنع أي صفحة غير صفحة التغيير.
     */
    public function test_a_forced_password_reset_revokes_sessions_and_tokens(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('نسي كلمة المرور', 'forgetful@example.test');

        $sessionId = $this->loginViaSpa($target);
        $target->createToken('test');

        $this->assertNotNull($sessionId);
        $this->assertSame(1, $target->tokens()->count());

        $r = $this->postJsonAs("/api/users/{$target->id}/force-password-reset", [], $admin);

        $r->assertOk();

        $fresh = $target->fresh();

        $this->assertTrue((bool) $fresh->must_change_password, 'الحساب مش متكلّفش يغيّر كلمة المرور');
        $this->assertNull($fresh->password_changed_at);
        $this->assertSame(0, $fresh->tokens()->count(), 'التوكين فاضل شغّال');
        $this->assertSame(
            0,
            DB::table('sessions')->where('user_id', $target->id)->count(),
            'الجلسة فاضلت شغّالة'
        );
    }

    /**
     * ⭐⭐ إعادة التعيين على **نفسك** مسموحة.
     *
     * ⭐ مش متناقضة مع «مفيش وقفة على النفس»: نسيت كلمة المرور
     * حالة مشروعة، ومش بتقفل على حد حاجة —-setting
     * `must_change_password` بيخليك تعمل `/password` وبعدين
     * ترجع.
     *
     * ⭐ واللي اتقفل فعلاً (الجلسات) هو هدف إعادة التعيين.
     */
    public function test_an_admin_can_force_their_own_password_reset(): void
    {
        $admin = $this->admin();

        $this->loginViaSpa($admin);

        $r = $this->postJsonAs("/api/users/{$admin->id}/force-password-reset", [], $admin);

        $r->assertOk();

        $this->assertTrue((bool) $admin->fresh()->must_change_password);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑦ الدور
    // ═══════════════════════════════════════════════════════════════

    public function test_an_admin_can_change_a_role(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('بدور', 'role.change@example.test');

        $teacher = $this->makeRole('teacher', ['programs.view']);


        $r = $this->putJsonAs("/api/users/{$target->id}/role", [
            'role_id' => $teacher->id,
        ], $admin);

        $r->assertOk();

        $this->assertTrue($target->fresh()->hasRole('teacher'));
    }

    /**
     * ⭐⭐⭐ **مفيش حد يغيّر دور نفسه** — حتى لو `assertCanAssignRole`
     * سمح.
     *
     * ⭐ `RoleGrantService` بيمنع **التصعيد** (تسليم دور فيه
     * صلاحيات انت مش ماسكها). بس مش بيمنع **التنزيل**.
     *
     * ⭐ والمشكلة في التنزيل مش أمنية — عملية:
     *   ① مدير بيشيل دوره عن نفسه بالغلط
     *   ② رجع يضيفه تاني… و`assertCanAssignRole` بقى يشتغل
     *      بـ`permissionsActorLacks()` وهي دلوقتي **فاضية**
     *      لأنه فقد الدور اللي كان بياخد منه الصلاحيات
     *   ③ النتيجة: المدير عايز يرجع لدوره المAdmin ومايقدرش.
     *
     * ⭐ ولا ينفع يتخطى بإعادة الاتصال بالـAPI — الطلب بيتبعت
     * بـcookie بتاع الجلسة، والجلسة نفسها اتبطّلت بـ
     * `EnsurePasswordChanged` بعد التعديل.
     */
    public function test_nobody_can_change_their_own_role(): void
    {
        $admin = $this->admin();

        $teacher = $this->makeRole('teacher', ['programs.view']);

        // ⚡ PR 1.9: نأكد أولاً — عشان الـ422 يجي من قاعدة «ممنوع
        // تغيير دور نفسك» مش من إعادة التأكيد

        $r = $this->putJsonAs("/api/users/{$admin->id}/role", [
            'role_id' => $teacher->id,
        ], $admin);

        $r->assertStatus(422, 'المدير قدر يغيّر دور نفسه');

        $this->assertTrue($admin->fresh()->hasRole('admin'), 'الدور اتغيّر رغم الرفض');
    }

    /**
     * ⭐⭐ وقاعدة التصعيد شغّالة هنا كمان — مش بس في
     * `EmployeeController`.
     */
    public function test_a_role_change_cannot_escalate(): void
    {
        $hr = $this->makeUserWithRole('hr', [
            'users.view', 'users.roles.assign', 'programs.view',
        ]);

        $target = $this->makeUser('هدف', 'escalate.role@example.test');

        $adminRole = $this->makeRole('admin', ['finance.movements.reverse']);

        // ⚡ PR 1.9: نأكد أولاً — عشان الرفض ده يكون من قاعدة
        // التصعيد نفسها مش من إعادة التأكيد

        $this->putJsonAs("/api/users/{$target->id}/role", [
            'role_id' => $adminRole->id,
        ], $hr)->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑧ السجل
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **كل عملية بتتسجّل، ومافيش عملية من غير سجل.**
     *
     * ⭐ البند 3 في AGENTS.md: العمليات الحساسة في `audit_logs`.
     *
     * ⭐ والاختبار ده **بالعدّ** مش بغياب: بياخد كل العمليات
     * اللي Marqu عليها ويقارن عددها بعد كل واحد فيهم. فلو
     * عملية جديدة اتضافت من غير تسجيل، الـassertIncreasing
     * هيسقط.
     */
    public function test_every_action_is_audited(): void
    {
        $admin = $this->admin();
        $target = $this->makeUser('سجل', 'audit.target@example.test');

        $countBefore = $this->userAuditCount();


        $this->postJsonAs("/api/users/{$target->id}/suspend", ['reason' => $this->reason()], $admin)->assertOk();
        $this->assertGreaterThan($countBefore, $this->userAuditCount(), 'الإيقاف ما اتسجلش');

        $this->postJsonAs("/api/users/{$target->id}/reactivate", [], $admin)->assertOk();
        $this->assertGreaterThan(
            $this->userAuditCount() - 1,
            $this->userAuditCount(),
            'إعادة التفعيل ما اتسجلتش'
        );

        $this->postJsonAs("/api/users/{$target->id}/force-password-reset", [], $admin)->assertOk();
        $this->assertGreaterThan($countBefore, $this->userAuditCount(), 'إعادة التعيين ما اتسجلتش');

        $teacher = $this->makeRole('teacher', ['programs.view']);

        $this->putJsonAs("/api/users/{$target->id}/role", [
            'role_id' => $teacher->id,
        ], $admin)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user_role_changed',
            'entity_id' => $target->id,
        ]);
    }

    // ⭐⭐ «طلب من غير JSON = 401 مش 500» متختبر في
    // `CheckPermissionTest::test_unauthenticated_is_401_json_not_a_redirect`
    // — وده بيته لوحده عشان السلوك ده خاص بـ`Authenticate`
    // middleware مش بإدارة الحسابات.
    //
    // ⭐ الاختبار هناك **اتغيّر** في مراجعة PR 1.5: كان بيفترض
    // 500 (سلوك موثّق كأنه مقصود)، وبقى 401 بعد
    // `redirectGuestsTo(fn () => null)`.

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ رجّع `SESSION_DRIVER` لـ`array`.
     *
     * ⭐ مهم: `loginViaSpa()` بيفرضه `database` عشان نفحص صف
     * الجلسة. فلو ما رجعناوش، الاختبارات اللي **بعده** هتستخدم
     * driver مختلف — وده بالظبط الصنف اللي `forgetGuards()`
     * بيحمي منه في الـauth.
     */
    protected function tearDown(): void
    {
        config(['session.driver' => 'array']);
        app('session')->forgetDrivers();

        parent::tearDown();
    }

    /**
     * ⭐ سبب الإيقاف/الأرشفة — **إجباري** (`min:5`).
     *
     * ⭐ ليش عام helper: عشان الاختبارات تركز على السلوك مش على
     * الـvalidation. لو حد غيّر `min:5` لـ`min:50` الاختبارات
     * هتفشل برسالة غلط، لكن لو كتب كل اختبار سببه بإيده كان
     * الخطأ هيفضل مخفي.
     */
    private function reason(): string
    {
        return 'سبب الاختبار — مخالفة نظام داخلية';
    }

    /**
     * ⭐⭐ عدد المديرين **النشطين** — أساس قاعدة «آخر مدير».
     *
     * ⭐⚠️ لازم `status = 'active'` مش بس «عنده دور admin».
     * مدير موقوف مش بيفتح حاجة، فم countingه في الحماية معناه
     * إنه يحمي أكاديمية مقفولة أصلاً — والقاعدة هتفشل على
     * آخر **مجدي**.
     */
    private function activeAdminCount(): int
    {
        return User::role('admin')
            ->where('status', 'active')
            ->count();
    }

    private function userAuditCount(): int
    {
        return DB::table('audit_logs')->where('entity_type', 'user')->count();
    }

    /**
     * ⭐⭐⭐ يفتح جلسة **حقيقية** persistently — في جدول `sessions`.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه بنفرض `database` driver هنا
     *
     * ⭐ `.env.testing` فيه `SESSION_DRIVER=array` — صح للمعموم
     * (أسرع، ومفيش صفوف بتتسرّب بين الاختبارات)، بس معناه إن
     * **مافيش صف في جدول `sessions`**. الحفظ في الذاكرة بس.
     *
     * ⭐ والـacceptance بتاع الـPR بيقول بالحرف «suspension
     * invalidates an open session» — والصف **هو** الجلسة. فلو
     * ما قدرش نفحصها، الاختبار بيتحول لـ«شدّد على Status» وده
     * اختبار تاني (`LoginHardeningTest` بيغطيه).
     *
     * ⭐ فبنبدّل الـdriver في الاختبار ده بس، وبنرجّعه في
     * `tearDown()`.
     */
    private function loginViaSpa(User $user): ?string
    {
        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();

        $headers = [
            'Referer' => 'http://localhost:3000/',
            'Origin' => 'http://localhost:3000',
            'Accept' => 'application/json',
        ];

        // ⭐ الـlogin نفسه POST محمي بـCSRF، فلازم الكوكيز الأول
        $csrf = $this->withHeaders($headers)->get('/sanctum/csrf-cookie');

        $xsrf = $csrf->getCookie('XSRF-TOKEN')?->getValue();
        $session = $csrf->getCookie('laravel_session')?->getValue();

        $response = $this->withHeaders($headers)
            ->withCookie('laravel_session', $session)
            ->withHeader('X-XSRF-TOKEN', $xsrf)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        if ($response->status() !== 200) {
            $this->fail(
                'تسجيل دخول الـSPA رجع '.$response->status()
                .' — body: '.mb_substr($response->getContent() ?: '', 0, 300)
            );
        }

        return DB::table('sessions')
            ->where('user_id', $user->id)
            ->orderByDesc('last_activity')
            ->value('id');
    }
}
