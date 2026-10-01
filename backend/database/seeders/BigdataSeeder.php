<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Branch;
use App\Models\User;
use App\Models\Student;
use App\Models\ParentModel;
use App\Models\Teacher;
use App\Models\TeacherContract;
use App\Models\TeacherRate;
use App\Models\Program;
use App\Models\Level;
use App\Models\SubscriptionPlan;
use App\Models\Subscription;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\MemorizationRecord;
use App\Models\ProgressRecord;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Lead;
use App\Models\Assessment;
use App\Models\Notification;
use App\Models\StudentGoal;
use App\Models\TeacherEarning;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class BigdataSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();
        if (!$org) {
            $org = Organization::create([
                'name' => 'أكاديمية القرآن الكريم',
                'slug' => 'quran-academy',
                'default_currency' => 'EGP',
                'default_timezone' => 'Africa/Cairo',
                'status' => 'active',
            ]);
        }

        $branch = Branch::firstOrCreate(['organization_id' => $org->id, 'code' => 'MAIN'], [
            'name' => 'فرع مدينة نصر',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
            'status' => 'active',
        ]);

        // Programs
        $programs = [
            Program::firstOrCreate(['organization_id' => $org->id, 'slug' => 'hifz'], ['name' => 'تحفيظ القرآن الكريم', 'status' => 'active']),
            Program::firstOrCreate(['organization_id' => $org->id, 'slug' => 'tajweed'], ['name' => 'تجويد القرآن الكريم', 'status' => 'active']),
            Program::firstOrCreate(['organization_id' => $org->id, 'slug' => 'correction'], ['name' => 'تصحيح التلاوة', 'status' => 'active']),
            Program::firstOrCreate(['organization_id' => $org->id, 'slug' => 'revision'], ['name' => 'مراجعة الحفظ', 'status' => 'active']),
        ];

        // Levels
        $levels = [];
        foreach ($programs as $program) {
            for ($i = 1; $i <= 3; $i++) {
                $levels[] = Level::firstOrCreate(['program_id' => $program->id, 'code' => "L{$i}"], [
                    'name' => "مستوى $i",
                    'sort_order' => $i,
                    'status' => 'active',
                ]);
            }
        }

        // Plans
        $plans = [
            SubscriptionPlan::firstOrCreate(['organization_id' => $org->id, 'program_id' => $programs[0]->id, 'name' => 'باقة شهري - حصتان'], ['billing_type' => 'monthly', 'price' => 400, 'currency' => 'EGP', 'lessons_count' => 8, 'lesson_duration_minutes' => 30, 'duration_days' => 30, 'status' => 'active']),
            SubscriptionPlan::firstOrCreate(['organization_id' => $org->id, 'program_id' => $programs[0]->id, 'name' => 'باقة مكثفة - 4 حصص'], ['billing_type' => 'monthly', 'price' => 700, 'currency' => 'EGP', 'lessons_count' => 16, 'lesson_duration_minutes' => 45, 'duration_days' => 30, 'status' => 'active']),
            SubscriptionPlan::firstOrCreate(['organization_id' => $org->id, 'program_id' => $programs[1]->id, 'name' => 'باقة تجويد'], ['billing_type' => 'monthly', 'price' => 600, 'currency' => 'EGP', 'lessons_count' => 12, 'lesson_duration_minutes' => 60, 'duration_days' => 30, 'status' => 'active']),
            SubscriptionPlan::firstOrCreate(['organization_id' => $org->id, 'program_id' => $programs[2]->id, 'name' => 'حصة تجريبية'], ['billing_type' => 'per_lesson', 'price' => 50, 'currency' => 'EGP', 'lessons_count' => null, 'lesson_duration_minutes' => 30, 'status' => 'active']),
        ];

        // Teachers
        $teacherNames = [
            'الشيخ أحمد محمد السيد', 'الشيخ محمد عبدالله حسن', 'الأستاذ سيد أحمد محمود',
            'الأستاذة فاطمة محمد علي', 'الشيخ خالد عمر فاروق', 'الأستاذة نورا سعيد عبدالرحمن',
            'الشيخ يوسف إبراهيم علي', 'الأستاذة مريم حامد كمال', 'الشيخ عمر فاروق مصطفى',
            'الأستاذة سارة أحمد محمود', 'الشيخ حامد كمال الدين', 'الأستاذة هدى مصطفى سالم',
        ];

        $teachers = [];
        $teacherUsers = [];
        foreach ($teacherNames as $idx => $name) {
            $teacherUsers[] = User::firstOrCreate(['email' => "teacher" . ($idx + 1) . "@quran-academy.com"], [
                'organization_id' => $org->id,
                'name' => $name,
                'password' => Hash::make('password'),
                'status' => 'active',
            ]);

            $teachers[] = Teacher::firstOrCreate(['user_id' => $teacherUsers[$idx]->id], [
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'teacher_code' => 'TCH-2024-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'display_name' => $name,
                'phone' => '01' . str_pad(rand(100000000, 999999999), 9, '0'),
                'email' => "teacher" . ($idx + 1) . "@quran-academy.com",
                'specialization' => ['تحفيظ', 'تجويد', 'تصحيح تلاوة', 'مراجعة'][$idx % 4],
                'status' => 'active',
                'joined_at' => now()->subMonths(rand(6, 24))->toDateString(),
            ]);

            TeacherContract::firstOrCreate(['teacher_id' => $teachers[$idx]->id], [
                'contract_type' => $idx % 2 == 0 ? 'per_lesson' : 'monthly',
                'start_date' => now()->subMonths(rand(6, 24))->toDateString(),
                'monthly_salary' => $idx % 2 == 0 ? null : [5000, 6000, 4500, 5500, 4800, 5200][$idx % 6],
                'currency' => 'EGP',
                'status' => 'active',
            ]);

            TeacherRate::firstOrCreate(['teacher_id' => $teachers[$idx]->id], [
                'rate_type' => $idx % 2 == 0 ? 'per_lesson' : 'monthly',
                'amount' => $idx % 2 == 0 ? [50, 75, 60, 55, 70, 65][$idx % 6] : [5000, 6000, 4500, 5500, 4800, 5200][$idx % 6],
                'currency' => 'EGP',
                'effective_from' => now()->subMonths(rand(6, 24))->toDateString(),
            ]);
        }

        // Parents
        $parentNames = [
            'محمد محمود السيد', 'أحمد عبدالله حسن', 'محمود سيد أحمد', 'سارة إبراهيم علي',
            'فاطمة حسن محمود', 'خالد عمر فاروق', 'نورا سعيد عبدالرحمن', 'عبدالرحمن طه مصطفى',
            'يوسف أحمد محمود', 'مريم حامد كمال', 'عمر فاروق مصطفى', 'هدى مصطفى سالم',
            'كريم سامي عبدالله', 'أمل كمال الدين', 'مصطفى حمدى محمود', 'سارة أحمد علي',
            'محمد سعد عبدالله', 'فاطمة علي حسن', 'أحمد فؤاد سالم', 'نورا جمال الدين',
        ];

        $parents = [];
        foreach ($parentNames as $idx => $name) {
            $parents[] = ParentModel::firstOrCreate(['organization_id' => $org->id, 'phone' => '01' . str_pad(rand(100000000, 999999999), 9, '0')], [
                'name' => $name,
                'email' => strtolower(str_replace([' ', '/'], ['', '.'], $name)) . $idx . '@email.com',
                'country_code' => '+20',
                'preferred_language' => 'ar',
                'status' => 'active',
            ]);
        }

        // Link ALL students to parents (each student gets 1-2 parents)
        $allStudents = Student::all();
        foreach ($allStudents as $idx => $student) {
            // Primary parent
            $primaryParent = $parents[$idx % count($parents)];
            \Illuminate\Support\Facades\DB::table('student_parents')->insertOrIgnore([
                'student_id' => $student->id,
                'parent_id' => $primaryParent->id,
                'relationship' => ['father', 'mother', 'guardian'][rand(0, 2)],
                'is_primary' => true,
                'can_manage' => true,
                'can_pay' => true,
                'can_receive_notifications' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            // Secondary parent (for some students)
            if ($idx % 2 == 0) {
                $secondaryParent = $parents[($idx + 1) % count($parents)];
                \Illuminate\Support\Facades\DB::table('student_parents')->insertOrIgnore([
                    'student_id' => $student->id,
                    'parent_id' => $secondaryParent->id,
                    'relationship' => ['mother', 'father', 'guardian'][rand(0, 2)],
                    'is_primary' => false,
                    'can_manage' => false,
                    'can_pay' => true,
                    'can_receive_notifications' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // 100 Students with varied scenarios
        $firstNames = ['أحمد', 'محمد', 'عمر', 'يوسف', 'علي', 'خالد', 'إبراهيم', 'مصطفى', 'سارة', 'فاطمة', 'مريم', 'نورا', 'هدى', 'أمل', 'ياسمين', 'ريم', 'دينا', 'منى', 'رانيا', 'هبة'];
        $lastNames = ['محمود', 'حسن', 'علي', 'أحمد', 'عبدالله', 'إبراهيم', 'مصطفى', 'سعيد', 'فاروق', 'عمر', 'كمال', 'حامد', 'سالم', 'رمضان', 'عثمان', 'بلال', 'طارق', 'حماد', 'زيدان', 'عبدالرحمن'];
        $cities = ['القاهرة', 'الجيزة', 'الإسكندرية', 'الدقهلية', 'الشرقية', 'أسيوط', 'سوهاج', 'قنا', 'أسوان', 'بورالسعيد'];
        $statuses = ['active', 'active', 'active', 'active', 'active', 'active', 'active', 'paused', 'inactive', 'lead', 'graduated', 'archived'];
        $countries = ['+20', '+20', '+20', '+20', '+20', '+966', '+971', '+965', '+974', '+962'];

        $students = [];
        for ($i = 1; $i <= 100; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $status = $statuses[array_rand($statuses)];
            $countryCode = $countries[array_rand($countries)];

            $students[] = Student::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'student_code' => 'STU-2024-' . str_pad($i, 4, '0', STR_PAD_LEFT),
                'first_name' => $firstName,
                'middle_name' => $lastNames[array_rand($lastNames)],
                'last_name' => $lastName,
                'date_of_birth' => now()->subYears(rand(6, 25))->subMonths(rand(1, 11))->toDateString(),
                'gender' => rand(0, 1) == 0 ? 'male' : 'female',
                'country_code' => $countryCode,
                'timezone' => 'Africa/Cairo',
                'phone' => $countryCode . str_pad(rand(100000000, 999999999), 9, '0'),
                'whatsapp' => $countryCode . str_pad(rand(100000000, 999999999), 9, '0'),
                'email' => strtolower($firstName) . $i . '@student.com',
                'status' => $status,
                'notes' => $cities[array_rand($cities)],
            ]);
        }

        // Subscriptions for active students
        $subscriptions = [];
        $activeStudents = array_filter($students, fn ($s) => in_array($s->status, ['active', 'paused']));
        foreach ($activeStudents as $idx => $student) {
            $planIdx = $idx % count($plans);
            $startDate = now()->subWeeks(rand(1, 8));
            $endDate = $startDate->copy()->addDays(30);
            $subStatus = $student->status === 'paused' ? 'paused' : ($idx % 10 == 0 ? 'expired' : 'active');

            $subscriptions[] = Subscription::create([
                'organization_id' => $org->id,
                'student_id' => $student->id,
                'plan_id' => $plans[$planIdx]->id,
                'program_id' => $plans[$planIdx]->program_id,
                'teacher_id' => $teachers[$idx % count($teachers)]->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'billing_type' => $plans[$planIdx]->billing_type,
                'price' => $plans[$planIdx]->price,
                'currency' => 'EGP',
                'lesson_duration_minutes' => $plans[$planIdx]->lesson_duration_minutes,
                'lessons_included' => $plans[$planIdx]->lessons_count,
                'status' => $subStatus,
                'auto_renew' => $idx % 3 == 0,
            ]);
        }

        // Lessons: past (completed), current (today), upcoming
        $lessonStatuses = ['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'scheduled', 'scheduled', 'scheduled', 'scheduled', 'scheduled', 'cancelled', 'completed', 'student_absent'];
        $timeSlots = [
            ['hour' => 8, 'min' => 0], ['hour' => 9, 'min' => 0], ['hour' => 10, 'min' => 0],
            ['hour' => 11, 'min' => 0], ['hour' => 12, 'min' => 0], ['hour' => 14, 'min' => 0],
            ['hour' => 15, 'min' => 0], ['hour' => 16, 'min' => 0], ['hour' => 17, 'min' => 0],
            ['hour' => 18, 'min' => 0], ['hour' => 19, 'min' => 0], ['hour' => 20, 'min' => 0],
        ];

        $lessons = [];
        $lessonCount = 0;
        foreach ($subscriptions as $idx => $sub) {
            $numLessons = rand(3, 12);
            for ($j = 0; $j < $numLessons; $j++) {
                $lessonCount++;
                // Mix of past, present, and future lessons
                $daysOffset = rand(-7, 14); // -7 = future, 0 = today, 14 = past
                $slot = $timeSlots[array_rand($timeSlots)];

                if ($daysOffset > 7) {
                    $status = $lessonStatuses[array_rand($lessonStatuses)];
                } elseif ($daysOffset > 0) {
                    $status = 'completed';
                } elseif ($daysOffset == 0) {
                    $status = $idx % 3 == 0 ? 'scheduled' : ($idx % 5 == 0 ? 'in_progress' : 'scheduled');
                } else {
                    $status = 'scheduled';
                }

                $lessons[] = Lesson::create([
                    'organization_id' => $org->id,
                    'student_id' => $sub->student_id,
                    'teacher_id' => $sub->teacher_id,
                    'subscription_id' => $sub->id,
                    'program_id' => $sub->program_id,
                    'level_id' => $levels[array_rand($levels)]->id,
                    'lesson_type' => $lessonCount % 20 == 0 ? 'makeup' : ($lessonCount % 15 == 0 ? 'trial' : 'regular'),
                    'scheduled_start_at' => now()->addDays($daysOffset)->setTime($slot['hour'], $slot['min']),
                    'scheduled_end_at' => now()->addDays($daysOffset)->setTime($slot['hour'], $slot['min'] + 30),
                    'duration_minutes' => 30,
                    'meeting_provider' => $lessonCount % 3 == 0 ? 'zoom' : ($lessonCount % 3 == 1 ? 'google_meet' : 'other'),
                    'meeting_url' => $lessonCount % 3 == 0 ? 'https://zoom.us/j/' . rand(1000000000, 9999999999) : null,
                    'status' => $status,
                    'cancellation_reason' => $status === 'cancelled' ? 'ظرف طارئ' : null,
                ]);
            }
        }

        // Attendance for completed lessons
        foreach ($lessons as $lesson) {
            if (in_array($lesson->status, ['completed', 'student_absent'])) {
                LessonAttendance::create([
                    'lesson_id' => $lesson->id,
                    'student_id' => $lesson->student_id,
                    'status' => $lesson->status === 'student_absent' ? 'absent' : (rand(0, 10) == 0 ? 'late' : 'present'),
                    'late_minutes' => rand(0, 10) == 0 ? rand(5, 15) : null,
                    'marked_at' => $lesson->scheduled_end_at,
                    'marked_by' => 1,
                ]);
            }
        }

        // Memorization records
        $surahs = \App\Models\QuranSurah::all();
        for ($i = 0; $i < 150; $i++) {
            $lesson = $lessons[array_rand($lessons)];
            MemorizationRecord::create([
                'student_id' => $lesson->student_id,
                'lesson_id' => $lesson->id,
                'teacher_id' => $lesson->teacher_id,
                'surah_id' => $surahs[array_rand($surahs->all())]->id,
                'from_ayah' => rand(1, 10),
                'to_ayah' => rand(11, 30),
                'quality' => rand(2, 5),
                'recorded_at' => now()->subDays(rand(0, 30)),
            ]);
        }

        // Progress records
        $categories = ['memorization', 'tajweed', 'pronunciation', 'reading', 'fluency', 'revision'];
        for ($i = 0; $i < 200; $i++) {
            $lesson = $lessons[array_rand($lessons)];
            ProgressRecord::create([
                'student_id' => $lesson->student_id,
                'lesson_id' => $lesson->id,
                'teacher_id' => $lesson->teacher_id,
                'program_id' => $lesson->program_id,
                'level_id' => $levels[array_rand($levels)]->id,
                'category' => $categories[array_rand($categories)],
                'score' => rand(20, 50) / 10,
                'recorded_at' => now()->subDays(rand(0, 30)),
            ]);
        }

        // Invoices & Payments
        $paymentMethods = ['cash', 'bank_transfer', 'wallet', 'online_payment'];
        $invoiceStatuses = ['paid', 'paid', 'paid', 'partially_paid', 'issued', 'overdue'];

        foreach ($subscriptions as $idx => $sub) {
            if ($idx % 2 == 0) continue; // Skip some to have variety

            $amount = $plans[$sub->plan_id - 1]->price ?? 500;
            $status = $invoiceStatuses[array_rand($invoiceStatuses)];
            $paidAmount = $status === 'paid' ? $amount : ($status === 'partially_paid' ? $amount / 2 : 0);

            $invoice = Invoice::create([
                'organization_id' => $org->id,
                'student_id' => $sub->student_id,
                'subscription_id' => $sub->id,
                'invoice_number' => 'INV-2024-' . str_pad($idx + 1, 4, '0', STR_PAD_LEFT),
                'issue_date' => now()->subWeeks(rand(1, 8))->toDateString(),
                'due_date' => now()->subWeeks(rand(1, 8))->addDays(7)->toDateString(),
                'subtotal' => $amount,
                'discount' => $idx % 4 == 0 ? 50 : 0,
                'tax' => 0,
                'total' => $amount - ($idx % 4 == 0 ? 50 : 0),
                'paid_amount' => $paidAmount,
                'balance_due' => $amount - $paidAmount,
                'currency' => 'EGP',
                'status' => $status,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $plans[$sub->plan_id - 1]->name ?? 'اشتراك',
                'item_type' => 'subscription',
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ]);

            if ($paidAmount > 0) {
                Payment::create([
                    'organization_id' => $org->id,
                    'student_id' => $sub->student_id,
                    'invoice_id' => $invoice->id,
                    'amount' => $paidAmount,
                    'currency' => 'EGP',
                    'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                    'transaction_reference' => 'PAY-' . strtoupper(uniqid()),
                    'status' => 'completed',
                    'paid_at' => now()->subWeeks(rand(1, 8))->addDays(rand(1, 5)),
                    'received_by' => 1,
                ]);
            }
        }

        // Leads
        $leadStatuses = ['new', 'contacted', 'qualified', 'trial_booked', 'trial_completed', 'offer_sent', 'converted', 'lost'];
        for ($i = 0; $i < 30; $i++) {
            Lead::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'full_name' => $firstNames[array_rand($firstNames)] . ' ' . $lastNames[array_rand($lastNames)],
                'phone' => '01' . str_pad(rand(100000000, 999999999), 9, '0'),
                'email' => 'lead' . $i . '@email.com',
                'student_age' => rand(6, 25),
                'interested_program_id' => $programs[array_rand($programs)]->id,
                'source' => ['facebook', 'instagram', 'website', 'referral', 'other'][array_rand(['facebook', 'instagram', 'website', 'referral', 'other'])],
                'assigned_staff_id' => 1,
                'status' => $leadStatuses[array_rand($leadStatuses)],
            ]);
        }

        // Assessments
        $assessmentResults = ['ready_to_subscribe', 'needs_follow_up', 'not_suitable', null];
        for ($i = 0; $i < 15; $i++) {
            Assessment::create([
                'lead_id' => $i < 10 ? $i + 1 : null,
                'teacher_id' => $teachers[array_rand($teachers)]->id,
                'scheduled_at' => now()->subDays($i * 2),
                'reading_score' => rand(1, 5),
                'tajweed_score' => rand(1, 5),
                'memorization_score' => rand(1, 5),
                'recommended_level' => 'المستوى ' . ['المبتدئ', 'المتوسط', 'المتقدم'][array_rand(['المبتدئ', 'المتوسط', 'المتقدم'])],
                'result' => $assessmentResults[array_rand($assessmentResults)],
            ]);
        }

        // Teacher Earnings
        for ($i = 0; $i < 50; $i++) {
            $lesson = $lessons[array_rand($lessons)];
            TeacherEarning::create([
                'teacher_id' => $lesson->teacher_id,
                'lesson_id' => $lesson->id,
                'amount' => [50, 75, 60, 55, 70, 65][array_rand([50, 75, 60, 55, 70, 65])],
                'currency' => 'EGP',
                'earning_date' => now()->subDays(rand(0, 14)),
                'status' => 'pending',
            ]);
        }

        // Expenses
        ExpenseCategory::firstOrCreate(['organization_id' => $org->id, 'slug' => 'salaries'], ['name' => 'رواتب الموظفين', 'status' => 'active']);
        ExpenseCategory::firstOrCreate(['organization_id' => $org->id, 'slug' => 'hosting'], ['name' => 'استضافة موقع', 'status' => 'active']);
        ExpenseCategory::firstOrCreate(['organization_id' => $org->id, 'slug' => 'ads'], ['name' => 'إعلانات', 'status' => 'active']);

        $expenseData = [
            ['رواتب شهر سبتمبر', 30000, 1],
            ['تجديد استضافة الموقع', 500, 2],
            ['حملة إعلانية على فيسبوك', 2000, 3],
            ['اشتراك Zoom', 300, 2],
            ['أدوات مكتبية', 800, 1],
        ];
        foreach ($expenseData as $exp) {
            Expense::create([
                'organization_id' => $org->id,
                'category_id' => $exp[2],
                'amount' => $exp[1],
                'currency' => 'EGP',
                'expense_date' => now()->subWeeks(rand(1, 4)),
                'description' => $exp[0],
                'payment_method' => 'bank_transfer',
                'created_by' => 1,
                'status' => 'approved',
            ]);
        }

        // Student Goals
        for ($i = 0; $i < 30; $i++) {
            $student = $students[array_rand($students)];
            StudentGoal::create([
                'student_id' => $student->id,
                'program_id' => $programs[array_rand($programs)]->id,
                'title' => ['حفظ جزء عم', 'حفظ جزء البقرة', 'إتقان أحكام المدود', 'حفظ جزء يس'][array_rand(['حفظ جزء عم', 'حفظ جزء البقرة', 'إتقان أحكام المدود', 'حفظ جزء يس'])],
                'target_value' => rand(5, 30),
                'current_value' => rand(1, 20),
                'unit' => 'صفحة',
                'status' => 'active',
                'created_by' => 1,
            ]);
        }

        // Notifications
        $eventTypes = ['lesson_reminder', 'subscription_expiring', 'subscription_expired', 'payment_received', 'makeup_created', 'trial_reminder'];
        for ($i = 0; $i < 30; $i++) {
            Notification::create([
                'user_id' => rand(1, 10),
                'event_type' => $eventTypes[array_rand($eventTypes)],
                'channel' => ['in_app', 'email', 'whatsapp', 'sms'][array_rand(['in_app', 'email', 'whatsapp', 'sms'])],
                'payload' => ['message' => 'إشعار اختبار ' . $i],
                'sent_at' => now()->subHours(rand(1, 72)),
                'read_at' => rand(0, 2) == 0 ? now()->subHours(rand(1, 24)) : null,
            ]);
        }

        echo "\n=== تم إنشاء البيانات الضخمة بنجاح ===\n";
        echo "الطلاب: " . Student::count() . "\n";
        echo "المعلمين: " . Teacher::count() . "\n";
        echo "الاشتراكات: " . Subscription::count() . "\n";
        echo "الحصص: " . Lesson::count() . "\n";
        echo "الحضور: " . LessonAttendance::count() . "\n";
        echo "الفواتير: " . Invoice::count() . "\n";
        echo "المدفوعات: " . Payment::count() . "\n";
        echo "العملاء المحتملين: " . Lead::count() . "\n";
        echo "التقييمات: " . Assessment::count() . "\n";
        echo "الإشعارات: " . Notification::count() . "\n";
    }
}
