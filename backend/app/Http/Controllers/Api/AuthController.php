<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\StrongPassword;
use App\Services\LoginRateLimiter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * ⭐ الرسالة الموحّدة لطلب الاستعادة.
     *
     * ⭐⭐ لازم تبقى **واحدة** سواء الإيميل مسجّل ولا لأ. الرد
     * المتفاوت («الإيميل غير مسجّل») بيكشف إن في حساب أصلاً —
     * وده **enumeration**: حد بيبعت إيميلات ويشوف مين عنده حساب.
     */
    public const FORGOT_MESSAGE = 'لو الإيميل مسجّل عندنا، هنبعتلك عليه لينك.';

    /**
     * ⭐⭐⭐ تسجيل الدخول — مع **حد للمحاولات**.
     *
     * ⚠️ كان مفتوح بالكامل: مافيش `RateLimiter` خالص، فتخمين
     * كلمة المرور كان **مجاني** وبلا حدود.
     *
     * @see \App\Services\LoginRateLimiter
     * @see \Tests\Feature\LoginHardeningTest
     */
    /**
     * ⭐⭐⭐ تسجيل الدخول — بيسأل: **مين اللي بيطلب؟**
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ الوضع قبل: إيه اللي كان
     *
     * الراوت كان بيرجّع `token` دايماً، و`lib/api.ts` كان بيحطّه
     * في `localStorage`. وده معناه إن **أي سكربت شغّال في الصفحة**
     * يقدر يقراه — أي ثغرة XSS في أي حزمة بتسرّب الجلسة كلها.
     * والكوكي `HttpOnly` المتصفح **مينعش** السكربتات من قراءته.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ الطريقتين
     *
     *   • **SPA** (الطلب جاي من دومين الواجهة): جلسة على السيرفر
     *     في كوكي `HttpOnly`. **مفيش توكن في الرد إطلاقاً.**
     *
     *   • **موبايل / أي عميل تاني**: توكن شخصي، زي الأول.
     *     تطبيق الموبايل مافيش كوكيز، والـCSRF مالوش معنى فيه.
     *
     * ⭐ التمييز بين الاتنين **مش** `Accept` ولا `User-Agent` — ده
     * بيتغيّر ويتقلب. هو نفس الفحص اللي Sanctum بيعمله عشان يقرر
     * يحقن `StartSession`: `EnsureFrontendRequestsAreStateful::fromFrontend`.
     *
     * ⭐ ليه نستخدم نفس الفحص بالظبط؟ عشان متبقاش في حالة وسطى:
     * طلب اتعامل معاه كـcookie بس الرد رجّع توكن، أو العكس.
     * الفحص واحد = السلوك متسق دايماً.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ وملاحظة CSRF
     *
     * `POST /login` نفسه **محمي** بـ`ValidateCsrfToken` (لأنه POST
     * والقادم من SPA بيدخل في مسار `statefulApi`). فعلى الـSPA
     * أول خطوة هي `GET /sanctum/csrf-cookie` عشان تاخد التوكن.
     * الموبايل مش داخل في المسار ده أصلاً — مافيش كوكي يسرقه حد.
     *
     * @see \Tests\Feature\SessionAuthenticationTest
     */
    public function login(Request $request, LoginRateLimiter $rateLimiter)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);

        $email = $request->string('email')->toString();

        // ⭐ الحد يتشخّص **قبل** أي استعلام للداتابيز — عشان
        // مهاجم بيخبط حساب مش موجود مابتخدش processing.
        if ($tooMany = $rateLimiter->enforce($request, $email)) {
            $rateLimiter->logFailure($request, $email, 'throttled');

            return $tooMany;
        }

        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            // ⭐⭐ تسجّل المحاولة الفاشلة — **من غير كلمة المرور**.
            $rateLimiter->hit($request, $email);
            $rateLimiter->logFailure($request, $email, 'bad_credentials');

            throw ValidationException::withMessages([
                'email' => ['بيانات الدخول غير صحيحة.'],
            ]);
        }

        if ($user->status !== 'active') {
            // ⭐ حساب موقوف = محاولة فاشلة كمان — وإلا حد يقدر
            // يستكشف الحسابات الموقوفة مقابل كلمة مرور **صحيحة**
            // من غير ما يدفع حد أقصى.
            $rateLimiter->hit($request, $email);
            $rateLimiter->logFailure($request, $email, 'inactive_account');

            throw ValidationException::withMessages([
                'email' => ['الحساب غير نشط — كلّم إدارة الأكاديمية.'],
            ]);
        }

        // ⭐ نجح — العدّاد بيرجع صفر
        $rateLimiter->clear($request, $email);

        $user->update(['last_login_at' => now()]);

        $isSpa = self::isSpaRequest($request);

        // ⭐⭐ **session fixation**: لو الـid فضل زي ما هو بعد
        // الدخول، حد يفتح `/login` وبياخد كوكي `laravel_session=X`
        // قبل ما المستخدم يعمل دخول، وبقى معاه جلسة صالحة **باسم
        // المستخدم ده**. فبنغيّر الـid إجبارياً.
        if ($isSpa) {
            Auth::guard('web')->login($user, $request->boolean('remember'));

            $request->session()->regenerate();

            $payload = [
                // ⭐ **مافيش `token` خالص** — الـSPA بيخزّن في
                // `localStorage` لو لقى الحقل ده.
                'auth_mode' => 'session',
                'user' => $this->profile($user),
            ];
        } else {
            $payload = [
                'auth_mode' => 'token',
                'token' => $user->createToken('api-token')->plainTextToken,
                'user' => $this->profile($user),
            ];
        }

        // تسجيل الدخول في سجل النشاط — لازم نمرر الـ user_id صراحةً
        // لأن المستخدم لسه مش متحقّق (audit_logs.user_id NOT NULL)
        app(\App\Services\AuditLogService::class)->log(
            'login', 'user', $user->id, null,
            ['auth_mode' => $isSpa ? 'session' : 'token'],
            $request, $user->id,
        );

        return response()->json($payload);
    }

    /**
     * ⭐⭐ هل الطلب جاي من الـSPA؟
     *
     * ⭐ **نفس** الفحص اللي Sanctum بيعمله قبل ما يحقن
     * `StartSession` و`ValidateCsrfToken`. فمفيش حالة وسطى:
     * إما الطلب متعامل معاه كـcookie، أو مش.
     *
     * ⭐ لو استعملنا `Accept` أو `User-Agent`، حد يقدر يقلّدهم
     * وياخد مسار الـcookie بطلب من غير Origin — أو العكس.
     *
     * @see \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::fromFrontend()
     */
    public static function isSpaRequest(Request $request): bool
    {
        return \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::fromFrontend($request);
    }

    /**
     * ⭐⭐ تسجيل الخروج — بطّل **اللي دخل بيه**، مش كل حاجة.
     *
     * ═══════════════════════════════════════════════════════════════
     * قبل
     *
     *     $request->user()->currentAccessToken()->delete();
     *
     * ⭐ ده كان بيرمي `Call to a member function delete() on null`
     * على جلسة كوكي — لأن `currentAccessToken()` بيرجّع `null`
     * لما الدخول كان بالحساب مش بتوكن. يعني **الخروج بالحساب
     * كان مكسور أصلاً**، ومش كان فيه حساب بالحساب أصلاً عشان
     * يبان.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ دلوقتي
     *
     *   • جلسة → `Auth::guard('web')->logout()` + `invalidate()` +
     *     `regenerateToken()`. الـ`invalidate` بيمسح بيانات الجلسة
     *     من السيرفر، والـ`regenerateToken` بيغيّر الـCSRF token
     *     بتاعها. من غير كده الكوكي بيفضل صالح.
     *
     *   • توكن → `currentAccessToken()->delete()` زي الأول.
     *
     * ⭐ `currentAccessToken()` بيرجّع `TransientToken` كائن مش
     * `PersonalAccessToken` لما الدخول بالحساب — و`delete()` على
     * الـ`TransientToken` **بينهي الجلسة** فعلاً (Laravel بيعمل
     * كده في `Guard::logout()`)، لكن بنفضل نميز صراحةً عشان
     * السلوك يبان في الكود.
     */
    public function logout(Request $request)
    {
        $user = $request->user();

        $token = $user->currentAccessToken();

        $usedSession = ! $token instanceof \Laravel\Sanctum\PersonalAccessToken;

        if ($usedSession) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        } else {
            $token->delete();
        }

        app(\App\Services\AuditLogService::class)->log(
            'logout', 'user', (int) $user->id, null,
            ['auth_mode' => $usedSession ? 'session' : 'token'],
            $request, $user->id,
        );

        return response()->json(['message' => 'تم تسجيل الخروج']);
    }

    public function me(Request $request)
    {
        return response()->json($this->profile($request->user()));
    }

    /**
     * بيانات المستخدم + مربوطه بالمعلم أو ولي الأمر (لو كان واحد منهم).
     *
     * public منذ PR 1.9 — TwoFactorController بيستخدمه في رد
     * التحقق أيضاً.
     */
    public function profile(User $user): array
    {
        $user->load(['roles', 'teacher', 'parentProfile']);

        $parentProfile = $user->parentProfile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'department' => $user->department,
            'status' => $user->status,
            // ⭐⭐ الواجهة محتاجة تعرف ده عشان توديه لصفحة تغيير
            // كلمة المرور. الـmiddleware لوحده مش كافي — هو بيرجّع
        // 403 مش redirect: ده API مش صفحة — الرد اللي بيرجّعه هو
        // اللي بيحوّل نفسه لـ redirect في الواجهة.
            'must_change_password' => (bool) ($user->must_change_password ?? false),
            'roles' => $user->roles->pluck('name'),
            // ⭐ الصلاحيات نفسها — عشان الواجهة تعرف مين يقدر يعدّل.
            // قبل كده الواجهة كانت عميانة: بتطلب وتاخد 403، أو
            // بتخفي الزرار وبعدين تطلع رسالة. دلوقتي تقدر تعرض
            // الزرار بس لللي له الصلاحية.
            'permissions' => $user->getAllPermissions()->pluck('name'),
                        /**
             * ⭐⭐⭐ `is_teacher` معناها «المستخدم **بيشتغل** معلم» —
             * مش «فيه سجل معلم في الداتابيز».
             *
             * ⚠️⚠️ المشكلة اللي اتكشفت:
             *
             *     'is_teacher' => $user->teacher !== null,
             *
             * أي `user_id` مربوط بسجل `teachers` كان بيحسب
             * معلم — حتى لو الحساب أصلاً **إداري**.
             *
             * ⭐ النتيجة الفعلية في القاعدة: حساب المدير نفسه
             * مربوط بمعلم #1. والواجهة بتحسب
             * `isTeacherOnly = is_teacher && teacher` وبتوديه
             * `/my-schedule` — يعني **المدير مش بيوصل أي صفحة
             * إدارية خالص**.
             *
             * ⭐ الفصل الصح: **الدور** هو اللي بيقول الشخص ده
             * معلم ولا إداري. ربط السجل بيقول «فيه بيانات» بس.
             *
             * ⭐ وأ-condition واحد بيكفي:
             *   - لازم يكون مربوط بسجل معلم
             *   - ولازم **مش** يكون عنده دور إداري
             */
            'is_teacher' => $user->teacher !== null && !$user->hasRole('admin'),
            'teacher' => $user->teacher ? [
                'id' => $user->teacher->id,
                'teacher_code' => $user->teacher->teacher_code,
                'display_name' => $user->teacher->display_name,
                'specialization' => $user->teacher->specialization,
                'avatar_url' => $user->teacher->avatar_url,
            ] : null,
            // ⭐⭐ **مشتقّ بالكامل** من وجود ملف ولي أمر (PR 1.3).
            //
            // ⭐ قبل كده: `(bool) $user->is_parent || $parentProfile
            // !== null` — يعني **عمود + علاقة**. العمود كان ممكن
            // يقول `true` ومافيش `parents` record، فالمستخدم بياخد
            // `is_parent: true` وفي نفس الوقت بوابة الأبناء
            // بترجّع 404 «مفيش ملف ولي أمر». ردّين متناقضين من
            // مصدرين مختلفين لنفس الحقيقة.
            //
            // ⭐ دلوقتي: علاقة واحدة. مستحيل يتناقضوا.
            //
            // ⭐ والعمود لسه موجود في الداتابيز (الشيل في
            // migration بعدين) بس مافيش حاجة بتقراه.
            'is_parent' => $parentProfile !== null,
            'parent' => $parentProfile ? [
                'id' => $parentProfile->id,
                'name' => $parentProfile->name,
                'phone' => $parentProfile->phone,
                'email' => $parentProfile->email,
                'children_count' => $parentProfile->students()->count(),
            ] : null,
        ];
    }

    /**
     * ⭐⭐ الرد واحد سواء الإيميل موجود ولا لأ.
     *
     * ⭐ لو رجعنا «إيميلك مش مسجّل» للميل الغريب، أي حد بيبعت
     * طلب يقدر يكشف إيه الحسابات المسجّلة. ده **enumeration**:
     * enumerate الإيميلات مملوكATA وألقى مين عنده حساب.
     * فبنرجّع نفس الرسالة ونصمت في الحالتين.
     */
    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        // ⭐ بنبلع النتيجة: الرد واحد في كل الحالات.
        //
        // الأخطاء الحقيقية (DB مقفول مثلاً) بتفضل ترمي — دي مش
        // حالة «إيميل مش موجود»، دي خطأ سيرفر حقيقي.
        try {
            Password::sendResetLink(['email' => $request->string('email')->toString()]);
        } catch (\Throwable $e) {
            // ⭐ⓘ بنسجّله عشان نفهم، بس **مش** بنردّ عليه.
            //
            // ⚠️ مفيش استثناءات مستوردة هنا عن قصد: `Password::`
            // بيرمي `UserNotFoundException`/`TokenMismatchException`
            // بس، وربط الأسماء بملف واحد أقلImports أقل خطأ.
            \Illuminate\Support\Facades\Log::warning('forgot-password failed', [
                'email' => $request->string('email')->toString(),
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => self::FORGOT_MESSAGE,
        ]);
    }

    /**
     * ⭐⭐ الاستعادة الحقيقية.
     *
     * ⚠️ الكود القديم كان:
     *
     *     public function resetPassword(Request $request)
     *     {
     *         $request->validate([...]);
     *         return response()->json(['message' => 'تم تغيير كلمة المرور']);
     *     }
     *
     * ⭐ مفيش `Password::reset`، مفيش تغيير، مفيش توكن — الراوت
     * كان بيرجّع 200 والمستخدم بيظن إنه غيّر كلمة المرور وهو
     * لسه على القديمة.
     */
public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'email' => 'required|email',
            'password' => [
                'required', 'string',
                'confirmed',
                // ⭐⭐ `StrongPassword` — لازم ١٢ حرف + مش شائعة +
                // مش جزء من الإيميل. `min:12` لوحده كان بيسمح
                // بـ `aaaaaaaaaaaa`.
                new StrongPassword(12, $request->string('email')->toString()),
            ],
        ]);

        $status = Password::reset(
            [
                'email' => $request->string('email')->toString(),
                'token' => $request->string('token')->toString(),
                'password' => $request->string('password')->toString(),
                'password_confirmation' => $request->string('password_confirmation')->toString(),
            ],
            function (User $user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    // ⭐ اتغيّرت كلمة المرور → مش محتاج يغيّرها تاني
                    'must_change_password' => false,
                    'password_changed_at' => now(),
                ])->save();

                // ⭐⭐⭐ إبطال كل الجلسات القائمة.
                //
                // ⭐ دي أهم خطوة في الاستعادة كلها: من غيرها، حد
                // سرق التوكن قبل ما صاحب الحساب يغيّر كلمة المرور
                // يقدر يدخل **بعد** التغيير — لأن التوكنات القديمة
                // بتفضل شغّالة.
                //
                // ⭐ وبكده الاستعادة بتبقى وسيلة **طرد** مهاجم،
                // مش بس تغيير كلمة.
                $user->tokens()->delete();

                app(\App\Services\AuditLogService::class)->log(
                    'password_reset',
                    'user',
                    (int) $user->id,
                    null,
                    ['tokens_revoked' => true],
                    request(),
                    $user->id,
                );
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $message = self::resetErrorMessage($status);

            return response()->json([
                'message' => $message,
                'errors' => ['email' => [$message]],
            ], 422);
        }

        return response()->json(['message' => 'تم تغيير كلمة المرور. سجّل دخولك بالكلمة الجديدة.']);
    }

    /**
     * ⭐⭐ ترجمة نتيجة الـ password broker.
     *
     * ⚠️ `__($status)` **مش بترجم** — أسطر `passwords.*` في
     * `lang/` بتاعت Laravel مش متسطّبة بالعربي، فالرد كان بيطلع:
     *
     *     "This password reset token is invalid."
     *
     * ⭐ ودي كانت **مضلّلة**: الواجهة كلها عربي، والمستخدم بيقرأ
     * «اللينك مش صالح» بلغة تانية.
     */
    private static function resetErrorMessage(string $status): string
    {
        return match ($status) {
            Password::INVALID_TOKEN => 'اللينك غير صالح أو منتهي صلاحيته.',
            Password::INVALID_USER => 'حساب غير موجود.',
            Password::RESET_THROTTLED => 'استنى شوية — جرّب تاني بعد قليل.',
            default => 'اللينك مش صالح. اطلب واحد جديد.',
        };
    }

    /**
     * ⭐⭐ تغيير كلمة المرور لحساب `must_change_password = true`.
     *
     * ⭐ دي الـendpoint اللي `EnsurePasswordChanged` بيسمح بيها
     * جوه الفلتر — من غيرها الحساب يبقى محبوس ومفيش مخرج.
     */
    public function changePassword(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
'password' => [
                'required', 'string',
                'confirmed',
                new StrongPassword(12, $user->email),
            ],
        ]);

        // ⭐⭐ لازم كلمة المرور الحالية صح.
        //
        // ⭐ من غير الفحص ده، حد سرق التوكن يقدر يغيّر كلمة المرور
        // من غير ما يعرف القديمة — وكده الاستعادة مالهاش لازمة
        // والسرقة بتبقى **دائمة**.
        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'كلمة المرور الحالية غلط',
                'errors' => ['current_password' => ['كلمة المرور الحالية غير صحيحة']],
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($data['password']),
            'must_change_password' => false,
            'password_changed_at' => now(),
        ])->save();

        // ⭐⭐⭐ إبطال **التوكينات التانية** — والجلسات التانية كمان.
        //
        // ⭐ ⚠️ ⚠️ `currentAccessToken()` **ما عندهاش `id` دايمًا**.
        //
        // في وضع التوكين بترجّع `PersonalAccessToken` ليها `id`.
        // في وضع **الجلسة** (كل مستخدمات الويب من PR 1.1)
        // Sanctum بيرجّع `TransientToken` — placeholder **مالوش
        // `id` خالص**، وال accessing ليها كان بيعمل:
        //
        //     Undefined property: Laravel\Sanctum\TransientToken::$id
        //
        // يعني `changePassword` كانت بتعمل **500 على كل مستخدم
        // ويب**. وهي الـendpoint اللي `EnsurePasswordChanged`
        // بيسمح بيها جوه الفلتر — فحساب `must_change_password`
        // كان **محبوس ومالهوش مخرج**: الدخول شغّال، كل صفحة 403،
        // وبدون التغيير ده مافيش صفحة تعملها.
        //
        // ⭐ الحل: `instanceof PersonalAccessToken` قبل ما نقرأ
        // الـ`id`. و`?->` لوحدها مش كفاية — هي بتحمي من `null`
        // ⭐⭐⭐ إبطال **التوكينات التانية** — والجلسات التانية كمان.
        //
        // ⭐ ⚠️ ⚠️ `currentAccessToken()` **مالهاش `id` دايمًا**.
        //
        // في وضع التوكين بترجّع `PersonalAccessToken` ليها `id`.
        // في وضع **الجلسة** (كل مستخدمات الويب من PR 1.1)
        // Sanctum بيرجّع `TransientToken` — placeholder **مالوش
        // `id` خالص**، والوصول ليها كان بيعمل:
        //
        //     Undefined property: Laravel\Sanctum\TransientToken::$id
        //
        // يعني `changePassword` كانت بتعمل **500 على كل مستخدم
        // ويب**. وهي الـendpoint اللي `EnsurePasswordChanged`
        // بيسمح بيها جوه الفلتر — فحساب `must_change_password`
        // كان **محبوس ومالهوش مخرج**: الدخول شغّال، كل صفحة 403،
        // وبدون التغيير ده مافيش صفحة تعملها.
        //
        // ⭐ الحل: `instanceof PersonalAccessToken` قبل قراءة
        // الـ`id`. و`?->` لوحدها **مش كفاية** — هي بتحمي من
        // `null` مش من كائن مالوش الخاصية.
        $currentToken = $request->user()->currentAccessToken();

        $tokens = $user->tokens();

        if ($currentToken instanceof PersonalAccessToken) {
            $tokens->where('id', '!=', $currentToken->id);
        }

        $tokens->delete();

        // ⭐⭐⭐ **إبطال الجلسات التانية كمان** — مش التوكينات بس.
        //
        // ⭐ نفس صنف الـbug اللي `UserStatusService::forcePasswordReset`
        // اتقفل فيه (PR 1.5): من PR 1.1 الجلسات هي اللي بتعمل
        // الشغل، فإبطال التوكينات **مش كافي**.
        //
        // ═══════════════════════════════════════════════════════════
        // ⭐ ليه «امسح الكل وبعدين `migrate`» مش «استثنى الحالي»
        //
        // ⭐ المحاولة الأولى كانت:
        //
        //     ->where('id', '!=', $request->session()->getId())
        //
        // ⭐ وده ** هششّ**: بيحتاج الـid الحالي يطابق صف الجدول
        // بالظبط. وفي الاختبار طلع إن الـid في الـrequest بيختلف
        // عن الصف — فالصف الصح اتمسح. أي اعتماد على مطابقة الـid
        // هنا = **قفل خارجي** بيقفل المستخدم من جولته.
        //
        // ⭐ البديل — واللي Laravel نفسه بيعمله في
        // `SessionGuard::logoutOtherDevices()`:
        //
        //   ① امسح **كل** الجلسات
        //   ② `migrate()` على الجلسة الحالية → id جديد
        //
        // ⭐ `migrate()` بيحافظ على **attributes** الجلسة (فالمستخدم
        // فضل داخل) وبيغيّر الـid بس. و`StartSession` بيكتب الصف
        // الجديد آخر الطلب. فالنتيجة: جلسة واحدة شغّالة — الحالية.
        //
        // ⭐⭐ وتغيير الـid بعد تغيير كلمة المرور **مطلوب أمنياً**
        // (دفاع session fixation)، مش أثر جانبي: الكوكي الجديد
        // بيروح للمستخدم تلقائياً من `Set-Cookie`.
        $revokedSessions = DB::table('sessions')
            ->where('user_id', $user->id)
            ->delete();

        $request->session()->migrate(true);

        app(\App\Services\AuditLogService::class)->log(
            'password_changed',
            'user',
            (int) $user->id,
            null,
            // ⭐ عدد الجلسات اللي اتقفلت — ده هو **الرقم** اللي
            // بيقول «هل كان حد تاني داخل على الحساب». من غيره
            // السجل بيقول «اتغيّرت كلمة مرور» بس.
            ['sessions_revoked' => $revokedSessions],
            $request,
            (int) $user->id,
        );

        return response()->json([
            // ⭐ `AuthController::logout` بيرجّع `auth_mode`، والواجهة
            // ⭐ `AuthController::logout` بيرجّع `auth_mode`، والواجهة
            // ⭐ بتقراه. نفس الشكل هنا عشان يقدر يقرّر يدوّر
            'message' => 'اتغيّرت كلمة المرور. اتقفلت كل الجلسات التانية.',
            'auth_mode' => $request->user()->currentAccessToken() instanceof PersonalAccessToken
                ? 'token'
                : 'session',
        ]);
    }
}
