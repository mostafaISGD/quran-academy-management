<?php

namespace Tests\Feature;

use App\Models\Expense;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * ⭐⭐⭐ مصفوفة المسارات — أول اختبار بيثبت إن أي route جديد لازم يكون محمي.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة اللي الاختبار ده اتكتب عشانها
 *
 * في `routes/api.php` كان فيه **18 route** جوه `auth:sanctum` من غير
 * أي `permission:` — أي حد داخل الأكاديمية كان يقدر يوصلها:
 *
 *   GET  /api/expenses            ← مصروفات
 *   POST /api/expenses            ← تسجيل مصروف
 *   GET  /api/teacher-earnings    ← مستحقات المعلمين
 *   GET  /api/teacher-payments    ← مرتبات المعلمين
 *   GET  /api/parents             ← بيانات أولياء الأمور
 *   POST /api/parents             ← إنشاء ولي أمر
 *   + 12 route على هواتف الطلاب والربط بالأبناء
 *
 * الـ middleware نفسه موجود وشغّال (`CheckPermission`) — بس الراوتس
 * نسيت تستعمله. الاختبار ده بيقفل الباب على التكرار: أي route
 * جديد من غير صلاحية = الاختبار بيفشل، والسبب مكتوب في الرسالة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه الاستثناءات مكتوبة جوه الاختبار نفسه
 *
 * مش عشان نلتف على الفحص — العكس. أي استثناء لازم يكون:
 *   ① موثّق بسبب — ليه الراوت ده مش محمي بصلاحية
 *   ② داخل الاختبار — عشان محدش يضيفه من غير ما يفكّر
 *
 * ولو الراوت ده اتحمّى بالصلاحية بعد كده، الاختبار
 * `test_no_exception_entry_is_left_unused` بيفشل ويقول امسح سطره
 * — عشان القائمة ما تبقاش ك=dead weight بتكدب على نفسها.
 */
class RouteProtectionTest extends TestCase
{
    /**
     * ⭐ الاستثناءات — كل واحد مع سبب مكتوب.
     *
     * المفتاح = "METHOD uri" بالظبط زي `Route::getRoutes()`.
     *
     * @var array<string, string>
     */
    private const EXCEPTIONS = [
        // ===== الـ authentication نفسها =====
        'POST api/auth/logout' => 'بيلغي توكن المستخدم نفسه — من غيرها مفيش طريقة تخرج',
        'GET api/auth/me' => 'بيرجّع بروفايل المستخدم الحالي نفسه — أي حد داخل بيلاقيه',

        // ===== تغيير كلمة المرور =====
        //
        // ⭐ مالهوش `permission:` بالمعنى المعتاد — أي حساب يقدر
        // يغيّر كلمة مروره، ومفيش معنى لـ«صلاحية تغيير كلمة
        // مرورك».
        //
        // ⭐ والحماية هنا طبقتين: الـmiddleware نفسه
        // (`EnsurePasswordChanged`) هو اللي بيقفل الحساب، وهو اللي
        // بيسمح للراوت ده بالعدو. وبيتحقق من كلمة المرور الحالية
        // جوّه الـcontroller — فحد سرق التوكن مش يقدر يغيّرها.
        'POST api/auth/change-password' => 'أي حساب يغيّر كلمة مروره — محمي بـ EnsurePasswordChanged + التحقق من الكلمة الحالية',

        // ===== الإشعارات — محمية بالملكية جوه الـ controller =====
        //
        // الإشعارات **مش** موارد إدارية — كل دور بياخد إشعاراته.
        // فالحماية بالملكية مش بـ permission.
        'GET api/notifications' => 'الإشعارات مشتركة بين كل الأدوار — مفلترة بـ user_id جوه الـ controller',
        'POST api/notifications/{notification}/read' => 'مفلتر بملكية الإشعار (abort 404) جوه ال‏ـ controller',
        'POST api/notifications/read-all' => 'بيأثر على إشعارات المستخدم الحالي بس (whereNull + where user_id)',

        // ===== جدول أوقات المعلم — محمي بالملكية جوه الـ controller =====
        //
        // المعلم بيتحكم بجدوله هو، فـ `authorizeOwnership()` جوه
        // `TeacherScheduleController` هي اللي بتحمي الخمسة راو كامل.
        // الإدارة تعدّل أي حد لو عندها `teachers.edit`.
        'GET api/teachers/{teacher}/availability/completeness' => 'ملكية المعلم مفروضة في authorizeOwnership() جوه الـ controller',
        'GET api/teachers/{teacher}/availability' => 'ملكية المعلم مفروضة في authorizeOwnership() جوه الـ controller',
        'POST api/teachers/{teacher}/availability' => 'ملكية المعلم مفروضة في authorizeOwnership() جوه الـ controller',
        'PUT api/teachers/{teacher}/availability/{block}' => 'ملكية المعلم مفروضة في authorizeOwnership() + فحص إن الـ block بتاعه',
        'DELETE api/teachers/{teacher}/availability/{block}' => 'ملكية المعلم مفروضة في authorizeOwnership() + فحص إن الـ block بتاعه',

        // ===== بوابة ولي الأمر =====
        //
        // مش محمي بـ permission لأن الحماية عنده مش بالصلاحية، هي
        // بـ middleware تاني: `parent.only` اللي بيتأكد إن المستخدم
        // فعلاً ولي أمر. وده الوضع الصح — ولي الأمر مالوش دور
        // في كتالوج الصلاحيات أصلاً.
        'GET api/parent/children' => 'محمي بـ parent.only middleware (مش permission) — وده الوضع الصح لولي الأمر',

        // ===== مستندات PDF (PR 2.17) =====
        //
        // الفاتورة/الإيصال PDF عائليان قبلهما: ولي الأمر مالوش
        // كتالوج صلاحيات أصلاً (نفس منطق بوابة ولي الأمر فوق)،
        // فتقيد بـ `permission:` هيمنعه من الطباعة أكثر وسيطاً.
        // الحكم كله في الـPolicies: موظف مالي (finance.payments.view
        // + بلا هوامش) — طالب (إيصالاته/فواتيره) — ولي بشرط
        // can_view_finance — وولي آخر 403. اختباراته في
        // InvoicePdfTest + PaymentReceiptTest.
        'GET api/invoices/{invoice}/pdf' => 'محمي بـ InvoicePolicy::viewPdf — الولي بشرط can_view_finance والفرد الغريب 403',
        'GET api/payments/{payment}/receipt' => 'محمي بـ PaymentPolicy::viewPdf — نفس منطق الإيصال العائلي',
    ];

    /**
     * الراوتس اللي اتحمّت في PR 0.1 — دي اللي لازم تترفض
     * بمعنى حقيقي (403 مش 404) للمعلم وولي الأمر.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const NEWLY_PROTECTED = [
        ['GET', '/api/expenses'],
        ['POST', '/api/expenses'],
        ['GET', '/api/teacher-earnings'],
        ['GET', '/api/teacher-payments'],
        ['GET', '/api/parents'],
        ['POST', '/api/parents'],
        ['GET', '/api/students/{student}/phones'],
        ['POST', '/api/students/{student}/phones'],
        ['POST', '/api/students/{student}/phones/bulk'],
        ['PUT', '/api/students/{student}/phones/{phone}'],
        ['DELETE', '/api/students/{student}/phones/{phone}'],
        ['POST', '/api/students/{student}/phones/{phone}/set-primary'],
        ['POST', '/api/students/{student}/phones/{phone}/toggle'],
        ['GET', '/api/parents/search'],
        ['GET', '/api/students/{student}/parents'],
        ['POST', '/api/students/{student}/parents/{parent}/set-primary'],
        ['GET', '/api/phones/search'],
        ['GET', '/api/phones/search/parent'],
    ];

    // ═══════════════════════════════════════════════════════════════
    // ① المصفوفة نفسها
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ الاختبار الأساسي — أي route جوه auth:sanctum لازم يكون
     * عليه permission أو يكون استثناء موثّق.
     */
    public function test_every_authenticated_route_has_a_permission_or_a_written_exception(): void
    {
        $unprotected = [];

        foreach ($this->authenticatedRoutes() as $key => $route) {
            if ($this->hasPermissionMiddleware($route)) {
                continue;
            }

            if (isset(self::EXCEPTIONS[$key])) {
                continue;
            }

            $unprotected[] = $key;
        }

        $this->assertSame(
            [],
            $unprotected,
            "الراوتس دي جوه auth:sanctum من غير permission ومن غير استثناء موثّق:\n"
            .'  • '.implode("\n  • ", $unprotected)."\n\n"
            ."إما حطّ ->middleware('permission:...') عليها، "
            ."أو ضيفها في RouteProtectionTest::EXCEPTIONS بسبب مكتوب."
        );
    }

    /**
     * ⭐ عكس الاختبار اللي فوق: لو راو اتحمّى بالصلاحية، الاستثناء
     * بتاعه لازم يتشال. وإلا القائمة بتكدب — تقول دي استثناء وهي
     * فعلياً محمية، فحد يبالغ ويشيل الحماية.
     */
    public function test_no_exception_entry_is_left_unused(): void
    {
        $byKey = $this->authenticatedRoutes();

        $stale = [];

        foreach (self::EXCEPTIONS as $key => $reason) {
            if (! $byKey->has($key)) {
                $stale[] = "«{$key}» — الراوت ده مش موجود أو مش جوه auth:sanctum";

                continue;
            }

            if ($this->hasPermissionMiddleware($byKey->get($key))) {
                $stale[] = "«{$key}» — بقى عليه permission، فامسح السطر من EXCEPTIONS";
            }
        }

        $this->assertSame([], $stale, "استثناءات بقت مش لازمة:\n  • ".implode("\n  • ", $stale));
    }

    /**
     * كل استثناء لازم يكون جوه auth:sanctum فعلاً.
     *
     * لو واحد شُيل برّه من الجروب، هو بقى route عام — يعني أي حد
     * من غير login يقدر يوصله. استثناء راو عام = ثغرة.
     */
    public function test_every_exception_route_is_still_authenticated(): void
    {
        $keys = $this->authenticatedRoutes()->keys()->all();

        $notAuthenticated = array_values(array_diff(array_keys(self::EXCEPTIONS), $keys));

        $this->assertSame(
            [],
            $notAuthenticated,
            "الاستثناءات دي مش جوه auth:sanctum anymore — يعني بقت routes عامة:\n  • "
            .implode("\n  • ", $notAuthenticated)
        );
    }

    /**
     * ⭐⭐ ترتيب الـ middleware **الفعلي** — مش المكتوب في الراوت.
     *
     * ⚠️ الفخ هنا اسمه `middlewarePriority`:
     *
     * Laravel **مش** بيشغّل الراوت بالترتيب اللي كاتبه في
     * `->middleware()`. هو بيعمل **sort** بالترتيب ده:
     *
     *   → الـ middleware اللي في `$middlewarePriority` بتتشغّل الأول
     *   → والباقي بيبقى بترتيب إعلانه
     *
     * و`AuthenticatesRequests` **داخل** الـ priority، فـ
     * `Authenticate:sanctum` بيتقدّم **دايمماً** على
     * `CheckPermission` (اللي مش في الـ list). عشان كده
     * ترتيب الإعلان في الراوتس مش هو اللي بيحمي —
     * الـ priority هو اللي بيحمي.
     *
     * ⭐ فلو الـ priority اتغيّر (حد ضاف `CheckPermission` في
     * `bootstrap/app.php` في الترتيب الغلط)، كل المسارات هتبقى
     * **403 غلاط** للمستخدم الصح — و`$request->user()` بيبقى null
     * والـ 403 مش بيقول السبب.
     *
     * فبنفحص الـ order الحقيقي اللي Laravel بيحسبه.
     */
    public function test_authentication_runs_before_the_permission_check(): void
    {
        $router = app('router');

        foreach ($this->authenticatedRoutes() as $key => $route) {
            if (! $this->hasPermissionMiddleware($route)) {
                continue;
            }

            // ⭐ الترتيب الفعلي — زي ما Laravel هيشغّله فعلاً.
            $effective = $router->gatherRouteMiddleware($route);

            $authAt = $this->indexOfAuthMiddleware($effective);
            $permissionAt = $this->indexOfPermissionMiddleware($effective);

            $this->assertNotFalse(
                $authAt,
                "«{$key}» — مفيش Authenticate في الـ middleware الفعلي رغم وجود auth:sanctum"
            );

            $this->assertLessThan(
                $permissionAt,
                $authAt,
                "«{$key}»: CheckPermission هيشتغل **قبل** الـ authentication. "
                .'والنتيجة: $request->user() بيبقى null وCheckPermission بترجّع 403 غلاط '
                .'للمستخدم اللي عنده الصلاحية. السبب غالباً تغيير في $middlewarePriority '
                .'في bootstrap/app.php.'
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ② العزل الحقيقي — 403 مش بس إن الراوتس مذكورة
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ المعلم ياخد 403 من كل route اتحمّى.
     *
     * الاختبار (1) بيقول الراوت عليها permission. ده بيقول إن
     * الـ middleware بيشتغل فعلاً — ومفيش راو مرّت من غير ما
     * الـ permission تتطبّق على المستخدم الحقيقي.
     */
    public function test_a_teacher_is_denied_on_every_newly_protected_route(): void
    {
        $teacher = $this->makeUserWithRole('teacher', [
            'lessons.view', 'students.view',
        ]);

        foreach (self::NEWLY_PROTECTED as [$method, $uri]) {
            $this->callProtected($method, $uri, $teacher)
                ->assertForbidden("المعلم وصل «{$method} {$uri}» — الراوت دي لسه مفتوحة ليه");
        }
    }

    public function test_a_parent_is_denied_on_every_newly_protected_route(): void
    {
        $parent = $this->makeUserWithRole('parent', []);

        foreach (self::NEWLY_PROTECTED as [$method, $uri]) {
            $this->callProtected($method, $uri, $parent)
                ->assertForbidden("ولي الأمر وصل «{$method} {$uri}» — الراوت دي لسه مفتوحة ليه");
        }
    }

    /**
     * ⭐⭐ والأهم: اللي عنده الصلاحية لازم ياخد 200 مش 403.
     *
     * ⭐ ده اللي بيكشف خطأ الـ ordering لو حصل: الـ middleware
     * بيشتغل بس على الـ user الغلط، فالـ user الصح بياخد 403
     * وباقي الاختبارات بتعدّي عادي.
     */
    public function test_a_user_with_the_permission_is_not_blocked(): void
    {
        $receptionist = $this->makeUserWithRole('receptionist', ['finance.expenses.view']);

        Expense::create([
            'organization_id' => $this->org->id,
            'category_id' => $this->makeExpenseCategory()->id,
            'amount' => 100,
            'currency' => 'EGP',
            'expense_date' => now()->toDateString(),
            'description' => 'اختبار المصفوفة',
            'created_by' => $receptionist->id,
        ]);

        $this->getJsonAs('/api/expenses', $receptionist)->assertOk();
    }

    /**
     * الراوتس اللي اتحمّت دي لازم تفضل محمية فعلاً — غير كده حد
     * يقدر يحمي راوتي ويمرّ من غير ما الاختبار يصرخ.
     */
    public function test_every_newly_protected_route_really_has_a_permission(): void
    {
        $routes = $this->authenticatedRoutes();

        $missing = [];

        foreach (self::NEWLY_PROTECTED as [$method, $uri]) {
            // ⚠️ `ltrim` بيشيل حروف مش prefix — لازم `substr`.
            // و URI بتاع `Route::getRoutes()` فيه prefix الـ `api`،
            // فالمفتاح لازم يبقى "GET api/expenses" مش "GET expenses".
            $key = $method.' api/'.substr($uri, strlen('/api/'));

            if (! $routes->has($key)) {
                $missing[] = "«{$uri}» — الراوت مش موجود أو مش جوه auth:sanctum";

                continue;
            }

            if (! $this->hasPermissionMiddleware($routes->get($key))) {
                $missing[] = "«{$method} {$uri}» — لسه من غير permission";
            }
        }

        $this->assertSame([], $missing, "الراوتس دي اتغيرت:\n  • ".implode("\n  • ", $missing));
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * كل الراوتس جوه auth:sanctum، مفتاحها = "METHOD uri".
     *
     * @return Collection<string, Route>
     */
    private function authenticatedRoutes(): Collection
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true))
            ->mapWithKeys(fn (Route $route) => [
                $route->methods()[0].' '.$route->uri() => $route,
            ]);
    }

    private function hasPermissionMiddleware(Route $route): bool
    {
        return $this->indexOfPermissionMiddleware($route->gatherMiddleware()) !== false;
    }

    /**
     * ⭐ لازم يتعامل مع كل أشكال الـ authentication:
     *   • auth:sanctum                                   — قبل حل الـ alias
     *   • Illuminate\Auth\Middleware\Authenticate:sanctum — بعد حل الـ alias
     */
    private function indexOfAuthMiddleware(array $middleware)
    {
        foreach ($middleware as $index => $name) {
            if (! is_string($name)) {
                continue;
            }

            if ($name === 'auth' || $name === 'auth:sanctum' || str_ends_with($name, 'Authenticate:sanctum')) {
                return $index;
            }
        }

        return false;
    }

    /**
     * ⚠️ لازم يتعامل مع الشكلين:
     *   • permission:finance.view    — الشكل العادي
     *   • permission:finance.view,x  — الشكل الـ array
     *
     * و permission كـ middleware name جاي من alias في bootstrap/app.php.
     */
    /**
     * ⭐ لازم يتعامل مع الشكلين — المتغير ده هو اللي كان بيوقّعنا:
     *
     *   • permission:finance.view            — الشكل **قبل** حل الـ alias
     *     (اللي بيرجّعه gatherMiddleware)
     *
     *   • App\Http\Middleware\CheckPermission:finance.view
     *     — الشكل **بعد** حل الـ alias
     *     (اللي بيرجّعه gatherRouteMiddleware — وهو اللي بيشتغل فعلاً)
     *
     * ⚠️ لو فحصنا الشكل الأول بس، كنا هنفتقدوا كل الراوتس لأن الـ
     * alias بيتبسّط في الترتيب الفعلي.
     */
private function indexOfPermissionMiddleware(array $middleware)
    {
        foreach ($middleware as $index => $name) {
            if (! is_string($name)) {
                continue;
            }

            if (str_starts_with($name, 'permission:') || str_contains($name, 'CheckPermission:')) {
                return $index;
            }
        }

        return false;
    }

    /**
     * طلب على راو محمي — بيملا الـ placeholders بأرقام.
     *
     * المهم إن الطلب يوصل للـ middleware مش للـ controller. لو الـ
     * permission اتطبّقت، بيرجّع 403 قبل ما يعمل lookup للموديل.
     */
    private function callProtected(string $method, string $uri, $user): TestResponse
    {
        $headers = $this->authHeaders($user);

        // `substr` مش `ltrim` — ltrim بيشيل حروف مش prefix.
        $uri = preg_replace('/\{[^}]+\}/', '1', $uri);

        return match ($method) {
            'GET' => $this->withHeaders($headers)->getJson($uri),
            'POST' => $this->withHeaders($headers)->postJson($uri, []),
            'PUT' => $this->withHeaders($headers)->putJson($uri, []),
            'DELETE' => $this->withHeaders($headers)->deleteJson($uri),
        };
    }
}
