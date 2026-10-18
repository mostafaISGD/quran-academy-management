<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * أساس كل اختبارات الـ API.
 *
 * `RefreshDatabase` مع sqlite في الذاكرة: بيعمل الـ migrations **مرة
 * واحدة في العملية**، وبيلف كل اختبار في transaction بيتعمل rollback
 * بعده. ده معناه:
 *
 *  - كل اختبار بيلاقي الداتابيز نضيفة (مفيش bleed بين الاختبارات)
 *  - الـ ٥٧ migration مش بتتحمل في كل اختبار
 *  - **مفيش أي خطر على بيانات التطوير** — القاعدة في الذاكرة أصلاً
 */
abstract class TestCase extends BaseTestCase
{
    use BuildsFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // لازم يبقى قبل أي fixture عشان الـ FKs
        $this->bootFixtures();
    }

    /**
     * تسجيل دخول كـ API user وإرجاع الـ headers.
     *
     * الـ API كله محمي بـ Sanctum، فكل اختبار Feature محتاج ده.
     */
    protected function authHeaders($user): array
    {
        // ⭐⭐ **ننسى الـ guard قبل كل تسجيل دخول.**
        //
        // السبب: اختبارات Laravel بتعيش في **عملية واحدة**، فـ
        // `auth` service provider بيسمّي guard واحد في الـ container
        // وبيشتغل عبر كل الطلبات. أول ما حد يعمل Sanctum login، الـ
        // guard بيحفظ المستخدم ده جواه (`$this->user`).
        //
        // فلو اختبار طلب بحسابين مختلفين — الأول بـ reception مثلاً
        // والتاني بـ admin — الطلب التاني بيلاقي المستخدم **الأول**
        // لسه متخزّن. النتيجة: **403 غلط** على حساب ليه الصلاحية.
        //
        // ده كان بيخلي اختبار الجرس يفشل وجواه reason مفهومة:
        // «السيرفر رجّع 403 لحساب admin عنده `groups.manage`».
        // و`hasPermissionTo()` على الموديل بيقول `true` — لأنه فعلاً
        // صحيح. الغلط كان في الـ guard بس.
        //
        // ⚠️ ده **مش** سلوك خاطئ في الكود — في السيرفر الحقيقي كل
        // طلب عملية منفصلة والـ guard جديد. ده تفصيلة الاختبار بس.
        app('auth')->forgetGuards();

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
        $token = $login->json('token');

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    /**
     * ⭐ تصفير عدّادات الـ throttle.
     *
     * ⚠️ `RateLimiter::flush()` **مش موجودة** في Laravel 11 —
     * أول محاولة طلعت `Call to undefined method`. فبنمسح الـcache
     * اللي الـlimiter بيخزّن فيه.
     *
     * ⭐ لازم قبل كل اختبار بيتعامل مع throttle: `.env.testing`
     * فيه `CACHE_STORE=array`، فالعدّاد بيبقى في الذاكرة وبتشاركه
     * كل الاختبارات في العملية.
     */
    protected function resetRateLimiter(): void
    {
        cache()->clear();
    }

    /**
 * ⚡⭐ ننسى الـguard **بعد** `authHeaders()` مش قبلها.
 *
 * ⭐ السبب بالترتيب: `authHeaders()` بيعمل طلب `/auth/login` جوه
 * نفس الاختبار، وفي الفترة دي ترويسة `Authorization` القديمة
 * (من الطلب السابق) لسه في الترويسات، فالطلب بيـ**يتحقّق بالحساب
 * القديم** والـguard بيخزّنه. والطلب المقصود اللي بعده بياخد
 * نفس الـguard المخزّن، فيرجع **المستخدم الغلط** بصمت.
 *
 * ⭐ فالنظام الصح: login يجيب التوكن الجديد، وبعدين نمسح الـguard
 * عشان الطلب المقصود يحلّ التوكن **الجديد** من الصفر.
 */
protected function getJsonAs(string $url, $user): \Illuminate\Testing\TestResponse
    {
        $headers = $this->authHeaders($user);

        app('auth')->forgetGuards();

        return $this->withHeaders($headers)->getJson($url);
    }

    protected function postJsonAs(string $url, array $payload, $user): \Illuminate\Testing\TestResponse
    {
        $headers = $this->authHeaders($user);

        app('auth')->forgetGuards();

        return $this->withHeaders($headers)->postJson($url, $payload);
    }

    protected function putJsonAs(string $url, array $payload, $user): \Illuminate\Testing\TestResponse
    {
        $headers = $this->authHeaders($user);

        app('auth')->forgetGuards();

        return $this->withHeaders($headers)->putJson($url, $payload);
    }

    protected function deleteJsonAs(string $url, $user): \Illuminate\Testing\TestResponse
    {
        $headers = $this->authHeaders($user);

        app('auth')->forgetGuards();

        return $this->withHeaders($headers)->deleteJson($url);
    }
}