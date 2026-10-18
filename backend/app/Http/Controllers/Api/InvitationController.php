<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Rules\StrongPassword;
use App\Services\InvitationService;
use Illuminate\Http\Request;

/**
 * ⭐⭐⭐ الدعوات — إنشاء، قبول، إعادة إرسال، إلغاء.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ مين يقدر يدعي
 *
 * `users.invite` + قاعدة `RoleGrantService::assertCanAssignRole`:
 * **كل** صلاحية في الدور اللي بتسلّمه لازم تكون عندك أنت.
 *
 * ⭐ فمدير التوظيف (صلاحية `invite` بس) يقدر يدعي **معلم**، بس
 * مش **مدير** — لأن `users.roles.assign` مش عنده.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ التوكن في الرد — وهل ده تسريب؟
 *
 * ⭐ الرد بيرجع التوكن الخام **مرة واحدة**، للمدير اللي عمل
 * الدعوة (وهو أصلاً عنده `users.invite`).
 *
 * ⭐ **مش** تسريب لأن:
 *   • المدير ده **موثوق** — هو اللي قرّر يعمل الحساب
 *   • «تسليم الرابط» سلوك مقصود: شغل بارد بيبعت الدعوة على
 *     الواتساب
 *   • التوكن **مش بيتخزّن** بعد كده، فمافيش حاجة تبقى محفوظة
 *
 * ⭐⚠️ **ليه بنرجّعه أصلاً؟** عشان الواجهة تعرض «انسخ الرابط» —
 * ودي ميزة حقيقية للمدير اللي مش فاضي يبعت إيميل. لو رجعنا
 * «تم الإرسال بس»، المدير بيبعت رسالة **مكتوب فيها** الدعوة،
 * وده بيكسر الميزة.
 *
 * @see \App\Services\InvitationService
 * @see \Tests\Feature\AccountInvitationTest
 */
class InvitationController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    // ═══════════════════════════════════════════════════════════════
    // ① إنشاء
    // ═══════════════════════════════════════════════════════════════

    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'profile_type' => ['nullable', 'string', 'max:32'],
            'profile_id' => ['nullable', 'integer'],
        ]);

        $plain = $this->invitations->create($data, $request->user());

        return response()->json([
            'message' => 'بعتنا الدعوة على الإيميل.',
            // ⭐ التوكن الخام — **مرة واحدة**. مافيش طريقة تسترجعه.
            'token' => $plain,
            'accept_url' => $this->acceptUrl($plain),
            'expires_at' => now()->addHours(72)->toIso8601String(),
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════════
    // ② القبول — عام ومحدود بـ`throttle`
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ قبول الدعوة.
     *
     * ⭐ **مافيش `auth:sanctum`** — اللي بيقبّل لسه مالوش حساب.
     * ده السبب الوحيد إن الـراوت عام.
     *
     * ⭐ والـ`throttle` في الـroute: سطح **عام** بيقرأ من
     * توكنات = يفضّل يجرّب مليون واحد.
     *
     * ⭐ **410 مش 422** للتوكن الميت — شوف
     * `InvitationGoneException` للسبب.
     */
    public function accept(Request $request)
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'password' => [
                'required', 'string', 'confirmed',
                // ⭐⭐ **`StrongPassword` مش `uncompromised()`** — عن
                // قصد، وده قرار PR 0.4.
                //
                // ⭐ `Password::uncompromised()` بيعمل **طلب شبكة لـ
                // HaveIBeenPwned** على **كل** عملية تحقق. يعني:
                //   • الـAPI معتمد على خدمة خارجية — لو
                //     `api.pwnedpasswords.com` واقع، **مفيش حد
                //     يقدر يعمل حساب**.
                //   • والـrate limit بتاعنا بيقيس بالـIP بس، فحد
                //     من شبكات مشبّكة بيبعت آلاف الطلبات لـHIBP في
                //     الدقيقة.
                //   • Privacy: كلمة المرور بتروح لطرف تالت.
                //
                // ⭐ `StrongPassword` = ١٢ حرف + مش في القائمة
                // الشائعة + مش جزء من الإيميل. **أونلاين ومش قابل
                // للعطل**.
                new StrongPassword(12, (string) $request->string('email')),
            ],
        ]);

        // ⭐ `InvitationGoneException` بيرمي `render` بـ410.
        // شوف `ErrorResponder::SAFE_EXCEPTIONS`.
        $user = $this->invitations->accept($data['token'], $data['password']);

        return response()->json([
            'message' => 'الحساب اتفعّل. سجّل دخولك بالكلمة الجديدة.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->roles->pluck('name'),
            ],
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ إعادة الإرسال والإلغاء
    // ═══════════════════════════════════════════════════════════════

    public function resend(Request $request, int $invitation)
    {
        $plain = $this->invitations->resend(
            Invitation::findOrFail($invitation),
            $request->user(),
        );

        return response()->json([
            'message' => 'بعتنا رابط جديد على الإيميل.',
            'token' => $plain,
            'accept_url' => $this->acceptUrl($plain),
        ]);
    }

    public function destroy(Request $request, int $invitation)
    {
        $this->invitations->cancel(
            Invitation::findOrFail($invitation),
            $request->user(),
        );

        return response()->json(['message' => 'اتلغت الدعوة.']);
    }

    private function acceptUrl(string $plain): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/')
            .'/accept-invitation?token='.urlencode($plain);
    }
}