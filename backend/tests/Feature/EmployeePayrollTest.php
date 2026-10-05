<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeePayrollLine;
use App\Models\PayrollPeriod;
use Tests\TestCase;

/**
 * مرتبات الموظفين بالساعات.
 *
 * ⭐ القاعدة اللي الاختبارات دي كلها بتحميها:
 *
 *     المبلغ = الساعات المسجّلة في الحضور × سعر الساعة (لحظة الاحتساب)
 *
 * والمهم أكتر من كده: **اللقطات**. `hours` و `hourly_rate` بيتخزّنوا
 * وقت الاحتساب. لو عدّلنا الحضور أو غيّرنا سعر الموظف بعدها، سطر
 * المرتب المعتمد ما يتغيرش — لأنه مستحق اتراجع فعلاً.
 *
 * في نظام مرتبات، إعادة الحساب التلقائي بعد الدفع = خصم مزدوج.
 */
class EmployeePayrollTest extends TestCase
{
    private const START = '2026-03-01';

    private const END = '2026-03-31';

    private function accountant()
    {
        return $this->makeUserWithRole('accountant', [
            'payroll.manage', 'attendance.view', 'attendance.manage',
        ]);
    }

    /** موظف فريلانس بسعر ساعة + حضور في الفترة */
    private function hourlyEmployee(array $attDays = [], array $attrs = []): Employee
    {
        $e = $this->makeEmployee(array_merge([
            'employment_type' => 'contract',
            'hourly_rate' => 100,
        ], $attrs));

        foreach ($attDays as $day => $hours) {
            $this->makeAttendance($e, $day, [
                'status' => 'present',
                'worked_hours' => $hours,
            ]);
        }

        return $e;
    }

    private function period(array $attrs = []): PayrollPeriod
    {
        return $this->makePeriod(self::START, self::END, $attrs);
    }

    // ============================================================
    // ⭐ المبلغ = الساعات × السعر
    // ============================================================

    public function test_amount_equals_attendance_hours_times_the_rate(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->hourlyEmployee([
            '2026-03-02' => 6,
            '2026-03-03' => 7.5,
            '2026-03-04' => 4.25,
        ], ['hourly_rate' => 90]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $line = EmployeePayrollLine::first();
        // 6 + 7.5 + 4.25 = 17.75
        $this->assertEquals(17.75, (float) $line->hours);
        $this->assertEquals(90.0, (float) $line->hourly_rate);
        $this->assertEquals(1597.5, (float) $line->amount, '17.75 × 90 = 1597.5');
        $this->assertSame(3, $line->days_present);
    }

    /** الحضور خارج الفترة ما يتحسبش */
    public function test_attendance_outside_the_period_is_ignored(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $e = $this->makeEmployee(['hourly_rate' => 100]);
        $this->makeAttendance($e, '2026-03-10', ['worked_hours' => 5]);
        // قبل الفترة
        $this->makeAttendance($e, '2026-02-28', ['worked_hours' => 8]);
        // بعد الفترة
        $this->makeAttendance($e, '2026-04-01', ['worked_hours' => 9]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertEquals(5.0, (float) EmployeePayrollLine::first()->hours, 'فقط يوم واحد جوه الفترة');
    }

    /** موظف حضوره كله صفر (غياب/إجازة) → سطر صفري، مش معتمد */
    public function test_zero_hours_employee_gets_a_zero_draft(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        // 3 أيام غايب = 0 ساعة (زي ما `resolveHours` بيعمل)
        $e = $this->makeEmployee(['hourly_rate' => 100]);
        foreach (['2026-03-02', '2026-03-03', '2026-03-04'] as $day) {
            $this->makeAttendance($e, $day, ['status' => 'absent', 'worked_hours' => 0]);
        }

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $line = EmployeePayrollLine::first();
        $this->assertEquals(0.0, (float) $line->amount);
        $this->assertSame('draft', $line->status, 'مسودّة — مش معتمد');
    }

    /**
     * موظف **مالوش أي سجل حضور** في الفترة → مفيش سطر خالص.
     *
     * الفرق بينه وبين اللي فوق مهم: اللي فوق *اتسجّل* غايب كل يوم
     * (قرار إداري = راتبه صفر). ده **مالوش أي تسجيل** خالص —
     * يا إما الأدمن ناسي، يا إما مش شغال أصلاً في الفترة دي.
     *
     * سطر صفري معناه «اشتغل وراتبه صفر» وده غلط. الأحسن يظهر في
     * `missing` كتنبيه: «لسه ما سجّلناش حضوره».
     */
    public function test_employee_without_any_attendance_gets_no_line(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->makeEmployee(['hourly_rate' => 100]); // بلا حضور خالص

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertSame(0, EmployeePayrollLine::count());

        $r = $this->getJsonAs("/api/payroll/periods/{$period->id}/lines", $acc);
        $this->assertCount(1, $r->json('missing'));
        $this->assertSame('no_record', $r->json('missing.0.status'));
    }

    // ============================================================
    // مين يدخل في الاحتساب
    // ============================================================

    public function test_monthly_employees_are_skipped(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->hourlyEmployee(['2026-03-02' => 6]);                    // بالساعة
        $this->makeEmployee(['hourly_rate' => null]);                  // شهري
        $this->makeEmployee(['hourly_rate' => 80, 'employment_type' => 'volunteer']); // متطوع

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertSame(1, EmployeePayrollLine::count(),
            'الموظف الشهري والمتطوع مالهمش أجر بالساعة');
    }

    public function test_inactive_employees_are_skipped(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->hourlyEmployee(['2026-03-02' => 6]);

        $left = $this->makeEmployee(['hourly_rate' => 100]);
        $this->makeAttendance($left, '2026-03-03', ['worked_hours' => 8]);
        $left->update(['status' => 'inactive']);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertSame(1, EmployeePayrollLine::count());
    }

    /** الشاشة بتقول «مفيش حضور مسجّل» للي ليهم سعر بس مالهمش سطر */
    public function test_hourly_employee_without_attendance_shows_as_missing(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $idle = $this->makeEmployee(['hourly_rate' => 100]);
        $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $r = $this->getJsonAs("/api/payroll/periods/{$period->id}/lines", $acc);

        $this->assertCount(1, $r->json('missing'));
        $this->assertSame($idle->id, $r->json('missing.0.employee.id'));
        $this->assertSame('no_record', $r->json('missing.0.status'));
    }

    // ============================================================
    // ⭐ اللقطات — الأهم في المرتبات
    // ============================================================

    /**
     * إعادة احتساب المسودّة تاخد السعر الجديد — وده **المقصود**.
     *
     * المسودّة مش التزام، هي «محسوبة بالبيانات المتاحة دلوقتي».
     * لو ما أخدتش السعر الجديد، الأدمن اللي عدّل السعر مش هيقدر
     * يشوف أثره لحد ما يمسح السطر ويعمله من الأول.
     *
     * ⚠️ الضمان الحقيقي في الاختبار اللي بعده: السطر **المعتمد**
     * ما يتغيّرش — دي اللي بتمنع إعادة كتابة التاريخ.
     */
    public function test_regenerating_a_draft_picks_up_the_current_rate(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $e = $this->hourlyEmployee(['2026-03-02' => 10], ['hourly_rate' => 100]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->assertEquals(1000.0, (float) EmployeePayrollLine::first()->amount);

        // الموظف اتزود
        $e->update(['hourly_rate' => 200]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $line = EmployeePayrollLine::first();
        $this->assertEquals(200.0, (float) $line->hourly_rate, 'السعر الجديد');
        $this->assertEquals(2000.0, (float) $line->amount);
        $this->assertSame(1, EmployeePayrollLine::count(), 'واحد بس');
    }

    /**
     * ⭐ تغيير السعر **بعد الاعتماد** ما يغيّرش المرتب.
     *
     * ده **السبب** إن السعر متخزّن في السطر: لو قروا
     * `employees.hourly_rate` وقت الدفع، الموظف اللي اتزود بدري
     * ينعكس على مرتب الشهر اللي فات — والمرتب اللي الأدمن راجعه
     * ووافق عليه لازم يفضل زي ما هو.
     */
    public function test_changing_the_rate_after_approval_does_not_rewrite_history(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $e = $this->hourlyEmployee(['2026-03-02' => 10], ['hourly_rate' => 100]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->assertEquals(1000.0, (float) EmployeePayrollLine::first()->amount);

        // الموظف اتزود + اتزاد حضوره
        $e->update(['hourly_rate' => 200]);
        $this->makeAttendance($e, '2026-03-03', ['worked_hours' => 10]);

        $r = $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc);

        $this->assertSame(1, $r->json('skipped'));
        $line = EmployeePayrollLine::first();
        $this->assertEquals(100.0, (float) $line->hourly_rate, 'السعر لسه القديم');
        $this->assertEquals(10.0, (float) $line->hours, 'الساعات ما اتضاعفتش');
        $this->assertEquals(1000.0, (float) $line->amount, 'المبلغ زي ما هو');
    }

    // ============================================================
    // المبلغ بيتحسب في السيرفر بس
    // ============================================================

    public function test_amount_cannot_be_forced_by_the_client(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = EmployeePayrollLine::first();

        // عميل بيبعت amount مباشر — لازم يتتجاهل ويتحسب من hours×rate
        $r = $this->putJsonAs("/api/payroll/lines/{$line->id}", ['amount' => 999999], $acc);

        $this->assertEquals(400.0, (float) $r->json('amount'), 'المبلغ اتحسب تاني 4 × 100');
    }

    public function test_editing_hours_recomputes_the_amount(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 4], ['hourly_rate' => 50]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = EmployeePayrollLine::first();

        $r = $this->putJsonAs("/api/payroll/lines/{$line->id}", ['hours' => 10], $acc);

        $this->assertEquals(500.0, (float) $r->json('amount'), '10 × 50');
    }

    // ============================================================
    // دورة الحياة: مسودّة → معتمد → مدفوع
    // ============================================================

    public function test_only_draft_lines_are_editable(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = EmployeePayrollLine::first();

        $this->assertTrue($line->isEditable());
        $this->putJsonAs("/api/payroll/lines/{$line->id}", ['hours' => 5], $acc)->assertOk();

        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();

        $this->assertFalse($line->fresh()->isEditable());
        $this->putJsonAs("/api/payroll/lines/{$line->id}", ['hours' => 6], $acc)
            ->assertStatus(422);
    }

    public function test_approve_skips_zero_amount_lines(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->hourlyEmployee(['2026-03-02' => 6]);        // 600
        $e0 = $this->makeEmployee(['hourly_rate' => 100]); // كله غايب = 0

        foreach (['2026-03-02', '2026-03-03'] as $day) {
            $this->makeAttendance($e0, $day, ['status' => 'absent', 'worked_hours' => 0]);
        }

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $r = $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc);

        $this->assertSame(1, $r->json('approved'));
        $this->assertSame(1, $r->json('skipped_zero'));

        $zero = EmployeePayrollLine::where('employee_id', $e0->id)->first();
        $this->assertSame('draft', $zero->status, 'اللي راتبه صفر ما اتقرمش');
    }

    /** الدفع مرتين = مرفوض. ده اللي بيمنع الدفع المزدوج. */
    public function test_a_line_cannot_be_paid_twice(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = EmployeePayrollLine::first();

        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [
            'payment_method' => 'كاش', 'reference' => 'REF-1',
        ], $acc)->assertOk();

        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $acc)->assertStatus(422);

        $this->assertSame('paid', $line->fresh()->status);
        $this->assertNotNull($line->fresh()->paid_at);
    }

    /** سطر راتبه صفر ما بيتدفعش — عشان حد ميتقفلشش من غير سبب */
    public function test_a_zero_amount_line_cannot_be_paid(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $e = $this->makeEmployee(['hourly_rate' => 100]);
        $this->makeAttendance($e, '2026-03-02', ['status' => 'absent', 'worked_hours' => 0]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = EmployeePayrollLine::first();

        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $acc)->assertStatus(422);
    }

    // ============================================================
    // ⭐ سطر واحد لكل (موظف + فترة)
    // ============================================================

    public function test_regenerating_updates_instead_of_duplicating(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $e = $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->assertSame(1, EmployeePayrollLine::count());

        // ساعات جديدة
        $this->makeAttendance($e, '2026-03-03', ['worked_hours' => 6]);
        $r = $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc);

        $this->assertSame(0, $r->json('created'));
        $this->assertSame(1, $r->json('updated'));
        $this->assertSame(1, EmployeePayrollLine::count(), 'لسه سطر واحد');
        $this->assertEquals(10.0, (float) EmployeePayrollLine::first()->hours, 'اتحدّث لـ 10');
    }

    public function test_the_unique_constraint_blocks_duplicate_rows(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $e = $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        // محاولة إدراج مباشر — الـ unique في الداتابيز لازم يمنعها
        $this->expectException(\Illuminate\Database\QueryException::class);

        EmployeePayrollLine::create([
            'organization_id' => $this->org->id,
            'payroll_period_id' => $period->id,
            'employee_id' => $e->id,
            'hours' => 1,
            'amount' => 100,
            'status' => 'draft',
        ]);
    }

    // ============================================================
    // الفترة المقفولة
    // ============================================================

    public function test_a_closed_period_rejects_generation(): void
    {
        $acc = $this->accountant();
        $period = $this->period(['status' => 'finalized']);
        $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)
            ->assertStatus(422);
        $this->assertSame(0, EmployeePayrollLine::count());
    }

    public function test_a_closed_period_rejects_approval(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $period->update(['status' => 'finalized']);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)
            ->assertStatus(422);
        $this->assertSame('draft', EmployeePayrollLine::first()->status);
    }

    // ============================================================
    // ⭐ الإقفال — «مرحلتين»: الأرقام بتتقفل، الدفع بيكمل
    // ============================================================

    /**
     * الإقفال مرفوض لو فيه مسودّات لسه ما اتراجعش.
     *
     * سطر ما اتراجعش ماينفعش يدخل فترة «مقفولة» — بعد كده مفيش
     * طريقة نعدّله. فالقاعدة: اعتمد الأول، بعدين اقفل.
     */
    public function test_closing_is_rejected_while_drafts_exist(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $r = $this->postJsonAs("/api/payroll/periods/{$period->id}/close", [], $acc);

        $r->assertStatus(422);
        $this->assertSame(1, $r->json('drafts'));
        $this->assertSame('open', $period->fresh()->status);
    }

    public function test_close_requires_no_drafts_and_stores_who_and_when(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 4]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();

        $this->postJsonAs("/api/payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $fresh = $period->fresh();
        $this->assertSame('finalized', $fresh->status);
        $this->assertNotNull($fresh->finalized_at, 'وقت الإقفال لازم يتسجّل');
        $this->assertSame($acc->id, $fresh->finalized_by, 'مين قفل لازم يتسجّل');
    }

    /**
     * ⭐ المدفوعات بتكمّل بعد الإقفال — ده معنى «مرحلتين».
     *
     * السبب واقعي: مش بندفع ١٢ موظف في نفس اللحظة. لو الإقفال
     * كان يمنع الدفع، محتاجين نفتح الفترة كل ما نخلص دفعة — وده
     * أسوأ من إنه يفضل مقفولة وسطرين لسه مدفوعين.
     */
    public function test_payment_continues_after_the_period_is_closed(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $line = EmployeePayrollLine::first();
        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $acc)->assertOk();

        $this->assertSame('paid', $line->fresh()->status);
    }

    /** بعد الإقفال: مفيش احتساب ولا تعديل ولا اعتماد — بس دفع */
    public function test_a_closed_period_freezes_the_numbers_but_allows_paying(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $e = $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $line = EmployeePayrollLine::first();

        // احتساب جديد مرفوض
        $this->makeAttendance($e, '2026-03-05', ['worked_hours' => 8]);
        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)
            ->assertStatus(422);

        // تعديل مرفوض
        $this->putJsonAs("/api/payroll/lines/{$line->id}", ['hours' => 99], $acc)
            ->assertStatus(422);

        // الأرقام ماتغيّرتش
        $this->assertEquals(6.0, (float) $line->fresh()->hours);
        $this->assertEquals(600.0, (float) $line->fresh()->amount);

        // بس الدفع شغال
        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $acc)->assertOk();
    }

    /**
     * ⭐ آخر سطر مدفوع بيقفل الفترة لوحدها → `paid`.
     *
     * عشان الأدمن ما يدوسش زرار تاني في الآخر. والشرط إن كل
     * **المستحق** اتصرف — السطر اللي راتبه صفر ماينفعش يتدفع،
     * فلو دخل في العدّ كانت الفترة مش هتعمل مدفوعة أبداً.
     */
    public function test_paying_the_last_line_marks_the_period_paid(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->hourlyEmployee(['2026-03-02' => 6]);        // 600
        $e0 = $this->makeEmployee(['hourly_rate' => 100]); // كله غايب = 0
        $this->makeAttendance($e0, '2026-03-03', ['status' => 'absent', 'worked_hours' => 0]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $this->assertSame('finalized', $period->fresh()->status);

        // السطر المستحق الوحيد
        $payable = EmployeePayrollLine::where('amount', '>', 0)->first();

        $this->postJsonAs("/api/payroll/lines/{$payable->id}/pay", [], $acc)->assertOk();

        $this->assertSame('paid', $period->fresh()->status,
            'آخر مستحق اتصرف → الفترة مدفوعة، والسطر صفري ما يمنعش');
    }

    // ============================================================
    // إعادة الفتح
    // ============================================================

    public function test_reopen_works_and_is_recorded(): void
    {
        $acc = $this->accountant();
        $period = $this->period(['status' => 'finalized', 'finalized_at' => now()]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/reopen", [], $acc)->assertOk();

        $fresh = $period->fresh();
        $this->assertSame('open', $fresh->status);
        $this->assertNull($fresh->finalized_at);
        $this->assertNull($fresh->finalized_by);
    }

    /** ⚠️ السطور المدفوعة ما بترجعش — اللي اتصرف مالوش رجعة */
    public function test_reopen_does_not_undo_payments(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/close", [], $acc)->assertOk();
        $line = EmployeePayrollLine::first();
        $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $acc)->assertOk();

        $r = $this->postJsonAs("/api/payroll/periods/{$period->id}/reopen", [], $acc);

        $r->assertOk();
        $this->assertSame(1, $r->json('paid_lines'));
        $this->assertSame('paid', $line->fresh()->status, 'المدفوع فضل مدفوع');
        $this->assertSame('open', $period->fresh()->status);
    }

    /** إعادة فتح فترة مفتوحة = طلب بلا معنى */
    public function test_reopening_an_open_period_is_rejected(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->postJsonAs("/api/payroll/periods/{$period->id}/reopen", [], $acc)
            ->assertStatus(422);
    }

    /**
     * ⚠️ فتح فترة اتقفلت لازم يبان في سجل العمليات.
     *
     * ده قرار محاسبي — لو حد غيّر رقم في شهر فات، لازم نعرف
     * مين فتح ومتى.
     */
    public function test_reopen_is_written_to_the_audit_log(): void
    {
        $acc = $this->accountant();
        $period = $this->period(['status' => 'finalized']);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/reopen", [], $acc)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $acc->id,
            'action' => 'update',
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
        ]);
    }

    public function test_close_is_written_to_the_audit_log(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)->assertOk();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/close", [], $acc)->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $acc->id,
            'action' => 'update',
            'entity_type' => 'payroll_period',
            'entity_id' => $period->id,
        ]);
    }

    // ============================================================
    // «دفع من غير اعتماد» مرفوض حتى لو الفترة مفتوحة
    // ============================================================

    public function test_a_draft_line_cannot_be_paid(): void
    {
        $acc = $this->accountant();
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();
        $line = EmployeePayrollLine::first();

        $r = $this->postJsonAs("/api/payroll/lines/{$line->id}/pay", [], $acc);

        $r->assertStatus(422);
        $this->assertSame('draft', $line->fresh()->status, 'لسه مسودّة');
    }

    /** الإقفال والإertura محتاجين صلاحية المرتبات */
    public function test_closing_requires_the_payroll_permission(): void
    {
        $receptionist = $this->makeUserWithRole('receptionist', ['attendance.view']);
        $period = $this->period(['status' => 'finalized']);

        $this->postJsonAs("/api/payroll/periods/{$period->id}/reopen", [], $receptionist)
            ->assertStatus(403);
    }

    public function test_overlapping_periods_are_rejected(): void
    {
        $acc = $this->accountant();
        $this->period();

        $this->postJsonAs('/api/payroll/periods', [
            'name' => 'مارس تاني',
            'start_date' => '2026-03-15',
            'end_date' => '2026-04-15',
        ], $acc)->assertStatus(422);
    }

    public function test_approving_with_no_drafts_is_rejected(): void
    {
        $acc = $this->accountant();
        $period = $this->period();

        $this->postJsonAs("/api/payroll/periods/{$period->id}/approve", [], $acc)
            ->assertStatus(422);
    }

    // ============================================================
    // الصلاحيات
    // ============================================================

    /**
     * ⭐ الفصل بين التسجيل والمرتبات.
     *
     * موظف الاستقبال بيقدّم الحضور بس **مش** يشغّل المرتبات — حماية
     * من إنه يقدّم الحضور بنفسه ويحسب مرتبه عليه.
     */
    public function test_attendance_manager_cannot_run_payroll(): void
    {
        $receptionist = $this->makeUserWithRole('receptionist', [
            'attendance.view', 'attendance.manage',
        ]);
        $period = $this->period();
        $this->hourlyEmployee(['2026-03-02' => 6]);

        $this->getJsonAs('/api/payroll/periods', $receptionist)->assertStatus(403);
        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $receptionist)
            ->assertStatus(403);

        $this->assertSame(0, EmployeePayrollLine::count());
    }

    public function test_accountant_can_run_payroll(): void
    {
        $acc = $this->accountant();
        $e = $this->makeEmployee(['hourly_rate' => 100]);

        // يقدّم الحضور
        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-02',
            'records' => [['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 5]],
        ], $acc)->assertOk();

        // يشغّل المرتبات
        $period = $this->period();
        $this->postJsonAs("/api/payroll/periods/{$period->id}/generate", [], $acc)->assertOk();

        $this->assertSame(1, EmployeePayrollLine::count());
        $this->assertEquals(500.0, (float) EmployeePayrollLine::first()->amount);
    }

    // ============================================================
    // حارس ضد الـ duplicate-calculation الباج اللي حصل قبل كده
    // ============================================================

    /**
     * ⭐ `amount` يتحسب في **مكان واحد بس**.
     *
     * الباج الأصلي في الحضور كان فيه نسختين (PHP و TypeScript) والنتيجة
     * رقمين مختلفين. لو حد أضاف نسخة تانية من معادلة الأجر، الأرقام
     * هتبدأ تختلف والمرتب غلط — والمرتب غلط = ضرر حقيقي.
     *
     * `computeAmount` في الـ Model هو المكان الوحيد المسموح.
     */
    public function test_amount_is_computed_in_exactly_one_place(): void
    {
        $controller = file_get_contents(
            app_path('Http/Controllers/Api/EmployeePayrollController.php')
        );

        // ممنوع `hours * rate` مكتوب مباشرة في الـ controller
        $this->assertDoesNotMatchRegularExpression(
            '/\$hours\s*\*\s*\$?[a-z_]*rate/i',
            $controller,
            'في حساب المبلغ مكتوب يدوي في الـ controller — لازم `EmployeePayrollLine::computeAmount`'
        );

        // والمكان ده فعلاً موجود وشغال
        $this->assertEquals(595.0, EmployeePayrollLine::computeAmount(7.0, 85.0));
        $this->assertEquals(0.0, EmployeePayrollLine::computeAmount(7.0, null),
            'من غير سعر ساعة = صفر');
    }

    /**
     * ⭐ مفيش نسخة تانية من حساب الأجر في **شاشة المرتبات**.
     *
     * الشاشة بتعرض `amount` من الـ API زي ما هو.
     *
     * ⚠️ استثناءين مقصودين: `attendance/page.tsx` و
     * `EmployeeProfile.tsx`. في السنتين دول الضرب **معاينة للعين**
     * («الأجر المتوقع كذا») مش رقم مرتب — الرقم اللي بيتدفع
     * بيجي من `EmployeePayrollLine::computeAmount` في السيرفر.
     *
     * سبب الاستثناء: لو خدّينا الضرب من كل الواجهة،-screen
     * attendance كان هيبقى لازم يطلبEndpoint جديد لكل معاينة.
     * الأهم إن **الشاشة اللي بتعرض مرتب مدفوع** تفضل عرض بس.
     *
     * عشان الـ guard ده مفهوم، الـ regex بيشيل التعليقات الأول —
     * من غير كده بيرمي على كل تعليق بيشرح القاعدة نفسها.
     */
    /**
     * يشيل تعليقات JS/TS سطور/block.
     *
     * لازم يتشالوا قبل أي فحص على الكود — والسبب موثّق في
     * الاستدعاء. الرموز في السلسلة النصية محفوظة عشان ما نبوسش
     * على regex.
     */
    private function stripComments(string $src): string
    {
        // نقسّم السطور الأول عشان نعرف إحنا جوّه string ولا لأ
        $out = [];
        $inBlock = false;

        foreach (preg_split('/\R/', $src) as $line) {
            $result = '';
            $len = strlen($line);
            $inStr = null; // ' أو " أو `

            for ($i = 0; $i < $len; $i++) {
                $c = $line[$i];
                $next = $i + 1 < $len ? $line[$i + 1] : '';

                if ($inBlock) {
                    if ($c === '*' && $next === '/') {
                        $inBlock = false;
                        $i++;
                    }
                    continue; // نسيب محتوى block comment
                }

                if ($inStr !== null) {
                    $result .= $c;
                    if ($c === '\\') {
                        $result .= $next;
                        $i++;
                    } elseif ($c === $inStr) {
                        $inStr = null;
                    }
                    continue;
                }

                // بداية string
                if ($c === '"' || $c === "'" || $c === '`') {
                    $inStr = $c;
                    $result .= $c;
                    continue;
                }

                // بداية block comment
                if ($c === '/' && $next === '*') {
                    $inBlock = true;
                    $i++;
                    continue;
                }

                // سطر comment لحد آخر السطر
                if ($c === '/' && $next === '/') {
                    break;
                }

                $result .= $c;
            }

            $out[] = $result;
        }

        return implode("\n", $out);
    }

    private function assertNoFrontendPayrollMath(): void
    {
        $frontend = base_path('../frontend');

        $offenders = [];

        foreach (['app', 'components', 'lib'] as $dir) {
            $path = $frontend.'/'.$dir;
            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($files as $file) {
                if (! in_array($file->getExtension(), ['ts', 'tsx'], true)) {
                    continue;
                }

                $src = (string) file_get_contents($file->getPathname());

                // ⭐⭐ نشيل **التعليقات** قبل الفحص.
                //
                // بدون الخطوة دي الـ guard بيرمي على كل تعليق بيشرح
                // القاعدة نفسها («الأجر المتوقع = الساعات × السعر»)،
                // وأول واحد بيقع هو اللي كاتب الشرح. ضاع وقت مرتين
                // في إعادة صياغة تعليقات عشان تتجاهى الـ guard.
                //
                // التعليقات مش كود، والحساب ما بيحصلش فيها.
                $code = $this->stripComments($src);

                foreach (preg_split('/\R/', $code) as $i => $line) {
                    // ⭐ استثناء **شاشة الحضور وملف الموظف**.
                    //
                    // في السنتين دول `hours * rate` **معاينة للعين**
                    // مش مرتب محتسب: بتقول الأدمن «متوقع يبقى كذا»
                    // وهو لسه بيكتب الأرقام. الرقم اللي بيتدفع
                    // بيجي من `EmployeePayrollLine::computeAmount`.
                    //
                    // الشاشة الجاية (`payroll/page.tsx`) مفيهاش
                    // ضرب — واللي معناه إنها عرض بس.
                    $relative = str_replace('\\', '/', $file->getPathname());
                    if (str_contains($relative, '/attendance/page.tsx')
                        || str_contains($relative, '/EmployeeProfile.tsx')) {
                        continue;
                    }
                    // `hours * rate` أو `rate * hours` — كود بس
                    if (preg_match('/\w*(hours|ساعات)\w*\s*[*×]\s*[\w.$\[\]]*\w*(rate|سعر|ساعة)/u', $line)
                        || preg_match('/\w*(rate|سعر)\w*\s*[*×]\s*[\w.$\[\]]*\w*(hours|ساعات)/u', $line)) {
                        $offenders[] = $file->getPathname().' → '.trim($line);
                        break;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            'في حساب أجر في الواجهة — لازم الرقم ييجي من السيرفر: '.implode(', ', $offenders));
    }

    /** sanity: الـ guard نفسه لازم يمسك الكود الحقيقي */
    public function test_the_frontend_guard_actually_catches_the_bug(): void
    {
        $bad = 'const amount = hours * rate;';

        $this->assertMatchesRegularExpression(
            '/\w*(hours|ساعات)\w*\s*[*×]\s*[\w.$\[\]]*\w*(rate|سعر|ساعة)/u',
            $bad,
            'guard مش بيمسك `hours * rate` — الاختبار ده بلا فايدة'
        );
    }

    public function test_frontend_has_no_duplicate_payroll_math(): void
    {
        $this->assertNoFrontendPayrollMath();
    }
}