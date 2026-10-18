<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * ⭐⭐⭐ منع تصعيد الصلاحيات — القاعدة الواحدة لكل النظام.
 *
 * ═══════════════════════════════════════════════════════════════
 * القاعدة
 *
 * **مين يقدر يديّ صلاحيات؟ اللي عنده `users.roles.assign`.**
 *
 * **مين يقدر يديّ إيه؟ بس اللي هو نفسه ماسك إياه.**
 *
 * ⭐ القاعدة التانية هي الأهم. هي اللي بتخلي التصعيد مستحيل
 * بالتدريج: لو الأدوار بتتوسّع شوية شوية (استقبال → مشرف →
 * مدير)، ما ينفع حد يسلّم دور أقوى من اللي معاه — عشان ما
 * يبقاش عنده سبب يدّي التصريح أصلاً.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه خدمة مش logic في الـ controllers
 *
 * ⭐ نفس القاعدة محتاجة في **تلات** أماكن:
 *   • `EmployeeController::store`   — تعيين دور لحساب جديد
 *   • `EmployeeController::update`  — تغيير دور موظف موجود
 *   • `RoleController::updatePermissions` — تعديل صلاحيات دور
 *
 * ولو اتكررت تلات مرات، تاني واحد هينسى حالة. والقاعدة الأمنية
 * اللي ليها ٣ نسخ هي قاعدة مش هتعدّي المراجعة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه `admin` استثناء صريح
 *
 * لأن دور `admin` معناه «كل الصلاحيات» — فلو دورنا فيه صلاحية
 * واحدة أقوى من اللي عندنا، يبقى دور `admin` مستحيل يتبعت لحد
 * مش مدير. وأدوار المالية دي مقصود إنها للمدير بس.
 * مقصود إنها للمدير بس.
 *
 * @see \Tests\Feature\PrivilegeEscalationTest
 */
class RoleGrantService
{
    /**
     * صلاحية تسليم الأدوار — لازم تكون في
     * `SeedRolesPermissions::PERMISSIONS`.
     */
    public const ASSIGN_PERMISSION = 'users.roles.assign';

    /**
     * ⭐ فحص: هل ده يقدر يسلّم الدور ده؟
     *
     * ⭐⭐ **بتعمل `abort(403)` — مش بترجع false وبس.**
     *
     * والسبب: المتصل بيبعت `role_id`، فلو رجعنا `false` وصمّينا
     * الطلب، المتصل هياخد **201** ويظن إن الدور اتعيّن — وهو
     * مش متعيّن. الرفض واضح أحسن بكتير من التجاهل الصامت.
     */
    public function assertCanAssignRole(User $actor, Role $role, ?Request $request = null): void
    {
        // ① التصريح
        if (! $this->hasPermission($actor, self::ASSIGN_PERMISSION)) {
            $this->deny($actor, $role, 'assign_without_permission', $request, [
                'missing_permission' => self::ASSIGN_PERMISSION,
            ]);

            abort(403, 'مالكش صلاحية إسناد الأدوار');
        }

        // ② `admin` للمديرين بس
        if ($this->isAdminRole($role) && ! $this->isAdmin($actor)) {
            $this->deny($actor, $role, 'assign_admin_role', $request);

            abort(403, 'دور المدير للمديرين بس');
        }

        // ③ ما تسلّمش اللي مش ماسكه
        $missing = $this->permissionsActorLacks($actor, $role);

        if ($missing !== []) {
            $this->deny($actor, $role, 'assign_stronger_role', $request, [
                'missing_permissions' => $missing,
            ]);

            abort(403, 'مش able تسلّم صلاحيات انت مش ماسكها');
        }
    }

    /**
     * ⭐ فحص: هل ده يقدر يعدّل صلاحيات الدور ده؟
     *
     * @param  array<int, int|string>  $permissionIdsOrNames
     */
    public function assertCanGrantPermissions(
        User $actor,
        Role $role,
        array $permissionIdsOrNames,
        ?Request $request = null,
    ): void {
        // ① التصريح
        if (! $this->hasPermission($actor, self::ASSIGN_PERMISSION)) {
            $this->deny($actor, $role, 'grant_without_permission', $request, [
                'missing_permission' => self::ASSIGN_PERMISSION,
            ]);

            abort(403, 'مالكش صلاحية تعديل صلاحيات الأدوار');
        }

        // ② أدوار النظام **مقفولة التعديل**
        //
        // ⭐ `admin` و`accountant` و`teacher` مبنية على قرار الفصل
        // في `SeedRolesPermissions`. لو حد قدر يديّل
        // `accountant` صلاحية `finance.reverse`، نص قرار فصل
        // المهام بيطيح من غير ما حد يبان.
        if ($role->is_system) {
            $this->deny($actor, $role, 'modify_system_role', $request);

            abort(403, 'أدوار النظام مش قابلة للتعديل');
        }

        // ③ ما تديش اللي مش ماسكه
        $names = $this->resolvePermissionNames($permissionIdsOrNames);
        $missing = array_values(array_filter(
            $names,
            fn (string $name) => ! $this->hasPermission($actor, $name),
        ));

        if ($missing !== []) {
            $this->deny($actor, $role, 'grant_stronger_permissions', $request, [
                'missing_permissions' => $missing,
            ]);

            abort(403, 'مش able تدي صلاحيات انت مش ماسكها');
        }
    }

    /**
     * ⭐ فحص مستقل: الدور ده نظام ولا لا؟ بيستخدمه الحذف لو اتضاف.
     */
    public function assertNotSystemRole(Role $role, ?Request $request = null): void
    {
        if (! $role->is_system) {
            return;
        }

        abort(403, 'أدوار النظام مش قابلة للتعديل');
    }

    /**
     * ⭐ اسم الدور اللي ممنوع يتبعت لحد مش مدير.
     *
     * ⭐ بنقارن بالاسم **مش** بالـ `is_system` — لأن دور المستخدم
     * ممكن يكون معمول بإيد حد (مش نظام)، فلو سمحنا بتمريره نكون
     * سايبين باب مفتوح بي الحلاف.
     */
    public function isAdminRole(Role $role): bool
    {
        return $role->name === 'admin';
    }

    public function isAdmin(User $user): bool
    {
        return $user->hasRole('admin');
    }

    /**
     * ⭐ تشغيل `hasPermissionTo` **بأمان**.
     *
     * ⭐ `hasPermissionTo()` بترمي `PermissionDoesNotExist` لو
     * الصلاحية مش موجودة في جدول `permissions` — ومن غير catch
     * النتيجة **500** بدل 403 (نفس الباج اللي كان في
     * `TeacherScheduleController::authorizeOwnership`).
     *
     * ⭐ والقاعدة المتّسقة مع `CheckPermission`: صلاحية ناقصة =
     * **ممنوع**، مش خطأ سيرفر.
     */
    public function hasPermission(User $user, string $name): bool
    {
        try {
            return $user->hasPermissionTo($name);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * ⭐ صلاحيات الدور اللي الـ actor مش ماسكها.
     *
     * @return array<int, string>
     */
    public function permissionsActorLacks(User $actor, Role $role): array
    {
        return $role->permissions
            ->pluck('name')
            ->reject(fn (string $name) => $this->hasPermission($actor, $name))
            ->values()
            ->all();
    }

    /**
     * يقبل IDs أو أسماء ويرجّع الأسماء.
     *
     * @param  array<int, int|string>  $idsOrNames
     * @return array<int, string>
     */
    public function resolvePermissionNames(array $idsOrNames): array
    {
        return array_values(array_filter(array_map(
            fn ($idOrName) => is_numeric($idOrName)
                ? Permission::find((int) $idOrName)?->name
                : (string) $idOrName,
            $idsOrNames,
        )));
    }

    /**
     * ⭐ تسجيل المحاولة المرفوضة.
     *
     * ⭐ ده مش رفاهية: من غير تسجيل الرفض، حد بيحاول يصعّد
     * الصلاحيات بيبقى **مفيش أثر ليه خالص** — وده بالظبط
     * السيناريو اللي محتاجين نعرفه.
     */
    private function deny(User $actor, Role $role, string $reason, ?Request $request, array $extra = []): void
    {
        app(AuditLogService::class)->log(
            'denied',
            'role',
            (int) $role->id,
            ['attempted_role' => $role->name],
            ['reason' => $reason] + $extra,
            $request,
            $actor->id,
        );
    }
}
