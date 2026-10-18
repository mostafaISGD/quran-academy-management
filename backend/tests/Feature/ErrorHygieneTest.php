<?php

namespace Tests\Feature;

use App\Http\Controllers\Controller;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ⭐⭐⭐ مافيش تسريب داخلي، والبيئة آمنة افتراضياً.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * ① ⭐⭐⭐ **19 استثناء بيرجّع `$e->getMessage()` للعميل.**
 *
 *    المراجعة ذكر `ParentController` بس. الواقع:
 *
 *      StudentPhoneController      ١١ مرة  (كل methods الـphones)
 *      FinancialAccountController  ٣ مرات
 *      ParentController            ٢ مرة
 *      AuthController              ١ مرة
 *      FinancialLedgerService      ١ مرة
 *
 *    ⭐ الرسالة دي بتطلع اسم class الـexception، وأسماء الجداول،
 *    وأسماء الأعمدة، وأحياناً **مسار الملف**. ده بيدّي مهاجم
 *    خريطة الداتابيز مجاناً.
 *
 * ② ⭐ `bootstrap/app.php` فيه `withExceptions` **فاضي** — مافيش
 *    handler موحّد، فأي exception بيمرّي زي ما هو.
 *
 * ③ ⭐ **`selectRaw` و `groupBy` بيحقنوا أسماء أعمدة مباشرة**:
 *
 *      ->selectRaw("$key, count(*) as aggregate")
 *      ->groupBy($key)
 *
 *    `$key` جاي من الـdeveloper جوه الـcontroller — فمفيش هجوم
 *    خارجي مباشر، **بس** أي تمريرة غلط (أو كود مستقبلي يمرّر
 *    `$_GET`) بتتحول لـ SQL injection.
 *
 * ④ ⭐ `config/sanctum.php` بيشاور على كلاسين مش موجودين في
 *    Laravel 11 (`App\Http\Middleware\VerifyCsrfToken` وغيره).
 *
 * ⑤ `backend/install_log.txt` متتبّع في git.
 *
 * ⑥ مافيش `SecurityHeaders` middleware — فمافيش حماية من
 *    clickjacking ولا MIME sniffing ولا تسرّب الـreferrer.
 */
class ErrorHygieneTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① ② الـ exception handler الموحّد
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ الـ handler لازم يرجّع رسالة **عامة** بالعربي.
     *
     * ⭐ وممنوع يظهر فيه: اسم class، أو مسار ملف، أو رقم سطر،
     * أو رسالة SQL. أي حاجة من دول **تسريب بنية داخلية**.
     */
    public function test_an_internal_exception_returns_a_generic_arabic_message(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        // ⭐ نطلّع exception من جوه request عشان نختبر الـhandler
        $this->app['router']->get('/_test/explode', fn () => throw new \RuntimeException(
            'SQLSTATE[42S02]: Base table or view not found: 23000 `qa_secret_table`.'
            .' Connection: mysql (host=db.internal) — C:\\inetpub\\qa\\app\\Http\\Kernel.php:412'
        ));

        $r = $this->withHeaders($this->authHeaders($user))->getJson('/_test/explode');

        $r->assertStatus(500);

        $body = $r->getContent();

        // ⭐ ممنوع يظهر أي حد من دول
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('SQLSTATE', $body);
        $this->assertStringNotContainsString('secret_table', $body);
        $this->assertStringNotContainsString('Kernel.php', $body);
        $this->assertStringNotContainsString('C:\\', $body);
        $this->assertStringNotContainsString(':412', $body);
        $this->assertStringNotContainsString('db.internal', $body);

        // ⭐ والرسالة العامة لازم تكون **عربي**
        $this->assertMatchesRegularExpression(
            '/[\x{0600}-\x{06FF}]/u',
            (string) $r->json('message'),
            'رسالة الخطأ لازم تكون بالعربي زي باقي الـAPI'
        );
    }

    /**
     * ⭐ التفاصيل **بتتسجل** — بترميها من الرد بس.
     *
     * ⭐ من غير التسجيل، الـhandler بيخسر أي قدرة على تشخيص
     * المشكلة في الإنتاج. الناس بتلوم المستخدم على «خطأ في
     * السيرفر» بدل ما تصلحه.
     */
    public function test_the_exception_handler_logs_the_details(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        $this->app['router']->get('/_test/logme', fn () => throw new \RuntimeException('very-secret-detail'));

        $logFile = storage_path('logs/laravel.log');

        $this->withHeaders($this->authHeaders($user))->getJson('/_test/logme')->assertStatus(500);

        $this->assertFileExists($logFile, 'ملف اللوج مش موجود — مينفعش نتحقق من التسجيل');

        $contents = (string) file_get_contents($logFile);

        // ⭐ نقارن **آخر ٥٠٠ كيلوبايت** — كفاية إن الـ stack يكون
        // جواها مهما كان طويل.
        //
        // ⚠️ أول نسخة قارنت `filesize` قبل/بعد (وباظت مع تراكم
        // ٣٤ ميجا)، والتانية قصّت ٤٠٠٠ حرف فالقصّة وقعت **قبل**
        // الرسالة نفسها.
        $tail = mb_substr($contents, -500000);

        $this->assertStringContainsString(
            'very-secret-detail',
            $tail,
            'التفاصيل ما اتسجلتش في الـlog — من غير كده الـhandler بيخسر القدرة على التشخيص'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ whitelist أعمدة `paginatedWithCounts`
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **القبول: عمود مش مسموح يعمل exception.**
     *
     * ⭐ الـroadmap بتقول يرمي exception — وده الصح عن حد الشكل
     * `abort(500)` اللي بيسمح للمستخدم يكمل والمهاجم يفحص الأعمدة.
     */
    public function test_a_disallowed_count_column_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runPaginatedWithCounts(
            ['status', 'evil_column_1), (select 1) as x --'],
        );
    }

    /**
     * ⭐ الـ sums ليها نفس الحماية — `sum($k)` كمان حقن مباشر.
     */
    public function test_a_disallowed_sum_column_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->runPaginatedWithCounts(
            ['status'],
            ['(select password from users limit 1)'],
        );
    }

    /**
     * ⭐ الاتجاه الصح: عمود حقيقي بيمشي عادي.
     *
     * ⭐ حماية بتوقف الوظيفة هي فشل، زي الحماية اللي مالهاش لازمة.
     */
    public function test_allowed_columns_still_work(): void
    {
        $this->makeStudent();

        $json = $this->runPaginatedWithCounts(['status']);

        $this->assertArrayHasKey('counts', $json);
        $this->assertArrayHasKey('status', $json['counts']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ ⑤ ⑥ الملفات
    // ═══════════════════════════════════════════════════════════════

    public function test_the_csrf_middleware_reference_exists(): void
    {
        $configured = config('sanctum.middleware.verify_csrf_token');

        $this->assertTrue(
            class_exists($configured),
            "config('sanctum.middleware.verify_csrf_token') بيشاور على class مش موجود: {$configured}"
        );
    }

    public function test_the_cookie_encrypt_middleware_exists(): void
    {
        $configured = config('sanctum.middleware.encrypt_cookies');

        $this->assertTrue(
            class_exists($configured),
            "config('sanctum.middleware.encrypt_cookies') بيشاور على class مش موجود: {$configured}"
        );
    }

    public function test_install_log_is_not_tracked_in_git(): void
    {
        $tracked = explode("\n", trim((string) shell_exec(
            'cd '.escapeshellarg(base_path()).' && git ls-files 2>/dev/null'
        )));

        $this->assertNotContains(
            'install_log.txt',
            array_map('basename', $tracked),
            'backend/install_log.txt لسه متتبّع في git — لازم يتشال ويتعمل gitignore'
        );
    }

    public function test_install_log_is_gitignored(): void
    {
        $ignore = (string) file_get_contents(base_path('.gitignore'));

        $this->assertMatchesRegularExpression(
            '/install_log/i',
            $ignore,
            'backend/.gitignore مافيهوش install_log'
        );
    }

    public function test_cors_origins_come_from_the_environment(): void
    {
        $source = (string) file_get_contents(config_path('cors.php'));

        // ⭐ ممنوع origins ثابتة مكتوبة في الكود
        $this->assertStringNotContainsString(
            "'http://localhost:3000',",
            $source,
            'config/cors.php فيه origins ثابتة — لازم تيجي من CORS_ALLOWED_ORIGINS'
        );

        $this->assertStringContainsString('CORS_ALLOWED_ORIGINS', $source);
    }

    public function test_the_env_example_disables_debug_and_documents_the_secrets(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        // ⭐ APP_DEBUG لازم false في المثال
        $this->assertMatchesRegularExpression(
            '/^APP_DEBUG=false$/m',
            $example,
            '.env.example لازم يبقى APP_DEBUG=false — الملف ده بيتنسخ في الإنتاج'
        );

        foreach ([
            'SESSION_DRIVER', 'SESSION_DOMAIN', 'SESSION_SECURE_COOKIES',
            'SANCTUM_STATEFUL_DOMAINS', 'SANCTUM_TOKEN_EXPIRATION',
            'CORS_ALLOWED_ORIGINS',
        ] as $key) {
            $this->assertStringContainsString(
                $key,
                $example,
                ".env.example مافيش {$key}"
            );
        }
    }

    public function test_the_testing_env_key_is_documented_as_test_only(): void
    {
        $testing = (string) file_get_contents(base_path('.env.testing'));

        // ⭐ المفتاح لازم يكون موجود (Laravel محتاجه)
        $this->assertStringContainsString('APP_KEY=base64:', $testing);

        // ⭐ بس لازم **موثّق** إنه للاختبار بس، مش سر حقيقي.
        //
        // ⭐ الاختبار مش بيقدر يعرف السر الحقيقي — فاللي بنتحقق
        // منه هو إن المفتاح ** معلن** على أنه دي قيمة اختبار.
        $this->assertMatchesRegularExpression(
            '/^APP_KEY=.*#.*TEST/m',
            $testing,
            '.env.testing لازم يوضّح إن المفتاح ده للاختبار بس'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ SecurityHeaders
    // ═══════════════════════════════════════════════════════════════

    public function test_security_headers_are_sent(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        $r = $this->withHeaders($this->authHeaders($user))->getJson('/api/programs');

        foreach ([
            'X-Content-Type-Options',
            'Referrer-Policy',
            'X-Frame-Options',
            'Permissions-Policy',
        ] as $header) {
            $this->assertTrue(
                $r->headers->has($header),
                "الهيدر {$header} مش موجود في الرد"
            );
        }
    }

    /**
     * ⭐ HSTS **في الإنتاج بس** — في التطوير بيكسّر الـHTTPS المحلي.
     *
     * ⭐ HSTS بيقول للمتصفح «من دلوقتي اتكلم مع الدومين ده
     * بـHTTPS بس». في `localhost` ده معناه إن أي خطأ في
     * التطوير بيقفل المتصفح على HTTP.
     */
    public function test_hsts_is_only_sent_in_production(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        $r = $this->withHeaders($this->authHeaders($user))->getJson('/api/programs');

        $this->assertFalse(
            $r->headers->has('Strict-Transport-Security'),
            'HSTS بيتبعث في التطوير — ده بيقفل المتصفح على localhost'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ بنادي الـmethod المحمي عن طريق anonymous subclass.
     */
    private function runPaginatedWithCounts(array $countKeys, array $sumKeys = []): array
    {
        $probe = new class extends Controller
        {
            public function run(
                Builder $query,
                Builder $counts,
                Request $request,
                array $countKeys,
                array $sumKeys,
            ) {
                return $this->paginatedWithCounts($query, $counts, $request, $countKeys, $sumKeys);
            }
        };

        $response = $probe->run(
            Student::query(),
            Student::query(),
            Request::create('/'),
            $countKeys,
            $sumKeys,
        );

        return json_decode($response->getContent(), true);
    }
}
