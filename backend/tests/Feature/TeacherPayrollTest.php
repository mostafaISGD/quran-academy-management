<?php

namespace Tests\Feature;

use App\Models\PayrollPeriod;
use App\Models\TeacherPayrollLine;
use Tests\TestCase;

/**
 * مرتبات المعلمين.
 *
 * ⭐ الفرق عن مرتبات الموظفين: المعلمين بالحصة أو راتب شهري.
 *   موظف = ساعات × سعر
 *   معلم = حصص × سعر (أو الراتب نفسه لو شهري)
 *
 * والمبدأ واحد: **`teacher_earnings` هو المصدر الوحيد** للمبالغ.
 * الاستعلام كله في `harvest()` — لو حسبناها تاني في أي مكان،
 * هنرجع باج «رقمين مختلفين» اللي حصل في الحضور.
 */
class TeacherPayrollTest extends TestCase
{
    private const START = '2026-03-01';

    private const END = '2026-03-31';

    private function accountant()
    {
        return $this->makeUserWithRole('accountant', ['payroll.manage']);
    }

    /** معلم بسعر شهري ثابت */
    private function monthlyTeacher(float $salary, string $name = 'معلم شهري'): \App\Models\Teacher
    {
        $t = $this->makeActiveTeacher(['display_name' => $name]);

        \Illuminate\Support\Facades\DB::table('teacher_rates')->insert([
            'teacher_id' => $t->id,
            'rate_type' => 'monthly',
            'amount' => $salary,
            'currency' => 'EGP',
            'effective_from' => self::START,
            'effective_to' => null,
        ]);

        return $t;
    }

    /** معلم بسعر لكل حصة */
    private function perLessonTeacher(float $rate, string $name = 'معلم بالحصة'): \App\Models\Teacher
    {
        $t = $this->makeActiveTeacher(['display_name' => $name]);

        \Illuminate\Support\Facades\DB::table('teacher_rates')->insert([
            'teacher_id' => $t->id,
            'rate_type' => 'per_lesson',
            'amount' => $rate,
            'currency' => 'EGP',
            'effective_from' => self::START,
            'effective_to' => null,
        ]);

        return $t;
    }

    private function period(array $attrs = []): PayrollPeriod
    {
        return $this->makePeriod(self::START, self::END, $attrs);
    }

    // ============================================================
    // ⭐ المصدر: teacher_earnings
    // ============================================================

    public function test_monthly_teacher_gets_their_salary_regardless_of_lessons(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4500);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4500]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $line = TeacherPayrollLine::first();
        $this->assertEquals(4500.0, (float) $line->amount, 'الراتب الشهري = السعر نفسه');
        $this->assertEquals(4500.0, (float) $line->rate_snapshot);
    }

    public function test_per_lesson_teacher_is_paid_per_lesson(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->perLessonTeacher(75);

        // ٣ حصص كمستحقات منفصلة
        foreach (['2026-03-05', '2026-03-12', '2026-03-19'] as $day) {
            $this->makeEarning($t, $day, ['amount' => 75]);
        }

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $line = TeacherPayrollLine::first();
        $this->assertEquals(225.0, (float) $line->amount, '٣ حصص × ٧٥');
    }

    /**
     * ⚠️ حصص جدول `lessons` بتظهر كعدد **بس** — مش كمصدر المبلغ.
     *
     * السبب: المبلغ من `teacher_earnings` (المستحق المراجَع)،
     * والعدد من `lessons`. لو حسبنا المبلغ من `lessons` كمان،
     * التنين هيفترقوا لو السعر اتغيّر بعد الحصة.
     */
    public function test_lessons_count_is_informational_not_the_amount_source(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->perLessonTeacher(75);

        $this->makeEarning($t, '2026-03-05', ['amount' => 75]);

        // حصة مكتملة واحدة بس في الجدول
        $this->makeLesson(
            $this->makeProgram(),
            $this->makeStudent(),
            $t,
            [
                'status' => 'completed',
                'scheduled_start_at' => '2026-03-05 16:00:00',
                'scheduled_end_at' => '2026-03-05 16:30:00',
                'duration_minutes' => 30,
            ],
        );

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $line = TeacherPayrollLine::first();
        $this->assertSame(1, $line->lessons_count, 'حصة مكتملة واحدة');
        $this->assertEquals(75.0, (float) $line->amount, 'المبلغ جاي من المستحق المسجّل');
    }

    /**
     * ⭐ الأرقام لازم تطابق المستحق المسجّل بالظبط.
     *
     * ده أهم اختبار في الملف: الشاشة بتعرض رقم المرتب، ولو الرقم
     * ده مش زي اللي في `teacher_earnings`، الأدمن بيدفع رقم غلط.
     */
    public function test_line_amount_always_matches_the_recorded_earnings(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $a = $this->monthlyTeacher(4500, 'أ');
        $b = $this->perLessonTeacher(75, 'ب');
        $c = $this->monthlyTeacher(3500, 'ج');

        $this->makeEarning($a, '2026-03-31', ['amount' => 4500]);
        $this->makeEarning($b, '2026-03-05', ['amount' => 75]);
        $this->makeEarning($b, '2026-03-12', ['amount' => 75]);
        $this->makeEarning($c, '2026-03-31', ['amount' => 3500]);

        $r = $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc);

        // 4500 + 150 + 3500
        $this->assertEquals(8150.0, (float) $r->json('harvested_total'));
        $this->assertEquals(3, TeacherPayrollLine::count());

        // كل سطر = مستحق المعلم المسجّل.
        //
        // ⭐ `whereRange` مش `whereBetween`: لو استخدمنا
        // `whereBetween` بنص تاريخ هنا، هنعمل **نفس** الباج اللي
        // الاختبار بيكشفه (آخر يوم بيضيع).
        $harvestedByTeacher = [];
        foreach (\App\Models\TeacherEarning::whereRange('earning_date', self::START, self::END)
            ->whereIn('status', ['approved', 'paid'])
            ->get() as $e) {
            $harvestedByTeacher[$e->teacher_id] = ($harvestedByTeacher[$e->teacher_id] ?? 0) + $e->amount;
        }

        foreach (TeacherPayrollLine::with('teacher')->get() as $line) {
            $truth = $harvestedByTeacher[$line->teacher_id] ?? 0;

            $this->assertEquals(
                $truth, (float) $line->amount,
                "{$line->teacher->display_name}: {$line->amount} ≠ المستحق {$truth}"
            );
        }
    }

    public function test_pending_earnings_are_excluded(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);

        // لسه ماتراجعش
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000, 'status' => 'pending']);

        $r = $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc);

        $this->assertEquals(0, TeacherPayrollLine::count(), 'مفيش مستحق مراجع');
        $this->assertEquals(0.0, (float) $r->json('harvested_total'));
    }

    public function test_cancelled_earnings_are_excluded(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);

        $this->makeEarning($t, '2026-03-31', ['amount' => 4000, 'status' => 'cancelled']);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertSame(0, TeacherPayrollLine::count());
    }

    public function test_earnings_outside_the_period_are_excluded(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);

        $this->makeEarning($t, '2026-02-28', ['amount' => 4000]);
        $this->makeEarning($t, '2026-04-01', ['amount' => 4000]);
        $this->makeEarning($t, '2026-03-15', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertEquals(4000.0, (float) TeacherPayrollLine::first()->amount);
    }

    // ============================================================
    // مين يدخل
    // ============================================================

    /** ⭐ معلم موقوف (inactive) مستحقّه مش بيتحسب — له حقه بس مش دلوقتي */
    public function test_inactive_teachers_are_skipped_but_shown_as_missing(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $active = $this->monthlyTeacher(4000, 'نشط');
        $this->makeEarning($active, '2026-03-31', ['amount' => 4000]);

        $retired = $this->monthlyTeacher(5500, 'موقوف');
        $retired->update(['status' => 'inactive']);
        $this->makeEarning($retired, '2026-03-31', ['amount' => 5500]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertSame(1, TeacherPayrollLine::count(), 'النشط بس');

        // والموقوف لازم يبان عشان الأدمن يلاحظ
        $r = $this->getJsonAs("/api/teacher-payroll/periods/{$period->id}/lines", $acc);
        $this->assertCount(1, $r->json('missing'));
        $this->assertSame('موقوف', $r->json('missing.0.teacher.name'));
        $this->assertSame(5500.0, (float) $r->json('missing.0.harvested'));
    }

    public function test_teacher_without_a_rate_is_skipped_and_counted(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        // معلم من غير سعر خالص
        $t = $this->makeActiveTeacher(['display_name' => 'بلا سعر']);
        $this->makeEarning($t, '2026-03-31', ['amount' => 3000]);

        $r = $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc);

        $this->assertSame(1, $r->json('no_rate'));
        $this->assertSame(0, TeacherPayrollLine::count());
        $this->assertStringContainsString('مالهومش سعر', $r->json('message'));
    }

    // ============================================================
    // ⭐ اللقطات
    // ============================================================

    /**
     * تغيير السعر **بعد الاعتماد** ما يغيّرش المرتب.
     *
     * نفس ضمانات مرتبات الموظفين: الرقم اللي راجعه الأدمن لازم
     * يفضل زي ما هو.
     */
    public function test_changing_the_rate_after_approval_does_not_rewrite_history(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $acc)->assertOk();

        // السعر اتزود
        \Illuminate\Support\Facades\DB::table('teacher_rates')
            ->where('teacher_id', $t->id)->update(['amount' => 6000]);

        $r = $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc);

        $this->assertSame(1, $r->json('skipped'), 'المعتمد اتخطّى');
        $line = TeacherPayrollLine::first();
        $this->assertEquals(4000.0, (float) $line->rate_snapshot);
        $this->assertEquals(4000.0, (float) $line->amount);
    }

    // ============================================================
    // المبلغ بيتحسب في السيرفر بس
    // ============================================================

    public function test_amount_cannot_be_forced_by_the_client(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->perLessonTeacher(75);
        foreach (['2026-03-05', '2026-03-12'] as $day) {
            $this->makeEarning($t, $day, ['amount' => 75]);
        }

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = TeacherPayrollLine::first();

        $r = $this->putJsonAs("/api/teacher-payroll/lines/{$line->id}", ['amount' => 999999], $acc);

        $this->assertEquals(150.0, (float) $r->json('amount'), 'اتحسب تاني 2 × 75');
    }

    /**
     * الأدمن يقدر يعدّل المسودّة، والمبلغ بيتحسب من اللي عدّله.
     *
     * ⭐ ليش ده مهم: لو الأرقام مش زي المستحق المسجّل (المعلم
     * عمل حصص زيادة، أو الـ seed غلط)، الأدمن يعدّل المبلغ بنفسه
     * والرقم اللي يتدفع هو اللي راجعه.
     */
    public function test_editing_lessons_recomputes_the_amount(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->perLessonTeacher(75);
        $this->makeEarning($t, '2026-03-05', ['amount' => 75]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = TeacherPayrollLine::first();

        $r = $this->putJsonAs("/api/teacher-payroll/lines/{$line->id}", [
            'lessons_count' => 10,
        ], $acc);

        $this->assertEquals(750.0, (float) $r->json('amount'), '10 × 75');
    }

    // ============================================================
    // دورة الحياة: مسودّة → معتمد → مدفوع
    // ============================================================

    public function test_only_draft_lines_are_editable(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = TeacherPayrollLine::first();

        $this->putJsonAs("/api/teacher-payroll/lines/{$line->id}", ['lessons_count' => 9], $acc)->assertOk();

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $acc)->assertOk();

        $this->putJsonAs("/api/teacher-payroll/lines/{$line->id}", ['lessons_count' => 8], $acc)
            ->assertStatus(422);
    }

    public function test_a_draft_line_cannot_be_paid(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = TeacherPayrollLine::first();

        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $acc)
            ->assertStatus(422);
    }

    public function test_a_line_cannot_be_paid_twice(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $line = TeacherPayrollLine::first();

        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $acc)->assertStatus(422);

        $this->assertSame('paid', $line->fresh()->status);
    }

    /** ⭐ آخر سطر مدفوع بيقفل الفترة (نفس منطق الموظفين) */
    public function test_paying_the_last_line_marks_the_period_paid(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $a = $this->monthlyTeacher(4000, 'أ');
        $this->makeEarning($a, '2026-03-31', ['amount' => 4000]);
        $b = $this->monthlyTeacher(5000, 'ب');
        $this->makeEarning($b, '2026-03-31', ['amount' => 5000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $first = TeacherPayrollLine::orderBy('id')->first();
        $this->postJsonAs("/api/teacher-payroll/lines/{$first->id}/pay", [], $acc)->assertOk();
        $this->assertSame('finalized', $period->fresh()->status, 'لسه في واحد مدفوعش');

        $second = TeacherPayrollLine::orderBy('id')->skip(1)->first();
        $this->postJsonAs("/api/teacher-payroll/lines/{$second->id}/pay", [], $acc)->assertOk();

        $this->assertSame('paid', $period->fresh()->status, 'آخر واحد اتصرف → مدفوعة');
    }

    // ============================================================
    // الإقفال — «مرحلتين»
    // ============================================================

    public function test_closing_is_rejected_while_drafts_exist(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $r = $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/close", [], $acc);

        $r->assertStatus(422);
        $this->assertSame('open', $period->fresh()->status);
    }

    public function test_payment_continues_after_the_period_is_closed(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $line = TeacherPayrollLine::first();
        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $acc)->assertOk();

        $this->assertSame('paid', $line->fresh()->status);
    }

    public function test_a_closed_period_freezes_the_numbers_but_allows_paying(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $line = TeacherPayrollLine::first();

        // مستحق جديد بعد الإقفال — يتجاهَل
        $this->makeEarning($t, '2026-03-30', ['amount' => 9999]);
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)
            ->assertStatus(422);

        $this->putJsonAs("/api/teacher-payroll/lines/{$line->id}", [
            'lessons_count' => 99,
        ], $acc)->assertStatus(422);

        $this->assertEquals(4000.0, (float) $line->fresh()->amount);

        // ⭐ بس الدفع شغال في الفترة المقفولة
        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $acc)->assertOk();
        $this->assertSame('paid', $line->fresh()->status);
    }

    public function test_reopen_does_not_undo_payments(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/close", [], $acc)->assertOk();
        $line = TeacherPayrollLine::first();
        $this->postJsonAs("/api/teacher-payroll/lines/{$line->id}/pay", [], $acc)->assertOk();

        $r = $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/reopen", [], $acc);

        $r->assertOk();
        $this->assertSame('open', $period->fresh()->status);
        $this->assertSame('paid', $line->fresh()->status, 'المدفوع فضل مدفوع');
    }

    public function test_reopen_is_written_to_the_audit_log(): void
    {
        $acc = $this->accountant();
        $period = $this->period(['status' => 'finalized']);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/reopen", [], $acc)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $acc->id,
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
        ]);
    }

    // ============================================================
    // ⭐ سطر واحد لكل (معلم + فترة)
    // ============================================================

    public function test_regenerating_updates_instead_of_duplicating(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->perLessonTeacher(75);
        $p = $this->makeProgram();
        $s = $this->makeStudent();

        // حصة مكتملة واحدة في الجدول
        $this->makeEarning($t, '2026-03-05', ['amount' => 75]);
        $this->makeLesson($p, $s, $t, [
            'status' => 'completed',
            'scheduled_start_at' => '2026-03-05 16:00:00',
            'scheduled_end_at' => '2026-03-05 16:30:00',
            'duration_minutes' => 30,
        ]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->assertSame(1, TeacherPayrollLine::count());
        $this->assertSame(1, TeacherPayrollLine::first()->lessons_count);
        $this->assertEquals(75.0, (float) TeacherPayrollLine::first()->amount);

        // حصة تانية اتقفلت
        $this->makeEarning($t, '2026-03-12', ['amount' => 75]);
        $this->makeLesson($p, $s, $t, [
            'status' => 'completed',
            'scheduled_start_at' => '2026-03-12 16:00:00',
            'scheduled_end_at' => '2026-03-12 16:30:00',
            'duration_minutes' => 30,
        ]);

        $r = $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc);

        $this->assertSame(0, $r->json('created'), 'مفيش سطر جديد');
        $this->assertSame(1, $r->json('updated'), 'السطر اتحدّث');
        $this->assertSame(1, TeacherPayrollLine::count(), 'لسه سطر واحد');
        $this->assertSame(2, TeacherPayrollLine::first()->lessons_count, 'اتحدّث لـ ٢');
        $this->assertEquals(150.0, (float) TeacherPayrollLine::first()->amount, '١٥٠ ج');
    }

    public function test_the_unique_constraint_blocks_duplicate_rows(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $t = $this->monthlyTeacher(4000);
        $this->makeEarning($t, '2026-03-31', ['amount' => 4000]);

        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->expectException(\Illuminate\Database\QueryException::class);

        TeacherPayrollLine::create([
            'organization_id' => $this->org->id,
            'payroll_period_id' => $period->id,
            'teacher_id' => $t->id,
            'amount' => 100,
            'status' => 'draft',
        ]);
    }

    // ============================================================
    // الصلاحيات
    // ============================================================

    public function test_attendance_manager_cannot_run_teacher_payroll(): void
    {
        $receptionist = $this->makeUserWithRole('receptionist', ['attendance.view', 'attendance.manage']);
        $period = $this->period();

        $this->getJsonAs('/api/teacher-payroll/periods', $receptionist)->assertStatus(403);
        $this->postJsonAs("/api/teacher-payroll/periods/{$period->id}/generate", [], $receptionist)
            ->assertStatus(403);
    }

    // ============================================================
    // ⭐ حارس ضد الـ duplicate-calculation
    // ============================================================

    /**
     * ⭐ المبلغ يتحسب في **مكان واحد بس**: `computeAmount`.
     *
     * لو حد كتب `lessons * rate` في الـ controller، الأرقام هتبدأ
     * تختلف — والمرتب الغلط = ضرر حقيقي.
     */
    public function test_amount_is_computed_in_exactly_one_place(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/Api/TeacherPayrollController.php')
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\$\w*lessons\w*\s*\*\s*\$?\w*rate/i',
            $controller,
            'في حساب المبلغ مكتوب يدوي — لازم `TeacherPayrollLine::computeAmount`'
        );

        // والمكان ده شغال
        $this->assertEquals(300.0, TeacherPayrollLine::computeAmount(4, 75, 'per_lesson'));
        $this->assertEquals(4500.0, TeacherPayrollLine::computeAmount(2, 4500, 'monthly'),
            'شهري = الراتب نفسه مهما كانت الحصص');
        $this->assertEquals(0.0, TeacherPayrollLine::computeAmount(4, null, 'per_lesson'));
    }

    /**
     * ⭐ المعلمين مش بياخدوا نفس معادلة الموظفين.
     *
     * الباج: كانت المعادلة `lessons * rate + hours * rate`. لما
     * `lessons = 0` سقطنا على `hours * rate` — يعني نطّقنا
     * منطق الموظفين على المعلم. والمعلم بالحصة مش بيتقاس
     * بالساعات أصلاً.
     */
    public function test_the_two_sides_do_not_share_the_same_formula(): void
    {
        // ٤ حصص × ٧٥ = ٣٠٠ للمعلم
        $this->assertEquals(300.0, TeacherPayrollLine::computeAmount(4, 75, 'per_lesson'));

        // الراتب الشهري = السعر نفسه مهما كانت الحصص
        $this->assertEquals(4500.0, TeacherPayrollLine::computeAmount(30, 4500, 'monthly'));

        // ⭐ الاختلاف الحقيقي: شهري بالحصص
        $this->assertNotEquals(
            TeacherPayrollLine::computeAmount(30, 4500, 'monthly'),
            TeacherPayrollLine::computeAmount(30, 4500, 'per_lesson'),
            'شهري بالحصص = ١٣٥٠٠ — لازم يبقى ٤٥٠٠ (الراتب نفسه)'
        );
    }

    /** ⚠️ صفر حصص = صفر، مش fall-through لمنطق الموظفين */
    public function test_zero_lessons_means_zero_not_fallback(): void
    {
        $this->assertSame(
            0.0,
            TeacherPayrollLine::computeAmount(0, 75, 'per_lesson'),
            'مافيش حصص = ٠، مش السعر ولا أي رقم تاني'
        );
    }
}