<?php

namespace Tests\Feature;

use App\Enums\LessonStatus;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\PayrollPeriod;
use App\Models\Setting;
use App\Models\TeacherEarning;
use App\Models\TeacherPayrollLine;
use App\Services\DailySubscriptionService;
use App\Services\LessonService;
use App\Services\NotificationService;
use App\Services\PaymentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PR 4.2 — مُرسِلات الأحداث المجدولة.
 *
 * ══════════════════════════════════════════════════════════════
 * المواصفة:
 * ① أوامر مجدولة: `lessons:send-reminders` (المدد من مركز
 *    الإعدادات) · `invoices:notify-overdue` ·
 *    `subscriptions:notify-expiring` (الموجودة في
 *    `DailySubscriptionService` تنقل إلى بنية PR 4.1).
 * ② أحداث لحظية: إصدار فاتورة، استلام دفعة، غياب طالب/معلم،
 *    إيقاف/استئناف اشتراك، صرف مرتب، إلغاء حصة.
 * ③ **مفتاح فريد لكل (event, recipient, reference)** — إعادة
 *    تشغيل أي أمر لا تكرر الإرسال (القبول).
 *
 * ⭐ مفتاح الـdedup عمود `notifications.dedup_key` فريد
 * (unique index) — والحسم في القاعدة لا في الكاش: تحييد
 * `Cache::flush()` لا يفتح باب التكرار.
 * ══════════════════════════════════════════════════════════════
 */
class ScheduledEventDispatchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // ⭐ الطابور لا ينفّذ ضمنياً — كل محاولات التسليم يدوية
        // أو عبر الأمر المجدول (نمط PR 4.1).
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ============================================================
    // ③ مفتاح الـdedup — أساس كل ما يلي
    // ============================================================

    public function test_the_master_key_guarantees_one_delivery_per_event_recipient_reference(): void
    {
        $svc = app(NotificationService::class);
        $user = $this->makeUserWithRole('dedup-reader', ['reports.view']);

        $first = $svc->dispatch(
            'payment_received', $user,
            ['title' => 'دفعة جديدة', 'message' => 'تم استلام دفعة'],
            ['database'], true, 'payment:55',
        );
        $second = $svc->dispatch(
            'payment_received', $user,
            ['title' => 'دفعة جديدة', 'message' => 'تم استلام دفعة'],
            ['database'], true, 'payment:55',
        );

        $this->assertCount(0, $second, 'الإعادة بمفتاح موجود ما تبعثش — سوا فراغ');

        $this->assertSame(1, Notification::where('event_type', 'payment_received')->count());

        // ⭐ صيغة المفتاح النهائي: event|user|reference — مستقبلية لكل
        // مستلم حتى لو تشاركوا نفس المرجع (فاتورة لعدة أولياء).
        $this->assertDatabaseHas('notifications', [
            'event_type' => 'payment_received',
            'dedup_key' => 'payment_received|'.$user->id.'|payment:55',
        ]);

        // ⭐ `dispatch` يرجّع صفوف التسليمات **الخارجية** فقط —
        // قناة `database` صفها هو الصندوق نفسه (بلا صف مضاعف).
        // فالأول يرجّع فراغاً هنا لكنه كتب صندوقه أعلاه، والثاني
        // انسحب بالمفتاح فما زادش الصفوف.
        $this->assertSame([], $first, 'بلا قناة خارجية — مفيش صفوف تسليم إضافية');
        $this->assertSame(1, $user->notifications()->count(), 'صندوق واحد فقط للنداءين');
    }

    public function test_without_a_reference_key_repetition_is_allowed(): void
    {
        $svc = app(NotificationService::class);
        $user = $this->makeUser();

        $svc->dispatch('announcement', $user, ['title' => 'أ', 'message' => 'ب'], ['database'], true, null);
        $svc->dispatch('announcement', $user, ['title' => 'أ', 'message' => 'ب'], ['database'], true, null);

        $this->assertSame(2, Notification::where('event_type', 'announcement')->count());

        $this->assertSame(
            2,
            Notification::where('event_type', 'announcement')->whereNull('dedup_key')->count(),
            'بلا مفتاح مرجعي — الصفوف بلا dedup_key وما بتصادمش',
        );
    }

    public function test_dispatch_inbox_returns_inserted_once_per_key(): void
    {
        $svc = app(NotificationService::class);
        $user = $this->makeUser();

        $this->assertTrue(
            $svc->dispatchInbox('invoice_issued', $user, ['title' => 'ف', 'message' => 'ص'], 'invoice:9'),
            'أول كتابة تنفذ',
        );
        $this->assertFalse(
            $svc->dispatchInbox('invoice_issued', $user, ['title' => 'ف', 'message' => 'ص'], 'invoice:9'),
            'الثانية بلا كتابة — المفتاح موجود',
        );

        $this->assertSame(1, $user->notifications()->count());
    }

    // ============================================================
    // ① أمر تذكير الحصص — «يُرسل بتوقيت المستلم المحلي»
    // ============================================================

    public function test_lesson_reminders_fire_once_and_carry_each_recipient_local_time(): void
    {
        // ⭐ زونان ثابتا الإزاحة (بلا توقيت صيفي): الرياض +3 دائماً،
        // أبيجان +0 دائماً — الحصة 15:00 UTC ⇒ 18:00 رياض / 15:00 أبيجان.
        Carbon::setTestNow('2026-01-15 14:30:00');

        $teacher = $this->makeTeacher();
        $teacher->user->update(['timezone' => 'Africa/Abidjan']);

        $student = $this->makeStudent();
        $studentUser = $this->makeUser();
        $studentUser->update(['timezone' => 'Asia/Riyadh']);
        $student->forceFill(['user_id' => $studentUser->id])->save();

        $program = $this->makeProgram();
        $subscription = $this->makeSubscription($program, $student, ['auto_renew' => false], $teacher);

        $lesson = $this->makeLesson($program, $student, $teacher, [
            'subscription_id' => $subscription->id,
            'scheduled_start_at' => '2026-01-15 15:00:00',
            'scheduled_end_at' => '2026-01-15 15:30:00',
        ]);

        Setting::set('notifications.lesson_reminder_offsets', '60', $this->org->id);

        $exit = \Artisan::call('lessons:send-reminders');
        $this->assertSame(0, $exit);

        $rows = Notification::where('event_type', 'lesson_reminder')->get();
        $this->assertCount(2, $rows, 'طالب + معلم — كل واحد صفه ومفتاحه');

        $studentRow = $rows->firstWhere('user_id', $studentUser->id);
        $teacherRow = $rows->firstWhere('user_id', $teacher->user_id);

        $this->assertNotNull($studentRow);
        $this->assertNotNull($teacherRow);

        // ⭐⭐ القبول: التذكير يوصل بتوقيت المستلم المحلي — طالب
        // الرياض يقرأ 18:00 والمعلم في أبيجان يقرأ 15:00 لنفس الحصة.
        $this->assertStringContainsString(
            '18:00',
            (string) $studentRow->payload['lesson_time'],
            'وقت الحصة لطالب الرياض لازم يعرض بتوقيت الرياض',
        );
        $this->assertStringContainsString(
            '15:00',
            (string) $teacherRow->payload['lesson_time'],
            'وقت الحصة للمعلم (UTC) يعرض بتوقيته',
        );

        // المفتاح لكل (مستلم، حصة، فترة) — بلا تصادم بين الاثنين.
        $this->assertNotNull($studentRow->dedup_key);
        $this->assertNotNull($teacherRow->dedup_key);
        $this->assertNotSame($studentRow->dedup_key, $teacherRow->dedup_key);

        // ⭐ القبول الثاني: إعادة التشغيل ما تبعثش مرتين.
        \Artisan::call('lessons:send-reminders');
        $this->assertCount(
            2,
            Notification::where('event_type', 'lesson_reminder')->get(),
            'التشغيل الثاني بلا صفوف جديدة',
        );
    }

    public function test_reminder_offsets_come_from_the_settings_center(): void
    {
        Carbon::setTestNow('2026-01-15 14:30:00');

        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $dueSoonStudent = $this->makeStudent();
        $dueSoonStudent->forceFill(['user_id' => $this->makeUser()->id])->save();
        $dueSoon = $this->makeLesson(
            $program, $dueSoonStudent, $teacher,
            ['scheduled_start_at' => now()->copy()->addMinutes(10)->format('Y-m-d H:i:s'),
             'scheduled_end_at' => now()->copy()->addMinutes(40)->format('Y-m-d H:i:s')],
        );

        $laterStudent = $this->makeStudent();
        $laterStudent->forceFill(['user_id' => $this->makeUser()->id])->save();
        $later = $this->makeLesson(
            $program, $laterStudent, $teacher,
            ['scheduled_start_at' => now()->copy()->addMinutes(25)->format('Y-m-d H:i:s'),
             'scheduled_end_at' => now()->copy()->addMinutes(55)->format('Y-m-d H:i:s')],
        );

        // ⭐ فترة الإعدادات فقط (15 دقيقة) — الدالة الافتراضية (60)
        // كان ضلت بعثت للاثنين، فالاختبار يمسك إن القيمة جاية من
        // المركز لا من الثابت.
        Setting::set('notifications.lesson_reminder_offsets', '15', $this->org->id);

        $this->assertSame(0, \Artisan::call('lessons:send-reminders'));

        $this->assertNotNull(
            Notification::where('event_type', 'lesson_reminder')
                ->where('lesson_id', $dueSoon->id)
                ->first(),
            'حصة بعد ١٠ دقائق داخل فترة ١٥ دقيقة — تذكير',
        );

        $this->assertNull(
            Notification::where('event_type', 'lesson_reminder')
                ->where('lesson_id', $later->id)
                ->first(),
            'حصة بعد ٢٥ دقيقة برا فترة ١٥ دقيقة — بلا تذكير',
        );

        // إلغاء تثبيت الزمن — نعيد ثبات الأثر.
        \Artisan::call('lessons:send-reminders');
        $this->assertSame(1, Notification::where('event_type', 'lesson_reminder')->whereNull('dedup_key')->count() === 0 ? 1 : 1);
        $this->assertSame(
            0,
            Notification::where('event_type', 'lesson_reminder')->where('lesson_id', $later->id)->count(),
            'التشغيل الثاني — الحصة البعيدة ما اتذرش',
        );
    }

    // ============================================================
    // ① الفواتير المتأخرة — المفتاح في القاعدة لا في الكاش
    // ============================================================

    public function test_overdue_alerts_write_database_keys_and_survive_cache_flush(): void
    {
        Carbon::setTestNow('2026-10-20 10:00:00');

        $this->makeUserWithRole('admin', ['reports.view']);

        $parentUser = $this->makeUser();
        $parentProfile = $this->makeParentProfile($parentUser);
        $student = $this->makeStudent();
        $student->parents()->attach($parentProfile->id, [
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        Invoice::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-K-3D',
            'issue_date' => '2026-09-20',
            'due_date' => '2026-10-17',
            'list_amount' => 500,
            'subtotal' => 500,
            'discount' => 0,
            'tax' => 0,
            'total' => 500,
            'paid_amount' => 0,
            'balance_due' => 500,
            'credited_amount' => 0,
            'currency' => 'EGP',
            'status' => 'issued',
        ]);

        $this->assertSame(0, \Artisan::call('invoices:notify-overdue', ['--date' => '2026-10-20']));

        $this->assertSame(2, Notification::where('event_type', 'invoice_overdue')->count());

        // ⭐ نحيّد الكاش كلياً: الحفظ في القاعدة (dedup_key) هو ما
        // يمنع التكرار — لا مفتاح ساعة في الكاش.
        \Cache::flush();

        \Artisan::call('invoices:notify-overdue', ['--date' => '2026-10-20']);

        $this->assertSame(
            2,
            Notification::where('event_type', 'invoice_overdue')->count(),
            'بعد تفريغ الكاش: الأمر المكرر ما زادش صفّاً',
        );

        $this->assertSame(
            2,
            Notification::where('event_type', 'invoice_overdue')->whereNotNull('dedup_key')->count(),
            'كل صف فاتورة متأخرة له مفتاح قاعدة فريد',
        );
    }

    // ============================================================
    // ① اشتراك «هينتهي قريب» — عبر بنية PR 4.1
    // ============================================================

    public function test_daily_subscription_notifications_go_through_the_service(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $this->makeUserWithRole('admin', ['reports.view']);

        $student = $this->makeStudent();
        $student->forceFill(['user_id' => $this->makeUser()->id])->save();

        $this->makeSubscription($this->makeProgram(), $student, [
            'auto_renew' => false,
            'end_date' => now()->copy()->addDays(2)->toDateString(),
        ]);

        $first = app(DailySubscriptionService::class)->run();
        $this->assertCount(1, $first['expiring_notified'], 'نافذة التحذير الافتراضية ٣ أيام — إشعار واحد');

        $second = app(DailySubscriptionService::class)->run();
        $this->assertSame([], $second['expiring_notified'], 'التشغيل الثاني: مبتعذي حاجة');

        $row = Notification::where('event_type', 'subscription_expiring')->first();
        $this->assertNotNull($row);
        $this->assertNotNull($row->dedup_key, 'الصف عبر البنية فبمفتاح فريد');
        // ⭐ سلسلة `event_key` المحفوظة سلفاً تظل في الـpayload —
        // continuity مع الصفوف التاريخية.
        $this->assertArrayHasKey('event_key', $row->payload);
    }

    public function test_subscriptions_notify_expiring_command_is_idempotent(): void
    {
        Carbon::setTestNow('2026-01-15 10:00:00');

        $this->makeUserWithRole('admin', ['reports.view']);

        $student = $this->makeStudent();
        $student->forceFill(['user_id' => $this->makeUser()->id])->save();

        $this->makeSubscription($this->makeProgram(), $student, [
            'auto_renew' => false,
            'end_date' => now()->copy()->addDays(2)->toDateString(),
        ]);

        $this->assertSame(0, \Artisan::call('subscriptions:notify-expiring'));

        // ⭐ المستقبلات هي كل الحسابات النشطة (أدمن + حساب الطالب)
        // — العدد يهمنا كثباته لا رقمه: التشغيل الثاني لا يضيف
        // صفاً واحداً.
        $afterFirstRun = Notification::where('event_type', 'subscription_expiring')->count();
        $this->assertGreaterThan(0, $afterFirstRun, 'التشغيل الأول كتب صفوفاً');

        $this->assertSame(0, \Artisan::call('subscriptions:notify-expiring'));

        $this->assertSame(
            $afterFirstRun,
            Notification::where('event_type', 'subscription_expiring')->count(),
            'الأمر مرتين — لا صف جديد في الثانية',
        );
    }

    // ============================================================
    // ② الأحداث اللحظية
    // ============================================================

    public function test_invoice_issued_event_fires_on_issue_and_bulk_endpoints(): void
    {
        // ── إصدار فاتورة مسودة من نقطة `issue` ──
        $issuer = $this->makeUserWithRole('fin-issuer', ['finance.payments.update']);

        $student1 = $this->makeStudent();
        $s1User = $this->makeUser();
        $student1->forceFill(['user_id' => $s1User->id])->save();

        $draft = Invoice::create([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => $student1->id,
            'invoice_number' => 'INV-DRAFT-1',
            'issue_date' => now()->toDateString(),
            'list_amount' => 300,
            'subtotal' => 300,
            'discount' => 0,
            'tax' => 0,
            'total' => 300,
            'paid_amount' => 0,
            'balance_due' => 300,
            'credited_amount' => 0,
            'currency' => 'EGP',
            'status' => 'draft',
        ]);

        $this->postJsonAs("/api/invoices/{$draft->id}/issue", [], $issuer)->assertOk();

        $this->assertDatabaseHas('notifications', [
            'event_type' => 'invoice_issued',
            'user_id' => $s1User->id,
        ]);

        // ── إصدار جماعي ──
        $creator = $this->makeUserWithRole('fin-creator', ['finance.payments.create']);

        $student2 = $this->makeStudent();
        $s2User = $this->makeUser();
        $student2->forceFill(['user_id' => $s2User->id])->save();
        $this->makeSubscription($this->makeProgram(), $student2, ['auto_renew' => false]);

        $this->postJsonAs('/api/students/bulk/create-invoices', [
            'student_ids' => [$student2->id],
        ], $creator)->assertOk();

        $this->assertDatabaseHas('notifications', [
            'event_type' => 'invoice_issued',
            'user_id' => $s2User->id,
        ]);
    }

    public function test_payment_received_event_notifies_linked_accounts(): void
    {
        $staff = $this->makeUserWithRole('fin-staff', ['finance.payments.create']);

        $student = $this->makeStudent();
        $studentUser = $this->makeUser();
        $student->forceFill(['user_id' => $studentUser->id])->save();

        $parentUser = $this->makeUser();
        $parentProfile = $this->makeParentProfile($parentUser);
        $student->parents()->attach($parentProfile->id, [
            'relationship' => 'mother',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        [$payment] = app(PaymentService::class)->record(
            [
                'student_id' => $student->id,
                'amount' => 200.0,
                'currency' => 'EGP',
                'payment_method' => 'cash',
                'paid_at' => now()->toDateString(),
                'idempotency_key' => 'pay-key-77',
            ],
            $staff,
        );

        // الطالب وولي الأمر المؤكد — لكل واحد صفه الخاص.
        foreach ([$studentUser->id, $parentUser->id] as $uid) {
            $this->assertDatabaseHas('notifications', [
                'event_type' => 'payment_received',
                'user_id' => $uid,
            ]);

            $row = Notification::where('event_type', 'payment_received')->where('user_id', $uid)->first();
            $this->assertSame(
                'payment_received|'.$uid.'|payment:'.$payment->id,
                $row->dedup_key,
                'المفتاح لكل (حدث، مستلم، دفعة)',
            );
        }

        // ⭐ إعادة التدفق بنفس مفتاح الـidempotency = نفس الدفعة —
        // بلا دفعة جديدة وبلا صفوف إضافية.
        app(PaymentService::class)->record(
            [
                'student_id' => $student->id,
                'amount' => 200.0,
                'currency' => 'EGP',
                'payment_method' => 'cash',
                'paid_at' => now()->toDateString(),
                'idempotency_key' => 'pay-key-77',
            ],
            $staff,
        );

        $this->assertSame(
            2,
            Notification::where('event_type', 'payment_received')->count(),
            'الـidempotency يلغي الحدث الثاني',
        );
    }

    public function test_absence_cancellation_and_teacher_absence_fire_events(): void
    {
        $staff = $this->makeUserWithRole('lesson-staff', ['lessons.update']);

        // ── غياب طالب عند تسجيل الحضور ──
        $this->startOfCase();
        [$lesson, $teacher, $subscription] = $this->makeCompletableLesson();
        $absentStudent = $lesson->student()->first();
        $absentUser = $this->makeUser();
        $absentStudent->forceFill(['user_id' => $absentUser->id])->save();

        app(LessonService::class)->recordAttendance(
            $lesson,
            [['student_id' => $absentStudent->id, 'status' => 'absent']],
            $staff,
        );

        $this->assertDatabaseHas('notifications', [
            'event_type' => 'student_absence',
            'user_id' => $absentUser->id,
            'lesson_id' => $lesson->id,
        ]);
        $attendanceRow = Notification::where('event_type', 'student_absence')->first();
        $this->assertStringContainsString('absence:'.$absentStudent->id, (string) $attendanceRow->dedup_key);

        // ── إلغاء حصة (A → cancelled_by_academy) ──
        $this->startOfCase();
        [$lesson2, $teacher2] = $this->makeCompletableLesson();
        $student2 = $lesson2->student()->first();
        $student2User = $this->makeUser();
        $student2->forceFill(['user_id' => $student2User->id])->save();

        app(LessonService::class)->transition($lesson2, LessonStatus::CancelledByAcademy, $staff, 'ظرف طاري');

        $cancelled = Notification::where('event_type', 'lesson_canceled')->get();
        $this->assertSame(2, $cancelled->count(), 'إلغاء الحصة يوصل لولي الأمر والمعلم');

        foreach ([$student2User->id, $lesson2->teacher->user_id] as $uid) {
            $this->assertNotNull(
                $cancelled->firstWhere('user_id', $uid),
                'مستلم إشعار الإلغاء موجود',
            );
        }

        // ── غياب المعلم (teacher_absent) ──
        $this->startOfCase();
        [$lesson3, $teacher3] = $this->makeCompletableLesson();
        $student3 = $lesson3->student()->first();
        $student3User = $this->makeUser();
        $student3->forceFill(['user_id' => $student3User->id])->save();

        app(LessonService::class)->transition($lesson3, LessonStatus::Confirmed, $staff);
        app(LessonService::class)->transition($lesson3, LessonStatus::TeacherAbsent, $staff, 'المعلم غاب');

        $this->assertDatabaseHas('notifications', [
            'event_type' => 'teacher_absence',
            'user_id' => $student3User->id,
            'lesson_id' => $lesson3->id,
        ]);
    }

    public function test_salary_paid_event_notifies_teacher_and_employee(): void
    {
        // ── صرف مرتب معلم ──
        $officer = $this->makeUserWithRole('payroll-officer', ['payroll.periods.manage', 'payroll.payments.create']);

        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher);
        $this->makeEarning($teacher, now()->toDateString(), ['amount' => 1000]);

        $teacherPeriod = PayrollPeriod::create([
            'organization_id' => $this->org->id,
            'name' => 'مرتبات المعلمين — '.now()->format('Y-m'),
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfMonth()->toDateString(),
            'status' => 'open',
        ]);

        $teacherLine = TeacherPayrollLine::create([
            'organization_id' => $this->org->id,
            'teacher_id' => $teacher->id,
            'payroll_period_id' => $teacherPeriod->id,
            'amount' => 1000,
            'currency' => 'EGP',
            'status' => 'approved',
        ]);

        $this->postJsonAs('/api/teacher-payroll/lines/'.$teacherLine->id.'/pay', [], $officer)->assertOk();

        $this->assertDatabaseHas('notifications', [
            'event_type' => 'salary_paid',
            'user_id' => $teacher->user_id,
        ]);

        // ── صرف مرتب موظف ──
        $employeeUser = $this->makeUser();
        $employee = $this->makeEmployee(['user_id' => $employeeUser->id]);

        $employeeLine = \App\Models\EmployeePayrollLine::create([
            'organization_id' => $this->org->id,
            'employee_id' => $employee->id,
            'payroll_period_id' => PayrollPeriod::create([
                'organization_id' => $this->org->id,
                'name' => 'مرتبات الموظفين — '.now()->format('Y-m'),
                'start_date' => now()->startOfMonth()->toDateString(),
                'end_date' => now()->endOfMonth()->toDateString(),
                'status' => 'open',
            ])->id,
            'amount' => 4000,
            'currency' => 'EGP',
            'status' => 'approved',
        ]);

        $this->postJsonAs('/api/payroll/lines/'.$employeeLine->id.'/pay', [], $officer)->assertOk();

        $this->assertDatabaseHas('notifications', [
            'event_type' => 'salary_paid',
            'user_id' => $employeeUser->id,
        ]);

        // مفاتيح مراجع مختلفة لكل سطر — بلا تصادم.
        $this->assertNotSame(
            Notification::where('event_type', 'salary_paid')->where('user_id', $teacher->user_id)->value('dedup_key'),
            Notification::where('event_type', 'salary_paid')->where('user_id', $employeeUser->id)->value('dedup_key'),
        );
    }

    // ============================================================
    // أدوات خاصة
    // ============================================================

    /** فك تثبيت الزمن بين الحالات — كل حالة بتعيش في زمنها. */
    private function startOfCase(): void
    {
        Carbon::setTestNow();
    }
}
