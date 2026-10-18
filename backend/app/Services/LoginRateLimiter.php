<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * ⭐⭐⭐ تخمين كلمة المرور — يبقى مكلف.
 *
 * ═══════════════════════════════════════════════════════════════
 * الثغرة
 *
 * `POST /auth/login` كان مفتوح بالكامل — مافيش `RateLimiter`
 * خالص. حد يجرّب مليون كلمة مرور على حساب معروف، والسيرفر
 * بيجاوب `422` كل مرة. فالتخمين **مجاني** وبلا حدود.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه **مفتاحين** مش واحد
 *
 * ① **حسب الإيميل** (`login:email:{email}`) — بيمنع تخمين
 *    كلمة مرور **حساب معروف** من أي IP. ده الهجوم الأساسي.
 *
 * ② **حسب الـIP** (`login:ip:{ip}`) — بيمنع **spray**: مهاجم
 *    يجرّب كلمة واحدة على **آلاف الإيميلات** (نوع واحد). من غير
 *    حد الـIP، ده بيمرّ لأن كل إيميل عدّاده لوحده.
 *
 * ⭐ لازم **الاتنين** مع بعض. حد على الـIP بس بيقفل بيت كامل
 * ورا NAT مع أول ٢٠ محاولة؛ وحد بالإيميل بس مالهوش سقف على
 * عدد الحسابات اللي بيخبطها في الدقيقة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ التصفير بعد النجاح
 *
 * من غير كده، مستخدم بيغلط ٤ مرات يتنضف عدّاده، يدخل بنجاح،
 * يغلط ٥ تاني، ويقفل — رغم إنه اتأكد من نفسه. المحاولة
 * الناجحة دليل إن-password اللي في إيده صح، فتبقى لازم
 * تتفتح له الطريق.
 *
 * @see \Tests\Feature\LoginHardeningTest
 */
class LoginRateLimiter
{
    /** ⭐ ٥ محاولات / دقيقة / إيميل — مقسومة من الخارطة */
    public const MAX_PER_EMAIL = 5;

    /** ⭐ ٢٠ / دقيقة / IP — سقف أعمق لهجمات الـspray */
    public const MAX_PER_IP = 20;

    public const WINDOW_SECONDS = 60;

    /** @return array{0: string, 1: string} المفتاح [email, ip] */
    private function emailKey(string $email): string
    {
        return 'login:email:'.mb_strtolower(trim($email));
    }

    private function ipKey(string $ip): string
    {
        return 'login:ip:'.$ip;
    }

    /**
     * ⭐ الـhandler اللي بيرجّع 429 بالعربي.
     *
     * ⭐ `ThrottleRequests` بيرجّع JSON إنجليزي. والـAPI كله
     * عربي، فبنتعامل معاه بأنفسنا عشان الرسالة تكون مفهومة
     * للمستخدم الحقيقي اللي بيغلط في كلمة مروره ٥ مرات.
     */
    public function tooManyAttemptsResponse(int $seconds): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => "محاولات كتير. استنى {$seconds} ثانية وحاول تاني.",
            'retry_after' => $seconds,
        ], 429);
    }

    /**
     * ⭐ بنالتقط الـ429 بدل ما `throttle:` middleware يعملها.
     *
     * ⭐ ليه بنعملها يدوي مش middleware؟
     *
     * ① عشان **نعمل custom message** بالعربي.
     * ② عشان **نعرف مين** اتقفل (إيميل ولا IP) ونقول للمستخدم
     *    بشكل مفيد.
     * ③ عشان **نصفّر العدّاد** بنجاح — الـmiddleware بيرجّع
     *    response قبل ما الـcontroller يشتغل، فمفيش مكان
     *    نعمل منه التصفير.
     *
     * @return \Illuminate\Http\JsonResponse|null رد 429 أو null
     */
    public function enforce(Request $request, string $email): ?\Illuminate\Http\JsonResponse
    {
        $ip = $request->ip();
        $limiter = app(\Illuminate\Cache\RateLimiter::class);

        // ⭐ الإيميل الأول — وده الهجوم الأساسي
        $emailKey = $this->emailKey($email);

        if ($limiter->tooManyAttempts($emailKey, self::MAX_PER_EMAIL)) {
            return $this->tooManyAttemptsResponse(
                $limiter->availableIn($emailKey)
            );
        }

        // ⭐ بعدين الـIP — بيغطي الـspray
        $ipKey = $this->ipKey($ip);

        if ($limiter->tooManyAttempts($ipKey, self::MAX_PER_IP)) {
            return $this->tooManyAttemptsResponse(
                $limiter->availableIn($ipKey)
            );
        }

        return null;
    }

    /** ⭐ سجّل محاولة — سواء نجحت أو فشلت */
    public function hit(Request $request, string $email): void
    {
        $limiter = app(\Illuminate\Cache\RateLimiter::class);

        $limiter->hit($this->emailKey($email), self::WINDOW_SECONDS);
        $limiter->hit($this->ipKey($request->ip()), self::WINDOW_SECONDS);
    }

    /**
     * ⭐ تصفير العدّاد — **بعد** نجاح فقط.
     *
     * ⭐ بنصفّر الإيميل والـIP مع بعض. لو نجح بـ IP معيّن
     * هنفسه وبإيميل معيّن، فالمحاولات السابقة على الإيميل ده
     * من IPs تانية كانت **محاولات على نفس الحساب** فلازم
     * تروح كمان.
     */
    public function clear(Request $request, string $email): void
    {
        $limiter = app(\Illuminate\Cache\RateLimiter::class);

        $limiter->clear($this->emailKey($email));
        $limiter->clear($this->ipKey($request->ip()));
    }

    /**
     * ⭐ تسجيل المحاولة الفاشلة — **من غير كلمة المرور**.
     *
     * ⭐⚠️ كلمة المرور **ما تتسجلش أبداً** — لو اتسجلت، أي حد
     * يقرا السجل يقدر يسجّل دخول بالحسابات.
     *
     * ⭐ بنسجّل: الإيميل، الـIP، الوقت، ونوع الفشل. كل اللي
     *كشف هجوم.
     */
    public function logFailure(Request $request, string $email, string $reason): void
    {
        // ⭐ `entity_id` برضه `0` — وده **صح**: السجل بيتوثّق على
        // مستوى الـentity type، و«المستخدم اللي مالوش id» مش
        // معروف. العمود ده `unsignedBigInteger` **من غير FK**.
        app(AuditLogService::class)->log(
            'login_failed',
            'user',
            0,
            null,
            [
                // ⭐⭐ الإيميل هنا هو **الشيء الوحيد** اللي بيقول
                // مين اللي حاول. من غيره السطر بيقول «حد حاول
                // يدخل» وده مالوش قيمة في تحليل هجوم.
                'email' => mb_strtolower(trim($email)),
                'ip' => $request->ip(),
                'reason' => $reason,
                // ⭐⚠️ مافيش `password` — عن قصد ودايماً.
            ],
            $request,
            // ⭐⭐⭐ `null` مش `0` — وده **أهم سطر في الدالة**.
            //
            // ⭐ النسخة الأولى كانت `$this->userIdFor($email) ?: 0`،
            // والعمود كان `NOT NULL` + FK على `users`. فمحاولة
            // الدخول لإيميل **مش موجود** كانت بتعمل:
            //
            //     SQLSTATE[23000]: FOREIGN KEY constraint failed
            //
            // ⭐⭐⭐ والنتيجة كانت مزدوجة — الأسوأ فيهم مش الأول:
            //
            //   ① **500 بدل 422** — أي إيميل عشوائي في صفحة
            //      الدخول كان بيرمي خطأ سيرفر.
            //
            //   ② ⭐⭐ **والحدث ما كانش بيتسجّل خالص.** يعني
            //      إيميلات بتتخمّن (أدق attacks، password reuse)
            //      كانت **بتختفي من `audit_logs`** — أحسن حالة
            //      اختراب في النظام، والـmonitoring مش شايفها.
            //
            // ⭐ `0` كان اختيار صامت عشان `NOT NULL`، وهو قيمة
            // **مزيّفة** برضه: بتقول «في مستخدم رقم 0».
            // `NULL` بتقول بدقة **«الفاعل مش معروف»**.
            //
            // ⭐ والعمود بقى `nullable` في migration
            // `2026_10_15_000003_make_audit_logs_user_id_nullable`.
            //
            // @see \Tests\Feature\LoginHardeningTest::test_a_login_attempt_for_an_unknown_email_is_422_not_500
            $this->userIdFor($email),
        );
    }

    private function userIdFor(string $email): ?int
    {
        // ⭐ بندور على الـid عشان نربط السجل بالحساب لو موجود.
        // مافيش Eloquent — بنستخدم query خام خفيف.
        return \Illuminate\Support\Facades\DB::table('users')
            ->where('email', mb_strtolower(trim($email)))
            ->value('id');
    }
}
