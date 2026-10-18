<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST) : ''
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes that an issued token will be
    | considered valid. This will override any values set in the token's
    | "expires_at" column, but first-party sessions are not affected.
    |
    | ⭐⭐⭐ الكان هنا كان `null` — وده كان **أخطر سطر في الملف**.
    |
    | `null` معناها «التوكين صالح للأبد». التوكنات مخزّنة في
    | `localStorage` (شوف `frontend/lib/api.ts`)، فأي حد يفتح
    | جهاز المستخدم — أو يسرق المتصفح — يقدر يدخل **بعد شهور**
    | من يوم إصدار التوكن. وأي غلطة في الجهاز (فايروس، متصفح
    | مشترك، جهاز ضائع) مالهاش تاريخ انتهاء.
    |
    | ⭐ ٧٢٠ دقيقة = ١٢ ساعة. مدة معقولة لمدرس بيشتغل من
    | جهاز واحد: يوم شغل كامل، ولما يرجع المستخدم يسجّل دخول
    | تاني من غير ما يتأثر.
    |
    | ⭐ الـvalue من الـenvironment مش مكتوبة هنا عن قصد: الإعداد
    | يتحكم فيه من `.env` في كل بيئة من غير كود. والقراءة من
    | `settings` جاية في PR 3.12 مع `SettingsRegistry` (هو لسه
    | مش موجود دلوقتي).
    |
    */

    'expiration' => (int) env('SANCTUM_TOKEN_EXPIRATION', 720),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they are building repositories with tokens
    | that are stored in plain text.
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        // ⭐⭐ بيشاور على كلاسين مش موجودين في Laravel 11.
        //
        // Laravel 11 نقلهم من `App\Http\Middleware` للـframework:
        //
        //   ValidateCsrfToken  →  Illuminate\Foundation\Http\Middleware\ValidateCsrfToken
        //   EncryptCookies     →  Illuminate\Cookie\Middleware\EncryptCookies
        //
        // ⭐ لاحظ: `EncryptCookies` نقل لـ`Cookie\Middleware` مش
        // `Foundation\Http\Middleware`. و`VerifyCsrfToken` لسه
        // موجود في الـframework بس هو **deprecated** — الاسم الصح
        // في Laravel 11 هو `ValidateCsrfToken`.
        //
        // ⭐ الـclass القديمة كانت موجودة في Laravel 10 ومايلهاش
        // بديل → لو Sanctum-stateful (cookie auth) اتفعّل، ده كان
        // هيدي `Class not found` وقت أول request.
        //
        // @see \Tests\Feature\ErrorHygieneTest
        'verify_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
    ],

];
