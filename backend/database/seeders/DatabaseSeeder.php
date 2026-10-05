<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Branch;
use App\Models\User;
use App\Models\QuranSurah;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create organization
        $org = Organization::create([
            'name' => 'أكاديمية القرآن',
            'slug' => 'quran-academy',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);

        // Create branch
        Branch::create([
            'organization_id' => $org->id,
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
            'status' => 'active',
        ]);

        // Create admin user
        $admin = User::create([
            'organization_id' => $org->id,
            'name' => 'المدير',
            'email' => 'admin@quran-academy.com',
            'password' => Hash::make('password'),
            'status' => 'active',
        ]);

        // Create permissions
        $permissions = [
            ['name' => 'students.view', 'slug' => 'students-view', 'module' => 'students'],
            ['name' => 'students.create', 'slug' => 'students-create', 'module' => 'students'],
            ['name' => 'students.edit', 'slug' => 'students-edit', 'module' => 'students'],
            ['name' => 'students.delete', 'slug' => 'students-delete', 'module' => 'students'],
            ['name' => 'teachers.view', 'slug' => 'teachers-view', 'module' => 'teachers'],
            ['name' => 'teachers.create', 'slug' => 'teachers-create', 'module' => 'teachers'],
            ['name' => 'teachers.edit', 'slug' => 'teachers-edit', 'module' => 'teachers'],
            ['name' => 'teachers.delete', 'slug' => 'teachers-delete', 'module' => 'teachers'],
            ['name' => 'lessons.view', 'slug' => 'lessons-view', 'module' => 'lessons'],
            ['name' => 'lessons.create', 'slug' => 'lessons-create', 'module' => 'lessons'],
            ['name' => 'lessons.edit', 'slug' => 'lessons-edit', 'module' => 'lessons'],
            ['name' => 'lessons.cancel', 'slug' => 'lessons-cancel', 'module' => 'lessons'],
            ['name' => 'payments.view', 'slug' => 'payments-view', 'module' => 'payments'],
            ['name' => 'payments.create', 'slug' => 'payments-create', 'module' => 'payments'],
            ['name' => 'payments.refund', 'slug' => 'payments-refund', 'module' => 'payments'],
            ['name' => 'payments.edit', 'slug' => 'payments-edit', 'module' => 'payments'],
            ['name' => 'leads.view', 'slug' => 'leads-view', 'module' => 'leads'],
            ['name' => 'leads.create', 'slug' => 'leads-create', 'module' => 'leads'],
            ['name' => 'leads.edit', 'slug' => 'leads-edit', 'module' => 'leads'],
            ['name' => 'reports.view', 'slug' => 'reports-view', 'module' => 'reports'],
            ['name' => 'programs.view', 'slug' => 'programs-view', 'module' => 'programs'],
            ['name' => 'attendance.view', 'slug' => 'attendance-view', 'module' => 'attendance'],
            ['name' => 'attendance.manage', 'slug' => 'attendance-manage', 'module' => 'attendance'],
            ['name' => 'payroll.manage', 'slug' => 'payroll-manage', 'module' => 'payroll'],
            ['name' => 'settings.manage', 'slug' => 'settings-manage', 'module' => 'settings'],
        ];

        // ⚠️ التوست عندي: دي نسخة ثانية من نفس قائمة الصلاحيات.
        // المصدر الحقيقي هو `SeedRolesPermissions::PERMISSIONS` — لو
        // أضفت صلاحية هنا بس، مش هتظهر لما `RealisticDataSeeder`
        // يشتغل. المفروض الاتنين يتّحدوا في مصدر واحد.

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                [...$perm, 'guard_name' => 'web']
            );
        }

        // Create roles
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['slug' => 'admin', 'guard_name' => 'web', 'is_system' => true]
        );
        $adminRole->syncPermissions(Permission::all());

        // Assign admin role
        $admin->assignRole($adminRole);

        // Seed Quran Surahs
        $surahs = [
            ['number' => 1, 'name_ar' => 'الفاتحة', 'name_en' => 'Al-Fatiha', 'ayah_count' => 7, 'revelation_type' => 'meccan'],
            ['number' => 2, 'name_ar' => 'البقرة', 'name_en' => 'Al-Baqara', 'ayah_count' => 286, 'revelation_type' => 'medinan'],
            ['number' => 3, 'name_ar' => 'آل عمران', 'name_en' => 'Aal-Imran', 'ayah_count' => 200, 'revelation_type' => 'medinan'],
            ['number' => 36, 'name_ar' => 'يس', 'name_en' => 'Ya-Sin', 'ayah_count' => 83, 'revelation_type' => 'meccan'],
            ['number' => 55, 'name_ar' => 'الرحمن', 'name_en' => 'Ar-Rahman', 'ayah_count' => 78, 'revelation_type' => 'medinan'],
            ['number' => 56, 'name_ar' => 'الواقعة', 'name_en' => 'Al-Waqi\'a', 'ayah_count' => 96, 'revelation_type' => 'meccan'],
            ['number' => 67, 'name_ar' => 'الملك', 'name_en' => 'Al-Mulk', 'ayah_count' => 30, 'revelation_type' => 'meccan'],
            ['number' => 112, 'name_ar' => 'الإخلاص', 'name_en' => 'Al-Ikhlas', 'ayah_count' => 4, 'revelation_type' => 'meccan'],
            ['number' => 113, 'name_ar' => 'الفلق', 'name_en' => 'Al-Falaq', 'ayah_count' => 5, 'revelation_type' => 'meccan'],
            ['number' => 114, 'name_ar' => 'الناس', 'name_en' => 'An-Nas', 'ayah_count' => 6, 'revelation_type' => 'meccan'],
        ];

        foreach ($surahs as $surah) {
            QuranSurah::firstOrCreate(['number' => $surah['number']], $surah);
        }
    }
}
