<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * يولّد موظفين واقعيين — بعضهم بحسابات دخول وبعضهم بدون.
 *
 * بيانات متنوعة: أقسام ووظائف مختلفة، مدير مباشر، حالات توظيف متنوعة.
 */
class SeedEmployees extends Seeder
{
    /**
     * صف موظف: [الاسم، الوظيفة، القسم، نوع التوظيف، الحالة، رقم المدير]
     * رقم المدير بيشير لرقم الموظف في القائمة دي (1-based).
     */
    private const ROWS = [
        ['أحمد محمد', 'محاسب', 'المالية', 'full_time', 'active', null],
        ['سارة علي', 'مشرفة أكاديمية', 'الإدارة', 'full_time', 'active', null],
        ['محمد حسن', 'موظف استقبال', 'الاستقبال', 'full_time', 'active', 2],
        ['منى عبد الله', 'خدمة عملاء', 'الاستقبال', 'part_time', 'active', 2],
        ['خالد إبراهيم', 'مسؤول متابعة', 'الإدارة', 'full_time', 'active', 2],
        ['هبة الله عبد الله', 'محاسبة', 'المالية', 'full_time', 'on_leave', 1],
        ['عمر حداد', 'مبيعات', 'المبيعات', 'full_time', 'active', 5],
        ['فاطمة عز الدين', 'مسؤولة جودة', 'الإدارة', 'contract', 'active', 2],
        ['حسين الغزالي', 'مسؤول تقنية', 'تقنية المعلومات', 'full_time', 'active', 5],
        ['لمار شلبي', 'مسوق', 'التسويق', 'part_time', 'active', 7],
        ['نور الدين الشاذلي', 'موظف استقبال', 'الاستقبال', 'volunteer', 'inactive', 3],
        ['ياسمين يوسف', 'مصممة', 'التسويق', 'contract', 'active', 10],
    ];

    /** أسماء الإناث — عشان نعرف الجنس */
    private const FEMALE = ['سارة', 'منى', 'هبة', 'فاطمة', 'لمار', 'ياسمين'];

    public function run(): void
    {
        mt_srand(20261004);

        $now = now();
        $ids = [];

        // أدوار الموظفين — كل دور بصلاحيات مختلفة.
        // ليش بنعملها هنا؟ عشان قسم «الدور والصلاحيات» في ملف الموظف
        // يبقى فيه حاجة تتعرض، مش «بدون دور» لكل الـ 12.
        $rolePermissions = [
            'accountant' => ['students.view', 'payments.view', 'payments.create', 'payments.edit', 'reports.view'],
            'receptionist' => ['students.view', 'students.create', 'students.edit', 'leads.view', 'leads.create', 'leads.edit'],
            'supervisor' => ['students.view', 'students.edit', 'teachers.view', 'lessons.view', 'lessons.edit', 'reports.view'],
        ];

        $roleIds = [];
        foreach ($rolePermissions as $roleName => $perms) {
            $role = \Spatie\Permission\Models\Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                ['slug' => $roleName, 'guard_name' => 'web', 'is_system' => false],
            );
            $role->syncPermissions(\Spatie\Permission\Models\Permission::whereIn('name', $perms)->get());
            $roleIds[$roleName] = $role->id;
        }

        // أول 6 موظفين عندهم حساب دخول + دور
        $rolesForEmployees = ['accountant', 'supervisor', 'receptionist', 'receptionist', 'supervisor', 'accountant'];

        foreach (self::ROWS as $i => [$name, $jobTitle, $department, $employmentType, $status, $managerNo]) {
            $gender = in_array(explode(' ', $name)[0], self::FEMALE, true) ? 'female' : 'male';
            $phone = '01' . str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT);
            $email = 'employee' . ($i + 1) . '@quran-academy.com';

            // أول ٦ موظفين عندهم حساب دخول
            $userId = null;
            if ($i < 6) {
                $userId = DB::table('users')->insertGetId([
                    'organization_id' => 1,
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'password' => Hash::make('password'),
                    'timezone' => 'Africa/Cairo',
                    'locale' => 'ar',
                    'job_title' => $jobTitle,
                    'department' => $department,
                    'status' => 'active',
                    'email_verified_at' => $now,
                    'last_login_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // دور الموظف — كل واحد دور مختلف عشان تتعرض
                // الصلاحيات المختلفة في ملفه
                $roleName = $rolesForEmployees[$i] ?? null;
                if ($roleName) {
                    DB::table('model_has_roles')->insert([
                        'role_id' => $roleIds[$roleName],
                        'model_type' => 'App\\Models\\User',
                        'model_id' => $userId,
                    ]);
                }
            }

            $joinedYear = 2023 + ($i % 4);

            $ids[] = DB::table('employees')->insertGetId([
                'organization_id' => 1,
                'user_id' => $userId,
                'name' => $name,
                'phone' => $phone,
                'country_code' => '+20',
                'email' => $email,
                'gender' => $gender,
                'date_of_birth' => ($joinedYear - 25) . '-06-15',
                'nationality' => 'مصري',
                'address' => 'القاهرة',
                'job_title' => $jobTitle,
                'department' => $department,
                'employment_type' => $employmentType,
                'manager_id' => null,
                'joined_at' => sprintf('%d-%02d-%02d', $joinedYear, ($i % 12) + 1, ($i % 27) + 1),
                'status' => $status,
                'notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // المدير المباشر — بعد ما كل الموظفين اتعملوا
        foreach (self::ROWS as $i => [,,,,, $managerNo]) {
            if ($managerNo === null) {
                continue;
            }
            DB::table('employees')
                ->where('id', $ids[$i])
                ->update(['manager_id' => $ids[$managerNo - 1]]);
        }

        $this->command?->info('✅ ' . count(self::ROWS) . ' موظف — 6 بحساب دخول و' . count($roleIds) . ' أدوار');
    }
}
