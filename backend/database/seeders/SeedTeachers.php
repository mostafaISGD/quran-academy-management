<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * يولّد 15 معلم مع مستخدماتهم وعقودهم وأسعارهم.
 * يعتمد على وجود: organizations, branches, programs (من RealisticDataSeeder).
 */
class SeedTeachers extends Seeder
{
    private const N = 15;

    /** @var array<string, mixed> */
    private array $d;

    public function __construct()
    {
        $this->d = require database_path('data/realistic.php');
    }

    public function run(): void
    {
        mt_srand(20260101); // نتائج ثابتة

        $now = now();
        $users = [];
        $teachers = [];
        $contracts = [];
        $rates = [];

        for ($i = 1; $i <= self::N; $i++) {
            $gender = $i % 3 === 0 ? 'female' : 'male';
            $first = $this->pick($this->d[$gender . '_first']);
            $last = $this->pick($this->d['last']);
            $name = "{$first} {$last}";

            $phone = $this->phone();
            $email = $this->slugify($first . '.' . $last) . $i . '@quran-academy.com';
            $userId = 100 + $i;

            // 13 من 15 نشط، والباقي غير نشط
            $status = $i <= 13 ? 'active' : 'inactive';

            $users[] = [
                'id' => $userId, 'organization_id' => 1, 'name' => $name,
                'email' => $email, 'phone' => $phone,
                'password' => Hash::make('password'),
                'timezone' => 'Africa/Cairo', 'locale' => 'ar',
                'job_title' => 'معلم قرآن', 'department' => 'الأكاديمية',
                'status' => $status === 'active' ? 'active' : 'inactive',
                'email_verified_at' => $now, 'last_login_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ];

            // سنة انضمام عشوائية بين 2023 و 2026
            $joinedYear = 2023 + ($i % 4);
            $joinedMonth = 1 + ($i % 12);
            $joinedDay = 1 + ($i % 27);

            $teachers[] = [
                'id' => $i, 'organization_id' => 1,
                'branch_id' => ($i % 4 === 0) ? 2 : 1, // ربعهم في الفرع الثاني
                'user_id' => $userId,
                'teacher_code' => sprintf('TCH-%03d', $i),
                'display_name' => $name,
                'phone' => $phone,
                'email' => $email,
                'country_code' => '+20',
                'timezone' => 'Africa/Cairo',
                'specialization' => $this->d['teacher_specializations'][$i % count($this->d['teacher_specializations'])],
                'status' => $status,
                'joined_at' => sprintf('%d-%02d-%02d', $joinedYear, $joinedMonth, $joinedDay),
                'avatar_url' => null,
                'qualifications' => $this->d['teacher_qualifications'][$i % count($this->d['teacher_qualifications'])],
                'years_of_experience' => 3 + ($i % 12),
                'languages' => $this->d['teacher_languages'][$i % count($this->d['teacher_languages'])],
                'bio' => $this->d['teacher_bio'][$i % count($this->d['teacher_bio'])],
                'notes' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];

            // العقد: 9 شهري و 6 لكل حصة
            $perLesson = ($i % 3 === 0);
            $contracts[] = [
                'id' => $i, 'teacher_id' => $i,
                'contract_type' => $perLesson ? 'per_lesson' : 'monthly',
                'start_date' => sprintf('%d-%02d-%02d', $joinedYear, $joinedMonth, $joinedDay),
                'end_date' => null,
                'monthly_salary' => $perLesson ? null : (3500 + ($i % 5) * 500),
                'currency' => 'EGP',
                'status' => $status === 'active' ? 'active' : 'expired',
                'notes' => null, 'created_at' => $now, 'updated_at' => $now,
            ];

            // سعر الحصة
            $rateAmount = $perLesson ? (45 + ($i % 4) * 10) : (3500 + ($i % 5) * 500);
            $rates[] = [
                'id' => $i, 'teacher_id' => $i,
                'rate_type' => $perLesson ? 'per_lesson' : 'monthly',
                'amount' => $rateAmount, 'currency' => 'EGP',
                'duration_minutes' => $perLesson ? 30 : null,
                'effective_from' => sprintf('%d-%02d-%02d', $joinedYear, $joinedMonth, $joinedDay),
                'effective_to' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($users, 20) as $chunk) {
            DB::table('users')->insert($chunk);
        }
        foreach (array_chunk($teachers, 20) as $chunk) {
            DB::table('teachers')->insert($chunk);
        }
        DB::table('teacher_contracts')->insert($contracts);
        DB::table('teacher_rates')->insert($rates);

        $this->command?->info('   → ' . self::N . ' معلم (+ مستخدمين + عقود + أسعار)');
    }

    private function pick(array $arr): string
    {
        return $arr[mt_rand(0, count($arr) - 1)];
    }

    /** رقم هاتف مصري: 01x + 8 أرقام */
    private function phone(): string
    {
        $prefix = ['010', '011', '012', '015'][mt_rand(0, 3)];
        $rest = mt_rand(10000000, 99999999);
        return $prefix . $rest;
    }

    private function slugify(string $name): string
    {
        $map = [
            'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ا' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th',
            'ج' => 'g', 'ح' => 'h', 'خ' => 'kh', 'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z',
            'س' => 's', 'ش' => 'sh', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z', 'ع' => 'a',
            'غ' => 'gh', 'ف' => 'f', 'ق' => 'q', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n',
            'ه' => 'h', 'و' => 'w', 'ي' => 'y', 'ى' => 'a', 'ة' => 'a', 'ء' => '', ' ' => '',
        ];
        $out = '';
        foreach (preg_split('//u', $name, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
            $out .= $map[$ch] ?? $ch;
        }
        return $out;
    }
}