<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Tests\TestCase;

/**
 * الحضور بالساعات المرنة.
 *
 * القاعدة الأهم: **`worked_hours` هي مصدر الأجر، ومصدر واحد بس**.
 * قبل كده كان في نسختين (PHP و TypeScript) والنتيجة كانت رقمين
 * مختلفين لنفس السجل: السيرفر بيخزّن ٠ والشاشة بتعرض فاضي.
 *
 * دي اختبارات على القاعدة دي — ولّا مين يقول إن الساعات اتغيرت
 * من غير ما حد يفكر.
 */
class AttendanceHoursTest extends TestCase
{
    private function admin()
    {
        return $this->makeUserWithRole('admin', ['attendance.view', 'attendance.manage']);
    }

    private function makeActiveEmployee(array $attrs = []): Employee
    {
        return $this->makeEmployee(array_merge([
            'status' => 'active',
            'employment_type' => 'contract',
        ], $attrs));
    }

    // ============================================================
    // الساعات هي المصدر
    // ============================================================

    public function test_admin_entered_hours_are_stored_verbatim(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        $r = $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-10',
            'records' => [
                ['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 7.5],
                ['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 6.25],
            ],
        ], $admin);

        // ما فيش account — الاختبار بيحتاج واحد
        $this->assertSame(200, $r->status());
        $record = AttendanceRecord::first();
        $this->assertEquals(6.25, (float) $record->worked_hours);
    }

    public function test_absent_always_means_zero_hours(): void
    {
        $admin = $this->admin();
        $absent = $this->makeActiveEmployee();
        $leave = $this->makeActiveEmployee();

        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-11',
            'records' => [
                ['employee_id' => $absent->id, 'status' => 'absent', 'worked_hours' => 8],
                ['employee_id' => $leave->id, 'status' => 'on_leave', 'worked_hours' => 6],
            ],
        ], $admin);

        foreach (AttendanceRecord::all() as $r) {
            $this->assertSame(
                0.0,
                (float) $r->worked_hours,
                "الغائب/الإجازة لازم صفر — {$r->status} رجع {$r->worked_hours}"
            );
        }
    }

    public function test_half_day_defaults_to_four_hours(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-12',
            'records' => [['employee_id' => $e->id, 'status' => 'half_day']],
        ], $admin);

        $this->assertEquals(4.0, (float) AttendanceRecord::first()->worked_hours);
    }

    public function test_half_day_can_be_overridden(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        // نص يوم بس ٣ ساعات فعلاً
        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-13',
            'records' => [['employee_id' => $e->id, 'status' => 'half_day', 'worked_hours' => 3]],
        ], $admin);

        $this->assertEquals(3.0, (float) AttendanceRecord::first()->worked_hours);
    }

    public function test_present_without_hours_stores_zero(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-14',
            'records' => [['employee_id' => $e->id, 'status' => 'present']],
        ], $admin);

        $this->assertEquals(0.0, (float) AttendanceRecord::first()->worked_hours);
    }

    public function test_zero_is_respected_not_treated_as_missing(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        // «حاضر بس ٠ ساعة» قرار مشروع (مش مصادفة، بيوم خالص)
        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-15',
            'records' => [['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 0]],
        ], $admin);

        $this->assertEquals(0.0, (float) AttendanceRecord::first()->worked_hours);
    }

    // ============================================================
    // التحقق من المدخلات
    // ============================================================

    public function test_hours_above_24_are_rejected(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        $r = $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-16',
            'records' => [['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 25]],
        ], $admin);

        $r->assertStatus(422);
        $this->assertDatabaseMissing('attendance_records', ['date' => '2026-03-16']);
    }

    /**
     * «غايب + ٩٩ ساعة» لازم يرفض، مش يتجاهل.
     *
     * لو اتجاهلناها، حد يقدر يسجّل ٢٣ ساعة لموظف غايب من غير ما
     * النظام يوقفه. الرفض بيقول «الساعات دي غلط» بوضوح.
     */
    public function test_absent_with_huge_hours_is_rejected_not_silently_zeroed(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        $r = $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-17',
            'records' => [['employee_id' => $e->id, 'status' => 'absent', 'worked_hours' => 99]],
        ], $admin);

        $r->assertStatus(422);
    }

    public function test_negative_hours_are_rejected(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-18',
            'records' => [['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => -5]],
        ], $admin)->assertStatus(422);
    }

    public function test_invalid_status_is_rejected(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-19',
            'records' => [['employee_id' => $e->id, 'status' => 'maybe', 'worked_hours' => 5]],
        ], $admin)->assertStatus(422);
    }

    // ============================================================
    // upsert — سطر واحد لكل (موظف + يوم)
    // ============================================================

    public function test_recording_twice_updates_instead_of_duplicating(): void
    {
        $admin = $this->admin();
        $e = $this->makeActiveEmployee();

        // نفس اليوم مرتين — لازم يتحدّث
        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-20',
            'records' => [['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 2]],
        ], $admin);

        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-20',
            'records' => [['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 9]],
        ], $admin);

        $this->assertSame(1, AttendanceRecord::count(), 'سطر واحد بس — مش اتضاعف');
        $this->assertEquals(9.0, (float) AttendanceRecord::first()->worked_hours);

        // يوم تاني = سطر تاني (المفتاح employee + date)
        $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-21',
            'records' => [['employee_id' => $e->id, 'status' => 'present', 'worked_hours' => 4]],
        ], $admin);

        $this->assertSame(2, AttendanceRecord::count(), 'يوم مختلف = سطر مختلف');
    }

    public function test_the_whole_day_is_saved_in_one_call(): void
    {
        $admin = $this->admin();
        $employees = collect(range(1, 5))->map(fn () => $this->makeActiveEmployee());

        $r = $this->postJsonAs('/api/attendance', [
            'date' => '2026-03-22',
            'records' => $employees->map(fn ($e, $i) => [
                'employee_id' => $e->id,
                'status' => 'present',
                'worked_hours' => 4 + $i,
            ])->all(),
        ], $admin);

        $r->assertStatus(200);
        $this->assertSame(5, $r->json('saved'));
        // 4+5+6+7+8 = 30 — ده مجموع الساعات الفعلية المولّدة
        $this->assertEquals(30.0, (float) $r->json('total_hours'));
        $this->assertSame(5, AttendanceRecord::count());
    }

    public function test_volunteers_are_excluded_from_the_sheet(): void
    {
        $admin = $this->admin();
        $this->makeActiveEmployee(['employment_type' => 'contract']);
        $this->makeActiveEmployee(['employment_type' => 'volunteer']);

        $r = $this->getJsonAs('/api/attendance/day?date=2026-03-23', $admin);

        $this->assertCount(1, $r->json('rows'), 'المتطوع مالوش حضور');
    }

    // ============================================================
    // ⭐ مفيش نسخة ثانية من حساب الساعات
    // ============================================================

    /**
     * حارس ضد Regression: مفيش نسخة تانية بتحسب الساعات في الواجهة.
     *
     * الباج الأصلي كان: `hoursBetween` في PHP ونسخة مطابقة في
     * TypeScript. لو حد أضاف تالتة، الأرقام هتبدأ تختلف من غير ما
     * أي اختبار يفشل.
     */
    public function test_frontend_has_no_duplicate_hours_calculation(): void
    {
        $frontend = base_path('../frontend');

        // sanity: الـ guard لازم يمسك الكود فعلاً، وإلا الاختبار
        // بيمرّ على الفاضي من غير ما يفحص حاجة
        $sanity = <<<'TS'
function hoursBetween(inp: string, out: string) {
  const start = ih * 60 + im;
  const end = oh * 60 + om;
}
TS;
        $this->assertMatchesRegularExpression(
            '#[)\d\w]\s*[*\/]\s*60\b|\bhours?\b[^;]*[*\/]\s*\d#i',
            $sanity,
            'guard مش بيمسك حساب الساعات الحقيقي — الاختبار ده بلا فايدة'
        );

        $offenders = [];

        foreach (['app', 'components', 'lib'] as $dir) {
            $path = $frontend.'/'.$dir;
            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'ts' && $file->getExtension() !== 'tsx') {
                    continue;
                }
                $src = file_get_contents($file->getPathname());

                // ⭐ البحث عن **حساب الساعات من وقت دخول وخروج**:
                // لازم السطر يقرأ وقتين ويحسب منهم (فاصلة أو رقم
                // صحيح متغير). شرط «السطر نفسه» مهم — عشان
                // `check_in` في المقارنة و`toIso` فيه `* 60000`
                // ما اتحسبوش.
                foreach (preg_split('/\R/', $src) as $line) {
                    $hasBothTimes =
                        (str_contains($line, 'check_in') && str_contains($line, 'check_out'))
                        || (str_contains($line, 'inp') && str_contains($line, 'out'));

                    // hours بالضبط (مش CSS ولا أي `*` تانية):
                    $hasHoursMath = preg_match(
                        '#[)\d\w]\s*[*\/]\s*60\b|\bhours?\b[^;]*[*\/]\s*\d#i',
                        $line
                    );

                    if ($hasBothTimes && $hasHoursMath) {
                        $offenders[] = $file->getPathname().' → '.trim($line);
                        break;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'في دالة بتحسب الساعات في الواجهة — لازم السيرفر يكون المصدر الوحيد: '
            . implode(', ', $offenders)
        );
    }

    public function test_late_minutes_is_gone_everywhere(): void
    {
        // العمود اتشال من الداتابيز، فلازم يختفي من الكود كله
        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('attendance_records', 'late_minutes'),
            'عمود late_minutes لسه موجود — مافيش وقت مرجعي نقيس بيه التأخير'
        );

        $this->assertStringNotContainsString(
            'late_minutes',
            file_get_contents(app_path('Http/Controllers/Api/AttendanceController.php')),
            'الكود لسه بيفكر في late_minutes'
        );
    }

    // ============================================================
    // سعر الساعة
    // ============================================================

    public function test_hourly_rate_is_stored_and_validated(): void
    {
        $admin = $this->makeUserWithRole('admin', ['students.edit']);
        $e = $this->makeActiveEmployee();

        // التحديث بيستخدم `sometimes` — يعني كل حقل لوحده يكفي
        $this->putJsonAs("/api/employees/{$e->id}", ['hourly_rate' => 95.5], $admin)->assertOk();
        $this->assertEquals(95.5, (float) Employee::find($e->id)->hourly_rate);

        // سالب مرفوض
        $this->putJsonAs("/api/employees/{$e->id}", ['hourly_rate' => -10], $admin)->assertStatus(422);

        // أكبر من ألف مرفوض (على الأرجح خطأ إدخال)
        $this->putJsonAs("/api/employees/{$e->id}", ['hourly_rate' => 5000], $admin)->assertStatus(422);

        // null = بيشتغل بالشهر
        $this->putJsonAs("/api/employees/{$e->id}", ['hourly_rate' => null], $admin)->assertOk();
        $this->assertNull(Employee::find($e->id)->hourly_rate);
    }

    public function test_hourly_rate_appears_in_the_attendance_sheet(): void
    {
        $admin = $this->admin();
        $this->makeActiveEmployee();
        $this->makeActiveEmployee(['hourly_rate' => 80]);

        $r = $this->getJsonAs('/api/attendance/day?date=2026-03-24', $admin);

        $rates = collect($r->json('rows'))->pluck('employee.hourly_rate')->all();
        $this->assertContains(80.0, array_map('floatval', $rates));
        $this->assertContains(null, $rates);
    }
}