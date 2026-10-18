<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐⭐ يوقّف الحساب اللي لازم يغيّر كلمة المرور.
 *
 * ═══════════════════════════════════════════════════════════════
 * السبب
 *
 * الحساب الجديد بيتعمله كلمة مرور عشوائية **مولّدة على السيرفر**.
 * محدش بيقراها ومحدش بيعرفها — وده المقصود، عشان مفيش كلمة مرور
 * افتراضية معروفة في الداتابيز.
 *
 * ⭐ بس الحساب لازم يبقى **حيّ**: الموظف محتاج يفتح الإيميل
 * يغيّر كلمة المرور. فلو سكرنا كل حاجة، الحساب ما ينفعش يُستخدم
 * ولا يُتخلّص منه — فيبقى بلا فايدة.
 *
 * ═══════════════════════════════════════════════════════════════
 * اللي بيتسمح
 *
 *   • `POST /auth/change-password` — **هو** الغرض من الفلتر
 *   • `GET  /api/auth/me`          — لازم يعرف مين هو
 *   • `POST /api/auth/logout`      — لازم يقدر يخرج
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه 403 مش redirect
 *
 * ده **API** مش صفحة. الراوت اللي بيرجّع 403 هو اللي الواجهة
 * بتحوّل لـ redirect لصفحة تغيير كلمة المرور — فمفيش طبقة
 * * بواسطة `EnsurePasswordChanged` نفسه.
 *
 * ⭐ وحقل `must_change_password` في رد `/auth/me` هو اللي بيقول
 * للواجهة «روح صفحة التغيير». الـmiddleware لوحده مش كافي.
 *
 * @see \Tests\Feature\PasswordPolicyTest
 */
class EnsurePasswordChanged
{
    /**
     * ⭐ المسارات اللي بتتفتح لـ `must_change_password = true`.
     *
     * ⚠️ المقارنة بـ `is()` مش `startsWith()` عن قصد: لو
     * استخدمنا `startsWith` كان `auth/me` هيفتح `auth/meanwhile`
     * كمان — ونفس الحاجة لـ `auth/logout-all`.
     *
     * @var array<int, string>
     */
    private const ALLOWED = [
        'api/auth/change-password',
        'api/auth/me',
        'api/auth/logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // ⭐⭐ مافيش user = الراوت **عام** — مش من شأننا.
        //
        // ⚠️ أول نسخة كانت بترجّع 403 هنا، وده كان بيكسر
        // `/auth/login` بالكامل: الفلتر متسجّل على مجموعة `api`
        // كلها، و`login` مش عليه `auth:sanctum`، فكان
        // `$request->user()` null والرد 403 على تسجيل الدخول.
        //
        // ⭐ لو الراوت محمي و`Authenticate` فشل، هو اللي بيرجّع
        // **401** — مش إحنا. فلو وصلنا هنا بـ null، الراوت عام
        // بالتصميم.
        if (! $user) {
            return $next($request);
        }

        // ⭐ صلاحية ناقصة = مش مجبور — زي `CheckPermission`.
        // العمود موجود في المخطط بس لو الـmigration اتنفذت.
        $mustChange = (bool) ($user->must_change_password ?? false);

        if (! $mustChange) {
            return $next($request);
        }

        foreach (self::ALLOWED as $allowed) {
            if ($request->is($allowed)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'لازم تغيّر كلمة المرور الأول',
            'must_change_password' => true,
            'redirect_to' => '/change-password',
        ], 403);
    }
}
