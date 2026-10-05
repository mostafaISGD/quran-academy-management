<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeePayrollLine;
use App\Models\PayrollPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * مرتبات الموظفين بالساعات.
 *
 * ⭐ المبدأ: الاحتساب بيقرأ الحضور المسجّل وبياخد **لقطة**.
 * بعد ما يتحسب السطر، تعديل الحضور أو تغيير سعر الموظف ما بيعدّلش
 * المرتب — لازم إعادة احتساب صريحة.
 *
 * دورة الحياة:
 *   1. `generate`  → يقرأ الحضور ويكتب سطور `draft`
 *   2. الأدمن يراجع ويعدّل (المسودّة بس قابلة للتعديل)
 *   3. `approve`   → يعتمد كل المسودّات مرة واحدة
 *   4. `pay`       → يسجّل الدفع
 *
 * مفيش «حساب المرتب أوتوماتيك بالكامل»: المسودّات في انتظار مراجعة
 * إنسان، لأن رقم المرتب قرار فيه مال.
 */
class EmployeePayrollController extends Controller
{
    // ============================================================
    // الفترات
    // ============================================================

    public function periods(Request $request)
    {
        $periods = PayrollPeriod::query()
            ->withCount('employeeLines')
            ->orderByDesc('start_date')
            ->get();

        // إجماليات لكل فترة — محسوبة من employee_payroll_lines
        $totals = DB::table('employee_payroll_lines')
            ->selectRaw('payroll_period_id, sum(amount) as total, count(*) as lines, sum(hours) as hours')
            ->groupBy('payroll_period_id')
            ->get()
            ->keyBy('payroll_period_id');

        $rows = $periods->map(function ($p) use ($totals) {
            $t = $totals->get($p->id);

            return [
                'id' => $p->id,
                'name' => $p->name,
                'start_date' => $p->start_date,
                'end_date' => $p->end_date,
                'status' => $p->status,
                'lines_count' => $p->employee_lines_count,
                'total_amount' => round((float) ($t->total ?? 0), 2),
                'total_hours' => round((float) ($t->hours ?? 0), 2),
                // ⭐ بتّحطّ في الشاشة عشان زر الإقفال يقول
                // «في ٣ أسطر لسه مدفوعش» قبل ما يدوس.
                'payable' => $this->payableCount($p->id),
            ];
        });

        return response()->json([
            'periods' => $rows,
            'summary' => [
                'total' => $periods->count(),
                'open' => $periods->where('status', 'open')->count(),
            ],
            // موظفين بسعر ساعة — دول اللي ليهم سطور
            'hourly_employees' => $this->hourlyEmployees(),
        ]);
    }

    /** موظفين عندهم سعر ساعة — هم بس اللي بيتحسب لهم بالساعات */
    private function hourlyEmployees(): array
    {
        return Employee::query()
            ->whereNotNull('hourly_rate')
            ->whereNotIn('employment_type', ['volunteer'])
            ->whereIn('status', ['active', 'on_leave'])
            ->orderBy('name')
            ->get(['id', 'name', 'job_title', 'hourly_rate'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'job_title' => $e->job_title,
                'hourly_rate' => (float) $e->hourly_rate,
            ])
            ->all();
    }

    public function createPeriod(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $overlap = PayrollPeriod::where('start_date', '<=', $data['end_date'])
            ->where('end_date', '>=', $data['start_date'])
            ->exists();

        if ($overlap) {
            return response()->json([
                'message' => 'الفترة دي بتتداخل مع فترة موجودة',
            ], 422);
        }

        $period = PayrollPeriod::create([
            'organization_id' => $request->user()->organization_id,
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => 'open',
        ]);

        return response()->json($period, 201);
    }

    // ============================================================
    // سطور فترة
    // ============================================================

    public function lines(Request $request, PayrollPeriod $period)
    {
        $lines = EmployeePayrollLine::query()
            ->where('employee_payroll_lines.payroll_period_id', $period->id)
            // ⚠️ `orderBy('employee.name')` من غير join بيرجّع
            // `ORDER BY "employee"."name"` → no such column.
            // لازم join صريح، و`select(*)` عشان الأعمدة
            // المتكررة (name في الجدولين) ما تتفلطش.
            ->join('employees', 'employees.id', '=', 'employee_payroll_lines.employee_id')
            ->select('employee_payroll_lines.*')
            ->with('employee')
            ->orderBy('employees.name')
            ->get()
            ->map(fn ($l) => [
                'id' => $l->id,
                'employee' => [
                    'id' => $l->employee->id,
                    'name' => $l->employee->name,
                    'job_title' => $l->employee->job_title,
                    'department' => $l->employee->department,
                ],
                'hours' => round((float) $l->hours, 2),
                'hourly_rate' => $l->hourly_rate !== null ? (float) $l->hourly_rate : null,
                'amount' => round((float) $l->amount, 2),
                'currency' => $l->currency,
                'days_present' => $l->days_present,
                'status' => $l->status,
                'status_label' => EmployeePayrollLine::statusLabel($l->status),
                'editable' => $l->isEditable(),
                'payment_method' => $l->payment_method,
                'reference' => $l->reference,
                'paid_at' => $l->paid_at,
                'notes' => $l->notes,
            ]);

        // موظفين ليهم سعر ساعة بس مالهمش سطر في الفترة دي
        // (يمكن ما فيش حضور). لازم يبانوا عشان الأدمن يلاحظ.
        $withLine = $lines->pluck('employee.id')->all();
        $missing = collect($this->hourlyEmployees())
            ->reject(fn ($e) => in_array($e['id'], $withLine))
            ->map(fn ($e) => [
                'employee' => $e,
                'hours' => null,
                'amount' => null,
                'status' => 'no_record',
                'status_label' => 'مفيش حضور مسجّل',
            ]);

        return response()->json([
            'period' => [
                'id' => $period->id,
                'name' => $period->name,
                'start_date' => $period->start_date,
                'end_date' => $period->end_date,
                'status' => $period->status,
                'finalized_at' => $period->finalized_at,
            ],
            'lines' => $lines,
            'missing' => $missing,
            'totals' => [
                'hours' => round($lines->sum('hours'), 2),
                'amount' => round($lines->sum('amount'), 2),
                'lines' => $lines->count(),
                'paid' => $lines->where('status', 'paid')->count(),
                'approved' => $lines->where('status', 'approved')->count(),
                'draft' => $lines->where('status', 'draft')->count(),
            ],
        ]);
    }

    /**
     * ⭐ الاحتساب: يقرأ الحضور ويكتب سطور مسودّة.
     *
     * - الموظف لازم يكون عنده `hourly_rate` (الباقي شهري، مش هنا).
     * - الساعات = مجموع `worked_hours` من `attendance_records` في الفترة.
     * - لو في سطر **مسودّة** لنفس الموظف في نفس الفترة → يتحدّث
     *   (إعادة احتساب). لو معتمد أو مدفوع → **مش هيتلمس**، لأن
     *   ده قيد نقدي.
     */
    public function generate(Request $request, PayrollPeriod $period)
    {
        // الفترة المقفولة (معتمدة/مدفوعة) مش بتتقبل احتساب جديد
        if ($period->status !== 'open') {
            return response()->json([
                'message' => 'الفترة دي مقفولة — افتحها الأول',
            ], 422);
        }

        $attendance = DB::table('attendance_records')
            ->whereRange('date', $period->start_date, $period->end_date)
            ->selectRaw('employee_id, sum(worked_hours) as hours, count(*) as days')
            ->groupBy('employee_id')
            ->get()
            ->keyBy('employee_id');

        $employees = Employee::query()
            ->whereNotNull('hourly_rate')
            ->whereNotIn('employment_type', ['volunteer'])
            ->whereIn('status', ['active', 'on_leave'])
            ->get();

        $orgId = $request->user()->organization_id ?? 1;
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            $existing = EmployeePayrollLine::where('payroll_period_id', $period->id)
                ->where('employee_id', $employee->id)
                ->first();

            // معتمد أو مدفوع → ماينفعش نعيد حسابه
            if ($existing && ! $existing->isEditable()) {
                $skipped++;
                continue;
            }

            $att = $attendance->get($employee->id);

            // ⭐ مفيش سجلات حضور في الفترة دي → ماعملناش سطر أصلاً.
            //
            // السطر صفري Amount معناه «اشتغل وراتبه صفر»، وده هيبان
            // في جدول المرتبات كأنه مسموح. الأحسن نخليه يظهر في
            // `missing` في `lines()` — «لسه ما سجّلناش حضوره» —
            // وده تنبيه مفيد للأدمن مش رقم مربك.
            if (! $att) {
                continue;
            }

            $hours = round((float) $att->hours, 2);
            $days = (int) $att->days;
            $amount = EmployeePayrollLine::computeAmount($hours, (float) $employee->hourly_rate);

            $payload = [
                'organization_id' => $orgId,
                'hours' => $hours,
                'hourly_rate' => (float) $employee->hourly_rate,
                'amount' => $amount,
                'currency' => 'EGP',
                'days_present' => $days,
                'period_start' => $period->start_date,
                'period_end' => $period->end_date,
                'status' => 'draft',
            ];

            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                EmployeePayrollLine::create($payload + [
                    'payroll_period_id' => $period->id,
                    'employee_id' => $employee->id,
                ]);
                $created++;
            }
        }

        app(\App\Services\AuditLogService::class)->log(
            'create', 'employee_payroll', $period->id,
            null,
            ['created' => $created, 'updated' => $updated, 'skipped' => $skipped],
            $request,
        );

        return response()->json([
            'message' => "تم الاحتساب: {$created} جديد، {$updated} متحدّث"
                .($skipped ? "، {$skipped} متخطّى (معتمد أو مدفوع)" : ''),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]);
    }

    /** تعديل سطر — المسودّات بس، والفترة لازم تكون مفتوحة */
    public function updateLine(Request $request, EmployeePayrollLine $line)
    {
        if (! $line->isEditable()) {
            return response()->json([
                'message' => 'السطر ده اتعتمد بالفعل — مش قابل للتعديل',
            ], 422);
        }

        // السطر لسه مسودّة بس الفترة اتقفلت. المسودّات المفروض
        // مايفضلش منها حاجة وقت الإقفال (شرط الإقفال بيمنع ده)،
        // فده حالة inconsistent — نمنعها برضه بدل ما نعمل تعديل
        // في فترة «مقفولة».
        if ($line->period && ! $line->period->isOpen()) {
            return response()->json([
                'message' => 'الفترة دي مقفولة — افتحها الأول',
            ], 422);
        }

        $data = $request->validate([
            'hours' => 'sometimes|numeric|min:0|max:800',
            'hourly_rate' => 'nullable|numeric|min:0|max:1000',
            'days_present' => 'sometimes|integer|min:0|max:31',
            'notes' => 'nullable|string',
        ]);

        $old = $line->only(array_keys($data));

        if (array_key_exists('hours', $data) || array_key_exists('hourly_rate', $data)) {
            $hours = (float) ($data['hours'] ?? $line->hours);
            $rate = array_key_exists('hourly_rate', $data)
                ? ($data['hourly_rate'] === null ? null : (float) $data['hourly_rate'])
                : ($line->hourly_rate === null ? null : (float) $line->hourly_rate);

            // ⭐ المبلغ بيتحسب من الساعات والسعر — مايبعتش من العميل
            $data['amount'] = EmployeePayrollLine::computeAmount($hours, $rate);
        }

        $line->update($data);

        app(\App\Services\AuditLogService::class)->logUpdate(
            'employee_payroll', $line->id, $old, $line->only(array_keys($data)), $request,
        );

        return response()->json($line->fresh());
    }

    /** اعتماد كل المسودّات في الفترة مرة واحدة */
    public function approve(Request $request, PayrollPeriod $period)
    {
        if ($period->status !== 'open') {
            return response()->json(['message' => 'الفترة دي مقفولة'], 422);
        }

        $drafts = EmployeePayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'draft')
            ->get();

        if ($drafts->isEmpty()) {
            return response()->json([
                'message' => 'مفيش مسودّات تعتمد — اعمل احتساب الأول',
            ], 422);
        }

        // السطر اللي راتبه صفر (مش ليه حضور) مش بيتعتمد — عشان
        // ميتدفعش حد راتبه صفر
        $approved = $drafts->filter(fn ($l) => (float) $l->amount > 0);
        $zero = $drafts->reject(fn ($l) => (float) $l->amount > 0);

        EmployeePayrollLine::whereIn('id', $approved->pluck('id'))
            ->update(['status' => 'approved', 'updated_at' => now()]);

        app(\App\Services\AuditLogService::class)->log(
            'update', 'employee_payroll', $period->id,
            null,
            ['approved' => $approved->count(), 'skipped_zero' => $zero->count()],
            $request,
        );

        return response()->json([
            'message' => "تم اعتماد {$approved->count()} سطر"
                .($zero->isNotEmpty() ? "، {$zero->count()} اتخطّى لأن راتبه ٠" : ''),
            'approved' => $approved->count(),
            'skipped_zero' => $zero->count(),
        ]);
    }

    /**
     * ⭐ إقفال الفترة: `open` → `finalized`.
     *
     * الإقفال معناه: **الأرقام اتقفلت**. مستحيل يتحسب تاني ولا
     * يتعدّل سطر معتمد بعد كده.
     *
     * ⚠️ المدفوعات **بتكمّل** بعد الإقفال — ده اختيار مقصود
     * (مرحلتين). السبب واقعي: مستحيل نصرف فلوس ١٢ موظف في نفس
     * اللحظة. فلو الإقفال كان يمنع الدفع، محتاجين نحل نقود
     * أو نفتح الفترة كل ما نخلص دفعة — وده أسوأ.
     *
     * فالقاعدة بعد الإقفال:
     *   | مسودّة  → تعديل ❌ | اعتماد ❌ | دفع ❌ (لسه مش معتمد)
     *   | معتمد  → تعديل ❌ |            | دفع ✅
     *   | مدفوع  → تعديل ❌ |            | دفع ❌ (اتدفع)
     *
     * ⚠️ شرط الإقفال: مفيش مسودّات. سطر لسه ما اتراجعش ماينفعش
     * يتحط جوه فترة «مقفولة» — بعد كده مفيش способ نعدّله.
     */
    public function close(Request $request, PayrollPeriod $period)
    {
        if ($period->status === 'finalized') {
            return response()->json(['message' => 'الفترة دي مقفولة خلاص'], 422);
        }
        if ($period->status === 'paid') {
            return response()->json([
                'message' => 'الفترة دي اتقفلت خلاص — افتحها الأول',
            ], 422);
        }

        // ⭐ بس المسودّات ليها **مبلغ**. السطر صفري ما بيتقرمش
        // (approve بيخطّيه)، فلو حسبناه هنا كانت الفترة مش
        // هتتقفل أبداً — موظف غايب كل الشهر = مسودّة صفدية بتقفل
        // الإقفال على طول. وده تعارض حقيقي بين قاعدتين.
        //
        // السطر الصفري مالوش رقم يتراجع، فمفيش حاجة بتتقفل.
        $drafts = EmployeePayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'draft')
            ->where('amount', '>', 0)
            ->count();

        if ($drafts > 0) {
            return response()->json([
                'message' => "في {$drafts} سطر لسه مسودّة — اعتمدهم الأول",
                'drafts' => $drafts,
            ], 422);
        }

        $approved = EmployeePayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'approved')
            ->where('amount', '>', 0)
            ->count();

        $period->update([
            'status' => 'finalized',
            'finalized_at' => now(),
            'finalized_by' => $request->user()->id,
        ]);

        app(\App\Services\AuditLogService::class)->log(
            'update', 'payroll_period', $period->id,
            ['status' => 'open'],
            ['status' => 'finalized', 'unpaid_lines' => $approved],
            $request,
        );

        return response()->json([
            'message' => $approved > 0
                ? "الفترة اتقفلت. {$approved} سطر لسه مدفوعش — اتصرف من غير ما تفتح."
                : 'الفترة اتقفلت وكل المستحقات اتصرفت.',
            'period' => $period->fresh(),
            'unpaid' => $approved,
        ]);
    }

    /**
     * ⭐ إعادة فتح فترة مقفولة.
     *
     * مسموح، بس **بيتسجّل** — فتح فترة اتقفلت قرار محاسبي، ولازم
     * يبقى فيه أثر مين عمله إمتى. الـ audit log بياخد `user_id`
     * من الـ request، فالسجل بيقول مين فتح.
     *
     * الفايدة العملية: لو اتقفلت الفترة بالغلط أو اتحسب خطأ،
     * مش محتاجين نعدل الداتابيز بإيدينا.
     */
    public function reopen(Request $request, PayrollPeriod $period)
    {
        if ($period->status === 'open') {
            return response()->json(['message' => 'الفترة دي مفتوحة أصلاً'], 422);
        }

        $paid = EmployeePayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'paid')
            ->count();

        $was = $period->status;

        $period->update([
            'status' => 'open',
            'finalized_at' => null,
            'finalized_by' => null,
        ]);

        app(\App\Services\AuditLogService::class)->log(
            'update', 'payroll_period', $period->id,
            ['status' => $was],
            ['status' => 'open', 'paid_lines' => $paid],
            $request,
        );

        return response()->json([
            'message' => 'الفترة اتفتحت' . ($paid > 0 ? " — {$paid} سطر مدفوع (المدفوع مش بيرجع)" : ''),
            'period' => $period->fresh(),
            'paid_lines' => $paid,
        ]);
    }

    /**
     * تسجيل دفع سطر واحد.
     *
     * ⭐ مسموح **بعد إقفال الفترة** — المقصود إن الصرف يتم بعد
     * ما الأرقام تتقفل. بس السطر لازم يكون معتمد.
     */
    public function pay(Request $request, EmployeePayrollLine $line)
    {
        $data = $request->validate([
            'payment_method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:100',
        ]);

        if ($line->status === 'paid') {
            return response()->json([
                'message' => 'السطر ده اتدفع خلاص',
            ], 422);
        }

        // ⭐ لازم معتمد — «دفع من غير اعتماد» غلط. وده بيفرض
        // مرحلة الاعتماد حتى لو الفترة اتفتحت تاني.
        if ($line->status !== 'approved') {
            return response()->json([
                'message' => 'السطر ده لسه مسودّة — اعتمده الأول',
            ], 422);
        }

        if ((float) $line->amount <= 0) {
            return response()->json([
                'message' => 'مش ممكن تدفع سطر راتبه صفر — اعمل احتساب الأول',
            ], 422);
        }

        $paidBefore = EmployeePayrollLine::where('payroll_period_id', $line->payroll_period_id)
            ->where('status', 'paid')
            ->count();

        $line->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'processed_by' => $request->user()->id,
        ]);

        // ⭐ آخر سطر مدفوع → الفترة نفسها بتتحوّل `paid`.
        //
        // ده بيفيد evita يدوي: الأدمن يدفع آخر واحد فتلاقي الفترة
        // «مدفوعة» لوحدها من غير ما يدوس زرار تاني.
        $period = PayrollPeriod::find($line->payroll_period_id);
        if ($period && $period->status !== 'paid' && $paidBefore + 1 >= $this->payableCount($period->id)) {
            $period->update(['status' => 'paid']);
        }

        app(\App\Services\AuditLogService::class)->logUpdate(
            'employee_payroll', $line->id,
            ['status' => $line->status],
            ['status' => 'paid', 'amount' => $line->amount],
            $request,
        );

        return response()->json($line->fresh());
    }

    /**
     * ⭐ عدد السطور **القابلة للدفع** في الفترة.
     *
     * دي محسوبة على «المستحق»: سطر راتبه صفر مش داخل في العدّ —
     * ماينفعش يتدفع، فلو دخل في الحساب كانت الفترة مش هتعمل
     * «مدفوعة» أبداً.
     *
     * والدالة دي **تعريف واحد** للرقم — مستخدمة في `pay` (عشان
     * نعرف امتى نقفل الفترة) وفي `close`. لو حسبناها في المكانين
     * بطرق مختلفة، الحد هيتاخد مرتين أو ينقص.
     */
    private function payableCount(int $periodId): int
    {
        return EmployeePayrollLine::where('payroll_period_id', $periodId)
            ->where('status', '!=', 'draft')
            ->where('amount', '>', 0)
            ->count();
    }

    /** كل المدفوعات في فترة — للتقرير */
    public function report(Request $request, PayrollPeriod $period)
    {
        $lines = EmployeePayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'paid')
            ->with('employee')
            ->get();

        $byEmployee = $lines
            ->groupBy('employee_id')
            ->map(fn ($group) => [
                'employee' => [
                    'name' => $group->first()->employee->name,
                    'job_title' => $group->first()->employee->job_title,
                ],
                'amount' => round($group->sum('amount'), 2),
                'hours' => round($group->sum('hours'), 2),
                'payments' => $group->count(),
            ])
            ->values()
            ->sortByDesc('amount');

        return response()->json([
            'period' => ['id' => $period->id, 'name' => $period->name],
            'employees' => $byEmployee,
            'total' => round($lines->sum('amount'), 2),
            'currency' => 'EGP',
        ]);
    }
}