<?php

namespace Tests\Feature;

use App\Models\ParentModel;
use App\Models\Student;
use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * ⭐⭐⭐ عزل الأدوار — شبكة الأمان بعد كل ثغرة اتقفلت في Phase 0.
 *
 * ═══════════════════════════════════════════════════════════════
 * إيه اللي ده بيعمل، وإيه اللي **مش** بيعمله
 *
 * `RouteProtectionTest` (PR 0.1) بيقول: «كل راو محمي إما عليه
 * صلاحية أو باستثناء موثّق». ده صح، بس مش كافي:
 *
 *   ⭐ راو عليه `permission:finance.movements` — الـmiddleware موجود
 *     والاختبار راضي. بس **مين** اللي عنده الصلاحية دي؟ محدش
 *     بيسأل. لو الـseeder غيّر قائمة صلاحيات دور، محدش في
 *     الاختبارات هيعرف إن الدور فقد الوصول.
 *
 *   ⭐ وده مش hypothetical. في `SeedRolesPermissions` و
 *     `SeedEmployees` فيه **قائمتين مختلفتين لدور `accountant`**،
 *     والاتنين بيستخدموا `syncPermissions` — فاللي بيشتغل **آخر**
 *     هو اللي بيفوز. و`RealisticDataSeeder` بيشغّل
 *     `SeedEmployees` **بعد** `SeedRolesPermissions`، فالمحاسب في
 *     البيانات التجريبية بيفقد **كل** صلاحياته المالية.
 *     الاختبار ده بيكشف ده.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه المصفوفة بتتولد من جدول الراوتس نفسها
 *
 * المصفوفة مش مكتوبة بإيدنا — بتتولد من `Route::getRoutes()` وقت
 * التشغيل. يعني:
 *
 *   • راو جديد بيتضاف  → بيدخل المصفوفة **تلقائياً**
 *   • راو بيتشال       → بيخرج **تلقائياً**
 *
 * ⚠️ مصفوفة مكتوبة بإيدها بتتقادم بهدوء. بعد ستة شهور محدش متأكد
 * إن `/api/payroll/lines/{line}/pay` اتغطّت فعلاً ولا كانت مغطّاة
 * قبل كده واتبعتت.
 *
 * @see \Tests\Feature\RouteProtectionTest  شكل الراوت ومركزه
 * @see ROADMAP.md  PR 1.7  استبدال الأسماء + جدول التوقعات الكامل
 */
class RoleIsolationTest extends TestCase
{
    /**
     * ⭐ الأدوار اللي بيتجرب عليها العزل، مع سبب كل واحد.
     *
     * ⭐ **`accountant` مش هنا** — هو دور **متوسط** بيشوف المالية.
     * فمافيش له اختبار «يرفض كل حاجة». اختباره هو الفصل: بيشوف
     * اللي له وبيرفض اللي مالوش.
     *
     * @var array<string, string>
     */
    private const RESTRICTED_ROLES = [
        'teacher' => 'معلم — له programs.view بس. أي حاجة أكتر من كده تسريب.',
        'parent' => 'ولي أمر — له programs.view وبوابة ابنه بس. لا غير.',
        'receptionist' => 'استقبال — مفيش مالي خالص. ده الفصل اللي بنحميه.',
    ];

    /** ⭐ الأدوار اللي لازم الـseeders بتنتجها — مش بس اللي فوق */
    private const EXPECTED_ROLES = ['admin', 'accountant', 'receptionist', 'teacher', 'parent'];

    protected function setUp(): void
    {
        parent::setUp();

        // ⭐ بنشغّل الـseeders **الحقيقيين** — عشان الصلاحيات اللي
        // الاختبار بيختبرها هي نفس اللي في الإنتاج، مش نسخة.
        //
        // ⚠️ لازم الاتنين، مش واحد: كل واحد فيهم بيكتب على الأدوار،
        // والترتيب بيحدد مين بيفوز (ده بالظبط اللي بيتختبر تحت).
        $this->seed(\Database\Seeders\SeedRolesPermissions::class);
        $this->seed(\Database\Seeders\SeedEmployees::class);
    }

    /**
     * ⭐⭐ الموظف بيوصل فعلاً للدور بتاعه.
     *
     * ⭐ الاختبار اللي فوق بيحرس **قيمة** الدور. ده بيحرس **الوصلة**:
     * إن `SeedEmployees` لسه بيعرف يحطّ الدور على الموظف الصح.
     *
     * ⭐ مهم بعد نقل تعريف الأدوار لـ`ROLES`: `SeedEmployees` بقى
     * بيقرأ `$roleIds` من الكتالوج بدل ما يبنيه من قائمته. لو
     * القراءة غلط، `$roleIds` بتطلع فاضية وكل موظف بيفضل
     * **من غير دور** — ونظام مكسور بصمت تاني.
     */
    public function test_seeded_employees_actually_receive_their_role(): void
    {
        $receptionist = $this->userForRole('receptionist');

        // ⭐ `SeedEmployees` بيعمل `User` per `Employee` والدور بيتحدد
        // من **عنوان الوظيفة** (`job_title`)، مش من اسم عشوائي.
        $seeded = User::where('email', 'like', '%@quran-academy.com')->get();

        $this->assertNotEmpty($seeded, 'SeedEmployees مأنشأش أي موظف — الـseeder مش شغال');

        $withoutRole = $seeded->filter(fn (User $user) => $user->roles->isEmpty());

        $this->assertSame(
            [],
            $withoutRole->pluck('email')->values()->all(),
            'موظفين اتعملوا من غير دور — المسار اللي بيقرأ ROLES غلط'
        );

        $this->assertTrue(
            $seeded->contains(fn (User $user) => $user->hasRole('receptionist')),
            'مفيش ولا موظف استقبال اتعمل — التحويل من عنوان الوظيفة للدور بقى غلط'
        );

        unset($receptionist);
    }

    // ═══════════════════════════════════════════════════════════════
    // ① الأدوار حقيقية — ولا واحد فيهم متخيّل
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ الاختبار ده بيكشف أسماء الأدوار الغلط.
     *
     * ⚠️ لو كتبت `makeUserWithRole('receptionist', …)` والدور مش
     * موجود في الـseeder، `firstOrCreate` بيعمله **من أول مرة**
     * والاختبار يعدّي بهدوء — وفي الإنتاج ما حدش يقدر يسجّل دخول
     * أصلاً. الـassert ده بيمنع الاختبار يكذب.
     */
    public function test_every_isolation_role_is_produced_by_a_seeder(): void
    {
        foreach (self::EXPECTED_ROLES as $roleName) {
            $this->assertTrue(
                Role::where('name', $roleName)->exists(),
                "دور «{$roleName}» مش موجود بعد تشغيل الـseeders. "
                .'لو الاختبار عايز يجرب عليه، لازم يكون دور حقيقي في الإنتاج.'
            );
        }
    }

    /**
     * ⭐⭐⭐ يوم واحد لت **قائمة** الدور = يا ديما فيا يا مفيش.
     *
     * `syncPermissions` بيمسح اللي قبله ويكتب الجديد. فأي دور
     * معرّف في أكتر من seeder: **آخر واحد بيشتغل بيفوز** وبس.
     *
     * ⭐ في الحالة دي: `SeedRolesPermissions` بيقول إن المحاسب ليه
     * `finance.*`، و`SeedEmployees` بيقوله ليه `attendance.view`.
     * والترتيب بيلغي كلام الأول، فالنتيجة: **محاسب مش بيقدر يفتح
     * صفحة الحسابات أصلاً** — في البيانات اللي بتتسلم للعميل.
     *
     * ⭐ الاختبار ده مش بيفضّل إيهpermissions للمشاهد. بيعمل
     * حاجة أقوى: **بيفرض إن الدور يفضل زي ما أول seeder قاله**.
     * فلو غيرنا نية الدور، لازم نعدل في الـseeder المالك — وده
     * بالظبط اللي عايزينه يبقى صعب.
     */
    public function test_no_role_is_defined_by_two_different_seeders(): void
    {
        // ⭐ الحالة (أ) — **بعد** تشغيل الاتنين. ده اللي شغال
        // فعلياً في البيانات اللي بتتسلم للعميل.
        $actual = $this->permissionsByRole();

        // ⭐ الحالة (ب) — بعد **`SeedRolesPermissions` لوحده**.
        //
        // ⚠️ ليش بنمسح جداول الأدوار بدل ما نشغّل الـseeder تاني؟
        // لأن `SeedEmployees` بيعمل `create` للمستخدمين — مش
        // `firstOrCreate` — فإعادة تشغيله بيكسر الـunique على
        // `(organization_id, email)`. المسح هنا بينسّق الحالة
        // من غير ما نلمس المستخدمين.
        DB::table('role_has_permissions')->delete();
        DB::table('model_has_roles')->delete();
        DB::table('roles')->delete();

        $this->seed(\Database\Seeders\SeedRolesPermissions::class);

        $authoritative = $this->permissionsByRole();

        $conflicts = [];

        foreach ($actual as $role => $resulted) {
            if (! array_key_exists($role, $authoritative)) {
                continue;
            }

            if ($resulted === $authoritative[$role]) {
                continue;
            }

            $added = array_values(array_diff($resulted, $authoritative[$role]));
            $removed = array_values(array_diff($authoritative[$role], $resulted));

            $conflicts[] = sprintf(
                '«%s»: زادت [%s] · ناقصت [%s] — يعني seeder تاني بيكتب فوق %s',
                $role,
                $added ? implode(', ', $added) : 'لا شيء',
                $removed ? implode(', ', $removed) : 'لا شيء',
                'SeedRolesPermissions',
            );
        }

        $this->assertSame(
            [],
            $conflicts,
            "دور معرّف في أكتر من seeder، واللي بيشتغل آخر بياخد السياج:\n  • "
            .implode("\n  • ", $conflicts)
            ."\n\nالنتيجة العملية: الصلاحيات اللي في `SeedRolesPermissions` بتمشي "
            ."لأن الـseeder التاني بيمسحها ويعوضها بقائمة تانية. "
            ."الحل: دور واحد في مصدر واحد."
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الفصل المالي
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ الاستقبال والمعلم وولي الأمر **مش** بيوصلوا للمالية.
     *
     * ⭐ الراوتات دي **مش مكتوبة هنا** — بتتولد من جدول الراوتس.
     * فلو حد ضاف `GET /api/payroll/pay` هيبقى جوّه المصفوفة في
     * نفس الـrun.
     */
    public function test_a_receptionist_cannot_reach_a_single_financial_route(): void
    {
        $this->assertCannotReachAny('receptionist', $this->financialRouteKeys());
    }

    public function test_a_teacher_cannot_reach_a_single_financial_route(): void
    {
        $this->assertCannotReachAny('teacher', $this->financialRouteKeys());
    }

    /** ⭐ ولي الأمر أضيق — ما عندوش حتى `programs.view`. */
    public function test_a_parent_cannot_reach_a_single_financial_route(): void
    {
        $this->assertCannotReachAny('parent', $this->financialRouteKeys());
    }

    /**
     * ⭐⭐ المعاكس: اللي عنده الصلاحية لازم يعدّي.
     *
     * ⭐ من غير الاختبار ده ممكن نكون قفلنا الباب **على الصح**
     * كمان. الإصلاح مش «ارفض الكل» — الإصلاح «ارفع اللي عنده
     * التصريح بس».
     */
    public function test_the_accountant_can_still_reach_the_financial_routes(): void
    {
        $accountant = $this->userForRole('accountant');

        $this->assertTrue(
            $accountant->can('finance.accounts.manage'),
            'المحاسب فقد finance.accounts — راجع تعارض الدور في test_no_role_is_defined_by_two_different_seeders'
        );
        $this->assertTrue($accountant->can('finance.movements.create'), 'المحاسب فقد finance.movements');
        $this->assertTrue($accountant->can('finance.accounts.view'), 'المحاسب فقد finance.view');
    }

    /**
     * ⭐⭐⭐ **فصل المهام** — أهم اختبار في الملف كله.
     *
     * المحاسب يسجّل تحصيل ومصروف. بس **العكس** (تصحيح دفعة
     * اتسجلت غلط) و**فتح فترة مقفلة** — قرار الإدارة.
     *
     * ⭐ السبب: لو المحاسب قدر يعكس أي دفعة، الأرقام اللي الإدارة
     * وقّعتها بقت قابلة للتغيير بإيد حد في التحصيل — وده بالظبط
     * اللي الأرقام المالية كلها مبنية عشانه تمنعه.
     *
     * ⭐ الـassert على الراوت نفسه مش على `can()` بس — عشان
     * نعرف إن الفصل متنفّذ فعلاً في طبقة الـmiddleware.
     */
    public function test_the_accountant_can_record_but_never_reverse_or_reopen(): void
    {
        $accountant = $this->userForRole('accountant');

        $this->assertTrue($accountant->can('finance.movements.create'), 'لازم يسجّل حركة');
        $this->assertTrue($accountant->can('finance.periods.lock'), 'لازم يقدر يقفل شهر');

        $this->assertFalse(
            $accountant->can('finance.movements.reverse'),
            '⭐ المحاسب بقى يعكس دفعة — ده أهم فصل في الخطة، وكسره خلّى الأرقام قابلة للتزوير'
        );
        $this->assertFalse(
            $accountant->can('finance.periods.unlock'),
            '⭐ المحاسب بقى يفتح فترة مقفولة — فتح الفترة قرار الإدارة'
        );

        // ⭐⭐ والـراو نفسه: مش بس الـpermission، الـmiddleware نفسه.
        foreach ([
            'POST api/movements/{movement}/reverse',
            'POST api/period-locks/{lock}/unlock',
        ] as $key) {
            $this->callRoute($this->routeByKey($key), $accountant)
                ->assertForbidden("المحاسب وصل «{$key}» — الفصل مكسور");
        }
    }

    /**
     * ⭐⭐⭐ **تسريب وجود** — أخطر حاجة في الملف ده.
     *
     * ⭐ الفخ اسمه `$middlewarePriority`:
     *
     *     AuthenticatesRequests
     *     ThrottleRequests
     *     SubstituteBindings   ← بيشتغل قبل CheckPermission
     *     Authorize
     *
     * يعني على `GET /students/{student}/payments` كان بيحصل:
     *
     *   ١. `SELECT * FROM students WHERE id = ?`
     *   ٢. مش موجود → **404 فوراً** (قبل أي فحص صلاحية)
     *   ٣. موجود    → 403
     *
     * ⭐ فمستخدم مالوش `payments.view` كان يقدر يفحص أي رقم
     * ويفتكر: 404 = مش موجود، 403 = موجود. ده **enumerating
     * للداتابيز كلها** من غير أي صلاحية — وبيعرف كمان عدد
     * الصلاحية بتتطبّق **قبل** ما Laravel يحمّل السجل من الداتابيز.
     *
     * ⭐ الاختبار ده بيفرض **التطابق**: نفس الصلاحية = نفس الرد
     * سواء الـid موجود أو مش موجود. والمستخدم اللي **عنده**
     * الصلاحية لسه بياخد 404 صح لما الـid غلط — عشان نمتنعش
     * نكسر الـUX.
     *
     * @see backend/README.md  «ترتيب الـmiddleware — الفخ اللي بيقتل كل حاجة»
     */
    public function test_being_denied_does_not_reveal_whether_the_id_exists(): void
    {
        $receptionist = $this->userForRole('receptionist');

        $existing = $this->makeStudent();
        $missing = Student::max('id') + 1000;

        $route = $this->routeByKey('GET api/students/{student}/payments');

        $withId = $this->callRouteWithId($route, $receptionist, (string) $existing->id);
        $withoutId = $this->callRouteWithId($route, $receptionist, (string) $missing);

        $this->assertSame(
            $withId->status(),
            $withoutId->status(),
            sprintf(
                '⭐⭐ الرد بيقول وجود الـid: id=%d رجّع %d، وid=%d رجّع %d. '
                .'حد مالوش صلاحية يقدر يخمّن الأرقام الموجودة في الداتابيز.',
                $existing->id,
                $withId->status(),
                $missing,
                $withoutId->status(),
            )
        );

        $this->assertSame(
            403,
            $withId->status(),
            'المفروض 403 — الراوت محمية بـ payments.view والاستقبال مالوش'
        );
    }

    /**
     * ⭐⭐ المعاكس: اللي **عنده** الصلاحية لسه بياخد 404 صح.
     *
     * ⭐ ده اللي بيمنعنا نحلّ التسريب بإجابة غلط: الرجوع لـ404
     * للكل بيخفي المعلومة، بس بيخلي المستخدم الصح ميفهمش إن
     * الطالب مش موجود. الفصل: **غير المصرّح 403 دايماً،
     * المصرّح بياخد 404 لما الـid غلط**.
     */
    public function test_a_permitted_user_still_gets_a_404_for_a_missing_id(): void
    {
        $missing = Student::max('id') + 1000;

        $accountant = $this->userForRole('accountant');

        $this->assertTrue($accountant->can('finance.payments.view'));

        $this->callRouteWithId($this->routeByKey('GET api/students/{student}/payments'), $accountant, (string) $missing)
            ->assertNotFound('المحاسب عنده payments.view فلازم ياخد 404 الصادق لما الـid مش موجود');
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ ولي الأمر
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ العزل الحقيقي: **مش** إن ولي الأمر بيوصل للراو — ده
     * موجود في `RouteProtectionTest`.
     *
     * ده إن ولي الأمر **تلميذ** مايشوفش **ولده التاني**.
     *
     * ⭐ `/parent/children` مالهوش `permission:` — محمي بـ
     * `parent.only`. فالعزل كله معناه: كل استعلام جوّاه يبدأ من
     * `parents.user_id = المستخدم الحالي`.
     *
     * ⭐ لو الـquery نسي الـwhere، الباقي كله سليم بس الدنيا
     * بتتسرّب. عشان كده الاختبار ده بيقارن **المحتوى** مش الكود.
     */
    public function test_a_parent_sees_only_their_own_children(): void
    {
        $theirChild = $this->makeStudent(['first_name' => 'ابني', 'last_name' => 'الصالح']);
        $otherChild = $this->makeStudent(['first_name' => 'مش', 'last_name' => 'ابني']);

        $myProfile = $this->parentProfileFor($theirChild);
        $theirProfile = $this->parentProfileFor($otherChild);

        $me = $this->linkParentUser($myProfile, 'parent');

        $body = $this->getJsonAs('/api/parent/children', $me);

        $body->assertOk();

        $ids = collect($body->json('children'))->pluck('student.id');

        $this->assertTrue(
            $ids->contains($theirChild->id),
            'ولي الأمر مش شايف ابنه — البوابة اتعطلت، وده غلط'
        );

        $this->assertFalse(
            $ids->contains($otherChild->id),
            '⭐⭐ ولي الأمر شايف ابن حد تاني في «children» — تسريب بيانات'
        );

        // ⭐ والـpayload كله مافيهوش أثر للغير
        $raw = $body->getContent();

        $this->assertStringNotContainsString(
            $theirProfile->name,
            $raw,
            'اسم ولي الأمر التاني ظاهر في الرد'
        );
    }

    /**
     * ⭐ حساب **مش** ولي أمر خالص: `parent.only` لازم يرفضه.
     *
     * ⭐ المعلم عنده `programs.view` بس. فلو `parent.only` اتكسر،
     * البوابة هترجّع لteacher عادي ويشوف بوابة فاضية.
     */
    public function test_an_account_that_is_not_a_parent_is_refused_the_portal(): void
    {
        $this->getJsonAs('/api/parent/children', $this->userForRole('teacher'))
            ->assertForbidden();
    }

    /**
     * ⭐⭐ وآخر واحد: **مفيش راو تاني** مخصوص لولي الأمر.
     *
     * ⭐ لو حد ضاف `GET /api/parent/payments` بـ
     * `permission:payments.view`، ومعلّم الحصة كان عنده
     * `payments.view`، يبقى ولي الأمر فتح بوابة جديدة من غير ما
     * الاختبارات تحس. عشان كده: راوتات `parent/*` لازم تكون
     * محمية بـ `parent.only` **مش** بصلاحية.
     */
    public function test_parent_routes_are_protected_by_parent_only_not_by_a_permission(): void
    {
        $bad = [];
        $checked = 0;

        foreach ($this->authenticatedRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/parent/')) {
                continue;
            }

            $checked++;
            $middleware = $route->gatherMiddleware();

            if ($this->indexOfParentOnly($middleware) === false) {
                $bad[] = 'api/'.$route->uri().' — مالهوش parent.only';
            }
        }

        // ⭐ الراوت لازم **موجود** الأول — غير كده الاختبار يعدّي
        // وهو مافحصش حاجة (نفس فخ «المصفوفة الفاضية» تحت).
        $this->assertGreaterThan(0, $checked, 'مفيش راوتات api/parent/* — الاختبار ده بقى بلا معنى');

        $this->assertSame([], $bad, "راوات لولي الأمر محمية بصلاحية بدل parent.only:\n  • ".implode("\n  • ", $bad));
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ المصفوفة الشاملة
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **المصفوفة الكاملة** — دي الـacceptance بتاع الـPR.
     *
     * بتتولّد من جدول الراوتس: كل راو عليه صلاحية لازم يرفض
     * الدور اللي مالوش الصلاحية. مش مكتوبة بإيدنا، فمش بتتقادم.
     *
     * ⭐ وبتتحقق من **كل** راو محمي — مش بس اللي متعلق بالدور
     * ده. عشان لو `teacher` فقد `programs.view` من غير ما حد
     * ياخد باله، الاختبار ده يصرخ.
     */
    public function test_the_matrix_denies_every_role_it_does_not_own(): void
    {
        $checked = 0;
        $leaks = [];

        foreach (self::RESTRICTED_ROLES as $roleName => $why) {
            $user = $this->userForRole($roleName);
            $permissions = $this->rolePermissions($roleName);

            foreach ($this->protectedRoutes() as $route) {
                $needed = $this->permissionsOnRoute($route);

                if ($needed === []) {
                    continue; // مالهوش صلاحية — ليه حماية تانية
                }

                if (array_intersect($needed, $permissions) !== []) {
                    continue; // ⭐ الدور عنده واحدة منهم — يعدّي صح
                }

                $checked++;

                $status = $this->callRoute($route, $user)->status();

                if ($status !== 403) {
                    $leaks[] = sprintf(
                        'دور «%s» وصل «%s /%s» (رجّع %d بدل 403) — محمية بـ [%s] وعنده [%s]',
                        $roleName,
                        $route->methods()[0],
                        $route->uri(),
                        $status,
                        implode(', ', $needed),
                        $permissions ? implode(', ', $permissions) : '(مفيش)',
                    );
                }
            }
        }

        $this->assertGreaterThan(
            0,
            $checked,
            'المصفوفة مافحصتش ولا راو — يعني الاختبار ده عدّى من غير ما يعمل حاجة'
        );

        $this->assertSame(
            [],
            $leaks,
            "عزل الأدوار مكسور:\n  • ".implode("\n  • ", $leaks)
        );
    }

    /**
     * ⭐⭐ الاتجاه الصح: **المصفوفة مش فارغة، ومش كلها رفض**.
     *
     * ⭐ لو `protectedRoutes()` رجّعت فاضية، الاختبار اللي فوق
     * يعدّي من غير ما يفحص حاجة — وده أسوأ بكتير من إنه يفشل.
     * الاختبار ده بيحرس **الحرّاس نفسه**.
     */
    public function test_the_matrix_actually_covers_the_routes(): void
    {
        $routes = $this->protectedRoutes();

        $this->assertGreaterThan(
            80,
            $routes->count(),
            'جدول الراوتس قارب يكون اتقريب — لو ده غلط، راجع middlewarePriority جوه auth:sanctum'
        );

        foreach (self::RESTRICTED_ROLES as $roleName => $why) {
            $allowed = 0;

            foreach ($routes as $route) {
                $needed = $this->permissionsOnRoute($route);

                if ($needed !== [] && array_intersect($needed, $this->rolePermissions($roleName)) !== []) {
                    $allowed++;
                }
            }

            $this->assertGreaterThan(
                0,
                $allowed,
                "دور «{$roleName}» مش بيوصل **لأي** راو محمي — يعني المصفوفة كلها رفض، "
                .'والاختبار بيبقى مكسور مش آمن. '.$why
            );
        }
    }

    /**
     * ⭐⭐⭐ الـacceptance الحقيقي بتاع الـPR: **راو جديد يدخل المصفوفة**.
     *
     * ⭐ بنضيف راو **من غير صلاحية** ونطلب منه لـ`receptionist` —
     * لازم يعدّي. وبعدين بنضيفه **بصلاحية مالية** ونطلب تاني —
     * لازم يترفض.
     *
     * ⭐ الـassert الأخير: الراوت الجديد **دخل** المصفوفة فعلاً
     * (`count` زاد). من غيره الاختبار الأول ممكن يعدّي لأن الراوت
     * مش اتقيّد أصلاً، ونكون بنفحص متنساش.
     */
    public function test_a_route_added_at_runtime_joins_the_matrix(): void
    {
        $before = $this->protectedRoutes()->count();

        $routes = $this->app['router'];

        $handler = fn () => response()->json(['ok' => true]);

        // ① راو بلا صلاحية — يعدّي
        $routes->get('/_matrix/open', $handler)->middleware(['auth:sanctum']);

        // ② راو **بوحدة صلاحية واحدة** — عشان نختبر الشكل الـarray
        //    جوه `permissionsOnRoute` كمان
        $routes->get('/_matrix/secret', $handler)
            ->middleware(['auth:sanctum', 'permission:finance.movements']);

        $after = $this->protectedRoutes()->count();

        $this->assertSame(
            $before + 1,
            $after,
            'الراوت الجديد بالصلاحية المالية ما دخلش المصفوفة — يعني المصفوفة مش بتتولّد من جدول الراوتس'
        );

        // ③ الراوت المحمي لازم يترفض من دور مالوش الصلاحية
        //
        // ⚠️ المفتاح **من غير** بادئة `api` — الراوت اتسجّل على
        // الـrouter مباشرة مش جوه مجموعة `api`، فـ`uri()` بترجّع
        // `_matrix/secret` زي ما هي.
        $this->callRoute($this->routeByKey('GET _matrix/secret'), $this->userForRole('receptionist'))
            ->assertForbidden('راو اتضاف حالاً بصلاحية مالية و`receptionist` وصله — المصفوفة مش شغالة');

        // ④ والراوت المفتوح يعدّي — عشان نثبت إن الرفض في (3)
        //    سببه الصلاحية، مش إن أي راو جديد بيرجع 403
        $this->callRoute($this->routeByKey('GET _matrix/open'), $this->userForRole('receptionist'))
            ->assertOk('الراوت المفتوح اترفض — الرفض في (3) سببه الصلاحية مش الراوت نفسه');
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers — المصفوفة
    // ═══════════════════════════════════════════════════════════════

    /** @return Collection<int, Route> */
    private function authenticatedRoutes(): Collection
    {
        return collect(RouteFacade::getRoutes()->getRoutes())
            ->filter(fn (Route $route) => in_array('auth:sanctum', $route->gatherMiddleware(), true));
    }

    /** ⭐ الراوتات اللي عليها صلاحية */
    private function protectedRoutes(): Collection
    {
        return $this->authenticatedRoutes()
            ->filter(fn (Route $route) => $this->permissionsOnRoute($route) !== []);
    }

    /**
     * ⭐⭐ الراوتات **المالية** — بالصلاحيات مش بالـURI.
     *
     * معرّفة بالصلاحيات عشان لو حد سمّى راو `/api/money`
     * بـ `finance.movements` يبقى مشمول تلقائياً.
     *
     * @return string[] مفاتيح "METHOD api/uri"
     */
    private function financialRouteKeys(): array
    {
        return $this->protectedRoutes()
            ->filter(function (Route $route) {
                foreach ($this->permissionsOnRoute($route) as $permission) {
                    if (str_starts_with($permission, 'finance.')
                        || str_starts_with($permission, 'payroll.')
                        || str_starts_with($permission, 'payments.')) {
                        return true;
                    }
                }

                return false;
            })
            ->map(fn (Route $route) => $route->methods()[0].' '.$route->uri())
            ->values()
            ->all();
    }

    /**
     * ⭐⭐ صلاحيات الراوت — بالشكلين.
     *
     * `permission:a` بييجي نص، و`permission:a,b` بييجي array. لو
     * فوتنا الشكل التاني، راو بصلاحية واحدة هيتحسب مالوش صلاحية
     * ويدخل المصفوفة غلط.
     *
     * @return string[]
     */
    private function permissionsOnRoute(Route $route): array
    {
        $found = [];

        foreach ($route->gatherMiddleware() as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            if (str_starts_with($entry, 'permission:')) {
                $found = array_merge($found, explode(',', substr($entry, strlen('permission:'))));
            } elseif (str_contains($entry, 'CheckPermission:')) {
                $found = array_merge($found, explode(',', explode('CheckPermission:', $entry)[1]));
            }
        }

        return array_values(array_filter(array_map('trim', $found)));
    }

    private function indexOfParentOnly(array $middleware)
    {
        foreach ($middleware as $index => $name) {
            if (! is_string($name)) {
                continue;
            }

            // ⭐ الشكلان: `parent.only` قبل حل الـalias، و
            // `App\Http\Middleware\EnsureUserIsParent` بعده.
            if ($name === 'parent.only' || str_contains($name, 'EnsureUserIsParent')) {
                return $index;
            }
        }

        return false;
    }

    /**
     * ⭐ المفتاح = "METHOD uri" و`uri` زي `Route::uri()` بيرجّعها —
     * يعني **متضمنة** بادئة الـ`api`.
     *
     * ⭐ `routeByKey` بيبسّط البادئة لو حد كتبها، عشان كده خطأ
     * الكتابة في المفتاح ما يطلعش رسالة غامضة.
     */
    private function routeByKey(string $key): Route
    {
        [$method, $uri] = explode(' ', $key, 2);

        $uri = preg_replace('#^api/api/#', 'api/', $uri);

        $route = $this->authenticatedRoutes()->first(
            fn (Route $r) => $r->methods()[0] === $method && $r->uri() === $uri
        );

        $this->assertNotNull($route, "الراوت «{$key}» مش موجود — الاختبار قديم والراوت اتشال أو اتغير");

        return $route;
    }

    /**
     * ⭐⭐⭐ بتجمّع كل الاختراقات وتقولهم **مرة واحدة**.
     *
     * ⚠️ ليش مش `assertForbidden` جوه اللوب؟
     *
     * لأن `assertForbidden` بيقفل عند أول إخفاق، فالخطأ بيطلع
     * لواحد بس من ٤٠ راو — والموضوع بالظبط إزاي نعرف، وكمان
     * إن ده بيضيّع وقت تشغيل الاختبار كل مرة. التجميع بيقول
     * «الراوتات دي كلهم مفتوحين» في سطر واحد.
     */
    private function assertCannotReachAny(string $roleName, array $routeKeys): void
    {
        $this->assertNotEmpty(
            $routeKeys,
            'مفيش راوتات مالية اتعرفت — يعني الاختبار ده بيفحص لا حاجة'
        );

        $user = $this->userForRole($roleName);
        $leaks = [];

        foreach ($routeKeys as $key) {
            $response = $this->callRoute($this->routeByKey($key), $user);

            if ($response->status() !== 403) {
                $leaks[] = sprintf('«%s» رجّع %d بدل 403', $key, $response->status());
            }
        }

        $this->assertSame(
            [],
            $leaks,
            "دور «{$roleName}» وصل راوتات مالية:\n  • ".implode("\n  • ", $leaks)
        );
    }

    /**
     * ⭐ طلب على راو — بملا الـplaceholders بأرقام.
     *
     * المهم إن الطلب يوصل للـmiddleware. لو الـpermission اتطبّقت
     * بيرجّع 403 قبل أي lookup للموديل.
     */
    private function callRoute(Route $route, User $user): TestResponse
    {
        return $this->callRouteWithId($route, $user, '1');
    }

    /**
     * ⭐ نفس `callRoute` بس بيملا الـplaceholder بقيمة **محددة**.
     *
     * ⭐ لازمها لاختبار تسريب الوجود: عايزين نقارن بين id موجود
     * وid مش موجود على **نفس** الراوت ونفس المستخدم.
     */
    private function callRouteWithId(Route $route, User $user, string $id): TestResponse
    {
        $headers = $this->authHeaders($user);

        $uri = '/'.preg_replace('/\{[^}]+\}/', $id, $route->uri());

        return match ($route->methods()[0]) {
            'GET', 'HEAD' => $this->withHeaders($headers)->getJson($uri),
            'POST' => $this->withHeaders($headers)->postJson($uri, []),
            'PUT', 'PATCH' => $this->withHeaders($headers)->putJson($uri, []),
            'DELETE' => $this->withHeaders($headers)->deleteJson($uri),
            default => $this->withHeaders($headers)->getJson($uri),
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers — الأدوار
    // ═══════════════════════════════════════════════════════════════

    /** ⭐⭐ دور بحقوقه **الحقيقية** من الـseeders */
    private function userForRole(string $roleName): User
    {
        $role = Role::where('name', $roleName)->first();

        $this->assertNotNull($role, "دور «{$roleName}» مش موجود — الـseeders م|Shغّلوش");

        $user = $this->makeUser();
        $user->assignRole($role);

        return $user->fresh();
    }

    /** @return string[] */
    private function rolePermissions(string $roleName): array
    {
        return Role::where('name', $roleName)->first()?->permissions->pluck('name')->sort()->values()->all() ?? [];
    }

    /** @return array<string, string[]> دور => صلاحياته (مرتبة) */
    private function permissionsByRole(): array
    {
        return Role::with('permissions')->get()->mapWithKeys(
            fn (Role $role) => [
                $role->name => $role->permissions->pluck('name')->sort()->values()->all(),
            ]
        )->all();
    }

    // ═══════════════════════════════════════════════════════════════
    // Profile fixtures
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ ملف ولي أمر **مرتبط بابن**.
     *
     * ⭐ بيبني على `makeParentProfile()` في `BuildsFixtures` (PR 1.3)
     * ويربطه بابن. بعد موحّدة الـfixtures، الدالة الأولى مكانش
     * لازم تتحط في ملف الاختبار أصلاً — تعريف واحد يعني تعديل
     * واحد.
     *
     * ⭐ `email` بيبقى **فاضي** هنا. قبل PR 1.3 كان
     * `parent{$n}@test.local`، وده بقى **خطر**: القيد العام على
     * `users.email` كان هيصطدم مع أي حساب آخر في نفس الاختبار.
     */
    private function parentProfileFor(Student $child, array $attrs = []): ParentModel
    {
        $parent = $this->makeParentProfile(null, $attrs);

        $child->parents()->attach($parent->id, [
            'relationship' => 'father',
            'is_primary' => true,
            // ⭐ PR 4.5: الرابط بلا `status` بقى `pending_verification`
            // (PR 1.11) — والبوابة بقت تفلتر المؤكد بس. الـfixture هنا
            // بيمثّل ربط **موظف أكّده**، زي باقي الاختبارات.
            'status' => 'verified',
        ]);

        return $parent->fresh();
    }

    /**
     * ⭐ حساب دخول مربوط بملف ولي أمر.
     *
     * ⭐⭐ **مافيش `is_parent => true`** — بقى مشتقّ (PR 1.3).
     * السطر القديم كان بيكتب عمود ما بقاش له معنى، وأي حد
     * بيفتكر إنه لسه بيشتغل لازم يقرا `hasParentProfile()`.
     *
     * ⭐ الترتيب مهم: نربط الـ`user_id` **قبل** ما نقرأ `is_parent`.
     * الـaccessor بيسأل عن `parentProfile`، فربطه بعد القراءة
     * هيدي `false` وهو كده.
     */
    private function linkParentUser(ParentModel $parent, string $roleName): User
    {
        $user = $this->makeUser();

        $parent->update(['user_id' => $user->id]);

        $user->assignRole(Role::where('name', $roleName)->firstOrFail());

        return $user->fresh();
    }
}
