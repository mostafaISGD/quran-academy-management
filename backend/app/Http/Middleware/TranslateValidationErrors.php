<?php

namespace App\Http\Middleware;

use App\Support\ValidationMessages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ترجمة رسائل التحقق (422) للعربي.
 *
 * ⭐ ليه middleware مش كل controller؟
 *
 * الـ validation شغال في **٦٠+ endpoint**. لو كل واحد كان
 * بيرجم بنفسه، كنا هينسى واحد — والرسايل المختلطة (عربي
 * وإنجليزي) أسوأ من إنجليزي كله.
 *
 * الـ middleware بتلمس كل استجابة 422 مرة واحدة. مفيش controller
 * محتاج يعرف حاجة، ولو قفلنا ناقصين بعد كده ما بتفرقش.
 */
class TranslateValidationErrors
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($response->getStatusCode() !== 422) {
            return $response;
        }

        $content = $response->getContent();
        $body = json_decode((string) $content, true);

        if (! is_array($body) || ! isset($body['errors']) || ! is_array($body['errors'])) {
            return $response;
        }

        [$translated, $allKnown] = ValidationMessages::translateAll($body['errors']);

        // ⚠️ لو في أي حقل مجهول، نرجّع الرد زي ما هو بالإنجليزي.
        // الرد المخلوط (بعضها عربي وبعضها إنجليزي) أسوأ من
        // الإنجليزي كله — المستخدم مش هيعرف الرسالة اللي قدامه.
        if (! $allKnown) {
            return $response;
        }

        if (! empty($translated)) {
            $first = reset($translated);
            $body['message'] = is_array($first)
                ? $first[0]
                : 'البيانات المدخلة غير صحيحة.';
        }

        $body['errors'] = $translated;

        $response->setContent((string) json_encode(
            $body,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return $response;
    }
}