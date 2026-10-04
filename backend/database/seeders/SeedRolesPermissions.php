<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * الأدوار والصلاحيات.
 *
 * ليش seeder لوحده؟ `RealisticDataSeeder` بيعمل wipe وبيحط بيانات
 * واقعية، بس لو شغّلته بعد `migrate:fresh` بيبقى مفيش ولا صلاحية —
 * يعني كل الـ endpoint اللي عليه `permission:` بيرجّع 403 والتطبيق
 * مش بيفتح أصلاً.
 *
 * Permissions دي taken من `routes/api.php` — أي صلاحية مستخدمة هناك
 * لازم تكون موجودة هنا.
 */
class SeedRolesPermissions extends Seeder
{
    /** @var array<int, array{name:string, module:string}> */
    private const PERMISSIONS = [
        ['name' => 'students.view', 'module' => 'students'],
        ['name' => 'students.create', 'module' => 'students'],
        ['name' => 'students.edit', 'module' => 'students'],
        ['name' => 'students.delete', 'module' => 'students'],
        ['name' => 'teachers.view', 'module' => 'teachers'],
        ['name' => 'teachers.create', 'module' => 'teachers'],
        ['name' => 'teachers.edit', 'module' => 'teachers'],
        ['name' => 'teachers.delete', 'module' => 'teachers'],
        ['name' => 'lessons.view', 'module' => 'lessons'],
        ['name' => 'lessons.create', 'module' => 'lessons'],
        ['name' => 'lessons.edit', 'module' => 'lessons'],
        ['name' => 'lessons.cancel', 'module' => 'lessons'],
        ['name' => 'payments.view', 'module' => 'payments'],
        ['name' => 'payments.create', 'module' => 'payments'],
        ['name' => 'payments.edit', 'module' => 'payments'],
        ['name' => 'payments.refund', 'module' => 'payments'],
        ['name' => 'leads.view', 'module' => 'leads'],
        ['name' => 'leads.create', 'module' => 'leads'],
        ['name' => 'leads.edit', 'module' => 'leads'],
        ['name' => 'reports.view', 'module' => 'reports'],
        ['name' => 'settings.manage', 'module' => 'settings'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                [
                    'slug' => str_replace('.', '-', $perm['name']),
                    'module' => $perm['module'],
                    'guard_name' => 'web',
                ],
            );
        }

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['slug' => 'admin', 'guard_name' => 'web', 'is_system' => true],
        );
        $adminRole->syncPermissions(Permission::all());

        // الحساب اللي إيميله admin@... هو اللي بيرث الدور
        $admins = User::where('email', 'like', '%@quran-academy.com')
            ->whereDoesntHave('teacher')
            ->get();

        foreach ($admins as $admin) {
            $admin->assignRole($adminRole);
        }

        $this->command?->info(
            '   → ' . count(self::PERMISSIONS) . ' صلاحية · دور admin · '
            . $admins->count() . ' حساب إداري'
        );
    }
}
