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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TestdataSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::first();
        if (!$org) {
            $org = Organization::create([
                'name' => 'أكاديمية القرآن',
                'slug' => 'quran-academy',
                'default_currency' => 'EGP',
                'default_timezone' => 'Africa/Cairo',
                'status' => 'active',
            ]);
        }

        $branch = Branch::firstOrCreate(['organization_id' => $org->id, 'code' => 'MAIN'], [
            'name' => 'الفرع الرئيسي',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
            'status' => 'active',
        ]);

        // Create users
        $users = [];
        for ($i = 1; $i <= 5; $i++) {
            $users[] = User::create([
                'organization_id' => $org->id,
                'name' => "مستخدم $i",
                'email' => "user$i@test.com",
                'password' => Hash::make('password'),
                'status' => 'active',
            ]);
        }

        // Create programs
        $programs = [
            Program::create(['organization_id' => $org->id, 'name' => 'تحفيظ القرآن', 'slug' => 'hifz', 'status' => 'active']),
            Program::create(['organization_id' => $org->id, 'name' => 'تجويد', 'slug' => 'tajweed', 'status' => 'active']),
            Program::create(['organization_id' => $org->id, 'name' => 'تصحيح التلاوة', 'slug' => 'correction', 'status' => 'active']),
        ];

        // Create levels
        $levels = [];
        foreach ($programs as $program) {
            for ($i = 1; $i <= 3; $i++) {
                $levels[] = Level::create([
                    'program_id' => $program->id,
                    'name' => "مستوى $i",
                    'code' => "L$i",
                    'sort_order' => $i,
                    'status' => 'active',
                ]);
            }
        }

        // Create subscription plans
        $plans = [
            SubscriptionPlan::create(['organization_id' => $org->id, 'program_id' => $programs[0]->id, 'name' => 'باقة شهري', 'billing_type' => 'monthly', 'price' => 500, 'currency' => 'EGP', 'lessons_count' => 4, 'lesson_duration_minutes' => 30, 'duration_days' => 30, 'status' => 'active']),
            SubscriptionPlan::create(['organization_id' => $org->id, 'program_id' => $programs[0]->id, 'name' => 'باقة بالحصة', 'billing_type' => 'per_lesson', 'price' => 50, 'currency' => 'EGP', 'lessons_count' => null, 'lesson_duration_minutes' => 30, 'status' => 'active']),
            SubscriptionPlan::create(['organization_id' => $org->id, 'program_id' => $programs[1]->id, 'name' => 'باقة تجويد متقدمة', 'billing_type' => 'monthly', 'price' => 800, 'currency' => 'EGP', 'lessons_count' => 8, 'lesson_duration_minutes' => 45, 'duration_days' => 30, 'status' => 'active']),
        ];

        // Create parents
        $parents = [];
        for ($i = 1; $i <= 3; $i++) {
            $parents[] = ParentModel::create([
                'organization_id' => $org->id,
                'name' => "ولي أمر $i",
                'phone' => "010000000$i",
                'email' => "parent$i@test.com",
                'status' => 'active',
            ]);
        }

        // Create teachers
        $teachers = [];
        for ($i = 1; $i <= 4; $i++) {
            $teachers[] = Teacher::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'user_id' => $users[$i - 1]->id,
                'teacher_code' => "TCH-00$i",
                'display_name' => "معلم $i",
                'phone' => "010000000$i",
                'email' => "teacher$i@test.com",
                'specialization' => $i <= 2 ? 'تحفيظ' : 'تجويد',
                'status' => 'active',
            ]);

            TeacherContract::create([
                'teacher_id' => $teachers[$i - 1]->id,
                'contract_type' => $i <= 2 ? 'per_lesson' : 'monthly',
                'start_date' => now()->subMonths(6)->toDateString(),
                'monthly_salary' => $i <= 2 ? null : 5000,
                'currency' => 'EGP',
                'status' => 'active',
            ]);

            TeacherRate::create([
                'teacher_id' => $teachers[$i - 1]->id,
                'rate_type' => $i <= 2 ? 'per_lesson' : 'monthly',
                'amount' => $i <= 2 ? 50 : 5000,
                'currency' => 'EGP',
                'effective_from' => now()->subMonths(6)->toDateString(),
            ]);
        }

        // Create students
        $students = [];
        for ($i = 1; $i <= 10; $i++) {
            $students[] = Student::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'student_code' => "STU-00$i",
                'first_name' => "طالب",
                'last_name' => "$i",
                'date_of_birth' => now()->subYears(10 + $i)->toDateString(),
                'gender' => $i % 2 == 0 ? 'female' : 'male',
                'country_code' => '+20',
                'phone' => "010000000$i",
                'email' => "student$i@test.com",
                'status' => $i <= 5 ? 'active' : 'paused',
            ]);
        }

        // Create subscriptions
        $subscriptions = [];
        for ($i = 0; $i < 5; $i++) {
            $subscriptions[] = Subscription::create([
                'organization_id' => $org->id,
                'student_id' => $students[$i]->id,
                'plan_id' => $plans[$i % 3]->id,
                'program_id' => $plans[$i % 3]->program_id,
                'teacher_id' => $teachers[$i % 4]->id,
                'start_date' => now()->subWeeks($i)->toDateString(),
                'end_date' => now()->addWeeks(4 - $i)->toDateString(),
                'billing_type' => $plans[$i % 3]->billing_type,
                'price' => $plans[$i % 3]->price,
                'currency' => 'EGP',
                'lesson_duration_minutes' => 30,
                'lessons_included' => $plans[$i % 3]->lessons_count,
                'status' => 'active',
            ]);
        }

        // Create lessons (this week)
        $lessons = [];
        $statuses = ['scheduled', 'confirmed', 'completed', 'completed', 'completed'];
        for ($i = 0; $i < 15; $i++) {
            $dayOffset = $i % 7;
            $hour = 15 + ($i % 8);
            $lessons[] = Lesson::create([
                'organization_id' => $org->id,
                'student_id' => $students[$i % 5]->id,
                'teacher_id' => $teachers[$i % 4]->id,
                'subscription_id' => $subscriptions[$i % 5]->id,
                'program_id' => $programs[$i % 3]->id,
                'level_id' => $levels[$i % 9]->id,
                'lesson_type' => $i == 14 ? 'makeup' : 'regular',
                'scheduled_start_at' => now()->startOfWeek()->addDays($dayOffset)->setTime($hour, 0),
                'scheduled_end_at' => now()->startOfWeek()->addDays($dayOffset)->setTime($hour, 30),
                'duration_minutes' => 30,
                'status' => $statuses[$i % 5],
            ]);
        }

        // Create attendance for completed lessons
        foreach ($lessons as $lesson) {
            if (in_array($lesson->status, ['completed', 'student_absent'])) {
                LessonAttendance::create([
                    'lesson_id' => $lesson->id,
                    'student_id' => $lesson->student_id,
                    'status' => $lesson->status === 'completed' ? 'present' : 'absent',
                    'marked_at' => $lesson->scheduled_end_at,
                    'marked_by' => $lesson->teacher_id,
                ]);
            }
        }

        // Create memorization records
        $surahs = \App\Models\QuranSurah::all();
        for ($i = 0; $i < 10; $i++) {
            MemorizationRecord::create([
                'student_id' => $students[$i % 5]->id,
                'lesson_id' => $lessons[$i % 15]->id,
                'teacher_id' => $teachers[$i % 4]->id,
                'surah_id' => $surahs[$i % $surahs->count()]->id,
                'from_ayah' => 1,
                'to_ayah' => 5 + $i,
                'quality' => rand(1, 5),
                'recorded_at' => now()->subDays($i),
            ]);
        }

        // Create progress records
        $categories = ['memorization', 'tajweed', 'pronunciation', 'reading', 'fluency', 'revision'];
        for ($i = 0; $i < 15; $i++) {
            ProgressRecord::create([
                'student_id' => $students[$i % 10]->id,
                'lesson_id' => $lessons[$i % 15]->id,
                'teacher_id' => $teachers[$i % 4]->id,
                'program_id' => $programs[$i % 3]->id,
                'level_id' => $levels[$i % 9]->id,
                'category' => $categories[$i % 6],
                'score' => rand(1, 50) / 10,
                'recorded_at' => now()->subDays($i),
            ]);
        }

        // Create invoices
        for ($i = 0; $i < 5; $i++) {
            $invoice = Invoice::create([
                'organization_id' => $org->id,
                'student_id' => $students[$i]->id,
                'subscription_id' => $subscriptions[$i]->id,
                'invoice_number' => 'INV-' . strtoupper(uniqid()),
                'issue_date' => now()->subWeeks($i)->toDateString(),
                'due_date' => now()->subWeeks($i)->addDays(7)->toDateString(),
                'subtotal' => $plans[$i % 3]->price,
                'total' => $plans[$i % 3]->price,
                'balance_due' => $i < 3 ? 0 : $plans[$i % 3]->price,
                'currency' => 'EGP',
                'status' => $i < 3 ? 'paid' : 'partially_paid',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $plans[$i % 3]->name,
                'quantity' => 1,
                'unit_price' => $plans[$i % 3]->price,
                'total' => $plans[$i % 3]->price,
            ]);

            if ($i < 3) {
                Payment::create([
                    'organization_id' => $org->id,
                    'student_id' => $students[$i]->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $plans[$i % 3]->price,
                    'currency' => 'EGP',
                    'payment_method' => ['cash', 'bank_transfer', 'wallet'][$i],
                    'status' => 'completed',
                    'paid_at' => now()->subWeeks($i),
                    'received_by' => 1,
                ]);
            }
        }

        // Create leads
        $leadStatuses = ['new', 'contacted', 'qualified', 'trial_booked', 'trial_completed', 'offer_sent', 'converted', 'lost'];
        for ($i = 0; $i < 12; $i++) {
            Lead::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'full_name' => "عميل محتمل $i",
                'phone' => "010000000$i",
                'email' => "lead$i@test.com",
                'student_age' => rand(6, 25),
                'interested_program_id' => $programs[$i % 3]->id,
                'source' => ['facebook', 'instagram', 'website', 'referral', 'other'][$i % 5],
                'assigned_staff_id' => $users[0]->id,
                'status' => $leadStatuses[$i % 8],
            ]);
        }

        // Create assessments
        $assessmentResults = ['ready_to_subscribe', 'needs_follow_up', 'not_suitable', null];
        for ($i = 0; $i < 6; $i++) {
            Assessment::create([
                'lead_id' => $i < 4 ? $i + 1 : null,
                'teacher_id' => $teachers[$i % 4]->id,
                'scheduled_at' => now()->subDays($i * 2),
                'reading_score' => rand(1, 5),
                'tajweed_score' => rand(1, 5),
                'memorization_score' => rand(1, 5),
                'recommended_level' => 'مستوى ' . rand(1, 3),
                'result' => $assessmentResults[$i % 4],
            ]);
        }

        // Create teacher earnings
        for ($i = 0; $i < 8; $i++) {
            TeacherEarning::create([
                'teacher_id' => $teachers[$i % 4]->id,
                'lesson_id' => $lessons[$i % 15]->id,
                'amount' => 50,
                'currency' => 'EGP',
                'earning_date' => now()->subDays($i),
                'status' => 'pending',
            ]);
        }

        // Create student goals
        for ($i = 0; $i < 5; $i++) {
            StudentGoal::create([
                'student_id' => $students[$i]->id,
                'program_id' => $programs[$i % 3]->id,
                'title' => "حفظ جزء " . ($i + 1),
                'target_value' => 30,
                'current_value' => rand(5, 25),
                'unit' => 'سورة',
                'status' => 'active',
            ]);
        }

        // Create notifications
        $eventTypes = ['lesson_reminder', 'subscription_expiring', 'subscription_expired', 'payment_received', 'makeup_created', 'trial_reminder'];
        for ($i = 0; $i < 8; $i++) {
            Notification::create([
                'user_id' => $users[$i % 5]->id,
                'event_type' => $eventTypes[$i % 6],
                'channel' => ['in_app', 'email', 'whatsapp', 'sms'][$i % 4],
                'payload' => ['message' => "إشعار اختبار $i"],
                'sent_at' => now()->subHours($i),
                'read_at' => $i < 3 ? now()->subHours($i) : null,
            ]);
        }

        echo "Test data created successfully!\n";
        echo "Students: " . Student::count() . "\n";
        echo "Teachers: " . Teacher::count() . "\n";
        echo "Lessons: " . Lesson::count() . "\n";
        echo "Invoices: " . Invoice::count() . "\n";
        echo "Payments: " . Payment::count() . "\n";
        echo "Leads: " . Lead::count() . "\n";
        echo "Assessments: " . Assessment::count() . "\n";
    }
}
