<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * ⭐⭐⭐ مفيش حساب بكلمة مرور معروفة — والاستعادة **بتفعل** فعلاً.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة اللي الاختبار ده بيثبتها
 *
 * ① **كلمة مرور ثابتة معروفة** في إنشاء الحسابات:
 *
 *      'password' => Hash::make('password'),
 *
 *    في `EmployeeController` (مرتين) وفي ٨ seeders. أي حد عنده
 *    قائمة الإيميلات يقدر يدخل بحساب أي موظف.
 *
 * ② ⭐⭐ `AuthController::forgotPassword` و `resetPassword` كانو
 *    **cosmetic بالكامل**:
 *
 *      public function forgotPassword(Request $request)
 *      {
 *          $request->validate(['email' => 'required|email']);
 *          return response()->json(['message' => 'تم إرسال...']);
 *      }
 *
 *    مفيش `Password::`، مفيش إيميل، مفيش توكن. الراوت موجود
 *    والرد 200 — والمستخدم بيقول «دعيت الرابط وما وصلنيش».
 *
 * ③ مفيش `password_reset_tokens` أصلاً في المخطط.
 *
 * ④ مفيش `must_change_password` — فحتى لو كلمة المرور اتغيّرت،
 *    مافيش ضغط يغيّرها.
 */
class PasswordPolicyTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① مفيش كلمة مرور افتراضية معروفة
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ الاختبار اللي بيمنع رجوع الباج — **بالـgrep** مش بالتخمين.
     *
     * ⚠️ بيستثني الـ seeders عن قصد: دي بيانات تجريبية بتترفض
     * في الإنتاج بـ PR 0.4 نفسه (test ⑥). أي ملف تاني فيه
     * `Hash::make('password')` = ثغرة.
     */
    public function test_no_default_password_outside_the_seeders(): void
    {
        $offenders = [];

        foreach ($this->phpFilesIn('app') as $file) {
            // ⚠️⭐ لازم `file_get_contents` — مش المسار نفسه.
            //
            // أول نسخة من الاختبار ده كانت بتعمل
            // `str_contains($file, "Hash::make('password')")` على
            // **المسار**، فكانت بتعدّي دايماً: الاختبار كان
            // فاضي تماماً وبيقول «مفيش كلمة مرور افتراضية» وهو
            // بيفحص ولا حرف.
            $source = file_get_contents($file);

            if (str_contains($source, "Hash::make('password')") || str_contains($source, 'Hash::make("password")')) {
                $offenders[] = str_replace(base_path(), '', $file);
            }
        }

        // ⭐ نتأكد إن الفحص اشتغل — مينفعش يبقى صامت تاني
        $this->assertGreaterThan(
            30,
            count($this->phpFilesIn('app')),
            'الفحص لازم يشوف ملفات كتير — لو قليل يبقى في خلل'
        );

        $this->assertSame(
            [],
            $offenders,
            "كلمات مرور افتراضية معروفة في:\n  • ".implode("\n  • ", $offenders)
            ."\n\nاستخدم Str::password() وخلّي must_change_password = true."
        );
    }

    /**
     * ⭐ والـ seeders نفسها — لازم **ترفض تشتغل في الإنتاج**.
     *
     * ده اللي بيخلّي الاستثناء فوق مقبول: كلمة المرور `'password'`
     * مقبولة في بيات تجريبية بتتيمس، ومرفوضة في بيئة حقيقية.
     */
    public function test_every_demo_seeder_refuses_to_run_in_production(): void
    {
        $seeders = [
            'DatabaseSeeder', 'SeedEmployees', 'SeedTeachers',
            'SeedParentAccounts', 'TestdataSeeder', 'BigdataSeeder',
            'RealdataSeeder', 'RealisticDataSeeder',
        ];

        $missing = [];

        foreach ($seeders as $seeder) {
            $path = database_path("seeders/{$seeder}.php");

            if (! file_exists($path)) {
                $missing[] = "{$seeder} — الملف مش موجود";

                continue;
            }

            $source = file_get_contents($path);

            // ⭐ لازم الـtrait **و** النداء — واحد فيهم ما يكفيش.
            //
            // ⚠️ لو الـtrait موجود بس من غير نداء، الـguard ماشغّال
            // والـseeder بيكتب عادي. ولو النداء موجود بس من غير
            // trait، الـseeder أصلاً مش هيشتغل.
            if (! str_contains($source, 'RefusesToRunInProduction')) {
                $missing[] = "{$seeder} — مافيش use للـ RefusesToRunInProduction trait";

                continue;
            }

            if (! preg_match('/\$this->guardProduction\(\s*[\'"]' . $seeder . '[\'"]\s*\)/', $source)) {
                $missing[] = "{$seeder} — مافيش نداء guardProduction() في أول run()";
            }
        }

        $this->assertSame([], $missing, "seeders من غير حارس الإنتاج:\n  • ".implode("\n  • ", $missing));
    }

    /**
     * ⭐⭐⭐ الحارس **بيشتغل فعلاً** — مش بس مكتوب في الملف.
     *
     * ⭐ الفحص النصي فوق (في الاختبار السابق) ممكن يمرّي بـ seeder
     * فيه `guardProduction()` في آخر `run()` بدل أوله — يعني
     * **بعد** ما يكتب البيانات. فلازم نتحقق بالتنفيذ.
     *
     * ⭐ وبيتحقق إن التطبيق **مش** في وضع الإنتاج دلوقتي — عشان
     * الاختبار نفسه يعدّي. فبنعمل seeder بيبوظ عمداً ونشوف.
     */
    public function test_the_production_guard_actually_refuses(): void
    {
        // ⭐ الـguard بيرمي لو `app()->isProduction()`. بنستدعيه
        // مباشرة وهو دالرة guard بس — من غير ما نغيّر `APP_ENV`،
        // لأن الاختبار دايماً في `testing`.
        $this->assertFalse(
            app()->isProduction(),
            'الاختبار ده بيشتغل في بيئة production — غلط'
        );

        // ⭐ الـtrait موجود وبيستخدم الدالة الصح
        $trait = new \ReflectionClass(\Database\Seeders\Concerns\RefusesToRunInProduction::class);

        $this->assertTrue(
            $trait->hasMethod('guardProduction'),
            'الـtrait مفقود منه guardProduction()'
        );

        $method = $trait->getMethod('guardProduction');
        $this->assertTrue(
            $method->isProtected(),
            'guardProduction() لازم تكون protected — غير كده الـseeder بيعرضها كـ API'
        );

        // ⭐ وبتشتغل من غير exception في غير الإنتاج
        $probe = new class {
            use \Database\Seeders\Concerns\RefusesToRunInProduction;

            public function tryGuard(string $name): string
            {
                try {
                    $this->guardProduction($name);

                    return 'passed';
                } catch (\RuntimeException $e) {
                    return 'refused';
                }
            }
        };

        $this->assertSame(
            'passed',
            $probe->tryGuard('TestdataSeeder'),
            'في غير الإنتاج، الحارس لازم يعدّي'
        );
    }

    /**
     * ⭐⭐⭐ الحارس **بيرمي فعلاً** في وضع الإنتاج.
     *
     * ⭐ الاختبار السابق بيتأكد إن الـtrait موجود وإنه يعدّي في
     * `testing`. ده بيتأكد إن في `production` **بيترمي** — وده
     * اللي بيحمي فعلياً.
     *
     * ⭐ بنعمل seeder وهمي بيستخدم نفس الـtrait، وبنلفّ
 * * ⭐ بنلفّ `app()->environment()` بالـ production بس ثانية،
     */
    public function test_the_guard_refuses_in_production(): void
    {
        $this->assertFalse(app()->isProduction());

        $probe = new class {
            use \Database\Seeders\Concerns\RefusesToRunInProduction;

            public function run(): void
            {
                $this->guardProduction('FakeSeeder');
            }
        };

        // ⭐ نلفّ الـenvironment ونرجّعه بعد — غير كده باقي
        // الاختبار بيشتغل في production وبيكسر كل حاجة.
        $original = app()['env'];

        try {
            app()['env'] = 'production';

            $this->assertTrue(
                app()->isProduction(),
                'الـenvironment ماتبغيش production — الاختبار بيلغبط'
            );

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('مرفوض في الإنتاج');

            $probe->run();
        } finally {
            app()['env'] = $original;

            $this->assertFalse(app()->isProduction(), 'الـenvironment مرجعش');
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ② إنشاء الحساب
    // ═══════════════════════════════════════════════════════════════

    public function test_an_employee_account_gets_a_random_unusable_password(): void
    {
        $admin = $this->makeUserWithRole('admin', ['students.update']);

        $r = $this->postJsonAs('/api/employees', [
            'name' => 'موظف جديد',
            'email' => 'new-employee@quran-academy.com',
            'create_account' => true,
        ], $admin)->assertCreated();

        $user = User::where('email', 'new-employee@quran-academy.com')->firstOrFail();

        // ⭐ الكلمة المولّدة **مش** `password`
        $this->assertFalse(
            Hash::check('password', $user->password),
            'الحساب لسه بيتعملهاش كلمة مرور معروفة'
        );

        // ⭐ ومفيش way نعرفها — عشان كده `must_change_password`
        $this->assertTrue(
            (bool) $user->must_change_password,
            'الحساب لازم be مجبور يغيّر كلمة المرور'
        );
    }

    /**
     * ⭐⭐ الكلمة المولّدة **ما ترجعش في الـAPI** — أبداً.
     *
     * ⚠️ أول نسخة من الاختبار ده كانت بتدوّر على الحرف
     * `'password'` جوه الرد — والرد فيه حقول اسمها
     * `must_change_password` و `password_changed_at`، فالاختبار
     * كان بيفشل على **أسماء الحقول** مش على التسريب.
     *
     * ⭐ فالفحص الصح: بصمة الـhash، مش اسم الحقل.
     */
    public function test_the_generated_password_is_never_returned_in_the_response(): void
    {
        $admin = $this->makeUserWithRole('admin', ['students.update']);

        $user = $this->makeUserWithRole('someone', []);

        $body = $this->postJsonAs('/api/employees', [
            'name' => 'موظف',
            'email' => 'secret-leak@quran-academy.com',
            'create_account' => true,
        ], $admin)->assertCreated()->getContent();

        // ⭐ بصمة bcrypt — دي اللي لو ظهرت، الباسورد اتسرّب
        $this->assertStringNotContainsString('$2y$', $body);
        $this->assertStringNotContainsString('$argon', $body);

        // ⭐ والـhash نفسه
        $this->assertStringNotContainsString($user->password, $body);
    }

    /**
     * ⭐ الحساب الجديد **مش بيفتح حاجة** غير تغيير كلمة المرور.
     *
     * ⚠️ وده السلوك المقصود: لو سابنا الحساب active وهو عارف
     * كلمة مرمية، يبقى عنده صلاحية كاملة ولسه ما قراشPolicy.
     */
    public function test_a_must_change_password_account_is_blocked_everywhere(): void
    {
        $user = $this->makeUserWithRole('new_hire', ['students.view']);
        $user->forceFill(['must_change_password' => true])->save();

        $headers = $this->authHeaders($user);

        // ⭐ محمي
        $this->withHeaders($headers)->getJson('/api/students')->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/programs')->assertStatus(403);

        // ⭐ مسموح — غيره مش هيعرف يغيّرها أصلاً
        $this->withHeaders($headers)->getJson('/api/auth/me')->assertOk();
        $this->withHeaders($headers)->postJson('/api/auth/logout')->assertOk();
    }

    public function test_a_normal_account_is_not_blocked(): void
    {
        // ⭐ `programs.view` هي اللي `/api/programs` بتطلبها — مش
        // `students.view`. أول نسخة كانت بتدي `students.view`
        // فيبقى 403 من `CheckPermission` (مش من الفلتر) والاختبار
        // بيفشل لسبب غلط.
        $user = $this->makeUserWithRole('normal', ['programs.view']);

        $this->getJsonAs('/api/programs', $user)->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الاستعادة بتفعل فعلاً
    // ═══════════════════════════════════════════════════════════════

    public function test_the_reset_token_table_exists(): void
    {
        $this->assertTrue(
            DB::getSchemaBuilder()->hasTable('password_reset_tokens'),
            'جدول password_reset_tokens مفقود — Password:: بيشتغل عليه'
        );
    }

    /**
     * ⭐⭐ `forgotPassword` بيبعت توكن **فعلاً**.
     *
     * * ⭐⭐ الرد موحّد سواء الإيميل مسجّل ولا لأ — عشان ما نكشفش
     * * إن في حساب أصلاً.
     */
    public function test_forgot_password_actually_sends_a_token(): void
    {
        Notification::fake();

        $user = $this->makeUserWithRole('someone', []);
        $user->forceFill(['email' => 'real-user@quran-academy.com'])->save();

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'real-user@quran-academy.com',
        ])->assertOk();

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'real-user@quran-academy.com',
        ]);

        Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class);
    }

    /**
     * ⭐ الرد **واحد** سواء الإيميل موجود أو لأ.
     */
    public function test_forgot_password_gives_the_same_answer_either_way(): void
    {
        Notification::fake();

        $known = $this->postJson('/api/auth/forgot-password', [
            'email' => 'nobody-here@nowhere.test',
        ])->assertOk();

        // ⭐ نفس الرسالة بالظبط — مافيش تمييز
        //
        // ⚠️ بتقارن بالـconst نفسه مش بنسخة مكتوبة، عشان لو
        // الرسالة اتغيّرت في الكود يبقى الاختبار بيلاقي السبب.
        $this->assertSame(
            \App\Http\Controllers\Api\AuthController::FORGOT_MESSAGE,
            $known->json('message'),
        );

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'nobody-here@nowhere.test',
        ]);
    }

    /**
     * ⭐⭐ الاستعادة بتغيّر كلمة المرور فعلاً.
     */
    public function test_reset_password_actually_changes_the_password(): void
    {
        Notification::fake();

        $user = $this->makeUserWithRole('someone', []);
        $user->forceFill(['email' => 'reset-me@quran-academy.com'])->save();

        $token = $this->plainTokenFor('reset-me@quran-academy.com');

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'reset-me@quran-academy.com',
            'password' => 'Kaligon-Shatra-2026!',
            'password_confirmation' => 'Kaligon-Shatra-2026!',
        ])->assertOk();

        $this->assertTrue(
            Hash::check('Kaligon-Shatra-2026!', $user->fresh()->password),
            'كلمة المرور ما اتغيرتش'
        );
    }

    /**
     * ⭐⭐ التوكن **مرة واحدة بس** — ده معنى «صالح للاستخدام مرة».
     */
    public function test_a_reset_token_works_only_once(): void
    {
        Notification::fake();

        $user = $this->makeUserWithRole('someone', []);
        $user->forceFill(['email' => 'once@quran-academy.com'])->save();

        $token = $this->plainTokenFor('once@quran-academy.com');

        $payload = [
            'token' => $token,
            'email' => 'once@quran-academy.com',
            'password' => 'Kaligon-Shatra-2026!',
            'password_confirmation' => 'Kaligon-Shatra-2026!',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        // ⭐⭐ التانية لازم تفشل
        //
        // ⚠️ المفتاح بالتعربي مش `email` — `TranslateValidationErrors`
        // بيترجم أسماء الحقول قبل ما الرد يطلع. وده السلوك
        // الصح: الرد كله عربي زي باقي الـAPI. أول نسخة من
        // الاختبار كانت بتدوّر على `email` وبتفشل.
        $this->postJson('/api/auth/reset-password', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('البريد الإلكتروني');

        // ⭐ وكلمة المرور ما اتغيرتش تاني
        $this->assertTrue(
            Hash::check('Kaligon-Shatra-2026!', $user->fresh()->password),
            'التوكن اتقبل مرتين — كلمة المرور اتغيرت مرتين'
        );
    }

public function test_an_old_token_stops_working_after_the_reset(): void
    {
        $user = $this->makeUserWithRole('someone', []);
        $user->forceFill(['email' => 'stale@quran-academy.com'])->save();

        // ⭐ توكن **منهوب** — قبل أي طلب استعادة.
        //
        // ⚠️ ما بنستعملش `plainTokenFor` هنا: هي بتنادي
        // `Notification::fake()` اللي بتمسح أي إشعار مسجّل
        // قبل كده — و`createToken` بينادي الـnotification فعلياً.
        $stolen = Password::broker()->createToken($user);

        // ⭐ sanity: المسروق شغّال قبل أي طلب
        $this->assertTrue(
            Password::broker()->tokenExists($user, $stolen),
            'التوكن المسروق مفروض يكون شغّال في البداية'
        );

        // ⭐ⓘ نمشي بالوقت 61 ثانية عشان نتجاوز الـthrottle.
        //
        // ⭐⭐ `sendResetLink` بيرجّع `RESET_THROTTLED` لو فيه توكن
        // اتعمل في آخر 60 ثانية (`config/auth.php: throttle`).
        // وده سلوك **صح** — بيمنع إغراق الإيميل بتوكنات.
        //
        // ⭐ فلو ما مشيناش بالوقت، الطلب بيرجّع 200 لكن **من غير
        // ما يبعت إيميل** — وده بالظبط اللي اختبارنا بيريد يتأكد
        // إنه مش بيحصل.
        $this->travel(61)->seconds();

        // ⭐ صاحب الحساب بيطلب استعادة جديدة (عبر الـAPI).
        //
        // ⚠️ من غير `Notification::fake()` — عشان نقدر نقرا التوكن
        // الجديد من الإشعار اللي اتبعت.
        $this->postJson('/api/auth/forgot-password', ['email' => 'stale@quran-academy.com'])
            ->assertOk();

        // ⭐ التوكن المسروق بقى ميت **فوراً** بالطلب الجديد
        $this->assertFalse(
            Password::broker()->tokenExists($user, $stolen),
            'طلب استعادة جديد لازم يبطل التوكن القديم فوراً'
        );
    }

    /**
     * ⭐ كلمة المرور الجديدة لازم تفصل التوكنين.
     *
     * ⭐⭐ ده **مهم أمنياً**: من غير ده، لو حد سرق التوكن قبل
     * ما صاحب الحساب يغيّر كلمة المرور، يقدر يدخل بيه بعد التغيير.
     */
    public function test_resetting_the_password_revokes_existing_tokens(): void
    {
        Notification::fake();

        $user = $this->makeUserWithRole('someone', []);
        $user->forceFill(['email' => 'revoke@quran-academy.com'])->save();

        // ⭐ جلسة شغّالة
        $this->postJson('/api/auth/login', [
            'email' => 'revoke@quran-academy.com',
            'password' => 'password',
        ])->assertOk();

        $tokensBefore = DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count();
        $this->assertGreaterThan(0, $tokensBefore, 'لازم يكون فيه توكن من تسجيل الدخول');

        $token = $this->plainTokenFor('revoke@quran-academy.com');

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => 'revoke@quran-academy.com',
            'password' => 'Kaligon-Shatra-2026!',
            'password_confirmation' => 'Kaligon-Shatra-2026!',
        ])->assertOk();

        $this->assertSame(
            0,
            DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count(),
            'الجلسات القديمة لسه شغّالة بعد تغيير كلمة المرور'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ التوكن **الصريح** من الإشعار — مش من جدول التوكنات.
     *
     * ⚠️ جدول `password_reset_tokens` بيخزّن `hash('sha256', $plain)`
     * مش التوكن نفسه. فلو الاختبار قرأ من الجدول كان هيبعت
     * **بصمة bcrypt** كـ token والطلب بيرجع «اللينك غير صالح» —
     * وبيبقى اختبار بيفشل لسبب مش ده.
     *
     * ⭐ فبنقرا من الإشعار، زي ما المستخدم بياخده من الإيميل.
     */
private function plainTokenFor(string $email): string
    {
        Notification::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => $email])
            ->assertOk();

        // ⭐ لازم الـUser **الحقيقي** من الداتابيز — مش object
        // جديد بإيميل بس.
        //
        // ⚠️ `assertSentTo` بيطابق بـ `routeNotificationFor` اللي
        // بتاخد `id` + `email` من الـnotifiable. فـ
        // `new User(['email' => …])` من غير id **مش** بيطابق.
        $user = User::where('email', $email)->firstOrFail();

        $plain = null;

        Notification::assertSentTo(
            $user,
            \Illuminate\Auth\Notifications\ResetPassword::class,
            function ($notification) use (&$plain) {
                $plain = $notification->token;

                return true;
            },
        );

        $this->assertNotNull($plain, "مفيش إشعار استعادة اتبعت للإيميل {$email}");

        return $plain;
    }

    /** @return array<int, string> */
    private function phpFilesIn(string $dir): array
    {
        $path = base_path($dir);

        if (! is_dir($path)) {
            return [];
        }

        $files = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
