<?php

namespace Tests\Feature;

use App\Models\LessonAttendance;
use App\Models\Organization;
use App\Models\Setting;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.14 — ربط تقارير الحضور والاشتراكات.**
 *
 * ⭐ **قبل PR ده** `ReportController::attendance` و`subscriptions`
 * كانا كود موجود بلا مسار (لا يمكن الوصول إليهم من الـAPI)،
 * وداخلياً كانوا يبنون التفصيل من تحميل **كل الصفوف** ثم تجميع
 * في PHP collections، وبدون أي عزل مؤسسات — لو اتوصلهم من
 * غير عزل كانت بترجّع لك بيانات أكاديميات أخرى.
 *
 * ⭐ **القبول:**
 *
 * ① المساران يعملان بصلاحية `reports.view` — والأرقام تطابق
 *    حساباً يدوياً على بيانات صغيرة.
 * ② العزل بالمؤسسة (حساب يدوي = ما يظهر فقط).
 * ③ نافذة «هتنتهي قريباً» تتبع مركز الإعدادات في الملخص
 *    وفي الراية التفصيلية (`is_expiring_soon`).
 */
class ReportsRoutesTest extends TestCase
{
    use RefreshDatabase;

    /** نافذة ثابتة داخل الشهر الحالي (لا تتأثر بطبيعة "الآن"). */
    private const FROM = '2026-10-01';

    private const TO = '2026-10-31';

    protected function makeViewer(): \App\Models\User
    {
        return $this->makeUserWithRole('report-viewer', ['reports.view']);
    }

    protected function markRow(
        \App\Models\Lesson $lesson,
        Student $student,
        \App\Models\User $by,
        string $status,
        string $markedAt,
    ): LessonAttendance {
        return LessonAttendance::create([
            'lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => $status,
            'marked_at' => $markedAt,
            'marked_by' => $by->id,
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ① مسار الحضور — أرقام تطابق الحساب اليدوي
    // ═══════════════════════════════════════════════════════════════

    public function test_the_attendance_report_route_is_wired_and_the_numbers_match(): void
    {
        $viewer = $this->makeViewer();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        // ⭐ بيانات يدوية صغيرة: طالبان × حصتان لكل طالب (صف حضور
        // واحد لكل حصة — القيد الفريد lesson_id في الجدول).
        $studentA = $this->makeStudent(['first_name' => 'أحمد', 'last_name' => 'بن عمر']);
        $studentB = $this->makeStudent(['first_name' => 'سارة', 'last_name' => 'بن علي']);

        $l1 = $this->makeLesson($program, $studentA, $teacher, [
            'scheduled_start_at' => '2026-10-05 16:00:00',
            'scheduled_end_at' => '2026-10-05 16:30:00',
            'status' => 'completed',
        ]);
        $l2 = $this->makeLesson($program, $studentA, $teacher, [
            'scheduled_start_at' => '2026-10-12 16:00:00',
            'scheduled_end_at' => '2026-10-12 16:30:00',
            'status' => 'completed',
        ]);
        $l3 = $this->makeLesson($program, $studentB, $teacher, [
            'scheduled_start_at' => '2026-10-05 17:00:00',
            'scheduled_end_at' => '2026-10-05 17:30:00',
            'status' => 'cancelled',
        ]);
        $l4 = $this->makeLesson($program, $studentB, $teacher, [
            'scheduled_start_at' => '2026-10-14 18:00:00',
            'scheduled_end_at' => '2026-10-14 18:30:00',
            'status' => 'student_absent',
        ]);

        // الطالب أ: حضر + تأخر. الطالب ب: غاب + حضر.
        $this->markRow($l1, $studentA, $viewer, 'present', '2026-10-05 16:04:00');
        $this->markRow($l2, $studentA, $viewer, 'late', '2026-10-12 16:09:00');
        $this->markRow($l3, $studentB, $viewer, 'absent', '2026-10-05 17:02:00');
        $this->markRow($l4, $studentB, $viewer, 'present', '2026-10-14 18:01:00');

        $r = $this->getJsonAs(
            '/api/reports/attendance?from='.self::FROM.'&to='.self::TO,
            $viewer,
        )->assertOk();

        $payload = $r->json();

        // ⭐ الحساب اليدوي للملخص.
        $this->assertSame(4, $payload['summary']['total_lessons_scheduled']);
        $this->assertSame(2, $payload['summary']['completed_lessons']);
        $this->assertSame(1, $payload['summary']['cancelled_lessons']);
        $this->assertSame(1, $payload['summary']['student_absent_lessons']);
        $this->assertSame(4, $payload['summary']['total_attendance_records']);
        $this->assertSame(2, $payload['summary']['present']);
        $this->assertSame(1, $payload['summary']['absent']);
        $this->assertSame(1, $payload['summary']['late']);

        // ⭐ تفصيل الطالبين (ترتيب محايد — نجدهما بالمفتاح).
        $byStudent = collect($payload['by_student'])->keyBy('student_id');
        $a = $byStudent[$studentA->id];
        $this->assertSame(2, $a['total_sessions']);
        $this->assertSame(1, $a['present']);
        $this->assertSame(1, $a['late']);
        $this->assertSame(0, $a['absent']);
        $this->assertEquals(50.0, $a['attendance_rate']);
        // ⭐ نفس دلالة «full_name» — الاسم بيتجمع من ثلاثة أجزاء
        // بلا تنظيف مسافات، فطالب من غير اسم أوسط يرجع باسمه
        // مساحة مزدوجة زي ما الـaccessor بيعمل.
        $this->assertSame($studentA->full_name, $a['student_name']);

        $b = $byStudent[$studentB->id];
        $this->assertSame(2, $b['total_sessions']);
        $this->assertEquals(50.0, $b['attendance_rate']);

        // ⭐ التفصيل اليومي: يومان.
        $byDate = collect($payload['by_date'])->keyBy('date');
        $this->assertCount(3, $byDate);
        $this->assertSame(2, $byDate['2026-10-05']['total']);
        $this->assertSame(1, $byDate['2026-10-05']['present']);
        $this->assertSame(1, $byDate['2026-10-05']['absent']);
        $this->assertSame(1, $byDate['2026-10-12']['total']);
        $this->assertSame(1, $byDate['2026-10-14']['total']);
    }

    public function test_the_attendance_report_respects_filters(): void
    {
        $viewer = $this->makeViewer();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();
        $studentA = $this->makeStudent();
        $studentB = $this->makeStudent();

        $l1 = $this->makeLesson($program, $studentA, $teacher, [
            'scheduled_start_at' => '2026-10-05 16:00:00',
            'scheduled_end_at' => '2026-10-05 16:30:00',
        ]);
        $l2 = $this->makeLesson($program, $studentB, $teacher, [
            'scheduled_start_at' => '2026-10-06 16:00:00',
            'scheduled_end_at' => '2026-10-06 16:30:00',
        ]);

        $this->markRow($l1, $studentA, $viewer, 'present', '2026-10-05 16:04:00');
        $this->markRow($l2, $studentB, $viewer, 'late', '2026-10-06 16:09:00');

        // ⭐ فلتر الطالب: فقط صفوف هذا الطالب تساهم.
        $payload = $this->getJsonAs(
            '/api/reports/attendance?from='.self::FROM.'&to='.self::TO.'&student_id='.$studentB->id,
            $viewer,
        )->assertOk()->json();

        $this->assertSame(1, $payload['summary']['total_attendance_records']);
        $this->assertSame(0, $payload['summary']['present']);
        $this->assertSame(1, $payload['summary']['late']);
        // ⭐ دروس الطالب ب فقط (الواحد المفلتر) في عداد الدروس.
        $this->assertSame(1, $payload['summary']['total_lessons_scheduled']);
        $this->assertCount(1, $payload['by_student']);
        $this->assertSame((int) $studentB->id, (int) $payload['by_student'][0]['student_id']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ① مسار الاشتراكات — أرقام تطابق الحساب اليدوي
    // ═══════════════════════════════════════════════════════════════

    public function test_the_subscriptions_report_route_is_wired_and_the_numbers_match(): void
    {
        $viewer = $this->makeViewer();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $s1 = $this->makeStudent();
        $s2 = $this->makeStudent();

        // ⭐ الحساب اليدوي: 3 نشطة (400 + 400 + 250 = 1050)، منتهية
        // واحدة (250)، موقوفة واحدة، ولأنشطة واحدة قريبة الانتهاء.
        $this->makeSubscription($program, $s1, ['price' => 400, 'status' => 'active']);
        $this->makeSubscription($program, $s2, ['price' => 400, 'status' => 'active', 'teacher_id' => $teacher->id, 'currency' => 'EGP']);
        $nearEnd = $this->makeSubscription($program, $s1, [
            'price' => 250,
            'status' => 'active',
            'end_date' => now()->copy()->addDays(2)->toDateString(),
        ]);
        $this->makeSubscription($program, $s2, ['price' => 250, 'status' => 'expired']);
        $this->makeSubscription($program, $s2, ['price' => 0, 'status' => 'paused']);

        $payload = $this->getJsonAs(
            '/api/reports/subscriptions?from='.self::FROM.'&to='.self::TO,
            $viewer,
        )->assertOk()->json();

        $this->assertSame(5, $payload['summary']['total']);
        $this->assertSame(3, $payload['summary']['active']);
        $this->assertSame(1, $payload['summary']['expired']);
        $this->assertSame(1, $payload['summary']['paused']);
        $this->assertSame(0, $payload['summary']['cancelled']);
        $this->assertSame(1, $payload['summary']['expiring_soon']);

        // ⭐ الإيراد بالعملة — منشأ SQL aggregate (القيم بالقرش
        // تمر عبر منشآت المال).
        $this->assertSame('1050.00', (string) $payload['revenue_by_currency']['EGP']['total']);
        $this->assertSame(3, $payload['revenue_by_currency']['EGP']['count']);

        // ⭐ محور البرنامج: كل الاشتراكات برنامج واحد.
        $this->assertCount(1, $payload['by_program']);
        $this->assertSame(5, $payload['by_program'][0]['total']);
        $this->assertSame(3, $payload['by_program'][0]['active']);
        $this->assertSame(1, $payload['by_program'][0]['expired']);
        $this->assertSame('1050.00', (string) $payload['by_program'][0]['revenue']);

        // ⭐ محور المعلم.
        $this->assertCount(2, $payload['by_teacher']);
        $withTeacher = collect($payload['by_teacher'])->firstWhere('teacher_id', (int) $teacher->id);
        $this->assertNotNull($withTeacher);
        $this->assertSame(1, $withTeacher['total_students']);
        $this->assertSame(1, $withTeacher['active']);

        // ⭐ التفاصيل: 5 صفوف، والراية «هتنتهي قريباً» على القريب فقط.
        $this->assertCount(5, $payload['details']);
        $detailById = collect($payload['details'])->keyBy('id');
        $this->assertTrue($detailById[$nearEnd->id]['is_expiring_soon']);
        // والبقية بعيدة/منتهية.
        $this->assertSame(1, collect($payload['details'])->where('is_expiring_soon', true)->count());
    }

    public function test_the_expiring_window_follows_the_settings_center(): void
    {
        $viewer = $this->makeViewer();
        $program = $this->makeProgram();
        $student = $this->makeStudent();

        // ⭐ اشتراك عند +5 أيام — خارج النافذة الافتراضية (3) لكن
        // داخل نافذة 7.
        $this->makeSubscription($program, $student, [
            'price' => 300,
            'status' => 'active',
            'end_date' => now()->copy()->addDays(5)->toDateString(),
        ]);

        $before = $this->getJsonAs(
            '/api/reports/subscriptions?from='.self::FROM.'&to='.self::TO,
            $viewer,
        )->assertOk()->json();
        $this->assertSame(0, $before['summary']['expiring_soon']);
        $this->assertSame(false, $before['details'][0]['is_expiring_soon']);

        // ⭐ نافذة المؤسسة من مركز الإعدادات = 7.
        Setting::set('subscriptions.expiry_warning_days', 7, (int) $viewer->organization_id, $viewer->id);

        $after = $this->getJsonAs(
            '/api/reports/subscriptions?from='.self::FROM.'&to='.self::TO,
            $viewer,
        )->assertOk()->json();
        $this->assertSame(1, $after['summary']['expiring_soon']);
        $this->assertSame(true, $after['details'][0]['is_expiring_soon']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ② العزل بالمؤسسة
    // ═══════════════════════════════════════════════════════════════

    public function test_both_reports_are_scoped_to_the_organization(): void
    {
        $viewer = $this->makeViewer();
        $orgA = (int) $viewer->organization_id;

        // ⭐ بيانات مؤسسة تانية (طالب/حصة/حضور/اشتراك داخل نفس الفترة).
        $otherOrg = Organization::create([
            'name' => 'أكاديمية بيانات غريبة',
            'slug' => 'other-org-314',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);
        $orgB = (int) $otherOrg->id;

        $foreignStudent = Student::create([
            'organization_id' => $orgB,
            'first_name' => 'غريب',
            'last_name' => 'التسريب',
            'student_code' => 'FRGN-001',
            'status' => 'active',
        ]);

        $foreignProgram = \App\Models\Program::create([
            'organization_id' => $orgB,
            'name' => 'برنامج غريب',
            'slug' => 'foreign-prg-314',
            'status' => 'active',
        ]);

        // ⭐ المعلم/الفرع من المؤسسة أ نفسه — المهم الحصة تابعة
        // للمؤسسة ب بالعمود `organization_id` (مثل الاشتراكات).
        $orgTeacher = $this->makeTeacher();
        $foreignLesson = \App\Models\Lesson::create([
            'organization_id' => $orgB,
            'student_id' => $foreignStudent->id,
            'teacher_id' => $orgTeacher->id,
            'program_id' => $foreignProgram->id,
            'scheduled_start_at' => '2026-10-05 16:00:00',
            'scheduled_end_at' => '2026-10-05 16:30:00',
            'duration_minutes' => 30,
            'status' => 'completed',
            'lesson_type' => 'regular',
        ]);

        $this->markRow($foreignLesson, $foreignStudent, $viewer, 'present', '2026-10-05 16:04:00');

        \App\Models\Subscription::create([
            'organization_id' => $orgB,
            'student_id' => $foreignStudent->id,
            'plan_id' => \App\Models\SubscriptionPlan::create([
                'organization_id' => $orgB,
                'program_id' => $foreignProgram->id,
                'name' => 'خطة غريبة',
                'billing_type' => 'monthly',
                'price' => 999,
                'currency' => 'EGP',
                'lessons_count' => 8,
                'lesson_duration_minutes' => 30,
                'duration_days' => 30,
                'status' => 'active',
            ])->id,
            'program_id' => $foreignProgram->id,
            'teacher_id' => $orgTeacher->id,
            'start_date' => '2026-10-01',
            'billing_type' => 'monthly',
            'price' => 999,
            'currency' => 'EGP',
            'status' => 'active',
        ]);

        // ══ من منظور مؤسسة أ: البيانات الغريبة ما تظهرش ══
        $attendance = $this->getJsonAs(
            '/api/reports/attendance?from='.self::FROM.'&to='.self::TO,
            $viewer,
        )->assertOk()->json();

        $this->assertSame(
            0,
            $attendance['summary']['total_attendance_records'],
            'حضور مؤسسة أخرى سرَب إلى تقرير مؤسسة أ',
        );
        $names = collect($attendance['by_student'])->pluck('student_name')->implode('|');
        $this->assertStringNotContainsString('غريب', $names, 'اسم طالب يخت مؤسسة أخرى ظهر في تقرير الحضور');

        $subs = $this->getJsonAs(
            '/api/reports/subscriptions?from='.self::FROM.'&to='.self::TO,
            $viewer,
        )->assertOk()->json();

        $this->assertSame(0, $subs['summary']['total'], 'اشتراكات مؤسسة أخرى سرَبت إلى التقرير');
        $this->assertSame([], $subs['details']);
        $this->assertSame(0, collect($subs['revenue_by_currency'])->sum('total') ?? 0);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الصلاحية — المسار داخلياً برأية التقارير
    // ═══════════════════════════════════════════════════════════════

    public function test_the_routes_require_the_reports_view_permission(): void
    {
        $noReports = $this->makeUserWithRole('plain-staffer', ['students.view']);

        $this->getJsonAs(
            '/api/reports/attendance?from='.self::FROM.'&to='.self::TO,
            $noReports,
        )->assertForbidden();

        $this->getJsonAs(
            '/api/reports/subscriptions?from='.self::FROM.'&to='.self::TO,
            $noReports,
        )->assertForbidden();
    }
}
