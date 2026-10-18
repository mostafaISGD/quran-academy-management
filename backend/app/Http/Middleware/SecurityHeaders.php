<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐⭐ هيدرز أمان أساسية على كل رد.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه دي مهمة
 *
 * كل واحدة فيهم بتسدّ باب هجوم محدد، ومفيش منها بتأثر على
 * الـAPI العادي:
 *
 * ① `X-Content-Type-Options: nosniff`
 *    ⭐ من غيرها المتصفح بيخمّن نوع الملف من المحتوى بدل
 *    الـ`Content-Type`. يعني حد يرفع `avatar.php` فيه JavaScript،
 *    والمتصفح ينفّذه كـJS. الخطر ده اسمه
 *    **MIME sniffing**.
 *
 * ② `X-Frame-Options: DENY`
 *    ⭐ يمنع أي موقع تاني يحطّ صفحاتنا في `<iframe>` — ده
 *    **clickjacking**: الـمهاجم يحطّ زر شفاف فوق زر «حوّل
 *    فلوس» ويخلي المستخدم يضغطه وهو فاكر إنه ضاغط حاجة تانية.
 *
 * ③ `Referrer-Policy: strict-origin-when-cross-origin`
 *    ⭐ من غيرها الـ`Referer` header بيكمّل **الرابط كامل**
 *    — يعني لو المستخدم كان على
 *    `/students/123?national_id=123456789` وحمّل ملف من CDN،
 *    الـCDN بيعرف الـURL ده. لواحنا بنطلب API، الـpath بيوصل
 *    كمان.
 *
 * ④ `Permissions-Policy`
 *    ⭐ بيقفل الـbrowser APIs اللي إحنا مش بنستخدمها (كاميرا،
 *    microphone، geolocation). بيقلّص سطح الهجوم لو اتحقّن
 *    سكربت في أي وقت في المستقبل.
 *
 * ⑤ `Strict-Transport-Security` — **في الإنتاج بس**
 *    ⭐ بيقول للمتصفح «من دلوقتي كلامنا مع الدومين ده لازم
 *    يكون HTTPS». مهم جدًا ضد network sniffing.
 *
 *    ⚠️ **ليه مش في التطوير:** الـHSTS بيتخزّن في المتصفح
 *    لسنين. لو اتبعث على `localhost`، أي خطأ في إعداد HTTPS
 *    المحلي بيقفل المتصفح على الـdev server. فبنقفله جوه
 *    `app()->isLocal()` / بيئة التطوير.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه middleware عام مش per-route
 *
 * ⭐ الهيدرز دي لازم تكون على **كل** رد. لو حطيناها على
 * route معيّن، أول ما نسي route بنسى حماية. الـmiddleware
 * العام بيضمن بالبناء.
 *
 * @see \Tests\Feature\ErrorHygieneTest
 */
class SecurityHeaders
{
    /**
     * ⭐ الهيدرز الثابتة — على كل رد، في كل بيئة.
     *
     * @var array<string, string>
     */
    private const ALWAYS = [
        // ⭐ كل رد JSON — نمنع المتصفح إنه يخمّن نوع تاني
        'X-Content-Type-Options' => 'nosniff',

        // ⭐ clickjacking
        'X-Frame-Options' => 'DENY',

        // ⭐ تقليل الـreferrer
        'Referrer-Policy' => 'strict-origin-when-cross-origin',

        // ⭐ قفل الـbrowser APIs اللي مش بنستخدمها
        'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::ALWAYS as $header => $value) {
            // ⭐ `headers->set` مش `add` — لو الـresponse ات فيه
            // فيه header بنفس الاسم، `add` هيولّد قيمتين
            // والمتصفح بياخد الأول (اللي إحنا مش عارفينه).
            $response->headers->set($header, $value);
        }

        if ($this->shouldSendHsts($request)) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains',
            );
        }

        return $response;
    }

    /**
     * ⭐ HSTSProduction بس — والشرح في الـdocblock فوق.
     */
    private function shouldSendHsts(Request $request): bool
    {
        if (! app()->isProduction()) {
            return false;
        }

        // ⭐ الـHSTS معناه HTTP مش هينفع خالص، فلو الطلب جاي
        // على HTTP في «إنتاج» يبقى في غلط إعداد (terminating
        // proxy ناقصة مثلاً). نبعت Header على HTTPS بس — خا
        // نكسر الـhealth checks اللي شغالة على HTTP.
        return $request->isSecure();
    }
}