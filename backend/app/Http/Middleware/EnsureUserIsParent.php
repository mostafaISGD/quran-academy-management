<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * يأكد إن الحساب ده ولي أمر.
 *
 * الغرض: صفحات أولياء الأمر ترجع بيانات الأبناء. من غير ده أي حساب
 * (معلم أو أدمن) يقدر يفتح الـ endpoint ويشوف أي طالب في الأكاديمية.
 */
class EnsureUserIsParent
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'محتاج تسجيل دخول'], 401);
        }

        if (!$user->is_parent) {
            return response()->json([
                'message' => 'الصفحة دي لولياء الأمور بس',
            ], 403);
        }

        return $next($request);
    }
}
