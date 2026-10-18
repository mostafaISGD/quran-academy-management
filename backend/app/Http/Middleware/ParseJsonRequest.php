<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐⭐⭐ كان **بيسجّل جسم كل طلب** في اللوج — بكلمات المرور نص صريح.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * الكود القديم كان:
 *
 *     Log::info('ParseJsonRequest middleware called', [
 *         'is_json'       => $request->isJson(),
 *         'content_type'  => $request->header('Content-Type'),
 *         'content'       => $request->getContent(),   // ⭐⭐⭐
 *     ]);
 *
 *     …
 *     Log::info('Merged JSON data', ['data' => $data]);   // ⭐⭐⭐
 *
 * يعني **كل** طلب API بينسخ جسمه كامل في
 * `storage/logs/laravel.log`، من غير استثناء. وأجسام الطلبات دي
 * فيها:
 *
 *   • `POST /api/auth/login`           → `password`
 *   • `POST /api/auth/change-password` → `current_password`, `password`
 *   • `POST /api/auth/reset-password`  → `password`
 *
 * ⭐⭐يعني **كلمات مرور كل المستخدمين بالنص الصريح** في ملف على
 * السيرفر. الملف ده بيتبعت لأي حد عنده صلاحية قراءة logs، أو
 * بيتسرّب مع backup، أو بيتبعت بالغلط لخدمة monitoring، أو بيترفع
 * على git لو حد عمل `git add storage/`.
 *
 * ⭐ واللوج كان وصل **٣٤ ميجا** من طلبات الاختبار لوحدها — يعني
 * التكلفة كانت بتتpaid بكل مستخدم وكلمة مروره، مرتين في كل طلب.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه الـmiddleware أصلاً؟
 *
 * ⭐ في Laravel 11، `Request::json()` بيقرا الـbody بس، والـ
 * validation شغالة على `all()`. يعني **مش ضروري** في الـhappy
 * path. بس هو موجود عشان بيغطي حاجة واحدة: الطلبات اللي
 * `Content-Type` بتاعها `application/json` بس الـbody فاضي أو
 * مش مظبوط، أو الـJSON بيرمي.
 *
 * ⭐ فبقيت القيمة: بتقرأ مرة واحدة وبتحاول decode، وبتبلع أي
 * خطأ بدل ما ترميه على الـcaller.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه مش بنحذفه؟
 *
 * ⚠️ هو متسجّل في `bootstrap/app.php` بـ`prependToGroup('api', …)`
 * وشغال في الإنتاج. حذفه تغيير سلوكي محتاج تحقق منفصل — من
 * غير دلائل إن الـvalidation بيعتمد عليه فعلاً.
 *
 * ⭐ بدل كده: نخليه **ساكت**، ونسيب `Log::info` للمطوّر لو حب.
 * ولو حب يفعّله، الـbody بيتنضّف الأول — فالتسجيل يفضل آمن حتى
 * لو اشتغل بالغلط.
 *
 * @see \Tests\Feature\SessionAuthenticationTest::test_request_bodies_are_never_written_to_the_log
 */
class ParseJsonRequest
{
    /**
     * ⭐ الحقول اللي **ما بتتسجّلش أبداً**، مهما كان الـverbosity.
     *
     * ⭐ دي **طبقة تانية** من الحماية، مش الحماية الأساسية. حمايتنا
     * الأساسية إننا مش بنسجّل جسم الطلب أصلاً — بس لو حد رجع
     * أضاف `Log::info('body', $request->all())` بعد كده، القائمة
     * اللي هنا هتمنع التسريب بدل ما تتكرر نفس الحادثة.
     *
     * @var array<int, string>
     */
    private const REDACTED = [
        'password',
        'password_confirmation',
        'current_password',
        'new_password',
        'token',
        'access_token',
        'plainTextToken',
        'two_factor_code',
        'two_factor_recovery_codes',
        'two_factor_secret',
        'secret',
        'api_key',
    ];

    /**
     * ⭐ تشغّل التسجيل — `PARSE_JSON_DEBUG=true` في `.env`.
     *
     * ⭐ `false` افتراضياً. وحيط، لما يبقى `true` الجسم **بينتضّف**
     * الأول — فالتسجيل بيبقى آمن حتى لو اشتغل بالغلط.
     */
    private static function debugEnabled(): bool
    {
        return filter_var(
            env('PARSE_JSON_DEBUG', false),
            FILTER_VALIDATE_BOOLEAN,
        );
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (self::debugEnabled()) {
            Log::debug('ParseJsonRequest', [
                'method' => $request->method(),
                'path' => $request->path(),
                'is_json' => $request->isJson(),
                'content_type' => $request->header('Content-Type'),
                // ⭐ مش الـraw body — الجاهز بعد التنضيف
                'keys' => array_keys($request->all()),
            ]);
        }

        if (! $request->isJson()) {
            return $next($request);
        }

        $raw = $request->getContent();

        if ($raw === '' || $raw === false) {
            return $next($request);
        }

        $data = json_decode($raw, true);

        // ⭐⚠️ **`json_decode` بيرجّع `null` لأ Reasons مختلفة**:
        // JSON مش صالح، أو الجسم هو حرفي `null`. و`null` كمان
        // نتيجة صحيحة لما حد يبعث `null` فعلاً.
        //
        // فبنفرّق بـ`json_last_error()` مش بقيمة `$data`.
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            // ⭐ بنبلع وبنكمّل. القرار «الجسم مش صالح» يبقى للـ
            // validation والـcontroller — الوسيط مش مكانه يرمي
            // استثناء على كل طلب.
            if (self::debugEnabled()) {
                Log::debug('ParseJsonRequest: JSON غير صالح', [
                    'path' => $request->path(),
                    'error' => json_last_error_msg(),
                ]);
            }

            return $next($request);
        }

        // ⭐⚠️ **القيم الحقيقية** بتتمرك — مش المنضّفة.
        //
        // لو mergeّينا المنضّف هنا، `Hash::check` في الـlogin هيقارن
        // `password` بـ `[redacted]` و**كل** تسجيل دخول هيفشل.
        // التنضيف بيحصل للتسجيل بس، وده الفصل اللي بيحمي التشغيل من
        // التسريب من غير ما نكسر حاجة.
        //
        // ⚠️ أول نسخة من التعديل ده غرّت هنا ودمجت المنضّف،
        // فسقط **كل** اختبار بـ422. مثالي للأمثلة اللي بتحب
        // تحذّر منها.
        $request->merge($data);

        if (self::debugEnabled()) {
            Log::debug('ParseJsonRequest: merged', [
                'path' => $request->path(),
                // ⭐ النسخة المنضّفة — دي اللي بتتسجّل
                'data' => self::redact($data),
            ]);
        }

        return $next($request);
    }

    /**
     * ⭐ ينضّف الحقول الحسّاسة — للـdebug **و** للـ`merge`.
     *
     * ⭐ الـ`merge` بيمرّ بالقيم الحقيقية عشان الـvalidation
     * يشتغل صح؛ التنضيف بيحصل **للتسجيل** بس. فالفصل ده مقصود:
     * **اللوج يكون نضيف، والطلب يفضل يشتغل زي ما هو.**
     *
     * ⚠️ مهم: لو نضّفنا قبل الـ`merge`، `Hash::check` والـlogin
     * هيشتغلوا على قيمة مش من الأصل ويكسر كل تسجيل دخول.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACTED, true)) {
                $data[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}