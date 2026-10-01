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

class RealdataSeeder extends Seeder
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
            'phone' => '0223456789',
            'status' => 'active',
        ]);

        // ===== PROGRAMS & LEVELS =====
        $programs = [
            Program::create(['organization_id' => $org->id, 'name' => 'تحفيظ القرآن الكريم كاملاً', 'slug' => 'hifz-complete', 'description' => 'برنامج تحفيظ القرآن الكريم كاملاً مع التلاوة والتجويد', 'status' => 'active']),
            Program::create(['organization_id' => $org->id, 'name' => 'تجويد القرآن الكريم', 'slug' => 'tajweed', 'description' => 'إتقان أحكام التجويد والنطق الصحيح', 'status' => 'active']),
            Program::create(['organization_id' => $org->id, 'name' => 'تصحيح التلاوة', 'slug' => 'correction', 'description' => 'تصحيح الأخطاء الشائعة في التلاوة', 'status' => 'active']),
            Program::create(['organization_id' => $org->id, 'name' => 'مراجعة الحفظ', 'slug' => 'revision', 'description' => 'مراجعة ما تم حفظه من القرآن الكريم', 'status' => 'active']),
        ];

        $levelNames = [
            'المستوى المبتدئ' => ['تلاوة من المصحف', 'حفظ جزء عم', 'أحكام النون الساكنة'],
            'المستوى المتوسط' => ['تلاوة متوسطة', 'حفظ جزء البقرة', 'أحكام الميم الساكنة'],
            'المستوى المتقدم' => ['تلاوة متقنة', 'حفظ جزء الكهف', 'أحكام المدود'],
        ];

        $levels = [];
        foreach ($programs as $pIdx => $program) {
            foreach ($levelNames as $levelName => $subjects) {
                $levels[] = Level::create([
                    'program_id' => $program->id,
                    'name' => $levelName,
                    'code' => 'PRG' . ($pIdx + 1) . 'L' . (count(array_filter($levels, fn($l) => $l->program_id === $program->id)) + 1),
                    'sort_order' => count(array_filter($levels, fn($l) => $l->program_id === $program->id)) + 1,
                    'description' => implode(' - ', $subjects),
                    'status' => 'active',
                ]);
            }
        }

        // ===== SUBSCRIPTION PLANS =====
        $plans = [
            SubscriptionPlan::create(['organization_id' => $org->id, 'program_id' => $programs[0]->id, 'name' => 'الباقة الأسبوعية - حصتان', 'billing_type' => 'monthly', 'price' => 400, 'currency' => 'EGP', 'lessons_count' => 8, 'lesson_duration_minutes' => 30, 'duration_days' => 30, 'status' => 'active', 'description' => '8 حصص شهرياً - 30 دقيقة للحصة']),
            SubscriptionPlan::create(['organization_id' => $org->id, 'program_id' => $programs[0]->id, 'name' => 'الباقة المكثفة - 4 حصص أسبوعياً', 'billing_type' => 'monthly', 'price' => 700, 'currency' => 'EGP', 'lessons_count' => 16, 'lesson_duration_minutes' => 45, 'duration_days' => 30, 'status' => 'active', 'description' => '16 حصة شهرياً - 45 دقيقة للحصة']),
            SubscriptionPlan::create(['organization_id' => $org->id, 'program_id' => $programs[1]->id, 'name' => 'باقة التجويد المتقدمة', 'billing_type' => 'monthly', 'price' => 600, 'currency' => 'EGP', 'lessons_count' => 12, 'lesson_duration_minutes' => 60, 'duration_days' => 30, 'status' => 'active', 'description' => '12 حصة شهرياً - 60 دقيقة للحصة']),
            SubscriptionPlan::create(['organization_id' => $org->id, 'program_id' => $programs[2]->id, 'name' => 'حصة تجريبية', 'billing_type' => 'per_lesson', 'price' => 50, 'currency' => 'EGP', 'lessons_count' => null, 'lesson_duration_minutes' => 30, 'status' => 'active', 'description' => 'حصة واحدة تجريبية']),
        ];

        // ===== PARENTS =====
        $parentsData = [
            ['أحمد محمود السيد', '01012345678', 'ahmed.parent@email.com'],
            ['محمد عبدالله حسن', '01123456789', 'mohamed.parent@email.com'],
            ['سارة إبراهيم علي', '01234567890', 'sara.parent@email.com'],
            ['محمود سيد أحمد', '01098765432', 'mahmoud.parent@email.com'],
            ['فاطمة حسن محمود', '01111222333', 'fatma.parent@email.com'],
            ['خالد عمر فاروق', '01222333444', 'khaled.parent@email.com'],
            ['نورا سعيد عبدالرحمن', '01033334444', 'noura.parent@email.com'],
            ['عبدالرحمن طه مصطفى', '01144445555', 'abdulrahman.parent@email.com'],
        ];

        $parents = [];
        foreach ($parentsData as $p) {
            $parents[] = ParentModel::create([
                'organization_id' => $org->id,
                'name' => $p[0],
                'phone' => $p[1],
                'email' => $p[2],
                'country_code' => '+20',
                'preferred_language' => 'ar',
                'status' => 'active',
            ]);
        }

        // ===== TEACHERS =====
        $teachersData = [
            ['الشيخ أحمد محمد السيد', '01001112222', 'ahmed.sheikh@quran-academy.com', 'تحفيظ', 'per_lesson'],
            ['الشيخ محمد عبدالله حسن', '01002223333', 'mohamed.hassan@quran-academy.com', 'تجويد', 'monthly'],
            ['الأستاذ سيد أحمد محمود', '01003334444', 'sayed.ahmed@quran-academy.com', 'تصحيح تلاوة', 'per_lesson'],
            ['الأستاذة فاطمة محمد علي', '01004445555', 'fatma.ali@quran-academy.com', 'تحفيظ أطفال', 'monthly'],
            ['الشيخ خالد عمر فاروق', '01005556666', 'khaled.farouk@quran-academy.com', 'تجويد متقدم', 'per_lesson'],
            ['الأستاذة نورا سعيد عبدالرحمن', '01006667777', 'noura.saeed@quran-academy.com', 'مراجعة', 'monthly'],
        ];

        $teachers = [];
        $teacherUsers = [];
        foreach ($teachersData as $idx => $td) {
            $teacherUsers[] = User::create([
                'organization_id' => $org->id,
                'name' => $td[0],
                'email' => 'teacher' . ($idx + 1) . '@quran-academy.com',
                'password' => Hash::make('password'),
                'status' => 'active',
            ]);

            $teachers[] = Teacher::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'user_id' => $teacherUsers[$idx]->id,
                'teacher_code' => 'TCH-2024-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'display_name' => $td[0],
                'phone' => $td[1],
                'email' => $td[2],
                'country_code' => '+20',
                'timezone' => 'Africa/Cairo',
                'specialization' => $td[3],
                'status' => 'active',
                'joined_at' => now()->subMonths(rand(6, 24))->toDateString(),
            ]);

            TeacherContract::create([
                'teacher_id' => $teachers[$idx]->id,
                'contract_type' => $td[4],
                'start_date' => now()->subMonths(rand(6, 24))->toDateString(),
                'monthly_salary' => $td[4] === 'monthly' ? [5000, 6000, 4500, 5500, 4800, 5200][$idx] : null,
                'currency' => 'EGP',
                'status' => 'active',
            ]);

            TeacherRate::create([
                'teacher_id' => $teachers[$idx]->id,
                'rate_type' => $td[4],
                'amount' => $td[4] === 'per_lesson' ? [50, 75, 60, 55, 70, 65][$idx] : [5000, 6000, 4500, 5500, 4800, 5200][$idx],
                'currency' => 'EGP',
                'duration_minutes' => $td[4] === 'monthly' ? null : [30, 45, 30, 30, 45, 30][$idx],
                'effective_from' => now()->subMonths(rand(6, 24))->toDateString(),
            ]);
        }

        // ===== STUDENTS =====
        $studentsData = [
            ['يوسف', 'أحمد', 'السيد', 'male', 12, 'القاهرة', 'مدينة نصر'],
            ['مريم', 'محمد', 'حسن', 'female', 10, 'القاهرة', 'المعادي'],
            ['عمر', 'خالد', 'فاروق', 'male', 14, 'الجيزة', 'المهندسين'],
            ['فاطمة', 'سيد', 'أحمد', 'female', 9, 'القاهرة', 'مصر الجديدة'],
            ['علي', 'محمود', 'حسن', 'male', 16, 'الجيزة', 'فيصل'],
            ['نور', 'عبدالرحمن', 'طه', 'female', 11, 'القاهرة', 'التجمع الخامس'],
            ['أحمد', 'إبراهيم', 'علي', 'male', 13, 'الجيزة', 'الدقي'],
            ['سارة', 'عمر', 'مصطفى', 'female', 8, 'القاهرة', 'مصر القديمة'],
            ['كريم', 'فؤاد', 'عبدالله', 'male', 15, 'الجيزة', 'الهرم'],
            ['هدى', 'حامد', 'عبدالعزيز', 'female', 10, 'التجمع الخامس', 'القاهرة'],
            ['مصطفى', 'سالم', 'حسن', 'male', 17, 'المعادي', 'القاهرة'],
            ['أمل', 'كمال', 'الدين', 'female', 12, 'مدينة نصر', 'القاهرة'],
        ];

        $students = [];
        foreach ($studentsData as $idx => $sd) {
            $students[] = Student::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'student_code' => 'STU-2024-' . str_pad($idx + 1, 3, '0', STR_PAD_LEFT),
                'first_name' => $sd[0],
                'middle_name' => $sd[1],
                'last_name' => $sd[2],
                'date_of_birth' => now()->subYears($sd[4])->subMonths(rand(1, 11))->toDateString(),
                'gender' => $sd[3],
                'country_code' => '+20',
                'timezone' => 'Africa/Cairo',
                'phone' => '01' . str_pad(rand(100000000, 999999999), 9, '0'),
                'email' => $sd[0] . $idx . '@student.com',
                'status' => $idx < 9 ? 'active' : ($idx == 9 ? 'paused' : 'graduated'),
                'notes' => $sd[5] . ' - ' . $sd[6],
            ]);
        }

        // ===== SUBSCRIPTIONS =====
        $subStatuses = ['active', 'active', 'active', 'active', 'active', 'expired', 'active', 'paused'];
        $subscriptions = [];
        for ($i = 0; $i < 8; $i++) {
            $planIdx = $i % count($plans);
            $startDate = now()->subWeeks(rand(1, 8));
            $endDate = $startDate->copy()->addDays(30);
            $subscriptions[] = Subscription::create([
                'organization_id' => $org->id,
                'student_id' => $students[$i]->id,
                'plan_id' => $plans[$planIdx]->id,
                'program_id' => $plans[$planIdx]->program_id,
                'teacher_id' => $teachers[$i % count($teachers)]->id,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'billing_type' => $plans[$planIdx]->billing_type,
                'price' => $plans[$planIdx]->price,
                'currency' => 'EGP',
                'lesson_duration_minutes' => $plans[$planIdx]->lesson_duration_minutes,
                'lessons_included' => $plans[$planIdx]->lessons_count,
                'status' => $subStatuses[$i],
                'auto_renew' => $i % 3 == 0,
                'notes' => $subStatuses[$i] === 'paused' ? 'توقف مؤقت بسبب السفر' : null,
            ]);
        }

        // ===== LESSONS (this week) =====
        $lessonStatuses = ['completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'completed', 'scheduled', 'scheduled', 'scheduled', 'scheduled', 'scheduled', 'cancelled', 'completed', 'student_absent'];
        $timeSlots = [
            ['day' => 'sat', 'hour' => 16, 'min' => 0],
            ['day' => 'sun', 'hour' => 17, 'min' => 0],
            ['day' => 'mon', 'hour' => 18, 'min' => 0],
            ['day' => 'tue', 'hour' => 16, 'min' => 30],
            ['day' => 'wed', 'hour' => 17, 'min' => 30],
            ['day' => 'thu', 'hour' => 18, 'min' => 30],
            ['day' => 'fri', 'hour' => 16, 'min' => 0],
            ['day' => 'sat', 'hour' => 19, 'min' => 0],
            ['day' => 'sun', 'hour' => 20, 'min' => 0],
            ['day' => 'mon', 'hour' => 19, 'min' => 30],
        ];

        $lessons = [];
        $dayMap = ['sat' => 6, 'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5];
        for ($i = 0; $i < 25; $i++) {
            $slot = $timeSlots[$i % count($timeSlots)];
            $dayNum = $dayMap[$slot['day']];
            $hour = $slot['hour'];
            $minute = $slot['min'];

            $lessons[] = Lesson::create([
                'organization_id' => $org->id,
                'student_id' => $students[$i % 9]->id,
                'teacher_id' => $teachers[$i % count($teachers)]->id,
                'subscription_id' => $subscriptions[$i % 8]->id,
                'program_id' => $programs[$i % 4]->id,
                'level_id' => $levels[($i % 9)]->id,
                'lesson_type' => $i == 24 ? 'makeup' : ($i == 22 ? 'trial' : 'regular'),
                'scheduled_start_at' => now()->startOfWeek()->addDays($dayNum)->setTime($hour, $minute),
                'scheduled_end_at' => now()->startOfWeek()->addDays($dayNum)->setTime($hour, $minute + 30),
                'duration_minutes' => 30,
                'meeting_provider' => $i % 3 == 0 ? 'zoom' : ($i % 3 == 1 ? 'google_meet' : 'other'),
                'meeting_url' => $i % 3 == 0 ? 'https://zoom.us/j/' . rand(1000000000, 9999999999) : null,
                'status' => $lessonStatuses[$i % 15],
                'cancellation_reason' => $lessonStatuses[$i % 15] === 'cancelled' ? 'ظرف طارئ للطالب' : null,
                'notes' => $i % 5 == 0 ? 'تحفيظ سورة الفاتحة مع التجويد' : ($i % 7 == 0 ? 'مراجعة سورة البقرة' : null),
            ]);
        }

        // ===== ATTENDANCE =====
        $attStatuses = ['present', 'present', 'present', 'late', 'present', 'absent', 'present', 'late'];
        foreach ($lessons as $idx => $lesson) {
            if (in_array($lesson->status, ['completed', 'student_absent'])) {
                LessonAttendance::create([
                    'lesson_id' => $lesson->id,
                    'student_id' => $lesson->student_id,
                    'status' => $lesson->status === 'student_absent' ? 'absent' : $attStatuses[$idx % 8],
                    'late_minutes' => $attStatuses[$idx % 8] === 'late' ? rand(5, 15) : null,
                    'marked_at' => $lesson->scheduled_end_at,
                    'marked_by' => 1,
                ]);
            }
        }

        // ===== MEMORIZATION RECORDS =====
        $surahs = \App\Models\QuranSurah::all();
        $memorizationData = [
            ['الفاتحة', 1, 7], ['البقرة', 1, 10], ['البقرة', 11, 25], ['البقرة', 26, 40],
            ['آل عمران', 1, 15], ['يس', 1, 12], ['الرحمن', 1, 15], ['الملك', 1, 10],
            ['الكهف', 1, 15], ['الناس', 1, 6], ['الإخلاص', 1, 4], ['الفلق', 1, 5],
        ];

        for ($i = 0; $i < 20; $i++) {
            $surahIdx = $i % count($memorizationData);
            $surah = $surahs->firstWhere('name_ar', $memorizationData[$surahIdx][0]) ?? $surahs[$i % $surahs->count()];
            MemorizationRecord::create([
                'student_id' => $students[$i % 9]->id,
                'lesson_id' => $lessons[$i % 25]->id,
                'teacher_id' => $teachers[$i % count($teachers)]->id,
                'surah_id' => $surah->id,
                'from_ayah' => $memorizationData[$surahIdx][1],
                'to_ayah' => $memorizationData[$surahIdx][2],
                'quality' => rand(2, 5),
                'notes' => rand(0, 3) === 0 ? 'ممتاز - استمرار على نفس المستوى' : null,
                'recorded_at' => now()->subDays(rand(0, 7)),
            ]);
        }

        // ===== PROGRESS RECORDS =====
        $categories = ['memorization', 'tajweed', 'pronunciation', 'reading', 'fluency', 'revision'];
        for ($i = 0; $i < 30; $i++) {
            ProgressRecord::create([
                'student_id' => $students[$i % 12]->id,
                'lesson_id' => $lessons[$i % 25]->id,
                'teacher_id' => $teachers[$i % count($teachers)]->id,
                'program_id' => $programs[$i % 4]->id,
                'level_id' => $levels[$i % 12]->id,
                'category' => $categories[$i % 6],
                'score' => rand(20, 50) / 10,
                'notes' => $i % 10 == 0 ? 'تقدم ملحوظ في الحفظ' : null,
                'recorded_at' => now()->subDays(rand(0, 14)),
            ]);
        }

        // ===== INVOICES & PAYMENTS =====
        $paymentMethods = ['cash', 'bank_transfer', 'wallet', 'online_payment'];
        $invoiceStatuses = ['paid', 'paid', 'paid', 'partially_paid', 'issued', 'overdue'];

        for ($i = 0; $i < 8; $i++) {
            $planIdx = $i % count($plans);
            $amount = $plans[$planIdx]->price;
            $status = $invoiceStatuses[$i % count($invoiceStatuses)];
            $paidAmount = $status === 'paid' ? $amount : ($status === 'partially_paid' ? $amount / 2 : 0);

            $invoice = Invoice::create([
                'organization_id' => $org->id,
                'student_id' => $students[$i % 9]->id,
                'subscription_id' => $subscriptions[$i % 8]->id,
                'invoice_number' => 'INV-2024-' . str_pad($i + 1, 4, '0', STR_PAD_LEFT),
                'issue_date' => now()->subWeeks($i)->toDateString(),
                'due_date' => now()->subWeeks($i)->addDays(7)->toDateString(),
                'subtotal' => $amount,
                'discount' => $i % 4 == 0 ? 50 : 0,
                'tax' => 0,
                'total' => $amount - ($i % 4 == 0 ? 50 : 0),
                'paid_amount' => $paidAmount,
                'balance_due' => $amount - $paidAmount,
                'currency' => 'EGP',
                'status' => $invoiceStatuses[$i % count($invoiceStatuses)],
                'notes' => $i % 3 == 0 ? 'خصم الإخوة' : null,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => $plans[$planIdx]->name,
                'item_type' => 'subscription',
                'quantity' => 1,
                'unit_price' => $amount,
                'total' => $amount,
            ]);

            if ($paidAmount > 0) {
                Payment::create([
                    'organization_id' => $org->id,
                    'student_id' => $students[$i % 9]->id,
                    'invoice_id' => $invoice->id,
                    'amount' => $paidAmount,
                    'currency' => 'EGP',
                    'payment_method' => $paymentMethods[$i % 4],
                    'transaction_reference' => 'PAY-' . strtoupper(uniqid()),
                    'status' => 'completed',
                    'paid_at' => now()->subWeeks($i)->addDays(rand(1, 5)),
                    'received_by' => 1,
                ]);
            }
        }

        // ===== LEADS =====
        $leadsData = [
            ['أ/ يوسف أحمد محمود', '01011111111', 'جديد من إعلان فيسبوك', 'facebook', 'new'],
            ['أ/ محمد سعد عبدالله', '01022222222', 'اشترك من الموقع', 'website', 'contacted'],
            ['أ/ فاطمة علي حسن', '01033333333', 'إحالة من ولي أمر', 'referral', 'qualified'],
            ['أ/ عبدالرحمن طه مصطفى', '01044444444', 'من إنستغرام', 'instagram', 'trial_booked'],
            ['أ/ مريم خالد عمر', '01055555555', 'مكالمة هاتفية', 'other', 'trial_completed'],
            ['أ/ أحمد فؤاد سالم', '01066666666', 'من الفيسبوك', 'facebook', 'offer_sent'],
            ['أ/ سارة حامد كمال', '01077777777', 'من الموقع', 'website', 'converted'],
            ['أ/ عمرو جمال الدين', '01088888888', 'إحالة', 'referral', 'lost'],
            ['أ/ هدى مصطفى سالم', '01099999999', 'من إنستغرام', 'instagram', 'new'],
            ['أ/ كريم سامي عبدالله', '01100000000', 'من فيسبوك', 'facebook', 'contacted'],
            ['أ/ نورهان أحمد علي', '01111111111', 'من الموقع', 'website', 'qualified'],
            ['أ/ مصطفى حمدى محمود', '01122222222', 'مكالمة', 'other', 'trial_booked'],
        ];

        $leads = [];
        foreach ($leadsData as $idx => $ld) {
            $leads[] = Lead::create([
                'organization_id' => $org->id,
                'branch_id' => $branch->id,
                'full_name' => $ld[0],
                'phone' => $ld[1],
                'email' => strtolower(str_replace([' ', '/'], ['', '.'], $ld[0])) . '@email.com',
                'country_code' => '+20',
                'student_age' => rand(6, 25),
                'interested_program_id' => $programs[$idx % 4]->id,
                'source' => $ld[3],
                'assigned_staff_id' => 1,
                'status' => $ld[4],
                'notes' => $idx % 3 == 0 ? 'يحتاج متابعة بعد أسبوع' : null,
            ]);
        }

        // ===== ASSESSMENTS =====
        $assessmentResults = ['ready_to_subscribe', 'needs_follow_up', 'not_suitable', null, 'ready_to_subscribe', null];
        for ($i = 0; $i < 8; $i++) {
            Assessment::create([
                'lead_id' => $i < 6 ? $leads[$i]->id : null,
                'teacher_id' => $teachers[$i % count($teachers)]->id,
                'scheduled_at' => now()->subDays($i * 3),
                'reading_score' => rand(1, 5),
                'tajweed_score' => rand(1, 5),
                'memorization_score' => rand(1, 5),
                'recommended_level' => 'المستوى ' . ['المبتدئ', 'المتوسط', 'المتقدم'][$i % 3],
                'notes' => $i % 2 == 0 ? 'مستوى جيد جداً - يُنصح بالاشتراك' : null,
                'result' => $assessmentResults[$i % 6],
            ]);
        }

        // ===== TEACHER EARNINGS =====
        for ($i = 0; $i < 15; $i++) {
            TeacherEarning::create([
                'teacher_id' => $teachers[$i % count($teachers)]->id,
                'lesson_id' => $lessons[$i % 25]->id,
                'amount' => [50, 75, 60, 55, 70, 65][$i % 6],
                'currency' => 'EGP',
                'earning_date' => now()->subDays(rand(0, 14)),
                'status' => 'pending',
            ]);
        }

        // ===== EXPENSES =====
        ExpenseCategory::firstOrCreate(['organization_id' => $org->id, 'slug' => 'salaries'], ['name' => 'رواتب الموظفين', 'status' => 'active']);
        ExpenseCategory::firstOrCreate(['organization_id' => $org->id, 'slug' => 'hosting'], ['name' => 'استضافة موقع', 'status' => 'active']);
        ExpenseCategory::firstOrCreate(['organization_id' => $org->id, 'slug' => 'ads'], ['name' => 'إعلانات', 'status' => 'active']);
        ExpenseCategory::firstOrCreate(['organization_id' => $org->id, 'slug' => 'software'], ['name' => 'برامج', 'status' => 'active']);

        Expense::create(['organization_id' => $org->id, 'category_id' => 1, 'amount' => 30000, 'currency' => 'EGP', 'expense_date' => now()->subWeeks(1), 'description' => 'رواتب شهر سبتمبر', 'payment_method' => 'bank_transfer', 'created_by' => 1, 'status' => 'approved']);
        Expense::create(['organization_id' => $org->id, 'category_id' => 2, 'amount' => 500, 'currency' => 'EGP', 'expense_date' => now()->subWeeks(2), 'description' => 'تجديد استضافة الموقع', 'payment_method' => 'online_payment', 'created_by' => 1, 'status' => 'approved']);
        Expense::create(['organization_id' => $org->id, 'category_id' => 3, 'amount' => 2000, 'currency' => 'EGP', 'expense_date' => now()->subWeeks(3), 'description' => 'حملة إعلانية على فيسبوك', 'payment_method' => 'wallet', 'created_by' => 1, 'status' => 'approved']);

        // ===== STUDENT GOALS =====
        for ($i = 0; $i < 8; $i++) {
            StudentGoal::create([
                'student_id' => $students[$i % 9]->id,
                'program_id' => $programs[$i % 4]->id,
                'title' => ['حفظ جزء عم', 'حفظ جزء البقرة', 'إتقان أحكام المدود', 'حفظ جزء يس', 'تصحيح تلاوة الفاتحة', 'حفظ جزء الملك', 'مراجعة سورة الكهف', 'حفظ جزء الناس'][$i],
                'target_value' => [30, 60, 10, 15, 5, 10, 20, 5][$i],
                'current_value' => rand(1, [25, 40, 8, 10, 3, 8, 15, 4][$i]),
                'unit' => 'صفحة',
                'status' => 'active',
                'created_by' => 1,
            ]);
        }

        // ===== NOTIFICATIONS =====
        $eventTypes = ['lesson_reminder', 'subscription_expiring', 'subscription_expired', 'payment_received', 'makeup_created', 'trial_reminder'];
        $channels = ['in_app', 'email', 'whatsapp', 'sms'];
        $messages = [
            'تذكير: لديك حصة غداً الساعة 4:00 مساءً',
            'اشتراك الطالب أحمد ينتهي خلال 3 أيام',
            'تم استلام دفعة 500 جنيه',
            'تم إنشاء حصة تعويضية للطالب محمد',
            'تذكير بتقييم تجريبي غداً',
        ];

        for ($i = 0; $i < 12; $i++) {
            Notification::create([
                'user_id' => ($i % 6) + 1,
                'event_type' => $eventTypes[$i % 6],
                'channel' => $channels[$i % 4],
                'payload' => ['message' => $messages[$i % 5], 'student_name' => $students[$i % 9]->first_name . ' ' . $students[$i % 9]->last_name],
                'sent_at' => now()->subHours(rand(1, 48)),
                'read_at' => $i < 6 ? now()->subHours(rand(1, 24)) : null,
            ]);
        }

        // ===== SETTINGS =====
        \App\Models\Setting::set('academy_name', 'أكاديمية القرآن الكريم', $org->id);
        \App\Models\Setting::set('academy_phone', '0223456789', $org->id);
        \App\Models\Setting::set('academy_email', 'info@quran-academy.com', $org->id);
        \App\Models\Setting::set('lesson_duration_default', 30, $org->id);
        \App\Models\Setting::set('currency_default', 'EGP', $org->id);

        echo "\n=== تم إنشاء البيانات الحقيقية بنجاح ===\n";
        echo "البرامج: " . Program::count() . "\n";
        echo "المستويات: " . Level::count() . "\n";
        echo "الباقات: " . SubscriptionPlan::count() . "\n";
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
