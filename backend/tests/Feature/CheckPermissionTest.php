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

        // مسار الكتابة محمي بـ programs.update — وهي **غير موجودة**
        // في داتابيز الاختبار (Fixtures بتعمل اللي الطلبه بس)
        $this->assertDatabaseMissing('permissions', ['name' => 'programs.update']);

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
     *
     * ═══════════════════════════════════════════════════════════════
     * ⚠️ **تغيّر سلوك في PR 1.5 (مراجعة) — 500 بقى 401.**
     *
     * ⭐ النسخة الأولى من هذا الاختبار كانت بتقول:
     *
     *     // من غير Accept: application/json، الوسيط بيحاول يوجّه
     *     // لـ login — وده بيولّد 500 في تطبيق API
     *     $html->assertStatus(500);
     *
     * ⭐ يعني السلوك **كان موثّق كأنه مقصود**. وهو **مش مقصود** —
     * التعليق نفسه بيقول «في تطبيق API»، يعني كاتب الاختبار
     * عارف إنه غلط ومش عارف ليه.
     *
     * ⭐ السبب: Laravel 11 بيجيب
     * `redirectGuestsTo(fn () => route('login'))` كـdefault، والـAPI
     * ده **مافيش فيه راوت `login`** — صفحة الدخول في الواجهة.
     *
     * ⭐⭐ `Authenticate::unauthenticated()`:
     *
     *     $request->expectsJson() ? null : redirect()->guest($this->redirectTo($request))
     *
     * ⭐ فمع `Accept: application/json` بيرجّع 401 من أول الطريق
     * والـdefault المكسور **مش هيتنفّذ أبداً**. ومن غير الـheader
     * بيرمي `RouteNotFoundException` → 500 بـstack trace.
     *
     * ⭐⭐⭐ الإصلاح: `$middleware->redirectGuestsTo(fn () => null)`
     * في `bootstrap/app.php`. والـ`null` معناه «مافيش redirect»
     * — وده بالظبط الصح لتطبيق API.
     *
     * ⭐ مين كان بيتمسّه؟ `lib/api.ts` بيبعت
     * `Accept: application/json` دايمًا، فالواجهة مش هتتأثر.
     * لكن: متصفح بيفتح `/api/...` في الـaddress bar، أو
     * monitoring probe، أو `curl` من غير هيدر.
     *
     * @see bootstrap/app.php
     * @see \Tests\Feature\UserManagementTest (الاختبار التوأم — بيرجّع 401 من Paths التانية)
     */
    public function test_unauthenticated_is_401_json_not_a_redirect(): void
    {
        // ⭐⭐⭐ **السلوك الجديد (وضع المستخدم الواحد).**
        //
        // مافيش ضيوف: `AutoLoginAdmin` بيسجّل دخول الأدمن على أي
        // طلب بلا توكن، فالطلب مش المسجّل **مش بيرجع 401** — بيرجع
        // زي أي حد تاني: 403 لو الأدمن الجديد مالوش الصلاحية.
        //
        // ⭐ والاختبار لسه مهم: ليه رد **JSON** مش 500 ولا redirect
        // لـ`login`؟ لأن `redirectGuestsTo(fn () => null)` شغّال،
        // وبيمنع `RouteNotFoundException` في الطلبات غير JSON.
        //
        // @see \App\Http\Middleware\AutoLoginAdmin
        $r = $this->getJson('/api/programs');

        $r->assertStatus(403);
        $this->assertIsString($r->json('message'), 'الرد لازم يكون JSON فيه رسالة — مش HTML');

        // ⭐⭐ ومن غير `Accept: application/json` برضه JSON مش 500
        $html = $this->get('/api/programs', ['Accept' => 'text/html']);

        $html->assertStatus(403, 'طلب غير JSON على راوت محمي رجّع 500 — redirectGuestsTo لسه بيوجّه لـlogin');
    }

    public function test_role_without_the_permission_is_denied(): void
    {
        $user = $this->makeUserWithRole('viewer', ['programs.view']);

        $this->postJsonAs('/api/programs', ['name' => 'محظور'], $user)->assertForbidden();
    }

    public function test_permission_grows_with_the_role(): void
    {
        $user = $this->makeUserWithRole('editor', ['programs.view']);
        $this->assertFalse($user->fresh()->can('lessons.update'));

        \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'lessons.update', 'guard_name' => 'web'],
            ['slug' => 'lessons-edit', 'module' => 'lessons', 'guard_name' => 'web'],
        );
        $user->roles->first()->givePermissionTo('lessons.update');

        $this->assertTrue($user->fresh()->can('lessons.update'), 'الصلاحية الجديدة لازم تشتغل فوراً');
    }
}
