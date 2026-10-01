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

        $user->update(['last_login_at' => now()]);

        return response()->json([
            'token' => $user->createToken('api-token')->plainTextToken,
            'user' => $user->load('roles'),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'تم تسجيل الخروج']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load(['roles']));
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
