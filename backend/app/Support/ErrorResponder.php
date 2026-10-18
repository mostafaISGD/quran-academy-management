<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * ⭐⭐⭐ رد الأخطاء الموحّد — **مافيش تسريب داخلي**.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * فيه **19** استثناء بيرجّع `$e->getMessage()` للعميل عبر ٥ ملفات
 * (`StudentPhoneController` ١١، `FinancialAccountController` ٣،
 * `ParentController` ٢، `AuthController` ١، `FinancialLedgerService` ١).
 *
 * ⭐ ورا كل رسالة دي: اسم class، أسماء جداول وأعمدة، مسار ملف،
 * رقم سطر. ده بيدّي مهاجم **خريطة الداتابيز مجاناً** — وهو أول
 * خطوة في أي هجوم.
 *
 * ═══════════════════════════════════════════════════════════════
 * القاعدة
 *
 * **التفاصيل بتتسجل، مش بترجع.**
 *
 * ⭐ الـ logs هي المكان الصح للتفاصيل: عندها سياق كامل
 * (stack trace، IP، user). والعميل مالوش أي حاجة يعمل بيها كده.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه الـ 4xx بتترجم والـ 5xx مخفية
 *
 * ٤٠٠-٤٩٩ = غلط **المستخدم** (مش مصنّف، ما فيش صلاحية، payload
 * غلط). الرسالة بتاعته مفيدة ليه وبتتحقق من condición حقيقية —
 * فبنحتفظ بيها.
 *
 * ٥٠٠+ = غلط **إحنا**. الرسالة مش مفيدة ليه (هو مش هو اللي
 * كسرها)، وبتكدّبه. فنستبدلها.
 *
 * ⭐ ده مش معناه إن أي 4xx يتسرّب — لو الـ 4xx نفسها بتبان
 * exception داخلي، هنستبدلها برضو.
 *
 * @see \Tests\Feature\ErrorHygieneTest
 */
class ErrorResponder
{
    /**
     * ⭐ الـ exceptions اللي بنعتبرها «كلام المستخدم» — مش أخطاء داخلية.
     *
     * ⭐ أي حاجة برّه من دول = خطأ في الكود، والرسالة بتاعته
     * بتروح للـlog مش للمستخدم.
     *
     * @var array<int, class-string>
     */
    private const SAFE_EXCEPTIONS = [
        \Illuminate\Validation\ValidationException::class,
        \Illuminate\Auth\AuthenticationException::class,
        \Illuminate\Auth\Access\AuthorizationException::class,
        \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface::class,
        \Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class,
        \Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException::class,
        \Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException::class,
        \Illuminate\Http\Exceptions\ThrottleRequestsException::class,
        \Spatie\Permission\Exceptions\PermissionDoesNotExist::class,
        // ⭐ خطأ **دعوة** — رسالة عربية مقصودة للمستخدم، والـ410
        // بيقوله «التوكن راح، متحاولش تاني».
        \App\Exceptions\InvitationGoneException::class,
    ];

    /**
     * ⭐ بنترجم حالة HTTP لرسالة عربية **محايدة**.
     *
     * ⭐ مفيش تفصيل تقني: ولا اسم class ولا SQL ولا مسار.
     *
     * @var array<int, string>
     */
    private const MESSAGES = [
        400 => 'الطلب مش صالح.',
        401 => 'لازم تسجّل دخول الأول.',
        403 => 'مش مسموح لك بالحاجة دي.',
        404 => 'الصفحة أو البيانات المطلوبة مش موجودة.',
        405 => 'الطريقة دي مش مسموحة.',
        409 => 'فيه تعارض في البيانات.',
        413 => 'الملف المرسل كبير أوي.',
        422 => 'البيانات المدخلة مش صحيحة.',
        429 => 'محاولات كتير. استنى شوية وحاول تاني.',
        500 => 'حصل خطأ في السيرفر.التفاصيل في اللوج بتاعنا.',
        503 => 'الخدمة مش متاحة مؤقتاً. جرب تاني بعد شوية.',
    ];

    /**
     * ⭐ الرد النهائي — بيتستخدم من `bootstrap/app.php`.
     */
    public static function respond(\Throwable $e, Request $request): ?JsonResponse
    {
        // ⭐ الـAPI كله JSON — والواجهة بتقرأ `message`.
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null; // ⭐ سيب Laravel يرد بطريقته (صفحة HTML)
        }

        $status = self::statusFor($e);

        // ⭐ التفاصيل بتتسجل **دايماً** — حتى لو الرد مخفي
        \Illuminate\Support\Facades\Log::error(
            'API error: '.$e->getMessage(),
            [
                'exception' => $e::class,
                'status' => $status,
                'path' => $request->path(),
                'method' => $request->method(),
                'user_id' => $request->user()?->id,
                'ip' => $request->ip(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            ],
        );

        $payload = [
            'message' => self::messageFor($e, $status),
        ];

        // ⭐ أخطاء الـvalidation: نرجّع تفاصيل الحقول — دي
        // مفيدة للمستخدم ومقصودة.
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            $payload['errors'] = $e->errors();
        }

        return response()->json($payload, $status);
    }

    /**
     * ⭐ حالة HTTP المناسبة.
     */
    private static function statusFor(\Throwable $e): int
    {
        // ⭐ دعوة راحت — الـ410 بيقول للمستخدم «متحاولش تاني»
        if ($e instanceof \App\Exceptions\InvitationGoneException) {
            return $e->status();
        }

        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return 422;
        }

        if ($e instanceof \Illuminate\Auth\AuthenticationException) {
            return 401;
        }

        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            return 403;
        }

        if ($e instanceof \Illuminate\Http\Exceptions\ThrottleRequestsException) {
            return 429;
        }

        if ($e instanceof HttpExceptionInterface) {
            return $e->getStatusCode();
        }

        return 500;
    }

    /**
     * ⭐⭐ **الرسالة اللي بترجع للمستخدم.**
     *
     * ⭐ الاستثناءات المسموحة (4xx) بترجّع رسالتها الأصلية،
     * لأنها كلام المستخدم ومش تقنية. أي حاجة تانية — كل **5xx**
     * و**أي 4xx مش معروف** — بترجّع رسالة عامة.
     */
    private static function messageFor(\Throwable $e, int $status): string
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) {
            return $e->getMessage();
        }

        if (self::isSafeToShow($e)) {
            return $e->getMessage();
        }

        return self::MESSAGES[$status] ?? 'حصل خطأ غير متوقع.';
    }

    /**
     * ⭐ هل الرسالة دي بتتفضّح للمستخدم؟
     */
    public static function isSafeToShow(\Throwable $e): bool
    {
        foreach (self::SAFE_EXCEPTIONS as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return false;
    }
}
