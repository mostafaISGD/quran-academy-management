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

use Database\Seeders\Concerns\RefusesToRunInProduction;

class DatabaseSeeder extends Seeder
{
    use RefusesToRunInProduction;
    public function run(): void
    {
        $this->guardProduction('DatabaseSeeder');

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

        /**
         * ⭐⭐⭐ **المصدر الحقيقي للصلاحيات: `SeedRolesPermissions`.**
         *
         * ⚠️⚠️ كان هنا **قائمة ثانية مكتوبة يدوي** بنفس الأسماء.
         * النتيجة اللي ظهرت فعلاً في القاعدة:
         *
         *     إجمالي الصلاحيات: ٢٥  (من الليستة القديمة)
         *     ناقص: finance.* · groups.* · pricing.* · leads.*
         *
         * يعني `/accounts` و `/finance` و `/pricing-admin` كانوا
         * بيرجعوا **403** للمدير نفسه.
         *
         * ⭐ دلوقتي في **مصدر واحد**: `config/permissions.php`.
         * ضيف صلاحية هناك وخلاص — مش هتحتاج تفتكر مكان تاني.
         *
         * ⚠️ **PR 1.6:** ده بقى يقرأ من `config/permissions.php`
         * مش من `SeedRolesPermissions::PERMISSIONS` (اللي اتشال).
         *
         * @see config/permissions.php
         * @see \Database\Seeders\SeedRolesPermissions::permissions()
         */
        foreach (SeedRolesPermissions::permissions() as $perm) {
            Permission::updateOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                [
                    'slug' => str_replace('.', '-', $perm['name']),
                    'module' => $perm['module'],
                    'group' => $perm['group'],
                    'description' => $perm['description'],
                    'guard_name' => 'web',
                ]
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
