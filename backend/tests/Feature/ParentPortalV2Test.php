<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\MemorizationRecord;
use App\Models\Payment;
use App\Models\ProgressRecord;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **بوابة ولي الأمر v2 (PR 4.5) — القراءة.**
 *
 * ⭐ البوابة القديمة كانت نداء واحد (`/parent/children`) بيرجّع
 * ملخّص. PR 4.5 بيفتح صفحات تفصيلية لكل ابن: الملف، الحضور،
 * التقدم، الحصص السابقة، الفواتير والمدفوعات.
 *
 * ⭐ القاعدة الحاكمة: **كل نداء جديد بيعدّي على `ParentChildScope`**
 * — مش بس بيتأكد إن الطالب موجود، بيتأكد إنه **ابن مؤكَّد** للحساب
 * ده، وإن العلم المناسب (`can_view_finance` / `can_view_progress`)
 * مفتوح.
 *
 * ⭐ واختبارات المال هنا هي اللي بتثبت قبول الـroadmap: «الولي ما
 * يشوفش مال ابنه لو `can_view_finance=false`» — مش في النداء القديم
 * بس، في **كل** نداء مالي جديد.
 */
class ParentPortalV2Test extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① العزل — ابن حد تاني مفيش
    // ═══════════════════════════════════════════════════════════════

    public function test_a_parent_cannot_open_another_familys_child(): void
    {
        [$me, , $mine] = $this->family();
        [, , $other] = $this->family();

        $urls = [
            "/api/parent/children/{$other->id}",
            "/api/parent/children/{$other->id}/attendance",
            "/api/parent/children/{$other->id}/progress",
            "/api/parent/children/{$other->id}/lessons",
            "/api/parent/children/{$other->id}/invoices",
            "/api/parent/children/{$other->id}/payments",
        ];

        foreach ($urls as $url) {
            $this->getJsonAs($url, $me)->assertForbidden(
                "البوابة فتحت «{$url}» لابن مش بتاع الحساب"
            );
        }

        $this->getJsonAs("/api/parent/children/{$mine->id}", $me)->assertOk();
    }

    /**
     * ⭐⭐⭐ **الرابط المعلّق مش ابن** (PR 1.11).
     *
     * ⚠️ `children()` القديمة كانت بتقرا `$parent->students()` كلها —
     * يعني رابط `pending_verification` (رقم متكرر حد أرسله) كان
     * بيظهر في البوابة. الـ`ParentChildScope` كان بيفلتره، بس
     * البوابة مكانتش بتسأله. PR 4.5 بيوحّد المصدر.
     */
    public function test_a_pending_link_grants_no_access(): void
    {
        $user = $this->makeUser();
        $parent = $this->makeParentProfile($user);
        $student = $this->makeStudent();

        // بلا `status` = `pending_verification` (الافتراضي في المخطط)
        $student->parents()->attach($parent->id, [
            'relationship' => 'father',
            'is_primary' => true,
        ]);

        $this->getJsonAs("/api/parent/children/{$student->id}", $user->fresh())
            ->assertForbidden();

        $list = $this->getJsonAs('/api/parent/children', $user->fresh())->assertOk();
        $this->assertCount(0, $list->json('children'), 'الرابط المعلّق ظهر في قائمة الأبناء');
    }

    // ═══════════════════════════════════════════════════════════════
    // ② المال — `can_view_finance`
    // ═══════════════════════════════════════════════════════════════

    public function test_finance_endpoints_are_forbidden_when_can_view_finance_is_false(): void
    {
        [$user, , $student] = $this->family();
        $invoice = $this->invoiceFor($student);
        $this->paymentFor($student, $invoice);

        // قبل الإغلاق — متاح
        $this->getJsonAs("/api/parent/children/{$student->id}/invoices", $user)->assertOk();
        $this->getJsonAs("/api/parent/children/{$student->id}/payments", $user)->assertOk();

        $this->setFlag($user, $student->id, 'can_view_finance', false);

        $this->getJsonAs("/api/parent/children/{$student->id}/invoices", $user)
            ->assertForbidden('الفواتير ظاهرة رغم can_view_finance=false');
        $this->getJsonAs("/api/parent/children/{$student->id}/payments", $user)
            ->assertForbidden('المدفوعات ظاهرة رغم can_view_finance=false');

        $show = $this->getJsonAs("/api/parent/children/{$student->id}", $user)->assertOk();
        $this->assertNull($show->json('outstanding'), 'المستحق ظهر في الملف');
        $this->assertNull($show->json('lesson_credit'), 'رصيد الحصص (مال) ظهر في الملف');
        $this->assertFalse($show->json('flags.can_view_finance'));
    }

    public function test_a_parent_with_the_finance_flag_sees_invoices_and_payments(): void
    {
        [$user, , $student] = $this->family();
        $invoice = $this->invoiceFor($student);
        $this->paymentFor($student, $invoice);

        $invoices = $this->getJsonAs("/api/parent/children/{$student->id}/invoices", $user)
            ->assertOk();

        $this->assertSame(
            $invoice->invoice_number,
            $invoices->json('invoices.0.invoice_number')
        );
        $this->assertEquals(
            400.0,
            (float) $invoices->json('invoices.0.balance_due'),
            'المبلغ لازم يخرج من الـcast كنص بالجنيه مش بالقرش'
        );

        $payments = $this->getJsonAs("/api/parent/children/{$student->id}/payments", $user)
            ->assertOk();

        $this->assertEquals(150.0, (float) $payments->json('payments.0.amount'));
        $this->assertSame($invoice->invoice_number, $payments->json('payments.0.invoice_number'));

        // ⭐ `outstanding.total` مجموع من SQL — لازم يعدّي على
        // `MoneyCast::fromAggregate` وإلا بيطلع ١٠٠× (قاعدة 6).
        $show = $this->getJsonAs("/api/parent/children/{$student->id}", $user)->assertOk();
        $this->assertEquals(
            400.0,
            (float) $show->json('outstanding.total'),
            'المستحق متضخّم ١٠٠× — المجموع اتحسب بالقرش واتعرض بالجنيه'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الحضور والتقدم والحصص — `can_view_progress`
    // ═══════════════════════════════════════════════════════════════

    public function test_progress_endpoints_hide_when_can_view_progress_is_false(): void
    {
        [$user, , $student] = $this->family();

        $this->setFlag($user, $student->id, 'can_view_progress', false);

        foreach (['attendance', 'progress', 'lessons'] as $part) {
            $this->getJsonAs("/api/parent/children/{$student->id}/{$part}", $user)
                ->assertForbidden("«{$part}» ظاهر رغم can_view_progress=false");
        }
    }

    public function test_attendance_is_scoped_to_the_child_and_summarised(): void
    {
        [$user, , $student] = $this->family();
        $teacher = $this->makeTeacher();
        $program = $this->makeProgram();

        $mine = $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => now()->subDays(2),
            'scheduled_end_at' => now()->subDays(2)->addMinutes(30),
            'status' => 'completed',
        ]);
        $this->attendanceFor($mine, $student, 'present');

        // طالب تاني — نفس المعلم والبرنامج، وبياناته ما تظهرش
        $other = $this->makeStudent();
        $theirs = $this->makeLesson($program, $other, $teacher, [
            'scheduled_start_at' => now()->subDays(3),
            'status' => 'completed',
        ]);
        $this->attendanceFor($theirs, $other, 'absent');

        $r = $this->getJsonAs("/api/parent/children/{$student->id}/attendance", $user)
            ->assertOk();

        $this->assertCount(1, $r->json('attendance'));
        $this->assertSame($mine->id, $r->json('attendance.0.lesson_id'));
        $this->assertSame('present', $r->json('attendance.0.status'));
        $this->assertSame(1, $r->json('summary.present'));
        $this->assertSame(0, $r->json('summary.absent'));
    }

    public function test_past_lessons_exclude_upcoming_and_other_children(): void
    {
        [$user, , $student] = $this->family();
        $teacher = $this->makeTeacher();
        $program = $this->makeProgram();

        $past = $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => now()->subDays(5),
            'status' => 'completed',
        ]);

        // حصة قادمة — مش «سابقة»
        $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => now()->addDays(2),
            'status' => 'scheduled',
        ]);

        $other = $this->makeStudent();
        $this->makeLesson($program, $other, $teacher, [
            'scheduled_start_at' => now()->subDays(4),
            'status' => 'completed',
        ]);

        $r = $this->getJsonAs("/api/parent/children/{$student->id}/lessons", $user)
            ->assertOk();

        $this->assertCount(1, $r->json('lessons'));
        $this->assertSame($past->id, $r->json('lessons.0.id'));
    }

    public function test_progress_returns_records_and_memorization_for_the_child_only(): void
    {
        [$user, , $student] = $this->family();
        $teacher = $this->makeTeacher();
        $program = $this->makeProgram();

        ProgressRecord::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'category' => 'tajweed',
            'score' => 88,
            'notes' => 'ممتاز',
            'recorded_at' => now()->subDay(),
        ]);

        $surah = \App\Models\QuranSurah::create([
            'number' => 1,
            'name_ar' => 'الفاتحة',
            'name_en' => 'Al-Fatihah',
            'ayah_count' => 7,
            'revelation_type' => 'meccan',
        ]);

        MemorizationRecord::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'surah_id' => $surah->id,
            'from_ayah' => 1,
            'to_ayah' => 5,
            'quality' => 4,
            'recorded_at' => now()->subDay(),
        ]);

        $other = $this->makeStudent();
        ProgressRecord::create([
            'student_id' => $other->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'category' => 'tajweed',
            'score' => 50,
            'recorded_at' => now()->subDay(),
        ]);

        $r = $this->getJsonAs("/api/parent/children/{$student->id}/progress", $user)
            ->assertOk();

        $this->assertCount(1, $r->json('progress'));
        $this->assertCount(1, $r->json('memorization'));
        $this->assertEquals(88.0, (float) $r->json('progress.0.score'));
        $this->assertSame($student->id, $r->json('student_id'));
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * عائلة كاملة: حساب دخول + ملف ولي + ابن مربوط **مؤكَّد**.
     *
     * @return array{0: User, 1: \App\Models\ParentModel, 2: Student}
     */
    private function family(array $pivot = []): array
    {
        $user = $this->makeUser();
        $parent = $this->makeParentProfile($user);
        $student = $this->makeStudent();

        $student->parents()->attach($parent->id, array_merge([
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
            'can_view_finance' => true,
            'can_view_progress' => true,
            'can_request_changes' => true,
        ], $pivot));

        return [$user->fresh(), $parent, $student];
    }

    private function setFlag(User $user, int $studentId, string $flag, bool $value): void
    {
        DB::table('student_parents')
            ->where('parent_id', function ($q) use ($user) {
                $q->select('id')->from('parents')->where('user_id', $user->id);
            })
            ->where('student_id', $studentId)
            ->update([$flag => $value]);
    }

    private function invoiceFor(Student $student): Invoice
    {
        return Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-'.strtoupper(uniqid()),
            'issue_date' => now()->subDays(10)->toDateString(),
            'due_date' => now()->addDays(5)->toDateString(),
            'total' => 400,
            'paid_amount' => 0,
            'balance_due' => 400,
            'currency' => 'EGP',
            'status' => 'issued',
        ]);
    }

    private function paymentFor(Student $student, Invoice $invoice): Payment
    {
        return Payment::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_id' => $invoice->id,
            'amount' => 150,
            'currency' => 'EGP',
            'payment_method' => 'cash',
            'status' => 'completed',
            'paid_at' => now()->subDays(2),
            'received_by' => $this->makeUser()->id,
        ]);
    }

    private function attendanceFor(Lesson $lesson, Student $student, string $status): LessonAttendance
    {
        return LessonAttendance::create([
            'lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'status' => $status,
            'marked_at' => now(),
            'marked_by' => $lesson->teacher->user_id ?? $this->makeUser()->id,
        ]);
    }
}
