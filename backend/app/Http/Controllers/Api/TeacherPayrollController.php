<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use App\Models\Teacher;
use App\Models\TeacherPayrollLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * مرتبات المعلمين.
 *
 * ⭐ نفس دورة `EmployeePayrollController` بالظبط، والفرق الوحيد في
 * **الإحساس**:
 *
 *   |            | المصدر        | المعادلة              |
 *   |------------|---------------|------------------------|
 *   | موظف       | الحضور        | ساعات × سعر            |
 *   | معلم حصة   | الحصص         | حصص × سعر              |
 *   | معلم شهري  | العقد         | الراتب ثابت            |
 *
 * الاستعلام كله في `harvest()` — وده **المصدر الوحيد** للمبالغ.
 * لو حسبناها تاني في `lines()` أو في التقرير، هنرجع نفس باج
 * «رقمين مختلفين» اللي حصل في الحضور.
 *
 * دورة الحياة: `generate` → مراجعة → `approve` → `pay`.
 * المدفوعات بتكمل بعد الإقفال (قرار «مرحلتين» زي الموظفين).
 */
class TeacherPayrollController extends Controller
{
    // ============================================================
    // ⭐ الحصاد — المصدر الوحيد للمبالغ
    // ============================================================

    /**
     * يجمع مستحقات المعلمين في فترة.
     *
     * `teacher_earnings` هو **المستحق** وهو بيتحسب من الحصص
     * المكتملة. إحنا بنقراه ونجمّعه حسب المعلم — مش بنحسب من
     * جديد، عشان مفيش روايتين للحقيقة.
     *
     * ليش `approved` + `paid` بس؟ الـ `pending` لسه ماتراجعش،
     * والـ `cancelled` ملغي. الدفعة بتتبني على اللي راجع فعلاً.
     *
     * @return \Illuminate\Support\Collection<string, object>
     */
    private function harvest(PayrollPeriod $period): \Illuminate\Support\Collection
    {
        return DB::table('teacher_earnings')
            ->whereRange('earning_date', $period->start_date, $period->end_date)
            ->whereIn('status', ['approved', 'paid'])
            ->select('teacher_id')
            ->selectRaw('sum(amount) as amount, count(*) as earning_count, min(earning_date) as first_date')
            ->groupBy('teacher_id')
            ->get()
            ->keyBy('teacher_id');
    }

    /**
     * حصص المعلم المكتملة في الفترة.
     *
     * ⭐ مهم: **مكتملة** بس. حصة لسه ما اتقامتش (scheduled) مش
     * بتتحسب — المعلم ما اشتغلهاش.
     *
     * ⭐ `lessons_count` من جدول `lessons` (الحصص الفعلية المكتملة)،
     * والمبلغ من `harvest` — وده مصدر الحقيقة للمال.
     *
     * ملاحظة: `teacher_earnings` ممكن يبقى صف واحد لراتب شهري،
     * فالمستحق الشهري مش معناها حصة واحدة. عشان كده الـ count
     * بيتبقى الحصص الفعلية من `lessons` (بعد الفلترة)، والمبلغ
     * جاي من `harvest` — وده لوحده مصدر الحقيقة للمال.
     */
    private function lessonStats(PayrollPeriod $period): \Illuminate\Support\Collection
    {
        return DB::table('lessons')
            ->whereNotNull('teacher_id')
            ->where('status', 'completed')
            ->whereRange('scheduled_start_at', $period->start_date, $period->end_date)
            ->select('teacher_id')
            ->selectRaw('count(*) as lessons, sum(duration_minutes) as minutes')
            ->groupBy('teacher_id')
            ->get()
            ->keyBy('teacher_id');
    }

    /** سعر المعلم الساري في الفترة — `effective_to` null = لسه شغال */
    private function rateFor(Teacher $teacher, PayrollPeriod $period): ?object
    {
        return DB::table('teacher_rates')
            ->where('teacher_id', $teacher->id)
            ->where('effective_from', '<=', $period->end_date)
            ->where(function ($q) use ($period) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>=', $period->start_date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    // ============================================================
    // الفترات
    // ============================================================

    public function periods(Request $request)
    {
        $periods = PayrollPeriod::query()
            ->withCount('teacherLines')
            ->orderByDesc('start_date')
            ->get();

        $totals = DB::table('teacher_payroll_lines')
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
                'lines_count' => $p->teacher_lines_count,
                'total_amount' => round((float) ($t->total ?? 0), 2),
                'total_hours' => round((float) ($t->hours ?? 0), 2),
                'payable' => $this->payableCount($p->id),
            ];
        });

        return response()->json([
            'periods' => $rows,
            'summary' => [
                'total' => $periods->count(),
                'open' => $periods->where('status', 'open')->count(),
            ],
            'teacher_count' => Teacher::where('status', 'active')->count(),
        ]);
    }

    // ============================================================
    // سطور فترة
    // ============================================================

    public function lines(Request $request, PayrollPeriod $period)
    {
        $lines = TeacherPayrollLine::query()
            ->where('teacher_payroll_lines.payroll_period_id', $period->id)
            ->join('teachers', 'teachers.id', '=', 'teacher_payroll_lines.teacher_id')
            ->select('teacher_payroll_lines.*')
            ->with('teacher')
            ->orderBy('teachers.display_name')
            ->get()
            ->map(fn ($l) => $this->present($l));

        // معلمين ليهم مستحق في الفترة بس مالهمش سطر
        // (يمكن اتحسبوا قبل ما الجدول يتعمل).
        $withLine = $lines->pluck('teacher.id')->all();
        $harvested = $this->harvest($period);
        $missing = collect($harvested)
            ->reject(fn ($e, $id) => in_array((int) $id, $withLine))
            ->map(fn ($e, $id) => [
                'teacher' => [
                    'id' => (int) $id,
                    'name' => Teacher::find($id)?->display_name ?? '—',
                ],
                'harvested' => round((float) $e->amount, 2),
                'amount' => null,
                'status' => 'no_line',
                'status_label' => 'مستحق مش محتسب',
            ])
            ->values();

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
            // للمقارنة: المستحق الفعلي في الفترة
            'harvested_total' => round((float) $harvested->sum('amount'), 2),
            'totals' => [
                'hours' => round($lines->sum('hours'), 2),
                'lessons' => $lines->sum('lessons_count'),
                'amount' => round($lines->sum('amount'), 2),
                'lines' => $lines->count(),
                'paid' => $lines->where('status', 'paid')->count(),
                'approved' => $lines->where('status', 'approved')->count(),
                'draft' => $lines->where('status', 'draft')->count(),
            ],
        ]);
    }

    /** شكل السطر في الـ response — في مكان واحد عشان ما يتكررش */
    private function present(TeacherPayrollLine $l): array
    {
        return [
            'id' => $l->id,
            'teacher' => [
                'id' => $l->teacher->id,
                'name' => $l->teacher->display_name,
                'job_title' => $l->teacher->specialization,
            ],
            'lessons_count' => $l->lessons_count,
            'hours' => round((float) $l->hours, 2),
            'rate_snapshot' => $l->rate_snapshot !== null ? (float) $l->rate_snapshot : null,
            'amount' => round((float) $l->amount, 2),
            'currency' => $l->currency,
            'status' => $l->status,
            'status_label' => TeacherPayrollLine::statusLabel($l->status),
            'editable' => $l->isEditable(),
            'payment_method' => $l->payment_method,
            'reference' => $l->reference,
            'paid_at' => $l->paid_at,
            'notes' => $l->notes,
        ];
    }

    // ============================================================
    // ⭐ الاحتساب
    // ============================================================

    /**
     * يقرأ المستحق من `teacher_earnings` ويكتب سطور مسودّة.
     *
     * - المعلم لازم يكون **نشط**.
     * - لو في سطر مسودّة لنفس المعلم → يتحدّث.
     * - لو معتمد أو مدفوع → **مش هيتلمس** (قيد نقدي).
     */
    public function generate(Request $request, PayrollPeriod $period)
    {
        if (! $period->isOpen()) {
            return response()->json([
                'message' => 'الفترة دي مقفولة — افتحها الأول',
            ], 422);
        }

        $harvested = $this->harvest($period);
        $stats = $this->lessonStats($period);

        $teachers = Teacher::query()
            ->where('status', 'active')
            ->whereIn('id', $harvested->keys())
            ->get();

        $orgId = $request->user()->organization_id ?? 1;
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $noRate = 0;

        foreach ($teachers as $teacher) {
            $existing = TeacherPayrollLine::where('payroll_period_id', $period->id)
                ->where('teacher_id', $teacher->id)
                ->first();

            if ($existing && ! $existing->isEditable()) {
                $skipped++;
                continue;
            }

            $earnings = $harvested->get($teacher->id);
            $lesson = $stats->get($teacher->id);
            $rate = $this->rateFor($teacher, $period);

            // ⭐ بلا سعر → صفر، **ومتخطّاش**. الشاشة بتقول «بدون
            // سعر» لو أُدرج. ده أوضح من صفر مخفي.
            if (! $rate) {
                $noRate++;
                continue;
            }

            $lessons = (int) ($lesson->lessons ?? 0);
            $hours = round(((float) ($lesson->minutes ?? 0)) / 60, 2);

            // ⭐ المبلغ = **المستحق المسجّل** (`harvest`)، مش
            // `lessons × rate`.
            //
            // ليش؟ لأن `teacher_earnings` هو اللي راجعه الأدمن
            // واتقفل — وهو متحسب بالحصة **وبالسعر وقت الحصة**.
            // لو حسبنا من جدول `lessons` تاني، التنين هيفترقوا
            // في حالتين:
            //   1. السعر اتغيّر بعد الحصة
            //   2. حصة اتمسحت بعد ما اتسجّل مستحقها
            // وكل واحدة منهم معناها «نقدًا مختلف» لنفس المعلم.
            //
            // `lessons_count` و `rate_snapshot` هنا **معلومات**
            // (معاينة: كام حصة وبكام) مش مصدر المبلغ.
            $amount = round((float) $earnings->amount, 2);

            $payload = [
                'organization_id' => $orgId,
                'lessons_count' => $lessons,
                'hours' => $hours,
                'rate_snapshot' => (float) $rate->amount,
                'amount' => $amount,
                'currency' => $rate->currency ?? 'EGP',
                'period_start' => $period->start_date,
                'period_end' => $period->end_date,
                'status' => 'draft',
                // ⭐ الرقم الحقيقي (من earnings) في الملاحظات، لو
                // الأدمن عدّل المسودّة وعايز يرجع للحقيقة
                'notes' => 'المستحق المسجّل: ' . round((float) $earnings->amount, 2) . ' ج',
            ];

            if ($existing) {
                $existing->update($payload);
                $updated++;
            } else {
                TeacherPayrollLine::create($payload + [
                    'payroll_period_id' => $period->id,
                    'teacher_id' => $teacher->id,
                ]);
                $created++;
            }
        }

        app(\App\Services\AuditLogService::class)->log(
            'create', 'teacher_payroll', $period->id, null,
            ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'no_rate' => $noRate],
            $request,
        );

        return response()->json([
            'message' => "تم الاحتساب: {$created} جديد، {$updated} متحدّث"
                .($skipped ? "، {$skipped} متخطّى (معتمد أو مدفوع)" : '')
                .($noRate ? "، {$noRate} مالهومش سعر" : ''),
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'no_rate' => $noRate,
            'harvested_total' => round((float) $harvested->sum('amount'), 2),
        ]);
    }

    /** تعديل سطر — المسودّات بس، والفترة مفتوحة */
    public function updateLine(Request $request, TeacherPayrollLine $line)
    {
        if (! $line->isEditable()) {
            return response()->json([
                'message' => 'السطر ده اتعتمد بالفعل — مش قابل للتعديل',
            ], 422);
        }

        if ($line->period && ! $line->period->isOpen()) {
            return response()->json([
                'message' => 'الفترة دي مقفولة — افتحها الأول',
            ], 422);
        }

        $data = $request->validate([
            'lessons_count' => 'sometimes|integer|min:0|max:200',
            'hours' => 'sometimes|numeric|min:0|max:800',
            'rate_snapshot' => 'sometimes|numeric|min:0|max:100000',
            'notes' => 'nullable|string|max:500',
        ]);

        $old = $line->only(array_keys($data));

        if (array_key_exists('lessons_count', $data)
            || array_key_exists('hours', $data)
            || array_key_exists('rate_snapshot', $data)) {
            $lessons = (int) ($data['lessons_count'] ?? $line->lessons_count);
            $hours = (float) ($data['hours'] ?? $line->hours);
            $rate = (float) ($data['rate_snapshot'] ?? $line->rate_snapshot);

            // نعرف نوع السعر عشان الشهري واليومي يتحسبوا صح
            $rateType = DB::table('teacher_rates')
                ->where('teacher_id', $line->teacher_id)
                ->orderByDesc('effective_from')
                ->value('rate_type');

            // ⭐ المبلغ بيتحسب في السيرفر — مايبعتش من العميل
            $data['amount'] = TeacherPayrollLine::computeAmount($lessons, $rate, $rateType);
        }

        $line->update($data);

        app(\App\Services\AuditLogService::class)->logUpdate(
            'teacher_payroll', $line->id, $old, $line->only(array_keys($data)), $request,
        );

        return response()->json($this->present($line->fresh()));
    }

    /** اعتماد كل المسودّات في الفترة مرة واحدة */
    public function approve(Request $request, PayrollPeriod $period)
    {
        if (! $period->isOpen()) {
            return response()->json(['message' => 'الفترة دي مقفولة'], 422);
        }

        $drafts = TeacherPayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'draft')
            ->get();

        if ($drafts->isEmpty()) {
            return response()->json([
                'message' => 'مفيش مسودّات تعتمد — اعمل احتساب الأول',
            ], 422);
        }

        $approved = $drafts->filter(fn ($l) => (float) $l->amount > 0);
        $zero = $drafts->reject(fn ($l) => (float) $l->amount > 0);

        TeacherPayrollLine::whereIn('id', $approved->pluck('id'))
            ->update(['status' => 'approved', 'updated_at' => now()]);

        app(\App\Services\AuditLogService::class)->log(
            'update', 'teacher_payroll', $period->id, null,
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

    // ============================================================
    // الدفع
    // ============================================================

    /**
     * تسجيل دفع سطر واحد.
     *
     * مسموح **بعد إقفال الفترة** — المقصود إن الصرف يتم بعد ما
     * الأرقام تتقفل. بس السطر لازم يكون معتمد.
     */
    public function pay(Request $request, TeacherPayrollLine $line)
    {
        $data = $request->validate([
            'payment_method' => 'nullable|string|max:50',
            'reference' => 'nullable|string|max:100',
        ]);

        if ($line->status === 'paid') {
            return response()->json(['message' => 'السطر ده اتدفع خلاص'], 422);
        }

        if ($line->status !== 'approved') {
            return response()->json([
                'message' => 'السطر ده لسه مسودّة — اعتمده الأول',
            ], 422);
        }

        if ((float) $line->amount <= 0) {
            return response()->json([
                'message' => 'مش ممكن تدفع سطر راتبه صفر',
            ], 422);
        }

        $paidBefore = TeacherPayrollLine::where('payroll_period_id', $line->payroll_period_id)
            ->where('status', 'paid')
            ->count();

        $line->update([
            'status' => 'paid',
            'paid_at' => now(),
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'processed_by' => $request->user()->id,
        ]);

        // آخر سطر مستحق → الفترة نفسها `paid`
        $period = PayrollPeriod::find($line->payroll_period_id);
        if ($period && $period->status !== 'paid' && $paidBefore + 1 >= $this->payableCount($period->id)) {
            $period->update(['status' => 'paid']);
        }

        app(\App\Services\AuditLogService::class)->logUpdate(
            'teacher_payroll', $line->id,
            ['status' => $line->status],
            ['status' => 'paid', 'amount' => $line->amount],
            $request,
        );

        return response()->json($this->present($line->fresh()));
    }

    // ============================================================
    // الإقفال
    // ============================================================

    /** ⭐ عدد السطور **القابلة للدفع** — تعريف واحد */
    private function payableCount(int $periodId): int
    {
        return TeacherPayrollLine::where('payroll_period_id', $periodId)
            ->where('status', '!=', 'draft')
            ->where('amount', '>', 0)
            ->count();
    }

    /**
     * إقفال الفترة — «مرحلتين» زي الموظفين بالظبط.
     *
     * ⚠️ شرط الإقفال: مفيش مسودّات **ليها مبلغ**. السطر الصفري
     * مالوش رقم يتراجع فمش بيقفل.
     *
     * ⚠️ بيتأكد من **سطر المعلمين هو** بس. مرتبات الموظفين ليها
     * مسارها بنفس الفترة، ومنفصل. ده معناه إنه ممكن科普مّل قفل
     * الفترة من ناحية المعلمين وفيه سطور موظفين لسه مسودّة.
     *
     * مقصود: كل طرف بيمرّ على سطوره. في **طريقتين للقفل** (موظفين
     * أو معلمين) بدل ما واحد يقفل والآخر يفاجئه بحاجة مقفولة.
     */
    public function close(Request $request, PayrollPeriod $period)
    {
        if ($period->status !== 'open') {
            return response()->json(['message' => 'الفترة دي مقفولة خلاص'], 422);
        }

        $drafts = TeacherPayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'draft')
            ->where('amount', '>', 0)
            ->count();

        if ($drafts > 0) {
            return response()->json([
                'message' => "في {$drafts} سطر لسه مسودّة — اعتمدهم الأول",
                'drafts' => $drafts,
            ], 422);
        }

        $approved = TeacherPayrollLine::where('payroll_period_id', $period->id)
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
            ['status' => 'finalized', 'side' => 'teachers', 'unpaid' => $approved],
            $request,
        );

        return response()->json([
            'message' => $approved > 0
                ? "الفترة اتقفلت. {$approved} سطر لسه مدفوعش — اتصرف من غير ما تفتح."
                : 'الفترة اتقفلت وكل المستحق اتصرف.',
            'period' => $period->fresh(),
            'unpaid' => $approved,
        ]);
    }

    /** إعادة فتح — بيتسجّل في audit log */
    public function reopen(Request $request, PayrollPeriod $period)
    {
        if ($period->status === 'open') {
            return response()->json(['message' => 'الفترة دي مفتوحة أصلاً'], 422);
        }

        $paid = TeacherPayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'paid')
            ->count();

        $was = $period->status;

        $period->update(['status' => 'open', 'finalized_at' => null, 'finalized_by' => null]);

        app(\App\Services\AuditLogService::class)->log(
            'update', 'payroll_period', $period->id,
            ['status' => $was],
            ['status' => 'open', 'side' => 'teachers', 'paid_lines' => $paid],
            $request,
        );

        return response()->json([
            'message' => 'الفترة اتفتحت' . ($paid > 0 ? " — {$paid} سطر مدفوع (المدفوع مش بيرجع)" : ''),
            'period' => $period->fresh(),
            'paid_lines' => $paid,
        ]);
    }

    /** تقرير المدفوعات في فترة */
    public function report(Request $request, PayrollPeriod $period)
    {
        $lines = TeacherPayrollLine::where('payroll_period_id', $period->id)
            ->where('status', 'paid')
            ->with('teacher')
            ->get();

        $byTeacher = $lines
            ->groupBy('teacher_id')
            ->map(fn ($g) => [
                'teacher' => [
                    'name' => $g->first()->teacher->display_name,
                    'job_title' => $g->first()->teacher->specialization,
                ],
                'amount' => round($g->sum('amount'), 2),
                'lessons' => $g->sum('lessons_count'),
                'hours' => round($g->sum('hours'), 2),
            ])
            ->values()
            ->sortByDesc('amount');

        return response()->json([
            'period' => ['id' => $period->id, 'name' => $period->name],
            'teachers' => $byTeacher,
            'total' => round($lines->sum('amount'), 2),
            'currency' => 'EGP',
        ]);
    }
}