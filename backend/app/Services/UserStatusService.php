<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ⭐ تغيير حالة الحساب — مع **كل الأثر**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه خدمة مش logic في الـ controller
 *
 * تغيير حالة الحساب عملية واحدة بثلاثة آثار لازملام **كلها**:
 *
 *   ① تحديث `users.status`
 *   ② **إبطال كل الجلسات والتوكينات** (عند الوقف)
 *   ③ تسجيل في `audit_logs` (مين عمل إيه على مين)
 *
 * ⭐ لو الأثر (②) ناقص، الوقف بيبقى أداة وهمية: الجلسة أو التوكين
 * الصادر **قبل** الوقفة بيفضل شغّال. ولما يكون في أكتر من endpoint
 * بتنادي نفس العملية، التكرار معناه مكان واحد نُسي.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ PR 1.5: **الجلسات** بقت جزء من العقد
 *
 * ⭐ `suspend()` existed من PR 0.4 وبتعمل
 * `$user->tokens()->delete()` — توكينات Sanctum بس.
 *
 * ⭐ ومن **PR 1.1** الواجهة بقت تستخدم كوكي جلسة HttpOnly، مش
 * توكين. فالخدمة كانت بتبطل التوكينات اللي **محدش بیستخدمهم**،
 * وتسيب الجلسات اللي **كل حاجة فعليًا بتعتمد عليها**.
 *
 * ⭐ و`EnsureAccountActive` كان بيمسك — بس على الطلب **الجاي**.
 * يعني «الجلسة مفتوحة» بالمعنى الحرفي لحد ما الجاي ييجي.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة الحاكمة: **مفيش حالة بتاخد الأكاديمية كلها معاها**
 *
 * ⭐ Academia one-tenant (مفيش tenant chooser في الـlogin). فلو
 * مفيش ولا مدير **نشط** واحد، **محدش** يقدر يدخل، ومافيش زر
 * «إعادة تعيين كلمة مرور المدير» في أي شاشة. الـrecovery الوحيد
 * هو `php artisan tinker` — وده مش حل.
 *
 * ⭐ عشان كده كل تغيير حالة بيعدّي على
 * {@see assertNotLastAdmin()}. والقاعدة **بتشتغل مع اللي بيعملوا
 * العملية كمان**، مش بس مع الـactor.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه `abort()` مش استثناء مخصص
 *
 * ⭐ نفس اختيار `RoleGrantService`: بيحتاج HTTP status ورسالة
 * عربية، وبيكتب audit قبل الرد. استثناء مخصص بيضيف class من غير
 * فايدة لما `HttpException` بيقبل `abort()` JudyOv وبيعمل
 * تسجيل تلقائي.
 *
 * @see \App\Http\Middleware\EnsureAccountActive
 * @see \Tests\Feature\UserManagementTest
 */
class UserStatusService
{
    public function __construct(private AuditLogService $audit) {}

    // ═══════════════════════════════════════════════════════════════
    // القواعد — بتتنفّذ **قبل** أي كتابة
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ مفيش حد يعمل العملية على **نفسه** — في الإيقاف
     * والأرشفة وتغيير الدور.
     *
     * ⭐ ⚠️ **ليه في تغيير الدور ولسه آمن التصعيد؟**
     *
     * `RoleGrantService` بيمنع التصعيد (تسليم دور فيه صلاحيات
     * انت مش ماسكها). فالـself-escalation مستحيل أصلاً. والسؤال
     * هنا تاني: ليه نمنع؟
     *
     * ⭐ مش حماية — **عمليات**. مدير بيشيل دوره عن نفسه بالغلط
     * يقفل على نفسه، والرجوع بياخد تلات خطوات:
     *
     *   ① `must_change_password` بيفرض تغيير كلمة المرور
     *   ② بعد التغيير، `RoleGrantService::assertCanAssignRole`
     *      بتشتغل بـ`permissionsActorLacks()` — وهي دلوقتي
     *      **فاضية**، لأن المدير فقد الدور اللي كان بياخد منه
     *      الصلاحيات
     *   ③ النتيجة: عايز يرجع لدوره الـadmin وما يقدرش.
     *
     * ⭐ ولا ينفع يتخطّاه بـ`fetch` من DevTools — الطلب بيمشي
     * بكوكي الجلسة، والجلسة نفسها مقفولة بـ
     * `EnsurePasswordChanged`.
     *
     * @param  string  $action  اسم العملية بالعربي — بتظهر للمستخدم
     */
    public function assertNotSelf(User $target, ?User $actor, string $action): void
    {
        if (! $actor || $actor->id !== $target->id) {
            return;
        }

        $this->audit->log(
            'user_action_rejected',
            'user',
            (int) $target->id,
            null,
            ['reason' => 'self_target', 'action' => $action],
            request(),
            (int) $actor->id,
        );

        abort(422, "مش هتقدر {$action} على حسابك — حد تاني هو اللي يقدر.");
    }

    /**
     * ⭐⭐⭐ **آخر مدير نشط مايتغيّرش حالته** — القاعدة الحاكمة.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ التعريف: `status = 'active'` **و** عنده دور `admin`
     *
     * ⭐ لازم `active` مش بس «عنده دور admin». مدير موقوف مش
     * بيفتح حاجة، فلو حسبناه في الحماية كنا بنحمي أكاديمية
     * **مقفولة أصلاً** — والقاعدة هتفشل على آخر مدير **مجدي**
     * وتسمح بإسقاطه.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐ ليه «بعد ما نعدّ» مش «هو آخر واحد»
     *
     * ⭐ لو في مديرين نشطين، الإيقاف بيسيب واحد. تمام.
     * فلو في واحد، الإيقاف بيسيب **صفر**.
     *
     * ⭐ `assertNotSelf` بيغطي حالة الـactor نفسه. فالحالة
     * اللي بتوصل هنا عملياً: **حد عنده `users.suspend` بس مش
     * مدير** بيوقّف آخر مدير. وده بالظبط اللي القاعدة بتقفله —
     * من غيرها أكتر من مسار بيقفل الأكاديمية كلها بضغطة.
     */
    public function assertNotLastAdmin(User $target, string $action): void
    {
        if (! $target->hasRole('admin') || $target->status !== 'active') {
            return;
        }

        $remaining = User::role('admin')
            ->where('status', 'active')
            ->where('id', '<>', $target->id)
            ->count();

        if ($remaining > 0) {
            return;
        }

        $this->audit->log(
            'user_action_rejected',
            'user',
            (int) $target->id,
            null,
            ['reason' => 'last_active_admin', 'action' => $action],
            request(),
            auth()->id(),
        );

        abort(422, 'ده آخر مدير نشط في الأكاديمية — من غيره محدش هيقدر يدخل.');
    }

    // ═══════════════════════════════════════════════════════════════
    // ① الإيقاف
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ إيقاف حساب.
     *
     * ⭐⚠️ **إبطال الجلسات والتوكينات إجباري عند الوقف.** مش
     * اختياري — وده اللي كان ناقص: `AuthController::login` كان
     * بيتأكد من `status` وقت الدخول بس، فالتوكين الصادر قبل
     * الوقفة بيفضل صالح.
     */
    public function suspend(User $user, ?User $actor = null, ?string $reason = null): User
    {
        $this->assertNotSelf($user, $actor, 'توقيف');
        $this->assertNotLastAdmin($user, 'توقيف');

        $user->forceFill(['status' => 'suspended'])->save();

        // ⭐⭐⭐-lineupترتيب الأثر: الحالة الأول (عشان الفلتر
        // يقفل فوراً)، بعدين الإبطال.
        $sessions = $this->revokeSessions($user);
        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        $this->audit->log(
            'user_suspended',
            'user',
            (int) $user->id,
            ['status' => 'active'],
            [
                'status' => 'suspended',
                'reason' => $reason,
                'sessions_revoked' => $sessions,
                'tokens_revoked' => $revoked,
            ],
            request(),
            $actor?->id,
        );

        return $user->fresh();
    }

    /**
     * ⭐ إعادة التفعيل.
     *
     * ⭐ ⭐⭐ **الجلسات والتوكينات بتفضل ميتة** — للمستخدم يدخل من
     * جديد. وده **صح**:
     *
     * إعادة التفعيل مش دعوة للرجوع للجلسات القديمة، خصوصاً لو
     * الوقفة كانت بسبب اشتباه في اختراق — إرجاع الجلسة القديمة
     * معناه رجوع المهاجم معاها.
     * ⭐ إعادة التفعيل مش دعوة للرجوع للجلسات القديمة — خصوصاً لو
     * الوقفة كانت بسبب اشتباه في اختراق. إرجاع الجلسة القديمة
     */
    public function reactivate(User $user, ?User $actor = null): User
    {
        // ⭐ الأرشفة **نهائية** — مافيش رجوع ليها.
        //
        // أرشفة بعد شهور محتاجة مراجعة بشرية (هل الحساب ده كان
        // بيستخدم؟)، وضغطة زر واحدة مش قرار.
        if ($user->status === 'archived') {
            abort(422, 'الحساب متأرشف — الأرشفة نهائية ومش بترجع.');
        }

        if ($user->status === 'active') {
            abort(422, 'الحساب أصلاً نشط.');
        }

        $user->forceFill(['status' => 'active'])->save();

        $this->logReactivation($user, $actor);

        return $user->fresh();
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الأرشفة
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ الأرشفة — **نهائية**، وبنفس آثار الوقف.
     *
     * ⭐ الفرق الوحيد بين الوقف والأرشفة: إمكانية الرجوع.
     * `suspended` → `reactivate`. `archived` → **مفيش**.
     *
     * ⭐ والأثر **نفسه**: الأرشفة لازم تبطّل الجلسات والتوكينات
     * زي الوقف بالظبط. الحساب المحفوظ في الداتابيز مش معناها
     * إنه لسه بيفتح.
     */
    public function archive(User $user, ?User $actor = null, ?string $reason = null): User
    {
        $this->assertNotSelf($user, $actor, 'أرشفة');
        $this->assertNotLastAdmin($user, 'أرشفة');

        $user->forceFill(['status' => 'archived'])->save();

        $sessions = $this->revokeSessions($user);
        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        $this->audit->log(
            'user_archived',
            'user',
            (int) $user->id,
            ['status' => $user->getOriginal('status')],
            ['status' => 'archived', 'reason' => $reason, 'sessions_revoked' => $sessions, 'tokens_revoked' => $revoked],
            request(),
            $actor?->id,
        );

        return $user->fresh();
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ إعادة تعيين كلمة المرور إجباريًا
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **إعادة التعيين بتقفل كل الدوال.**
     *
     * ⭐ السبب الوحيد: لو كلمة المرور اتسرّبت، إبطال التوكينات
     * **مش كافي** — من PR 1.1 في جلسات، والجلسة مش في
     * `personal_access_tokens`. فلازم نبطّل الاتنين.
     *
     * ⭐ والمعنى للمستخدم: يفتح التطبيق، يطلب تغيير كلمة المرور،
     * يدخل. أي صفحة تانية مقفولة بـ`EnsurePasswordChanged`.
     *
     * ⚠️ **مفيش تغيير للـ`status`** — الحساب يفضل نشط. الإغلاق
     * مؤقت لحد ما يغيّر، مش وقفة.
     *
     * @see \App\Http\Middleware\EnsurePasswordChanged
     */
    public function forcePasswordReset(User $user, ?User $actor = null): User
    {
        $user->forceFill([
            'must_change_password' => true,
            'password_changed_at' => null,
        ])->save();

        $sessions = $this->revokeSessions($user);
        $revoked = $user->tokens()->count();
        $user->tokens()->delete();

        $this->audit->log(
            'user_password_forced',
            'user',
            (int) $user->id,
            ['must_change_password' => false],
            ['must_change_password' => true, 'sessions_revoked' => $sessions, 'tokens_revoked' => $revoked],
            request(),
            $actor?->id,
        );

        return $user->fresh();
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **إبطال كل الجلسات** — مش التوكينات بس.
     *
     * ⭐ من PR 1.1 الـAPI بيقبل **جلستين** بالتوازي: كوكي جلسة
     * للواجهة، وتوكين للموبايل (`EnsureFrontendRequestsAreStateful`
     * بيقرّر). فلو Bordeauxاوبطّلنا التوكينات بس، الجلسة هتفضل
     * شغّالة.
     *
     * ⭐ `sessions.user_id` nullable — الـguest session مالها
     * `user_id` فمش هتتقفل. صح: جلسات الزوار مش جلسات حد.
     *
     * @return int كام جلسة اتقفلت — للـaudit
     */
    private function revokeSessions(User $user): int
    {
        return DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    public function logReactivation(User $user, ?User $actor = null): void
    {
        $this->audit->log(
            'user_reactivated',
            'user',
            (int) $user->id,
            ['status' => 'suspended'],
            ['status' => 'active'],
            request(),
            $actor?->id,
        );
    }

    /**
     * ⭐ تسجيل واقعة وقفة — **من غير تغيير الحالة**.
     *
     * ⭐ ليش منفصل عن `suspend()`؟ عشان الـtests والسيناريوهات
     * اللي بتغيّر الحالة على الـfly (زي fixture) تقدر توثّق
     * الحادثة بنفسها.
     */
    public function logSuspension(User $user, ?User $actor = null, ?string $reason = null): void
    {
        $this->audit->log(
            'user_suspended',
            'user',
            (int) $user->id,
            ['status' => 'active'],
            ['status' => 'suspended', 'reason' => $reason],
            request(),
            $actor?->id,
        );
    }
}
