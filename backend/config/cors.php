<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins
    |--------------------------------------------------------------------------
    |
    | ⭐⭐ كانت hardcoded على localhost:3000. ده معناه إن أي نشر
    | حقيقي للـAPI هيرفض الطلبات (أو — أسوأ — لو حد نسخ
    | `localhost` للملف في الإنتاج، الـAPI يبقى مفتوح للكل).
    |
    | ⭐ دلوقتي بتتحمل من `CORS_ALLOWED_ORIGINS` — قائمة مفصولة
    | بفاصلة، وكل origin بيتنضّف (مسافات وت.slash أخير).
    |
    | ⭐ `env()` جوّه الـconfig مسموح — Laravel بيقرا
    | الـconfig مرة واحدة وقت الـboot، فمفيش cache ولا dynamic.
    |
    | مثال في .env:
    |   CORS_ALLOWED_ORIGINS=https://app.example.com,https://admin.example.com
    |
    */

    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://127.0.0.1:3000')),
    ))),

    // ⭐ في الإنتاج: لو حد نسي يضبط الـenv، ديOrigins هتترفض
    // (404 في القائمة الفاضية) — وده **أأمن** من إنها تقبل الكل.
    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,

    // ⭐ لازم `true` عشان الـcookie-based Sanctum (HttpOnly)
    // في PR 1.1 تشتغل — من غيرها المتصفح مش هيبعت الكوكيز.
    'supports_credentials' => true,
];