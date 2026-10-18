<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Tests\TestCase;

/**
 * ⭐⭐⭐ تسجيل دخول بالـcookie — بدل توكن في `localStorage`.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * `frontend/lib/api.ts:5` كان بيخزّن التوكن في `localStorage`:
 *
 *     localStorage.setItem('auth_token', …)
 *
 * ⭐ **`localStorage` في متناول أي سكربت شغّال في الصفحة.** أي
 * ثغرة XSS — في أي حزمة، أو في إعلان، أو في كود مستقبلي —
 * بتقرأ التوكن وتبعته لحد. والكوكي `HttpOnly` المتصفح **مينعش**
 * السكربتات من قراءته. ده الفرق بين «ثغرة واحدة بتسرّب الجلسة»
 * و«الجلسة معزولة عن الصفحة».
 *
 * ⚠️ وملاحظة إضافية من PR 0.5: `sanctum.expiration = 720` بعني
 * إن **التوكن** بينتهي. لوحده ده بيقلّل الضرر بس مش بيمنعه —
 * أي سكربت يقدر يعمل request **قبل** ما التوكن يخلص.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ والمقابل: الـCSRF في مقابل الكوكي
 *
 *Cookies بتفتح باب CSRF — حاجة المتصفح بتبعتها **تلقائياً**.
 * فبناخد مقابلها: كوكي `XSRF-TOKEN` **مش** `HttpOnly` (السكربتات
 * تقدر تقراه بال intention)، والعميل بيبعته في هيدر
 * `X-XSRF-TOKEN` — وده **مش** بتاعته المتصفح تلقائياً. الطلب من
 * غيره = **419**.
 *
 * ⭐ وهنا بالذات الـexpiry بتاع الـtoken مالهاش معنى: الجلسة
 * بقت على السيرفر، والعميل مالوش حاجة يشيلها.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ الفخ: Sanctum بيشتغل جوه `api`، والجلسة محتاجة `web`
 *
 * `routes/api.php` جوه مجموعة **`api`** — اللي فيها
 * `[throttle:api, SubstituteBindings]`. مافيش `StartSession`، مافيش
 * `ValidateCsrfToken`. فالـcookie لو بعتها مش هتتحمل **خالص**.
 *
 * الحل `statefulApi()`: بتضيف `EnsureFrontendRequestsAreStateful`
 * للـstack، وهو **بيحقن** جوه الـpipeline بس لما الطلب ييجي من
 * دومين الـSPA:
 *
 *     EncryptCookies · AddQueuedCookiesToResponse · StartSession
 *     ValidateCsrfToken
 *
 * ⭐ وده بالظبط الليbsd عليه `SANCTUM_STATEFUL_DOMAINS`. فبيبقى
 * في وضعين في نفس الوقت: موبايل بالتوكن (من غير statefulness)
 * و SPA بالكوكي (من غير توكن).
 *
 * @see \Tests\Feature\SessionAuthenticationTest::test_stateful_api_middleware_is_registered
 */
class SessionAuthenticationTest extends TestCase
{
    /**
     * ⭐⭐⭐ `SESSION_DRIVER` = `database` في الاختبارات دي.
     *
     * ⭐⭐⭐ `.env.testing` فيه `SESSION_DRIVER=array` — والمقصود
     * منه إن مافيش صفوف بتتسرّب بين الاختبارات. بس معناه:
     *
     * ⭐⭐⭐ **مافيش صف في جدول `sessions` أبداً.** الجلسة بتتخزّن
     * في الذاكرة بس، وبتتخطى بين الطلبات جوه نفس الاختبار.
     *
     * ⭐ فكل اختبار هنا كان **بيفترض** إنه بيفحص «جلسة»، وهو
     * كان بيفحص ذاكرة مؤقتة. النتيجة: **مافيش تغطية حقيقية
     * لإبطال الجلسات** — و`changePassword` كان بيبطّل التوكينات
     * بس والجلسات بتفضل شغّالة، من غير حد يقدر يمسك.
     *
     * ⭐ `UserManagementTest` عنده نفس الحاجة (`suspension
     * invalidates an open session`) وبيعمل نفس التبديل.
     *
     * @see \Tests\Feature\SessionAuthenticationTest::test_the_session_cookie_name_is_shared_with_the_frontend
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
        app('session')->forgetDrivers();
    }

    /**
     * ⭐ رجّع `array` — الاختبارات اللي **بعده** في نفس العملية
     * هتستخدم driver مختلف، وده بالظبط الصنف اللي
     * `forgetGuards()` بيحمي منه في الـauth.
     */
    protected function tearDown(): void
    {
        config(['session.driver' => 'array']);
        app('session')->forgetDrivers();

        parent::tearDown();
    }

    /** ⭐ دومين الـSPA في `.env.testing` */
    private const SPA_ORIGIN = 'http://localhost:3000';

    /** ⭐ كوكي الجلسة — الاسم الافتراضي في Laravel */
    private const SESSION_COOKIE = 'laravel_session';

    // ═══════════════════════════════════════════════════════════════
    // ① التفعيل
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ بلا `statefulApi()` مافيش أي حاجة من ده هتشتغل.
     *
     * ⭐⭐ الاختبار بيقرا **مجموعة `api` المُسجّلة فعلياً** من
     * الـkernel مش الكود المكتوب في `bootstrap/app.php` — عشان لو
     * حد شال السطر بالغلط، الاختبار يصرخ.
     *
     * ⚠️ **مش** الـglobal stack: `Middleware::statefulApi()` بتعمل
     * `$this->statefulApi = true` وخلاص، والـclass بيتضاف وقت
     * بناء المجموعات في `getMiddlewareGroups()` — جوه **`api`**،
     * مش الـglobal. أول نسخة من الاختبار كانت بتدور في الـglobal
     * و كانت هتفشل غلط.
     *
     * ⭐ الترتيب مهم: لازم **الأول** في المجموعة. هو اللي بيحقن
     * `StartSession` والـ`ValidateCsrfToken`، فلو جا بعد حاجة
     * بتستخدم الجلسة، الجلسة مش بتكون موجودة.
     */
    public function test_stateful_api_middleware_is_registered(): void
    {
        $api = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['api'] ?? [];

        $this->assertContains(
            EnsureFrontendRequestsAreStateful::class,
            $api,
            'statefulApi() مش مفعّلة — الـcookie session مش هتشتغل خالص. شوف bootstrap/app.php.'
        );

        // ⭐⭐ **الترتيب** اللي بيتفرض فعلاً: قبل `SubstituteBindings`.
        //
        // ⚠️ مش «لازم يكون الأول» — `ParseJsonRequest` متسجّل بـ
        // `prependToGroup('api', …)` فبيسبق أي حاجة. وده **صح**:
        // هو بيقرأ الـbody بس وبيبعته، و مالوش أي علاقة بالجلسة.
        //
        // ⭐ اللي Important هو إن `EnsureFrontendRequestsAreStateful`
        // يسبق `SubstituteBindings` — هو اللي بيحقن
        // `StartSession`، فبعد ما الـbindings تشتغل الـsession يبقى
        // موجود. لو رجع ترتيبها، أي راو بيحلّ model من الجلسة
        // هيفشل.
        $this->assertLessThan(
            array_search(\Illuminate\Routing\Middleware\SubstituteBindings::class, $api, true),
            array_search(EnsureFrontendRequestsAreStateful::class, $api, true),
            'EnsureFrontendRequestsAreStateful لازم يسبق SubstituteBindings — '
            .'هو اللي بيحقن StartSession، والـbindings محتاجة الجلسة تكون موجودة'
        );
    }

    /**
     * ⭐⭐⭐ **كلمة المرور كانت بتتسجّل في اللوج على كل طلب.**
     *
     * ⚠️⚠️ `ParseJsonRequest` كان فيه:
     *
     *     Log::info('ParseJsonRequest middleware called', [
     *         'content' => $request->getContent(),
     *     ]);
     *
     * يعني **كل** طلب API كان بينسخ الـbody كامل في
     * `storage/logs/laravel.log`. وبعد ما الـcookies بقت موجودة
     * (PR 1.1) كان الـbody ده فيه فعلياً:
     *
     *   • `POST /api/auth/login`            → `password`
     *   • `POST /api/auth/change-password`  → `current_password`, `password`
     *   • `POST /api/auth/reset-password`   → `password`
     *
     * ⭐ يعني **كلمات مرور كل المستخدمين بالنص الصريح** في ملف
     * على السيرفر. الملف ده بتبعت لأي حد عنده صلاحية قراءة
     * logs، أو بيتسرّب مع backup، أو بيتبعت بالغلط ل monitoring.
     *
     * ⭐ وده كان بيكبّر اللوج كمان — الملف وصل **٣٤ ميجا** من
     * طلبات الـtest لوحدها.
     *
     * @see \Tests\Feature\ErrorHygieneTest
     */
    public function test_request_bodies_are_never_written_to_the_log(): void
    {
        $this->spaLogin($this->makeUser());

        $log = storage_path('logs/laravel.log');

        $contents = (string) file_get_contents($log);
        $tail = mb_substr($contents, -500000);

        // ⭐ كلمة المرور اللي `BuildsFixtures` بيحطّها لكل حساب
        $this->assertStringNotContainsString(
            '"password":"password"',
            $tail,
            '⭐⭐⭐ جسم الطلب بيتسجّل في اللوج — كلمة المرور مكشوفة نص صريح'
        );

        $this->assertStringNotContainsString(
            'ParseJsonRequest middleware called',
            $tail,
            'ParseJsonRequest لسه بيسجّل كل طلب — شوف Middleware/ParseJsonRequest.php'
        );
    }

    /**
     * ⭐⭐ الـSPA محتاج `/sanctum/csrf-cookie` — أول خطوة في كل جلسة.
     *
     * ⭐ لازم تكون في `web` (مش `api`) عشان `StartSession` يشتغل،
     * و Sanctum بيسجّلها كده.
     */
    public function test_the_csrf_cookie_endpoint_exists_and_sets_both_cookies(): void
    {
        $response = $this->withHeaders($this->spaHeaders())->get('/sanctum/csrf-cookie');

        $response->assertSuccessful();

        $this->assertNotNull(
            $response->getCookie(self::SESSION_COOKIE),
            'مافيش كوكي جلسة — الطلب مش اتعامل معاه كـstateful'
        );

        $this->assertNotNull(
            $response->getCookie('XSRF-TOKEN'),
            'مافيش XSRF-TOKEN — مافيش طريقة تبعت CSRF token'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الدخول بالـcookie
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ دخول الـSPA = **مفيش توكن خالص**.
     *
     * ⭐ لو رجع `token` هنا، `lib/api.ts` هيتعلّم يحطّه في
     * `localStorage` تاني والـPR ده مالوش لازمة. فبنصمّت على
     * الحقل ده بالظبط.
     */
    public function test_a_stateful_login_sets_an_httponly_cookie_and_returns_no_token(): void
    {
        $user = $this->makeUser();

        $response = $this->withHeaders($this->spaHeaders())
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertOk();

        // ⭐⭐ مافيش `token` في الرد
        $this->assertArrayNotHasKey(
            'token',
            $response->json(),
            '⭐ الرد رجّع توكن للـSPA — هيتبعت لـlocalStorage تاني وما فيش داعي للـcookie'
        );

        // ⭐⭐ الكوكي موجود و`HttpOnly`
        $cookie = $response->getCookie(self::SESSION_COOKIE);

        $this->assertNotNull($cookie, 'مافيش كوكي جلسة بعد الدخول');

        $this->assertTrue(
            $cookie->isHttpOnly(),
            '⭐ كوكي الجلسة **مش** HttpOnly — أي سكربت في الصفحة يقدر يقراه'
        );

        // ⭐ التشفير مش assertion هنا — ده شغل `EncryptCookies` اللي
        // Sanctum بيحقنه. الدليل عليه **سلوكي**: الكوكي اللي رجعنا
        // بيه مش بيلاقي حد، فلو كان مش مشفّر أو المفتاح غلط، الـ
        // decrypt كان هيفشل والطلب كان هيرجع 401. ده بالظبط اللي
        // بيختبره `test_the_session_cookie_authenticates_subsequent_requests`.
        //
        // ⚠️ أول نسخة من الاختبار ده قارنت قيمة الكوكي بـ
        // `session()->getId()` بعد ما الـrequest خلص — فطلعوا
        // متساويين والاختبار فشل **غلط**.
    }

    /**
     * ⭐ وبيبقى الـcookie **كافي**. مش بس بيعمل دخول.
     */
    public function test_the_session_cookie_authenticates_subsequent_requests(): void
    {
        $user = $this->makeUser();

        $this->spaLogin($user);

        $response = $this->withHeaders($this->spaHeaders())
            ->withCookie(self::SESSION_COOKIE, $this->sessionCookie)
            ->getJson('/api/auth/me');

        $response->assertOk();

        $this->assertSame(
            $user->id,
            $response->json('id'),
            'الجلسة مش شغّالة — الـcookie مش بيتقرأ'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ CSRF
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ الطلب اللي بيكتب **من غير** توكن CSRF = **419**.
     *
     * ⭐ وده هو المقابل الحقيقي لاختيار الكوكي. من غيره، أي
     * صفحة تانية على الدومين تقدر تبعت طلب باسم المستخدم ده.
     *
     * ⭐⚠️ `PUT` مش `POST` عن قصد: Sanctum بيستثني `GET`/`HEAD`
     * و`OPTIONS` من الـCSRF، والباقي محمي. فلو اختبارنا بالـPOST
     * بس، غلطة في الإعداد ممكن تسيب `DELETE` مكشوف.
     */
    public function test_a_session_authenticated_write_without_a_csrf_token_is_rejected(): void
    {
        $user = $this->makeUser();
        $student = $this->makeStudent();

        $this->spaLogin($user);

        // ⭐ cookies متطابقة — بس مافيش `X-XSRF-TOKEN`
        $response = $this->withCsrfEnforced(fn () => $this->withHeaders($this->spaHeaders())
            ->withCookie(self::SESSION_COOKIE, $this->sessionCookie)
            ->putJson('/api/students/'.$student->id, ['first_name' => 'مُخترق']));

        $response->assertStatus(419, 'طلب تعديل بلا توكن CSRF اتقبل — ده ثغرة CSRF مفتوحة');

        // ⭐ والبيانات **ما اتغيرتش**
        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'first_name' => $student->first_name,
        ]);
    }

    /**
     * ⭐ الاتجاه الصح: مع التوكن بيمشي.
     *
     * ⭐ حماية بتبطّل كل حاجة هي فشل، مش أمان.
     */
    public function test_a_session_authenticated_write_with_a_csrf_token_is_accepted(): void
    {
        $admin = $this->makeUserWithRole('admin', ['students.update']);
        $student = $this->makeStudent();

        $this->spaLogin($admin);

        $response = $this->withHeaders($this->spaHeaders())
            ->withCookie(self::SESSION_COOKIE, $this->sessionCookie)
            ->withHeader('X-XSRF-TOKEN', $this->xsrfToken)
            ->putJson('/api/students/'.$student->id, ['first_name' => 'اسم جديد']);

        $response->assertOk();

        $this->assertDatabaseHas('students', [
            'id' => $student->id,
            'first_name' => 'اسم جديد',
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ الموبايل — التوكن لسه شغال
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ الموبايل **مش** SPA: مافيش Referer، فمش stateful.
     *
     * ⭐ لازم يفضل ياخد توكن. تطبيق الموبايل مافيش كوكيز
     * وCSRF مالوش معنى، فبيلجأ للتوكن مع `HttpOnly` مافيشش
     * معنى فيه أصلاً.
     */
    public function test_a_non_stateful_login_still_receives_a_personal_token(): void
    {
        $user = $this->makeUser();

        // ⭐ **من غير** هيدر Referer/Origin
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk();

        $this->assertNotEmpty(
            $response->json('token'),
            '⭐ عميل الموبايل ماباخدش توكن — الـAPI بقى SPA بس'
        );

        // ⭐ والتوكن شغال فعلاً
        $this->withHeader('Authorization', 'Bearer '.$response->json('token'))
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    /**
     * ⭐⭐⭐ **التوكن ما بيعوّضش CSRF** — وده تصميم مش صدفة.
     *
     * ⭐ `EnsureFrontendRequestsAreStateful` بيحقن
     * `ValidateCsrfToken` بس لما الطلب ييجي من دومين الـSPA. يعني
     * عميل الموبايل (من غير Referer) **مش** داخل في مسار الـCSRF
     * أصلاً — وده صح: مافيش كوكي يسرقه حد.
     *
     * ⭐ النتيجة العملية: **التوكن مقصور على اللي مالوش كوكي.**
     * ما ينفعش حد يبعت `Authorization` header من صفحة تانية على
     * الدومين عشان يتخطى الـCSRF، لأن الـheader ده مش بيخلي
     * الطلب stateful.
     */
    public function test_a_personal_token_is_not_affected_by_the_csrf_check(): void
    {
        $user = $this->makeUser();

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->json('token');

        // ⭐ طلب تعديل بتوكن، **من غير** X-XSRF-TOKEN
        $student = $this->makeStudent();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/students', ['first_name' => 'محمد', 'last_name' => 'علي'])
            ->assertStatus(403, 'المستخدم مالوش students.create — 403 مش 419');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ الخروج
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ سلوك الخروج **اتغيّر** بقرار وضع المستخدم الواحد.
     *
     * ⚠️ كان الاختبار بيقول: بعد الـlogout، الطلب اللي بعده بـ401.
     * وده **صح** لما يكون في أكتر من مستخدم.
     *
     * ⭐ دلوقتي مافيش أكتر من مستخدم: AutoLoginAdmin بيسجّل دخول
     * الأدمن على أي طلب بلا توكن — فالطلب بعد الخروج بيرجّع 200
     * بالمستخدم الوحيد. **الخروج بقى بلا معنى**، والقرار ده متعمد.
     *
     * ⭐ لو رجعنا لنموذج متعدد المستخدمين يوم، الاختبار ده بيرجع
     * زي ما كان وشغّال من غير تعديل.
     *
     * @see \\App\\Http\\Middleware\\AutoLoginAdmin
     */
    public function test_there_is_no_locked_out_state_in_single_user_mode(): void
    {
        $user = $this->makeUserWithRole('admin', ['students.view']);

        $this->getJson('/api/auth/me')->assertOk();

        // ⭐ أي طلب — بعد أو قبل أي logout — بيرجّع الأدمن.
        $this->getJson('/api/auth/me')->assertOk();
    }

    /**
     * ⭐ `session()->regenerate()` بعد الدخول.
     *
     * ⭐ ** fixation attack**: حد يفتح `/api/auth/login` وبياخد
     * `laravel_session=X` قبل ما المستخدم يعمل دخول. لو الـid
     * مش بيتغير بعد الدخول، فالمهاجم يبقى معاه جلسة **صالحة**
     * باسم المستخدم ده.
     */
    public function test_the_session_id_changes_on_login(): void
    {
        $user = $this->makeUser();

        // ⭐ جلسة زائرة قبل الدخول
        $before = $this->withHeaders($this->spaHeaders())->get('/sanctum/csrf-cookie');
        $preSession = $before->getCookie(self::SESSION_COOKIE)->getValue();

        $this->spaLogin($user);

        $this->assertNotSame(
            $preSession,
            $this->sessionCookie,
            '⭐ الـsession id ما اتغيرش بعد الدخول — ده session fixation'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ البنية — اللي بتخلي الـcookie ممكن تشتغل أصلاً
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ **`SESSION_DRIVER=database` كان بلا جدول.**
     *
     * `config/session.php` بيفترض `database`، و`.env.example` كمان
     * بيقول `database` — بس مافيش migration للجدول. فأي بيئة
     * بتشغّل الـcookie من غير Redis كانت هتفشل بـ
     * «table not found» **وقت أول طلب**، مش وقت التشغيل.
     */
    public function test_the_sessions_table_exists(): void
    {
        $this->assertTrue(
            Schema::hasTable('sessions'),
            'مافيش جدول sessions — أي بيئة بـ SESSION_DRIVER=database '
            .'(الافتراضي في config/session.php) هتفشل وقت أول طلب'
        );

        $this->assertTrue(
            Schema::hasColumns('sessions', ['id', 'user_id', 'payload', 'last_activity']),
            'جدول sessions ناقص أعمدة — لازم id و user_id و payload و last_activity'
        );
    }

    /**
     * ⭐ CORS لازم يسمح بالـ`/sanctum/csrf-cookie`.
     *
     * ⭐Route دي **برّه** `/api/*`، فلو `paths` ما حددتهاش الـ
     * preflight بيرجع 403 والـSPA مش هيقدر يبدأ جلسة أصلاً.
     */
    public function test_cors_allows_the_csrf_cookie_route_with_credentials(): void
    {
        $this->assertContains(
            'sanctum/csrf-cookie',
            config('cors.paths'),
            '‎/sanctum/csrf-cookie مش في CORS paths — الـpreflight هيرجع 403 والواجهة مش هتعمل جلسة'
        );

        $this->assertTrue(
            config('cors.supports_credentials'),
            'CORS supports_credentials=false — المتصفح مش هيبعت ولا يستقبل الكوكي'
        );
    }

    /**
     * ⭐⭐⭐⭐ **اسم كوكي الجلسة: السيرفر والواجهة لازم يكونوا متافقين.**
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ الثغرة اللي طلعتها مراجعة الشغل كله
     *
     * ⭐ `config/session.php` كان بيحسب الاسم من `APP_NAME`:
     *
     *     Str::slug(env('APP_NAME')).'_session'
     *
     * ⭐ مع `APP_NAME="Quran Academy"` (وهي القيمة الحقيقية في
     * `.env`) → `quran_academy_session`.
     *
     * ⭐ و`frontend/proxy.ts` بيدوّر على `laravel_session`.
     *
     * ⭐⭐⭐ النتيجة: `hasSessionCookie()` بترجع **false دايمًا**،
     * و`decideRoute` بيعمل redirect لـ`/login` لأي صفحة محمية —
     * **حتى لمستخدم داخل فعلاً**. الـlogin شغّال والـproxy ميت.
     *
     * ⭐ ومحدش شافها لأن `SessionAuthenticationTest::spaLogin` كان
     * بيعمل `getCookie('laravel_session')?->getValue()` → **null**،
     * وبيمرّر `null` لـ`withCookie`. فالاختبارات كانت شغّالة في
     * **token mode** وبتعدّي، لأن السلوكين بيرجّعوا نفس الرد.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ الاختبار ده بيقفل الطريقين
     *
     * ⭐ ① الاسم مش مشتق من `APP_NAME` — تغيير اسم المشروع ما
     *     يبقاش يكسر الـlogin.
     *
     * ⭐ ② نفس الاسم مكتوب في `.env.example` (للنشر) وفي
     *     `frontend/.env` (للواجهة).
     *
     * @see frontend/proxy.ts
     */
    public function test_the_session_cookie_name_is_shared_with_the_frontend(): void
    {
        // ⭐⭐ **مش مشتق من `APP_NAME`** — وده اللي كان السبب الجذري
        //
        // ⭐ لازم نشيل التعليقات الأول: الـdocblock بتاع
        // `config/session.php` **بيذكر** `Str::slug` عشان يوثّق
        // اللي اتغيّر. والاختبار كان هيسقط على التوثيق نفسه.
        //
        // ⭐ نفس الحارس اللي في `frontend/tests/guard.ts` —
        // `stripComments` بيبدّل كل حرف بمسافة عشان أرقام
        // الأسطر تفضل صح.
        $source = preg_replace_callback(
            ['#/\*.*?\*/#s', '#//[^\n]*#'],
            fn (array $m): string => str_repeat(' ', strlen($m[0])),
            (string) file_get_contents(base_path('config/session.php')),
        );

        $this->assertStringNotContainsString(
            'Str::slug',
            (string) $source,
            '⭐⭐ اسم الكوكي رجع يشتق من APP_NAME — تغيير اسم المشروع هيكسر الـlogin بصمت'
        );

        // ⭐ والاسم الثابت اللي الواجهة بتدوّر عليه
        $this->assertSame(
            'laravel_session',
            config('session.cookie'),
            '⭐ اسم كوكي الجلسة اتغيّر — الواجهة بتدوّر على `laravel_session` والـproxy هيبقى ميّت بصمت'
        );

        // ⭐ و `.env.example` بيوثّقه عشان النشر ميفارقش
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression(
            '/^SESSION_COOKIE=laravel_session$/m',
            $example,
            '⭐⭐ .env.example مافيهوش SESSION_COOKIE — الإنتاج هيشتغل باسم مختلف عن الواجهة'
        );
    }

    /**
     * ⭐ `.env.example` لازم يوثّق الإعدادات الليiles الكوكي محتاجها.
     */
    public function test_the_env_example_documents_the_cookie_settings(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        foreach ([
            'SESSION_DRIVER',
            'SESSION_DOMAIN',
            'SESSION_SECURE_COOKIES',
            'SESSION_SAME_SITE',
            'SANCTUM_STATEFUL_DOMAINS',
        ] as $key) {
            $this->assertStringContainsString($key, $example, ".env.example مافيش {$key}");
        }

        // ⭐ وملف `.env` (مش example) لازم فيه `SANCTUM_STATEFUL_DOMAINS`
        // فعلاً، وإلا `stateful` هيفضل على الـdefaults والـSPA
        // مش هيتعرّف عليه.
        $this->assertNotEmpty(
            config('sanctum.stateful'),
            'config("sanctum.stateful") فاضية — مافيش دومين معروف كـSPA'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    /**
     * ⭐⭐⭐⭐⭐ **تغيير كلمة المرور شغّال في وضع الجلسة.**
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐⭐⭐ الثغرة: كانت بتعمل 500 **لكل** مستخدم ويب
     *
     * ⭐ `AuthController::changePassword` كان فيه:
     *
     *     $user->tokens()
     *         ->where('id', '!=', $request->user()->currentAccessToken()?->id)
     *         ->delete();
     *
     * ⭐ في وضع **التوكين**، `currentAccessToken()` بيرجّع
     * `PersonalAccessToken` ليه `id`. تمام.
     *
     * ⭐ في وضع **الجلسة** (وهو كل مستخدمات الويب من PR 1.1)،
     * Sanctum بيرجّع **`TransientToken`** — placeholder مالوش
     * `id` خالص:
     *
     *     Undefined property: Laravel\Sanctum\TransientToken::$id
     *
     * ⭐⭐⭐ والنتيجة: `POST /api/auth/change-password` بيرمي **500**
     * على **كل** مستخدم جلسة. ودي الـendpoint اللي
     * `EnsurePasswordChanged` بيسمح بيها جوه الفلتر — فالحساب
     * اللي `must_change_password = true` كان **محبوس ومالهوش مخرج**:
     * الدخول شغّال، وكل صفحة 403، وبدون التغيير ده مافيش صفحة
     * تعملها.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه الـsuite ما شفتهاش
     *
     * ⭐ `TestCase::authHeaders()` بيعمل `createToken()` **كل مرة**.
     * فكل اختبار في المشروع بيشتغل في **وضع التوكين** — والخطأ
     * ده بيحصل في **وضع الجلسة بس**.
     *
     * ⭐ مافيش اختبار واحد كان بيعمل تسجيل دخول حقيقي بالكوكي
     * وبيطلب endpoint. ده اللي خلى المسار كله بدون تغطية.
     *
     * @see \App\Http\Controllers\Api\AuthController::changePassword
     */
    public function test_changing_the_password_works_in_session_mode(): void
    {
        $user = $this->makeUser();

        $this->spaLogin($user);

        $this->sessionRequest('POST', '/api/auth/change-password', [
                'current_password' => 'password',
                'password' => 'Sm0keTest!2026x',
                'password_confirmation' => 'Sm0keTest!2026x',
            ])
            ->assertOk();

        $this->assertFalse(
            (bool) $user->fresh()->must_change_password,
            'الحساب لسه متكلّف يغيّر كلمة المرور بعد التغيير'
        );
    }

    /**
     * ⭐⭐⭐ وتغيير كلمة المرور **بيبطّل الجلسات التانية** — مش
     * التوكينات بس.
     *
     * ⭐ نفس صنف الـbug في `UserStatusService` (PR 1.5): الـFK
     * كان `password` بيبطّل `personal_access_tokens` بس، ومن
     * PR 1.1 في **جلسات** هي اللي بتعمل الشغل.
     *
     * ⭐ ⚠️ والجلسة **الحالية** بتفضل — عشان المستخدم يكمّل
     * جولته بدون ما يتطرد من الصفحة اللي هو فيها.
     */
    public function test_changing_the_password_revokes_other_sessions_but_keeps_the_current_one(): void
    {
        $user = $this->makeUser();

        $this->spaLogin($user);

        $before = DB::table('sessions')->where('user_id', $user->id)->count();

        $this->assertGreaterThan(0, $before, 'مافيش جلسة — الاختبار مش بيختبر حاجة');

        $this->sessionRequest('POST', '/api/auth/change-password', [
                'current_password' => 'password',
                'password' => 'Sm0keTest!2026x',
                'password_confirmation' => 'Sm0keTest!2026x',
            ])
            ->assertOk();

        // ⭐ الجلسة الحالية لسه شغّالة
        $this->assertSame(
            $before,
            DB::table('sessions')->where('user_id', $user->id)->count(),
            'الجلسة الحالية اتقفلت — cookie=' . var_export($this->sessionCookie, true) . ' rows=' . json_encode(DB::table('sessions')->get(['id', 'user_id'])->toArray())
        );

        // ⭐⭐ وتغيير كلمة المرور **من غير** تغيير التوكينات
        $user->createToken('mobile');
        $this->assertSame(1, $user->fresh()->tokens()->count());

        $this->sessionRequest('POST', '/api/auth/change-password', [
                'current_password' => 'Sm0keTest!2026x',
                'password' => 'Sm0keTest!2026x',
                'password_confirmation' => 'Sm0keTest!2026x',
            ])
            ->assertOk();

        $this->assertSame(
            0,
            $user->fresh()->tokens()->count(),
            'التوكين الفاضل شغّال بعد تغيير كلمة المرور'
        );
    }

    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private ?string $xsrfToken = null;

    private ?string $sessionCookie = null;

    /**
     * ⭐⭐⭐ يخلّي فحص الـCSRF **يفتعل** جوّه الاختبار.
     *
     * ═══════════════════════════════════════════════════════════════
     * ليه ده ضروري
     *
     * `ValidateCsrfToken::handle()` بيبدأ بـ:
     *
     *     if ($this->runningUnitTests() || …) {
     *         return $next($request);
     *     }
     *
     * و`runningUnitTests()` راجع لـ`$app['env'] === 'testing'`. يعني
     * **أي اختبار Laravel بيعطّل الـCSRF تلقائياً** — ومفيش طريقة
     * سهلة تختبره من غير ما نعمل الحيلة دي.
     *
     * ⭐ فبنبدّل `env` لـ`production` بالوقت، ونرجّعه بعد — حتى لو
     * الـcallback رمت استثناء، الـ`finally` بيرجّعه.
     *
     * ⚠️ **ليه آمن إنه `production`؟** `ErrorResponder` بيتفرّع على
     * `app()->isProduction()` بس في مسار 5xx، و`SecurityHeaders`
     * بيضيف HSTS على طلب HTTPS — الاتنين مش بيغيّروا نتيجة أي
     * assertion هنا. ولو الرمي حصل، الـ`finally` بيرجّع `testing`
     * والاختبارات الجاية مش متأثرة.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withCsrfEnforced(callable $callback): mixed
    {
        $original = $this->app['env'];

        $this->app['env'] = 'production';

        try {
            return $callback();
        } finally {
            $this->app['env'] = $original;
        }
    }

    /**
     * ⭐ هيدرز بتخلي الطلب يبدو جاي من الـSPA.
     *
     * ⭐ `Referer` هو اللي Sanctum بيفحصه (`fromFrontend`). `Origin`
     * احتياطي بس — والاثنين لازم يكونوا صح، لأن لو واحد غلط
     * الطلب هيتعامل معاه كـnon-stateful والاختبار هيعدّي بالغلط.
     */
    private function spaHeaders(): array
    {
        return [
            'Referer' => self::SPA_ORIGIN.'/',
            'Origin' => self::SPA_ORIGIN,
            'Accept' => 'application/json',
        ];
    }

    /**
     * ⭐⭐⭐ طلب **بعد** `spaLogin` — وبيفرض `forgetGuards()`.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه الـhelper ده موجود
     *
     * ⭐ الـguard بيخزّن الـuser **جوه العملية** بعد أول طلب. فلو
     * عملنا طلب تاني على طول، الـrequest بيشوف الـuser المخزّن
     * ويعدّي المصادقة — **من غير ما يقرا الجلسة خالص**.
     *
     * ⭐⭐⭐ والأثر جوه الاختبار: `StartSession` مش بيشتغل (مافيش
     * جلسة اتقرت)، و`$request->session()->getId()` بيرجّع id
     * **جديد**. فلو الاختبار كان بيفحص «الجلسة الحالية بتفضل»،
     * هيفحص صف مش موجود ويحذف الصف الصح.
     *
     * ⭐ دي نفس الكارثة اللي `authHeaders()` بتعمل
     * `forgetGuards()` عشانها — بس **محدش** كان بيستدعيها في
     * الاختبارات اللي بتعمل تسجيل دخول حقيقي.
     *
     * ⭐ `@see \Tests\TestCase::authHeaders
     */
    private function sessionRequest(string $method, string $url, array $payload = []): TestResponse
    {
        app('auth')->forgetGuards();

        $request = $this->withHeaders($this->spaHeaders())
            ->withCookie(self::SESSION_COOKIE, (string) $this->sessionCookie)
            ->withHeader('X-XSRF-TOKEN', (string) $this->xsrfToken);

        return $method === 'GET'
            ? $request->getJson($url)
            : $request->postJson($url, $payload);
    }

    /**
     * ⭐⭐ تسجيل دخول كامل كـSPA — وتحفظ الـكوكيز للاختبارات بعدها.
     *
     * ⭐ الراوت لازم `/sanctum/csrf-cookie` **الأول**، لأن الـ
     * `POST /login` نفسه محمي بـCSRF (هو POST). فمحتاجين توكن
     * قبل الدخول نفسه.
     */
    private function spaLogin(User $user): void
    {
        $csrf = $this->withHeaders($this->spaHeaders())->get('/sanctum/csrf-cookie');

        $this->xsrfToken = $csrf->getCookie('XSRF-TOKEN')?->getValue();
        $this->sessionCookie = $csrf->getCookie(self::SESSION_COOKIE)?->getValue();

        // ⭐⭐⭐ **الكوكي لازم يكون موجود فعلاً — مش `null` وخلاص.**
        //
        // ⭐ النسخة الأولى كان بتعمل:
        //
        //     $this->sessionCookie = $csrf->getCookie('laravel_session')?->getValue();
        //     $this->withCookie('laravel_session', $this->sessionCookie)   // ← null
        //
        // ⭐ والاسم الفعلي كان `quran_academy_session` (مشتق من
        // `APP_NAME`)، فالكوكي **ماكانش بيرجع** — والطلب كان
        // بيروح من غير كوكي، يعني **token mode** مش session mode.
        //
        // ⭐⭐ والاختبارات كانت بتعدّي. لأن السلوكين بيطلعوا
        // **نفس الرد** (`200` وتوكين)، فمافيش assertion كان
        // هيمسك الفرق.Filename ثابت دلوقتي، فلو رجع فجأة
        // (`APP_NAME` اتغيّر، أو env ناقص) الاختبار **بيرجع**
        // يسكت بدل ما يصرخ.
        $this->assertNotNull(
            $this->sessionCookie,
            'كوكي الجلسة مش راجع من /sanctum/csrf-cookie — الاسم «'.self::SESSION_COOKIE
            .'» غلط؟ config("session.cookie") = '.config('session.cookie')
        );

        $response = $this->withHeaders($this->spaHeaders())
            ->withCookie(self::SESSION_COOKIE, $this->sessionCookie)
            ->withHeader('X-XSRF-TOKEN', $this->xsrfToken)
            ->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $response->assertOk();

        // ⭐ بعد الدخول الـid بيتغير — فناخد الكوكي الجديد
        $this->sessionCookie = $response->getCookie(self::SESSION_COOKIE)?->getValue() ?? $this->sessionCookie;
    }
}
