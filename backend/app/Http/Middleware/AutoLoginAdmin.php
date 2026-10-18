<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * ⭐⭐⭐ **وضع المستخدم الواحد — دخول تلقائي للأدمن.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه ده موجود
 *
 * القرار: البرنامج **مستخدم واحد** هو الأدمن. مافيش صفحة دخول،
 * مافيش دعوات، مافيش استعادة كلمة، مافيش 2FA.
 *
 * ⭐ فبدل ما نشيل `auth:sanctum` من 200 راوت (وحاجة_permissions
 * معاه)، ده بيسجّل دخول الأدمن على كل طلب جاي من الواجهة. فالراوت
 * لوحده، المصفوفة بتفضل زي ما هي، والاختبارات بتفضل محمية.
 *
 * ⭐ لازم يشتغل **قبل** `auth:sanctum` — لأنه هو اللي بيعمل
 * المستخدم اللي `auth:sanctum` هيلاقيه.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ كيف بيتحدد الأدمن — خطوة واحدة، مش استعلام
 *
 * الأدمن = أول مستخدم في الجدول (`orderBy('id')`). فمش بنعمل
 * `where('email', 'admin@...')` (بيتحرك مع البيانات) ولا استعلام
 * على الأدوار في **كل طلب**.
 *
 * ⭐ والخيارين التانيين (`.env` أو أول مستخدم) بيقروا نفس
 * السطر بالظبط: أول سطر في جدول المستخدمين.
 *
 * ⚠️ **الموضع ده مكشوف على الشبكة**: أي حد يقدر يفتح اللوحة.
 * ده المقصود — أداة داخلية على شبكة موثوقة. لو اتعرضت لإنترنت
 * لازم رجوع صفحة دخول (الراوت والصلاحيات موجودين، شيلوا الـ
 * middleware ده وأرجعوا `/auth/login`).
 */
class AutoLoginAdmin
{
    /**
     * ⭐ بنقفل الشغل ده مرة واحدة في الدقيقة — مش استعلام
     * على الجدول في كل طلب.
     */
    public const CHECK_EVERY_SECONDS = 60;

    /**
     * ⭐ هل احنا اللي سجّلنا الدخول في الطلب ده؟
     *
     * ⭐ لازم نعرفه عشان `terminate()` ينضّف **بس** لما احنا اللي
     * غيّرنا حالة الـguard. نضيف `forgetGuards()` لكل طلب كان
     * هيعطّل اختبارات الـauth كلها.
     */
    private bool $autoLoggedIn = false;

    public function handle(Request $request, Closure $next): Response
    {
        // ⭐⭐⭐ **الأولوية للمصادقة الموجودة** — التوكن أو الجلسة.
        //
        // ⭐ ده مش تفصيل جمالي، ده اللي بيخلّي 600+ اختبار شغّالين
        // زي ما هم: كل اختبار بيندخّل باسم حساب مختلف ويتأكد إن
        // **الحساب ده** مش قادر يعمل عملية.
        //
        // ⭐ فلو كان الوسيط بيفرض الأدمن على كل طلب، كل اختبار
        // «معلم مش قادر يعمل كذا» بيبقى بيشتغل على حساب الأدمن
        // وبيمرّ بالغلط — أو يفشل. الاختبار بيكذب على نفسه.
        if ($request->bearerToken() !== null) {
            // ⭐ ننسى الـguard عشان الطلب يتعامل مع التوكن **من أول**،
            // ومن غير ما يرث مستخدم من طلب قبله.
            app('auth')->forgetGuards();

            return $next($request);
        }

        if ($request->hasSession() && auth()->guard('web')->check()) {
            app('auth')->forgetGuards();

            return $next($request);
        }

        $this->ensureAdminExists();

        // ⭐ حدّاد بسيط: 30 طلب في الدقيقة ضد أي سكربت يدور على
        // البوابة مرة كل ثانية.
        $key = 'auto-login:'.($request->ip() ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, 30)) {
            return response()->json(['message' => 'طلبات كتير — استنى شوية.'], 429);
        }

        RateLimiter::hit($key, self::CHECK_EVERY_SECONDS);

        // ⭐ الـguard بيتقرأ في `auth:sanctum` اللي بعدنا. من غير
        // السطر ده، `request->user()` بيفضل null ويبقى 401.
        auth()->setUser(User::orderBy('id')->first());

        $this->autoLoggedIn = true;

        return $next($request);
    }

    /**
     * ⭐⭐⭐ نضّف بعد الطلب — **ضروري** مش تحسين.
     *
     * ⭐ `auth()->setUser()` بيكتب في الـguard الموجود في الـcontainer.
     * فالطلب اللي بعده بيلاقي المستخدم ده محمّل — فالطلب اللي جاي
     * بتوكن صحيح ممكن يتعامل معاه على إنه الأدمن.
     *
     * ⭐ والاختبارات أكتر حساسية: كل طلبات الاختبار بتعدّي في
     * عملية واحدة، فالتسريب ده كان بيكسر اختبارات `status` و
     * `must_change_password` (بيقراوا `request->user()`).
     *
     * ⭐ `terminate()` بتتندى بعد ما الاستجابة تتبعت — فكل طلب
     * بيبتدي نضيف.
     */
    public function terminate(Request $request, Response $response): void
    {
        if ($this->autoLoggedIn) {
            app('auth')->forgetGuards();
        }
    }

    /**
     * ⭐ اتأكد إن في مستخدم واحد على الأقل — ولو القاعدة فاضية
     * (مفيش seeder اتشغّل) اعمل واحد بدل ما الـAPI يبقى 401 على
     * كل حاجة.
     */
    private function ensureAdminExists(): void
    {
        $lock = 'auto-login:bootstrap';

        if (RateLimiter::tooManyAttempts($lock, 1)) {
            return;
        }

        RateLimiter::hit($lock, self::CHECK_EVERY_SECONDS);

        if (User::query()->exists()) {
            return;
        }

        Log::warning('auto_login_created_admin');

        $user = User::create([
            'name' => 'مدير الأكاديمية',
            'email' => 'admin@academy.test',
            // ⭐⭐ كلمة مرور **عشوائية**: مافيش صفحة دخول، فمحدش
            // هيدخل بالكلمة دي أصلاً. وكلمة ثابتة هنا كانت هتبقى
            // باب مفتوح لو حد رجّع صفحة الدخول يوم.
            'password' => Hash::make(Str::random(48)),
            'status' => 'active',
            'must_change_password' => false,
            // ⭐ العمود NOT NULL — والأول هو الأكاديمية الوحيدة
            // (قرار الـroadmap: منظمة واحدة).
            'organization_id' => Organization::query()->value('id'),
        ]);

        // ⭐ لازم نعمل الدور لوحده كمان: القاعدة الفاضية معناها
        // مافيش seeder اتشغّل، ومافيش `assignRole` بيعرف يضيفه.
        $role = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['slug' => 'admin', 'guard_name' => 'web', 'is_system' => true],
        );

        // ⭐ وكل الصلاحيات الموجودة في الكتالوج. من غير السطر ده
        // الـ`CheckPermission` هيرفض كل راو والبرنامج هيطلع ميت.
        $role->syncPermissions(Permission::query()->get());

        $user->assignRole($role);
    }
}
