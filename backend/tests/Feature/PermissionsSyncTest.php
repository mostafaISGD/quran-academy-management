<?php

namespace Tests\Feature;

use Database\Seeders\SeedRolesPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **حارس مصدر الصلاحيات.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة اللي existed
 *
 * كان فيه **قائمة صلاحيات ثانية** مكتوبة يدوي في `DatabaseSeeder`
 * ناقصة `finance.*` و `groups.manage` و `pricing.manage`.
 *
 * ⭐ النتيجة: `GET /api/accounts` بيرجع **403** للمدير نفسه،
 * وصفحات `/accounts` و `/finance` و `/pricing-admin` ميتة فعلياً.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ إيه اللي الاختبارات دي بتحرسه
 *
 * ① كل صلاحية مستخدمة في `routes/api.php` **موجودة** في المصدر
 *    الرسمي — ده اللي بيمنع 403 في أي وقت في المستقبل.
 *
 * ② `DatabaseSeeder` بيقرأ من المصدر الواحد (مش ليستة ثانية).
 *
 * ③ `permissions:sync` بيضيف الناقص وبيمسح الزيادة للـ admin.
 */
class PermissionsSyncTest extends TestCase
{
    use RefreshDatabase;

    // ============================================================
    // ① المصدر الرسمي مكمّل
    // ============================================================

    public function test_the_official_source_has_every_permission_the_api_needs(): void
    {
        /**
         * ⭐⭐⭐ **الاختبار ده هو اللي بيمنع تكرار الـ 403.**
         *
         * لو حد أضاف route بـ `permission:finance.something` ونسي
         * يضيف الصلاحية للمصدر، الاختبار ده بيفشل **وقت البناء**
         * بدال ما المستخدم يقابل "غير مصرح" في الشاشة.
         *
         * ⚠️ **PR 1.6:** الراوتات لسه بأسماء قديمة (مثل `settings.manage`)
         * والـ PR 1.7 هو اللي يطبّق الخريطة. فالاختبار بيقبل
         * الاسم إذا كان في الكتالوج الجديد **أو** في خريطة التحويل.
         */
        $routes = file_get_contents(base_path('routes/api.php'));

        preg_match_all("/permission:([\w.]+)/", $routes, $matches);

        $used = array_values(array_unique($matches[1]));

        $this->assertNotEmpty($used, 'لازم نلاقي صلاحيات في الـ routes');

        $canonical = array_column(SeedRolesPermissions::permissions(), 'name');
        $legacy = array_keys(SeedRolesPermissions::migrationMap());

        $missing = array_values(array_diff($used, array_merge($canonical, $legacy)));

        $this->assertSame(
            [],
            $missing,
            "الصلاحيات دي مستخدمة في routes بس مش متعرّفة في المصدر ولا في خريطة التحويل:\n  "
                . implode("\n  ", $missing)
                . "\n\nضيفها في config/permissions.php"
        );
    }

    public function test_the_admin_role_gets_every_declared_permission(): void
    {
        $this->artisan('permissions:sync')->assertSuccessful();

        $admin = $this->makeUser();
        $admin->assignRole('admin');

        foreach (array_column(SeedRolesPermissions::permissions(), 'name') as $name) {
            $this->assertTrue(
                $admin->can($name),
                "المدير لازم يقدر «{$name}»"
            );
        }
    }

    // ============================================================
    // ② مصدر واحد بس — مفيش قائمة تانية
    // ============================================================

    public function test_database_seeder_reads_from_the_single_source(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/DatabaseSeeder.php'));

        $this->assertStringContainsString(
            'SeedRolesPermissions::permissions()',
            $seeder,
            'DatabaseSeeder لازم يقرأ من المصدر الرسمي'
        );

        /**
         * ⭐ وأهم: مافيش أي `['name' => '...']` جوه الـ seeder —
         * دي كانت<Listen>.Listen.Listen<Listen>
         */
        $this->assertDoesNotMatchRegularExpression(
            "/\['name'\s*=>\s*'[a-z]+\.[a-z.]+'.*'module'/i",
            $seeder,
            'DatabaseSeeder فيه لسه قائمة صلاحيات مكتوبة يدوي — لازم تتشال'
        );
    }

    // ============================================================
    // ③ الأمر شغّال وآمن
    // ============================================================

    public function test_the_sync_command_is_idempotent(): void
    {
        // ⭐ تشغيلة أول بتضيف الناقص
        $this->artisan('permissions:sync')->assertSuccessful();

        $first = Permission::where('guard_name', 'web')->count();

        // ⭐ تشغيلة تانية مش بتضاعف
        $this->artisan('permissions:sync')->assertSuccessful();

        $second = Permission::where('guard_name', 'web')->count();

        $this->assertSame(
            $first,
            $second,
            'تشغيل الأمر مرتين لازم يدي نفس النتيجة'
        );

        /**
         * ⚠️ **PR 1.6:** العدد دلوقتي أكبر من الكتالوج الجديد — لأن
         * الأمر بيعمل كمان **الأسماء القديمة** اللي لسه في الراوتات
         * وفي الأدوار. من غيرهم كانت الراوتات بترجع 403.
         *
         * ⭐ الاتنين قايم sales **بيتداخلوا** — فبنحسب الـunion.
         */
        $legacy = array_unique(array_merge(
            SeedRolesPermissions::legacyPermissionsInUse(),
            SeedRolesPermissions::legacyPermissionsInRoles(),
        ));

        $expected = count(SeedRolesPermissions::permissions()) + count($legacy);

        $this->assertSame(
            $expected,
            $first,
            'عدد الصلاحيات لازم يطابق المصدر + الأسماء القديمة اللي لسه مستخدمة'
        );
    }

    // ============================================================
    // ⑤ كل route محمي لازم يكون فيه `auth:sanctum`
    // ============================================================

    public function test_every_permission_route_also_requires_authentication(): void
    {
        /**
         * ⭐⭐⭐ **الاختبار ده بيمسك الغلط اللي حصل في `/promo-codes`.**
         *
         * ⚠️ الـ middleware `permission` لوحده **مش كافي**:
         *
         *     if (! $user) return 403;
         *     $user->hasPermissionTo($permission);
         *
         * فلو الـ route نسي `auth:sanctum`، الـ `$request->user()`
         * بيبقى `null` → **403 حتى لو الـ token صالح والصلاحية موجودة**.
         *
         * ⭐ والنتيجة كانت: `/promo-codes` و `/promo-codes/{id}`
         * بيرجعوا «غير مصرح» للمدير نفسه.
         *
         * ⭐ والاختبار ده بيقرا الـ routes **المسجّلة فعلياً** بعد
         * `artisan route:list` — مش الملف النصي. فبيشوف النتيجة
         * الحقيقية بعد تطبيق الـ middleware groups.
         */
        $this->artisan('route:list')->assertSuccessful();

        /** @var \Illuminate\Routing\Route[] $routes */
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'api/'));

        $checked = 0;
        $offenders = [];

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();

            $hasPermission = collect($middleware)
                ->contains(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'));

            if (! $hasPermission) {
                continue;
            }

            $checked++;

            /**
             * ⚠️ `Authenticate:sanctum` ممكن يكون متطبّق على **الـ
             * group** فيبقى اسمه بعد الـ gather.
             */
            $hasAuth = collect($middleware)->contains(fn ($m) => in_array($m, [
                'auth:sanctum',
                'Illuminate\Auth\Middleware\Authenticate:sanctum',
                'Illuminate\Auth\Middleware\Authenticate',
            ], true));

            if (! $hasAuth) {
                $offenders[] = implode('|', $route->methods())
                    . ' ' . $route->uri()
                    . ' → ' . collect($middleware)
                        ->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))
                        ->implode(',');
            }
        }

        $this->assertGreaterThan(50, $checked, 'لازم نفحص عدد معقول من الـ routes');

        $this->assertSame(
            [],
            $offenders,
            "الـ routes دي فيها permission بس **من غير auth** — يعني هترجع 403\n"
                . "حتى للمدير:\n  " . implode("\n  ", $offenders)
        );
    }

    public function test_dry_run_changes_nothing(): void    {
        $before = Permission::where('guard_name', 'web')->count();

        $this->artisan('permissions:sync', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(
            $before,
            Permission::where('guard_name', 'web')->count(),
            '--dry-run مافيشه يغيّر حاجة'
        );
    }

    public function test_sync_gives_admin_exactly_the_source_not_everything(): void
    {
        /**
         * ⭐⭐ **حارس ضد التوسّع العشوائي.**
         *
         * `syncPermissions(Permission::all())` كان هيعطي المدير
         * أي صلاحية اتضافت بالغلط. إحنا بنعطيه **المصدر بس**.
         */
        Permission::create([
            'name' => 'dangerous.stray',
            'slug' => 'dangerous-stray',
            'module' => 'dangerous',
            'guard_name' => 'web',
        ]);

        $this->artisan('permissions:sync')->assertSuccessful();

        $admin = $this->makeUser();
        $admin->assignRole('admin');

        $this->assertFalse(
            $admin->can('dangerous.stray'),
            'المدير ما ينفعش ياخد صلاحية بره المصدر الرسمي'
        );
    }

    // ============================================================
    // ④ فصل المهام — الدرس اللي.plan بيحميه
    // ============================================================

    public function test_the_accountant_cannot_reverse_or_unlock(): void
    {
        $this->artisan('permissions:sync')->assertSuccessful();

        /**
         * ⭐ دور المحاسب بينشأ جوّه `SeedRolesPermissions` عن قصد —
         * لأنه **مش** مجرد اسم، هو **قرار فصل المهام**.
         *
         * فبنشغّل الـ seeder نفسه عشان نضمن الاختبار بيفحص
         * القرار الحقيقي مش نسخة منه.
         */
        $this->seed(SeedRolesPermissions::class);

        $accountant = $this->makeUser();
        $accountant->assignRole('accountant');

        // ⭐ اللي المحاسب لازم يقدر
        $this->assertTrue($accountant->can('finance.accounts.view'), 'يشوف الأرصدة');
        $this->assertTrue($accountant->can('finance.accounts.manage'), 'يعمل خزينة');
        $this->assertTrue($accountant->can('finance.movements.create'), 'يسجّل حركة');

        // ⭐⭐ واللي **لازم ما يقدرش** — دي الخطة كلها
        $this->assertFalse(
            $accountant->can('finance.movements.reverse'),
            'المحاسب ما ينفعش يعكس دفعة — غير ده الأرقام كلها بلا معنى'
        );
        $this->assertFalse(
            $accountant->can('finance.periods.unlock'),
            'فتح فترة مقفولة قرار إدارة'
        );
    }
}
