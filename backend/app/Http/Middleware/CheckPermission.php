<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * فحص الصلاحيات.
 *
 * ⚠️ ليش الـ try/catch ده مهم؟
 *
 * `hasPermissionTo()` بترمي `PermissionDoesNotExist` لو الصلاحية
 * المطلوبة **مش موجودة في جدول permissions**. من غير الـ catch ده،
 * أي مسار عليه صلاحية ناقصة بيرجّع **500** مش 403.
 *
 * يعني: لو حد أضاف route جديد بـ صلاحية جديدة ونسي يضيفها لـ
 * `SeedRolesPermissions::PERMISSIONS`، كل المستخدمين هيشوفوا
 * Internal Server Error بدل «غير مصرح» — والخطأ في اللوج هيتكلم
 * عن permission مفقودة مش عن ت性问题 في الصلاحيات.
 *
 * السلوك الصح: **صلاحية مش معرّفة = ممنوع**. 403 + تسجيل تحذير
 * عشان المطوّر يلاحظ إن عليه يعمل migration/seeder.
 */
class CheckPermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        try {
            $allowed = $user->hasPermissionTo($permission);
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            // ٥٠٠ هنا معناه خطأ في الإعداد مش في المستخدم
            Log::warning('Permission missing from database', [
                'permission' => $permission,
                'route' => $request->path(),
                'hint' => 'أضفها لـ DatabaseSeeder::PERMISSIONS و SeedRolesPermissions::PERMISSIONS وبعدين اعمل seed',
            ]);

            return response()->json(['message' => 'غير مصرح'], 403);
        }

        if (! $allowed) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        return $next($request);
    }
}