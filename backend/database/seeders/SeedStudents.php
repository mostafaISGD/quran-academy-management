<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * يولّد 80 طالب مع أولياء الأمور وأرقام الهواتف والأهداف.
 * يعتمد على وجود: organizations, branches (من RealisticDataSeeder).
 */
class SeedStudents extends Seeder
{
    private const N = 80;

    /** @var array<string, mixed> */
    private array $d;

    public function __construct()
    {
        $this->d = require database_path('data/realistic.php');
    }

    public function run(): void
    {
        mt_srand(20260202);

        $now = now();
        $parents = [];
        $students = [];
        $links = [];
        $phones = [];
        $goals = [];

        $parentId = 0;

        for ($i = 1; $i <= self::N; $i++) {
            $isMale = ($i % 2) === 1;
            $gender = $isMale ? 'male' : 'female';

            // الاسم: بند + الأب + أحياناً الجد
            $first = $this->pick($this->d[$gender . '_first']);
            $middle = mt_rand(0, 100) < 55 ? $this->pick($this->d[$gender . '_first']) : null;
            $last = $this->pick($this->d['last']);
            $fatherLast = $this->pick($this->d['last']);

            $fullName = trim("{$first} " . ($middle ? "{$middle} " : '') . "{$last}");

            // تاريخ ميلاد: من 2008 لـ 2019
            $birthYear = 2008 + ($i % 12);
            $birthMonth = 1 + ($i % 12);
            $birthDay = 1 + ($i % 28);

            // حالة الطالب: 60 نشط، 12 متوقف، 5 تخرج، 3 غير نشط
            $roll = $i % 20;
            $status = match (true) {
                $roll < 12 => 'active',
                $roll < 15 => 'paused',
                $roll < 17 => 'graduated',
                default => 'inactive',
            };

            $studentPhone = $this->phone();

            // ولي الأمر: أب أو أم
            $parentId++;
            $isFather = mt_rand(0, 100) < 55;
            $pFirst = $isFather
                ? $this->pick($this->d['male_first'])
                : $this->pick($this->d['female_first']);
            $parentName = "{$pFirst} {$fatherLast}";
            $parentPhone = $this->phone();

            $parents[] = [
                'id' => $parentId, 'organization_id' => 1, 'user_id' => null,
                'name' => $parentName, 'phone' => $parentPhone,
                'alternate_phone' => mt_rand(0, 100) < 40 ? $this->phone() : null,
                'email' => $this->slugify($pFirst . $parentId) . '@gmail.com',
                'country_code' => '+20', 'preferred_language' => 'ar',
                'notes' => null,
                'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ];

            $students[] = [
                'id' => $i, 'organization_id' => 1,
                'branch_id' => ($i % 5 === 0) ? 2 : 1,
                'user_id' => null, 'lead_id' => null,
                'student_code' => sprintf('STU-%04d', $i),
                'first_name' => $first,
                'middle_name' => $middle,
                'last_name' => $last,
                'date_of_birth' => sprintf('%d-%02d-%02d', $birthYear, $birthMonth, $birthDay),
                'gender' => $gender,
                'country_code' => '+20',
                'timezone' => 'Africa/Cairo',
                'phone' => $studentPhone,
                'email' => null,
                'status' => $status,
                'notes' => $this->d['student_notes'][$i % count($this->d['student_notes'])],
                'created_at' => $now, 'updated_at' => $now,
            ];

            $links[] = [
                'student_id' => $i, 'parent_id' => $parentId,
                'relationship' => $isFather ? 'أب' : 'أم',
                'is_primary' => true, 'can_manage' => true, 'can_pay' => true,
                'can_receive_notifications' => true,
                'created_at' => $now, 'updated_at' => $now,
            ];

            // أرقام هواتف الطالب: الأساسي + أحياناً رقم ولي الأمر
            $phones[] = [
                'student_id' => $i, 'phone_number' => $studentPhone,
                'is_personal' => true, 'is_parent' => false,
                'is_whatsapp' => mt_rand(0, 100) < 60,
                'is_call' => true, 'is_primary' => true,
                'parent_name' => null, 'parent_relationship' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];

            if (mt_rand(0, 100) < 35) {
                $phones[] = [
                    'student_id' => $i, 'phone_number' => $parentPhone,
                    'is_personal' => false, 'is_parent' => true,
                    'is_whatsapp' => true, 'is_call' => true, 'is_primary' => false,
                    'parent_name' => $parentName,
                    'parent_relationship' => $isFather ? 'أب' : 'أم',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            // هدف واحد أو اتنين للطالب
            $goalTypes = [
                ['حفظ', 'أجزاء محفوظة', 'جزء'],
                ['تجويد', 'نسبة الإتقان', '%'],
                ['تسميع', 'عدد التسميعات', 'تسميع'],
                ['مراجعة', 'أيام المراجعة', 'يوم'],
                ['تلاوة', 'عدد التلاوات', 'تلاوة'],
            ];
            $goalCount = mt_rand(1, 100) < 55 ? 2 : 1;
            for ($g = 0; $g < $goalCount; $g++) {
                [$gtype, $title, $unit] = $goalTypes[($i + $g) % 5];
                $target = $unit === 'جزء' ? mt_rand(3, 20)
                    : ($unit === '%' ? mt_rand(60, 100) : mt_rand(8, 40));
                $current = (int) round($target * (mt_rand(20, 95) / 100));

                $goals[] = [
                    'student_id' => $i,
                    'program_id' => 1 + ($i % 6),
                    'title' => "{$title} - هدف الطالب",
                    'description' => "هدف: الوصول إلى {$target} {$unit} خلال الفترة القادمة",
                    'target_value' => $target,
                    'current_value' => $current,
                    'unit' => $unit,
                    'start_date' => now()->subDays(30)->format('Y-m-d'),
                    'target_date' => now()->addDays(30 + ($g * 45))->format('Y-m-d'),
                    'status' => $current >= $target ? 'completed' : 'active',
                    'created_by' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($parents, 25) as $chunk) {
            DB::table('parents')->insert($chunk);
        }
        foreach (array_chunk($students, 25) as $chunk) {
            DB::table('students')->insert($chunk);
        }
        foreach (array_chunk($links, 30) as $chunk) {
            DB::table('student_parents')->insert($chunk);
        }
        foreach (array_chunk($phones, 30) as $chunk) {
            DB::table('student_phones')->insert($chunk);
        }
        foreach (array_chunk($goals, 30) as $chunk) {
            DB::table('student_goals')->insert($chunk);
        }

        $this->command?->info('   → ' . self::N . ' طالب');
        $this->command?->info('   → ' . count($parents) . ' ولي أمر');
        $this->command?->info('   → ' . count($phones) . ' رقم هاتف');
        $this->command?->info('   → ' . count($goals) . ' هدف');
    }

    private function pick(array $arr): string
    {
        return $arr[mt_rand(0, count($arr) - 1)];
    }

    private function phone(): string
    {
        $prefix = ['010', '011', '012', '015'][mt_rand(0, 3)];
        return $prefix . mt_rand(10000000, 99999999);
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