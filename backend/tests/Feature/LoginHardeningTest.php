<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * ⭐⭐⭐ تخمين كلمة المرور بيكلف — والحساب الموقوف بيقف فوراً.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة اللي الاختبار ده بيثبتها
 *
 * ① **مافيش throttle على الدخول إطلاقاً.**
 *
 *    `POST /auth/login` كان مفتوح. حد يجرّب ١٠٠٠ كلمة مرور في
 *    الدقيقة على حساب， — مافيش حاجة بتوقفه.
 *
 * ② ⭐⭐ **التوكين ما بينتهيشش أبداً.**
 *
 *    `config/sanctum.php:47` فيه `'expiration' => null` — فالتوكين
 *    المولّد كان صالح **للأبد**. حد يسرق توكن من جهاز قديم يقدر
 *    يدخل النظام بعد شهور.
 *
 * ③ ⭐⭐ **حساب موقوف بيشتغل عادي.**
 *
 *    `AuthController::login` بيتأكد إن `status === 'active'` —
 *    بس **وقت الدخول بس**. الـmiddleware مفيش حاجة بتتأكد بعد كده.
 *
 *    ⭐ يعني: لو الأدمن وقف حساب، والتوكن بتاعه **صادر قبل
 *    الوقفة**، الحساب بيفضل شغّال لحد ما ينتهي عمر التوكين —
 *    ودي **للأبد** بالنقطة ②.
 *
 *    ⭐ ده تصعيد صلاحيات كامل: الوقف لازم يوقف فوراً.
 *
 * ④ مافيش تسجيل محاولات الدخول الفاشلة في `audit_logs` — فمافيش
 *    way تعرف إن حد بيعمل هجوم.
 */
class LoginHardeningTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① الـ throttle
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ **القبول: ٦ محاولات = 429.**
     *
     * ⭐ الحد **٥** محاولات في الدقيقة. فلو بعد ٥ Walesias-failures
     * الـ٦ بترجّع 429.
     *
     * ⚠️ `RateLimiter` بيخزّن في الـcache — و`.env.testing` فيه
     * `CACHE_STORE=array`، فالعدّاد **بيتنضّف** بين الاختبارات.
     * فبنعمل `flush` صريح عشان الاختبار يبقى مستقل.
     */
    public function test_six_failed_attempts_are_throttled(): void
    {
        $this->resetRateLimiter();

        $user = $this->makeUserWithRole('someone', []);

        // ⭐ ٥ محاولات — كلها بتفشل
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // ⭐⭐ السادسة = 429
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    /**
     * ⭐ الـ429 لازم يكون بالعربي — زي باقي الـAPI.
     */
    public function test_the_throttle_response_is_in_arabic(): void
    {
        $this->resetRateLimiter();

        $user = $this->makeUserWithRole('someone', []);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $r = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(429);

        $message = (string) $r->json('message');

        $this->assertNotSame('', $message, 'الرد 429 مافيهوش رسالة');
        $this->assertMatchesRegularExpression(
            '/[\x{0600}-\x{06FF}]/u',
            $message,
            "رسالة الـ429 لازم تكون بالعربي،Got: {$message}"
        );
    }

    /**
     * ⭐ الحد على **الإيميل** — مش على الـIP بس.
     *
     * ⭐⭐ ده اللي بيفرق: لو الحد كان بالـIP بس، مهاجم ورا NAT
     * (شركة، بيت، كافيه) بيخبط كل المستخدمين، ومهاجم عنده كتير من
     * الـIPs بيكسر حد الإيميل. لازم **الاتنين** مع بعض.
     *
     * ⭐ هنا بنثبت إن خمس محاولات على إيميل واحد بتقفل **الإيميل
     * ده** بالذات.
     */
    public function test_the_limit_is_per_email_not_just_per_ip(): void
    {
        $this->resetRateLimiter();

        $victim = $this->makeUserWithRole('someone', []);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $victim->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // ⭐ الإيميل ده بقى مقفول
        $this->postJson('/api/auth/login', [
            'email' => $victim->email,
            'password' => 'password',   // حتى بالكلمة الصح
        ])->assertStatus(429);
    }

    /**
     * ⭐ والعدّاد **بيرجع صفر** بعد نجاح.
     *
     * ⭐⚠️ ده مهم: من غير كده، مستخدم بيغلط ٤ مرات فيتنضف
     * عدّاده، يدخل بنجاح، يغلط ٥ مرات تاني ويقفل — رغم إنه اتأكد
     * من نفسه. المحاولة الناجحة لازم تفتح له الطريق.
     */
    public function test_a_successful_login_resets_the_counter(): void
    {
        $this->resetRateLimiter();

        $user = $this->makeUserWithRole('someone', []);

        // ٤ محاولات فاشلة
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }

        // ⭐ المحاولة الخامسة ناجحة
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        // ⭐ فالعدّاد اتصفّر — ٤ محاولات جديدة مسموحة
        for ($i = 1; $i <= 4; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ])->assertStatus(422);
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ② حساب موقوف بيشتغل — Bug حقيقي
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **القبول: حساب توقف بعد ما التوكين صدر → 403 فوراً.**
     *
     * ⭐ ده الاختبار اللي بيمسك الثغرة الحقيقية. قبل الـmiddleware
     * كان التوكين الصالح قبل الوقفة بيفضل شغّال — بشكل **غير
     * محدود** لأن `expiration = null`.
     */
    public function test_suspending_a_user_stops_their_existing_token_immediately(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        // ⭐ التوكين **صادر قبل** الوقفة
        $headers = $this->authHeaders($user);

        // ⭐ لسه شغّال
        $this->withHeaders($headers)->getJson('/api/programs')->assertOk();

        // ⭐ الأدمن بيوقف الحساب
        $user->forceFill(['status' => 'suspended'])->save();

        // ⭐⭐⭐ لازم ننسى الـguard.
        //
        // ⭐ الاختبارات كلها بتعيش في **عملية واحدة**، فـ
        // `auth` service provider بيسمّي guard واحد وبيشيله.
        // أول ما حد يعمل request، الـguard بيحفظ الـuser **المحمّل**
        // فيه. فلو ما نسّيناهوش، الطلب التاني بيشوف نفس الـmodel
        // القديم بـ `status = active`.
        //
        // ⚠️ ده **تفصيلة الاختبار** مش سلوك السيرفر: في الإنتاج
        // كل طلب عملية منفصلة والـguard جديد. وده بالظبط اللي
        // بتوثّقه `TestCase::authHeaders`.
        app('auth')->forgetGuards();

        // ⭐⭐ نفس التوكين — لازم يبقاش شغّال
        $r = $this->withHeaders($headers)->getJson('/api/programs');
        $this->assertContains(
            $r->status(),
            [401, 403],
            'حساب موقوف لسه بياخد 200 بتوكين صادر قبل الوقفة'
        );
    }

    /**
     * ⭐ وكلمة المرور الصح ما بتفتحش حساب موقوف.
     */
    public function test_a_suspended_user_cannot_log_in_even_with_the_right_password(): void
    {
        $this->resetRateLimiter();

        $user = $this->makeUserWithRole('someone', []);
        $user->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_an_inactive_user_is_also_blocked(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        $headers = $this->authHeaders($user);

        $user->forceFill(['status' => 'inactive'])->save();

        $this->withHeaders($headers)->getJson('/api/programs')
            ->assertStatus(403);
    }

    public function test_an_active_user_is_not_blocked(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        $this->getJsonAs('/api/programs', $user)->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ عمر التوكين
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ التوكين لازم **ينتهي**.
     *
     * ⭐ `config/sanctum.php` كان `'expiration' => null` — فالتوكين
     * صالح للأبد. ده بيلغي عملياً أي حماية من سرقة التوكين.
     */
    public function test_the_token_expiration_is_configured(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertNotNull(
            $expiration,
            "config('sanctum.expiration') ب null — التوكين صالح للأبد"
        );

        $this->assertIsInt($expiration, 'الانتهاء لازم يكون رقم دقايق');
        $this->assertGreaterThan(0, $expiration);
    }

    /**
     * ⭐⭐ والـ**قبول**: توكين منتهٍ = 401.
     *
     * ⭐ بنعمل التوكين بنفس expiry بـ Sanctum مباشرة، ونرجّعه
     * للوقت الحالي — فلازم `auth:sanctum` يرفضه.
     */
    public function test_an_expired_token_is_rejected(): void
    {
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        // ⭐ توكين **منتهٍ**.
        //
        // ⚠️ `createToken($name, $abilities, $expiresAt)` — الـ
        // `expiresAt` بارامتر **ثالث**، مش عنصر جوه الـ
        // `abilities`. أول نسخة كانت بتعديها جوه المصفوفة فالتوكين
        // اتعمل من غير انتهاء والطلب رجع 200.
        $token = $user->createToken(
            'expired-token',
            ['*'],
            now()->subMinute(),
        )->plainTextToken;

        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/programs')->assertStatus(401);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ سجل المحاولات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ محاولات الدخول الفاشلة **لازم تتسجل**.
     *
     * ⭐⚠️ **ومفيش كلمة المرور.** تسجيل كلمة المرور في السجل معناه
     * إنه أي حد يقدر يقرا السجل يقدر يسجّل دخول بحسابات.
     */
    public function test_a_failed_login_is_logged_without_the_password(): void
    {
        $this->resetRateLimiter();

        $user = $this->makeUserWithRole('someone', []);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'super-secret-attempt',
        ])->assertStatus(422);

        $log = DB::table('audit_logs')
            ->where('action', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'محاولة الدخول الفاشلة ما اتسجلتش');

        $encoded = json_encode($log) ?: '';

        $this->assertStringNotContainsString(
            'super-secret-attempt',
            $encoded,
            'كلمة المرور اتسجلت في audit_logs — ده سر مكشوف'
        );
        $this->assertStringContainsString(
            $user->email,
            $encoded,
            'السجل لازم يوضح مين اللي حاول'
        );
    }

    public function test_a_successful_login_is_logged(): void
    {
        $this->resetRateLimiter();

        $user = $this->makeUserWithRole('someone', []);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertTrue(
            DB::table('audit_logs')
                ->where('action', 'login')
                ->where('entity_type', 'user')
                ->where('entity_id', $user->id)
                ->exists(),
            'الدخول الناجح ما اتسجلش'
        );
    }

    public function test_suspension_and_reactivation_are_logged(): void
    {
        $admin = $this->makeUserWithRole('admin', ['users.suspend']);
        $user = $this->makeUserWithRole('someone', ['programs.view']);

        // ⭐ وقف
        $user->forceFill(['status' => 'suspended'])->save();

        app(\App\Services\UserStatusService::class)->logSuspension($user, $admin);

        $this->assertTrue(
            DB::table('audit_logs')
                ->where('action', 'user_suspended')
                ->where('entity_id', $user->id)
                ->where('user_id', $admin->id)
                ->exists(),
            'الوقفة ما اتسجلتش — ولاKnow مين وقّف مين'
        );

        // ⭐ رجّع
        $user->forceFill(['status' => 'active'])->save();

        app(\App\Services\UserStatusService::class)->logReactivation($user, $admin);

        $this->assertTrue(
            DB::table('audit_logs')
                ->where('action', 'user_reactivated')
                ->where('entity_id', $user->id)
                ->exists(),
            'إعادة التفعيل ما اتسجلتش'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ مسارات عامة — throttle
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ `POST /groups/{group}/waitlist` عام — وبيكتب في الداتابيز.
     *
     * ⭐ من غير حد، حد يبعت ١٠٠٠ طلب ويسبّute. الخارطة بتقول
     * ١٠ في الساعة لكل IP.
     */
    public function test_the_public_waitlist_route_is_throttled(): void
    {
        $this->resetRateLimiter();

        $group = $this->makeGroup();

        // ⭐ ١٠ مسموحة
        for ($i = 1; $i <= 10; $i++) {
            $r = $this->postJson("/api/groups/{$group->id}/waitlist", [
                'parent_name' => 'ولي أمر '.$i,
                'parent_phone' => '0100000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'child_name' => 'طفل',
            ]);

            $this->assertNotSame(
                429,
                $r->status(),
                "المحاولة {$i} اترفضت بـ429 والمفروض أول ١٠ تعدّي"
            );
        }

        // ⭐ الحادية عشرة
        $this->postJson("/api/groups/{$group->id}/waitlist", [
            'parent_name' => 'الحادية عشرة',
            'parent_phone' => '01000001111',
            'child_name' => 'طفل',
        ])->assertStatus(429);
    }
    /**
     * ⭐⭐⭐⭐ **محاولة دخول لإيميل مش موجود = 422 مش 500.**
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐⭐ الثغرة اللي طلعتها مراجعة الشغل كله
     *
     * ⭐ `LoginRateLimiter::logFailure` بيسجّل المحاولة في
     * `audit_logs`، وبيستخدم:
     *
     *     $this->userIdFor($email) ?: 0
     *
     * ⭐ `audit_logs.user_id` عنده **FK** على `users`. فالـ`0` ده
     * مش مستخدم موجود → **انتهاك قيد**:
     *
     *     SQLSTATE[23000]: FOREIGN KEY constraint failed
     *
     * ⭐⭐⭐ والنتيجة: **أي حد** يكتب إيميل عشوائي في صفحة
     * الدخول بياخد **500** بدل 422. مافيش session، مافيش تحقق
     * — الـFK بيفشل قبل أي حاجة تانية.
     *
     * ⭐ والأسوأ: ده بيخفي **`bad_credentials`** عن الـmonitoring،
     * لأن الـinsert ماتGets أصلاً. أحسن حالة اختراق — إيميلات
     * بتت.guess — **مش بتتسجّل خالص**.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ ليه `user_id = 0` غلط أصلاً
     *
     * ⭐ «مافيش مستخدم بالإيميل ده» هو **بالظبط** الحدث اللي
     * السجل مطلوب عشان يتسجّل. الـFK بيقول «كل سطر لازم
     * يخصّ حد» — وده غلط لصفوف الـauth، لأن الـauth بالذات
     * بتتعامل مع ناس **لسه مالهمش حساب**.
     *
     * ⭐ فالإصلاح: العمود `nullable`، و`logFailure` تبعت `null`.
     */
    public function test_a_login_attempt_for_an_unknown_email_is_422_not_500(): void
    {
        $this->resetRateLimiter();

        $r = $this->postJson('/api/auth/login', [
            'email' => 'nobody-here@example.test',
            'password' => 'whatever',
        ]);

        // ⭐ 422 زي أي بيانات دخول خاطئة
        $r->assertStatus(422, 'محاولة دخول لإيميل مجهول رجّعت 500 — الـaudit insert بيفشل بـFK');
    }

    /**
     * ⭐⭐⭐ والحدث **اتسجّل فعلاً** — مافيش.logger سايط.
     *
     * ⭐ الاختبار الأول بيقول «مش 500». ده بيقول **ليه**: لازم
     * السطر يبقى موجود بـ`user_id = null`.
     *
     * ⭐⚠️ لازم `email` في الـ`new_value` — من غيره السجل
     * بيقول «حد حاول يدخل» من غير **مين**. ودي بالظبط المعلومة
     * اللي `bad_credentials` المفروض يوثّقها.
     */
    public function test_a_login_attempt_for_an_unknown_email_is_audited(): void
    {
        $this->resetRateLimiter();

        $this->postJson('/api/auth/login', [
            'email' => 'ghost@example.test',
            'password' => 'whatever',
        ])->assertStatus(422);

        $log = DB::table('audit_logs')
            ->where('action', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($log, 'محاولة الدخول لإيميل مجهول ما اتسجلتش — أحسن حالة اختراق بتفوت');

        // ⭐ `user_id = null` — مش 0، ومفيش صفحة 0
        $this->assertNull(
            $log->user_id,
            'سجل لـمستخدم مجهول لازم يكون `user_id = null` — رقم 0 بيكسر الـFK'
        );

        $this->assertStringContainsString(
            'ghost@example.test',
            (string) $log->new_value,
            'السجل لازم يعرف مين اللي حاول'
        );
    }

}
