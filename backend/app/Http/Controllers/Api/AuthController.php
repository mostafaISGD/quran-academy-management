<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required']);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['بيانات الدخول غير صحيحة.']]);
        }

        if ($user->status !== 'active') {
            throw ValidationException::withMessages([
                'email' => ['الحساب غير نشط — كلّم إدارة الأكاديمية.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        // تسجيل الدخول في سجل النشاط — لازم نمرر الـ user_id صراحةً
        // لأن المستخدم لسه مش متحقّق (audit_logs.user_id NOT NULL)
        app(\App\Services\AuditLogService::class)->log(
            'login', 'user', $user->id, null, null, $request, $user->id,
        );

        return response()->json([
            'token' => $user->createToken('api-token')->plainTextToken,
            'user' => $this->profile($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج']);
    }

    public function me(Request $request)
    {
        return response()->json($this->profile($request->user()));
    }

    /**
     * بيانات المستخدم + مربوطه بالمعلم أو ولي الأمر (لو كان واحد منهم).
     *
     * الـ frontend بيستخدم `teacher` عشان يوجّه المعلّم لصفحة «جدولي»
     * بدل لوحة التحكم — كل معلّم بيتحكم بجدوله بنفسه.
     *
     * و `is_parent` + `parent` نفس الفكرة: ولي الأمر بيشوف أبناؤه بس،
     * مش بيانات الأكاديمية كلها. من غير العمود ده أي حساب جديد
     * هيتعامل معاه على إنه أدمن.
     */
    private function profile(User $user): array
    {
        $user->load(['roles', 'teacher', 'parentProfile']);

        $parentProfile = $user->parentProfile;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'job_title' => $user->job_title,
            'department' => $user->department,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name'),
            'is_teacher' => $user->teacher !== null,
            'teacher' => $user->teacher ? [
                'id' => $user->teacher->id,
                'teacher_code' => $user->teacher->teacher_code,
                'display_name' => $user->teacher->display_name,
                'specialization' => $user->teacher->specialization,
                'avatar_url' => $user->teacher->avatar_url,
            ] : null,
            'is_parent' => (bool) $user->is_parent || $parentProfile !== null,
            'parent' => $parentProfile ? [
                'id' => $parentProfile->id,
                'name' => $parentProfile->name,
                'phone' => $parentProfile->phone,
                'email' => $parentProfile->email,
                'children_count' => $parentProfile->students()->count(),
            ] : null,
        ];
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);
        return response()->json(['message' => 'تم إرسال رابط الاستعادة إذا كان البريد مسجلاً']);
    }

    public function resetPassword(Request $request)
    {
        $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => 'required|min:8|confirmed']);
        return response()->json(['message' => 'تم تغيير كلمة المرور']);
    }
}
