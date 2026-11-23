<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permission = Permission::firstOrCreate(
                ['name' => 'employee.portal', 'guard_name' => 'web'],
                ['slug' => 'employee-portal', 'module' => 'employee', 'group' => 'الحضور',
                    'description' => 'عرض الحضور والرواتب الخاصة بالموظف المرتبط بالحساب'],
            );
            Role::firstOrCreate(
                ['name' => 'employee', 'guard_name' => 'web'],
                ['slug' => 'employee', 'is_system' => true],
            );

            Role::where('guard_name', 'web')
                ->whereIn('name', ['employee', 'admin', 'accountant', 'receptionist', 'supervisor'])
                ->each(fn (Role $role) => $role->givePermissionTo($permission));
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'employee.portal')->where('guard_name', 'web')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
