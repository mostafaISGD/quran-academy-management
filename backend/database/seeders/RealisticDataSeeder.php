<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class RealisticDataSeeder extends Seeder
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var int */
    private int $orgId = 1;

    /** @var int */
    private int $branchId = 1;

    /** @var int */
    private int $adminUserId = 1;

    private \Closure $log;

    public function __construct()
    {
        $this->data = require database_path('data/realistic.php');

        $this->log = function (string $msg): void {
            $this->command?->info("  $msg");
        };
    }

    public function run(): void
    {
        $this->command?->info('🚀 بدء توليد بيانات واقعية...');

        $this->wipe();
        $this->command?->info('✅ تم مسح البيانات القديمة');

        $this->seedCore();
        $this->command?->info('✅ البيانات الأساسية (منشأة، فرع، مستخدم)');

        $this->seedPrograms();
        $this->command?->info('✅ البرامج والمستويات وخطط الاشتراك');

        (new SeedTeachers())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ المعلمين');

        (new SeedStudents())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ الطلاب وأولياء الأمور');

        (new SeedSurahs())->setContainer(app())->setCommand($this->command)->run();

        (new SeedSubscriptions())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ الاشتراكات والأرصدة والسور');

        (new SeedLessons())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ الحصص والحضور والتقدم');

        (new SeedFinance())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ الفواتير والمدفوعات');

        (new SeedTeacherFinance())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ مستحقات المعلمين والرواتب والمصروفات');

        (new SeedActivity())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ الإعدادات والعملاء المحتملين والإشعارات');

        (new BackfillParentPhones())->setContainer(app())->setCommand($this->command)->run();
        $this->command?->info('✅ ربط أولياء الأمور بأرقام الهاتف');

        $this->command?->info('🎉 اكتمل توليد البيانات الأساسية!');
    }

    // ============================================================
    // مسح كل الجداول مع الحفاظ على الصلاحيات والأدوار
    // ============================================================

    private function wipe(): void
    {
        $tables = [
            'lesson_credit_transactions', 'lesson_credit_accounts', 'lesson_reschedules',
            'memorization_records', 'progress_records', 'lesson_attendance',
            'teacher_ledger_entries', 'teacher_payments', 'teacher_earnings',
            'payroll_periods', 'expenses', 'expense_categories',
            'refunds', 'student_ledger_entries', 'payments', 'invoice_items', 'invoices',
            'subscription_pauses', 'subscriptions', 'lessons',
            'assessments', 'leads',
            'notifications', 'audit_logs', 'settings',
            'student_goals', 'student_phones', 'student_parents',
            'teacher_ratings', 'teacher_rates', 'teacher_contracts', 'teachers',
            'students', 'parents', 'subscription_plans', 'levels', 'programs', 'quran_surahs',
            'personal_access_tokens', 'users', 'branches',
        ];

        DB::statement('PRAGMA foreign_keys = OFF');
        foreach ($tables as $table) {
            DB::table($table)->delete();
        }
        DB::table('organizations')->where('id', 1)->delete();
        DB::statement('PRAGMA foreign_keys = ON');
    }

    // ============================================================
    // 1) المنشأة + الفرع + مستخدم الأدمن
    // ============================================================

    private function seedCore(): void
    {
        DB::table('organizations')->insert([
            'id' => 1,
            'name' => 'أكاديمية القرآن للقرآن الكريم',
            'slug' => 'quran-academy',
            'legal_name' => 'أكاديمية القرآن للقرآن الكريم - مؤسسة فردية',
            'email' => 'info@quran-academy.com',
            'phone' => '0223456789',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('branches')->insert([
            [
                'id' => 1, 'organization_id' => 1, 'name' => 'الفرع الرئيسي - المعادي',
                'code' => 'MAIN', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
                'phone' => '0223456789', 'address' => 'شارع الجمهورية، المعادي، القاهرة',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => 2, 'organization_id' => 1, 'name' => 'فرع مدينة نصر',
                'code' => 'MHD', 'timezone' => 'Africa/Cairo', 'currency' => 'EGP',
                'phone' => '0227654321', 'address' => 'شارع عباس العقاد، مدينة نصر، القاهرة',
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
        $this->branchId = 1;

        // مستخدم الأدمن (اللي بنسجل بيه)
        DB::table('users')->insert([
            'id' => 1, 'organization_id' => 1, 'name' => 'مدير الأكاديمية',
            'email' => 'admin@quran-academy.com',
            'phone' => '01000000000',
            'password' => Hash::make('password'),
            'timezone' => 'Africa/Cairo', 'locale' => 'ar',
            'job_title' => 'مدير الأكاديمية', 'department' => 'الإدارة',
            'status' => 'active', 'email_verified_at' => now(), 'last_login_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->adminUserId = 1;
    }

    // ============================================================
    // 2) البرامج + المستويات + خطط الاشتراك
    // ============================================================

    private function seedPrograms(): void
    {
        $programs = [
            ['تحفيظ القرآن', 'tahfeeq', 'برنامج تحفيظ من جزء عمّ إلى كامل القرآن'],
            ['تجويد', 'tajweed', 'أحكام التجويد النموذجي للمبتدئين والمتقدمين'],
            ['تلاوة وتدبر', 'recitation', 'إتقان التلاوة الصحيحة وتدبر المعاني'],
            ['قرآن للمبتدئين', 'beginners', 'البداية من تعريف الحروف والضبط'],
            ['تسميع ثلاثي', 'sanad', 'تسميع ثلاثي بالسند المتصل'],
            ['تقوية المحفوظات', 'revision', 'مراجعة المحفوظات ورفع المستوى'],
        ];

        $now = now();
        foreach ($programs as $i => [$name, $slug, $desc]) {
            DB::table('programs')->insert([
                'id' => $i + 1, 'organization_id' => 1, 'name' => $name,
                'slug' => $slug, 'description' => $desc, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // المستويات
        $levelNames = [
            ['المستوى الأول', 'L1'], ['المستوى الثاني', 'L2'], ['المستوى الثالث', 'L3'],
            ['المستوى الرابع', 'L4'], ['المستوى الخامس', 'L5'], ['المستوى السادس', 'L6'],
        ];

        $now = now();
        $levelId = 1;
        $rows = [];
        foreach ($programs as $pi => $prog) {
            $count = $pi < 3 ? 6 : 4;
            for ($l = 0; $l < $count; $l++) {
                [$lname, $lcode] = $levelNames[$l % count($levelNames)];
                $rows[] = [
                    'id' => $levelId, 'program_id' => $pi + 1, 'name' => $lname,
                    'code' => $prog[1] . '-' . ($l + 1), 'sort_order' => $l + 1,
                    'description' => "{$prog[0]} - {$lname}",
                    'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
                ];
                $levelId++;
            }
        }
        DB::table('levels')->insert($rows);

        // خطط الاشتراك
        $plans = [
            ['باقة التحفيظ الشهرية', 1, 'monthly', 400, 'EGP', 8, 30, 30, '8 حصص شهرياً - 30 دقيقة للحصة'],
            ['باقة التحفيظ المكثفة', 1, 'monthly', 650, 'EGP', 16, 30, 30, '16 حصة شهرياً - 30 دقيقة'],
            ['باقة التجويد الشهرية', 2, 'monthly', 450, 'EGP', 8, 45, 30, '8 حصص تجويد - 45 دقيقة'],
            ['باقة تجويد مكثفة', 2, 'monthly', 750, 'EGP', 16, 45, 30, '16 حصة تجويد - 45 دقيقة'],
            ['باقة المبتدئين', 4, 'monthly', 350, 'EGP', 8, 30, 30, '8 حصص للمبتدئين'],
            ['باقة التلاوة', 3, 'monthly', 400, 'EGP', 8, 30, 30, '8 حصص تلاوة وتدبر'],
            ['باقة الحصة الواحدة', 1, 'per_lesson', 60, 'EGP', 1, 30, null, 'حصة واحدة - 30 دقيقة'],
            ['باقة الحصة الواحدة (طويلة)', 2, 'per_lesson', 90, 'EGP', 1, 45, null, 'حصة واحدة - 45 دقيقة'],
            ['باقة التقوية المكثفة', 6, 'monthly', 600, 'EGP', 12, 30, 30, '12 حصة تقوية محفوظات'],
            ['باقة تسميع ثلاثي', 5, 'monthly', 700, 'EGP', 12, 45, 30, '12 حصة تسميع بالسند'],
        ];

        $now = now();
        foreach ($plans as $i => $p) {
            [$name, $progId, $billing, $price, $cur, $count, $dur, $days, $desc] = $p;
            DB::table('subscription_plans')->insert([
                'id' => $i + 1, 'organization_id' => 1, 'program_id' => $progId,
                'name' => $name, 'billing_type' => $billing, 'price' => $price, 'currency' => $cur,
                'lessons_count' => $count, 'lesson_duration_minutes' => $dur, 'duration_days' => $days,
                'status' => 'active', 'description' => $desc, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
