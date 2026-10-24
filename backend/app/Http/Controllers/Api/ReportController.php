<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Casts\MoneyCast;
use App\Models\Assessment;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\MemorizationRecord;
use App\Models\Payment;
use App\Models\PromoCodeUsage;
use App\Models\Refund;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\TeacherEarning;
use App\Models\TeacherPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    /**
     * ⭐⭐ PR 2.14 — تقرير «كام ضاع في الخصومات».
     *
     * المجهول اللي كان غايب: الإيراد بيتحسب من المدفوعات،
     * والخصومات كانت أساساً رقم مخفي جوه الفاتورة. التقرير ده
     * بيفضّل التكلفة لترويجي (دخله الكود) ويدوي (قرار إداري).
     *
     * كل الأرقام aggregates في SQL — مافيش تحميل السجلات في الذاكرة.
     */
    public function discounts(Request $request)
    {
        $from = $request->query('from');
        $to = $request->query('to');

        $inWindow = fn ($q) => $q
            ->when($from, fn ($q2) => $q2->whereDate('issue_date', '>=', $from))
            ->when($to, fn ($q2) => $q2->whereDate('issue_date', '<=', $to));

        // ⭐ إجماليات الفواتير المخصومة — الجزء الترويجي جوه
        // الفاتورة بيتقاس من جدول الاستخدامات (مجموع لكل فاتورة)
        $promoPerInvoice = PromoCodeUsage::query()
            ->selectRaw('invoice_id, SUM(discount_amount) AS promo_total')
            ->groupBy('invoice_id');

        $totals = Invoice::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('discount', '>', 0)
            ->where($inWindow)
            ->leftJoinSub($promoPerInvoice, 'usages', 'invoices.id', '=', 'usages.invoice_id')
            ->selectRaw('COUNT(*) AS invoices')
            ->selectRaw('COALESCE(SUM(discount), 0) AS total_discounts')
            ->selectRaw('COALESCE(SUM(usages.promo_total), 0) AS promo_discounts')
            ->selectRaw('COALESCE(SUM(invoices.discount - COALESCE(usages.promo_total, 0)), 0) AS manual_discounts')
            ->first();

        // ⭐⭐ الـaggregates بتطلع **خام بالقرش** — لازم تمر من
        // `fromAggregate` قبل أي مقارنة (قاعدة 6 — بداية عقد 2.13).
        $totalRaw = [
            'invoices' => (int) ($totals->invoices ?? 0),
            'promo_discounts' => (float) (MoneyCast::fromAggregate($totals->promo_discounts ?? null) ?? 0),
            'manual_discounts' => (float) (MoneyCast::fromAggregate($totals->manual_discounts ?? null) ?? 0),
            'total_discounts' => (float) (MoneyCast::fromAggregate($totals->total_discounts ?? null) ?? 0),
        ];

        // ⭐ تفصيل لكل كود — التكلفة الفعلية لكل فعالية تسويقية
        $byCode = PromoCodeUsage::query()
            ->join('promo_codes', 'promo_codes.id', '=', 'promo_code_usages.promo_code_id')
            ->join('invoices', 'invoices.id', '=', 'promo_code_usages.invoice_id')
            ->where('promo_code_usages.organization_id', $request->user()->organization_id)
            ->where($inWindow)
            ->selectRaw('promo_codes.id, promo_codes.code, promo_codes.name, promo_codes.type, promo_codes.value')
            ->selectRaw('COUNT(DISTINCT promo_code_usages.invoice_id) AS invoices_count')
            ->selectRaw('COALESCE(SUM(promo_code_usages.discount_amount), 0) AS promo_discounts')
            ->groupBy('promo_codes.id', 'promo_codes.code', 'promo_codes.name', 'promo_codes.type', 'promo_codes.value')
            ->orderByDesc('promo_discounts')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id,
                'code' => $row->code,
                'name' => $row->name,
                'type' => $row->type,
                'value' => (float) $row->value,
                'invoices_count' => (int) $row->invoices_count,
                'promo_discounts' => (float) (MoneyCast::fromAggregate($row->promo_discounts) ?? 0),
            ]);

        return response()->json([
            'from' => $from,
            'to' => $to,
            'totals' => $totalRaw,
            'by_code' => $byCode,
        ]);
    }

    public function dashboardSummary(Request $request)
    {
        // ⭐ PR 2.15 — كل العدّادات محكومة بالمنظمة. قبل كده كنت
        // ببص على الجداول كلها — إيراد أكاديمية تانية كان بيدخل
        // لوحة أكاديميتنا (FinancialReportTest duck بيثبته).
            $orgId = $request->user()->organization_id;
        $today = Carbon::today();
        $todayLessons = Lesson::query()->where('organization_id', $orgId)
            ->whereDate('scheduled_start_at', $today);
        $monthStart = $today->copy()->startOfMonth();
        // ⭐ `sum()` على الـ query بيرجّع **قرش** — الـ cast ما بيشتغلش
        // على aggregates. لازم `fromDatabase` قبل أي مقارنة أو جمع.
        $monthlyRevenue = \App\Casts\MoneyCast::fromAggregate(
            Payment::query()->where('organization_id', $orgId)
                ->where('status', 'completed')->whereRange('paid_at', $monthStart, $today)->sum('amount')
        );
        // ⭐⭐⭐ PR 3.12 — نافذة «هتنتهي قريب» من مركز الإعدادات
        // (`subscriptions.expiry_warning_days`) بدل الرقم الحبيب 3.
        $expiryWarningDays = (int) settings()->get('subscriptions.expiry_warning_days', $orgId);
        $expiringSoon = Subscription::query()->where('organization_id', $orgId)
            ->where('status', 'active')->whereRange('end_date', $today, $today->copy()->addDays($expiryWarningDays))->count();
        $pendingTeacherPayments = \App\Casts\MoneyCast::fromAggregate(
            TeacherPayment::query()->join('teachers as tp_t', 'tp_t.id', '=', 'teacher_payments.teacher_id')
                ->where('tp_t.organization_id', $orgId)
                ->whereIn('teacher_payments.status', ['pending'])->sum('teacher_payments.amount')
        );
        $unscheduledLeads = Lead::query()->where('organization_id', $orgId)
            ->whereIn('status', ['new', 'contacted'])->count();

        return response()->json([
            'today' => [
                'lessons_total' => (clone $todayLessons)->count(),
                'lessons_completed' => (clone $todayLessons)->where('status', 'completed')->count(),
                'lessons_upcoming' => (clone $todayLessons)->whereIn('status', ['scheduled', 'confirmed'])->count(),
            ],
            'totals' => [
                'active_students' => Student::where('organization_id', $orgId)->where('status', 'active')->count(),
                'active_teachers' => Teacher::where('organization_id', $orgId)->where('status', 'active')->count(),
                // ⭐ رقم خام — مش `number_format`
                'monthly_revenue' => $monthlyRevenue,
            ],
            'alerts' => [
                'subscriptions_expiring_soon' => $expiringSoon,
                'pending_teacher_payments' => $pendingTeacherPayments,
                'unscheduled_leads' => $unscheduledLeads,
            ],
        ]);
    }

    /**
     * PR 2.15 — التقرير المالي كله aggregates في SQL عبر
     * `FinancialReportService` — للمنظمة، لكل عملة، والتحويل
     * بسعر الصرف المسجّل وقت العملية. مافيش تحميل موديلات.
     */
    public function financial(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        $user = $request->user();
        $org = \App\Models\Organization::query()
            ->where('id', $user->organization_id)
            ->first(['id', 'default_currency']);

        abort_unless($org !== null, 403);

        $service = new \App\Services\FinancialReportService(
            $org->id,
            $org->default_currency ?? config('currencies.default'),
        );

        return response()->json($service->financial($start, $end, [
            'branch_id' => $request->integer('branch_id') ?: null,
            'program_id' => $request->integer('program_id') ?: null,
            'teacher_id' => $request->integer('teacher_id') ?: null,
        ]));
    }

    /**
     * ⭐⭐ PR 2.16 — تقارير المتابعة اليومية عبر
     * `FollowupReportService`: أعمار الديون (0–30/31–60/61–90/+90
     * — صف لكل طالب×عملة)، أرصدة الخزائن، التزامات المعلمين،
     * مصادر الإيراد (برنامج/معلم). كل الـaggregates في الخدمة.
     */
    public function aging(Request $request)
    {
        $service = new \App\Services\FollowupReportService((int) $request->user()->organization_id);

        return response()->json($service->aging(Carbon::now()));
    }

    public function accountBalances(Request $request)
    {
        $service = new \App\Services\FollowupReportService((int) $request->user()->organization_id);

        return response()->json($service->accountBalances());
    }

    public function teacherLiabilities(Request $request)
    {
        $service = new \App\Services\FollowupReportService((int) $request->user()->organization_id);

        return response()->json($service->teacherLiabilities());
    }

    public function revenueSources(Request $request)
    {
        $service = new \App\Services\FollowupReportService((int) $request->user()->organization_id);

        return response()->json($service->revenueSources());
    }

    /**
     * ⭐⭐ PR 2.16 — التصدير: CSV مع BOM (الإكسل بيفتح العربي سليم)
     * لكل تقرير + للفواتير والمدفوعات والمصروفات.
     *
     * صلاحية **منفصلة** `reports.export` (`reports.view` كمان،
     * بالراوت) — اللي بيتابع الأرقام على الشاشة مش بالضرورة ياخد
     * ملف كامل ببيانات. التسجيل هنا مش تكميل: تصدير نسخة بيانات
     * خارجية — لوق + audit بمين وصدّر إيه وكم صف.
     */
    public function export(Request $request)
    {
        $validated = $request->validate([
            // التقارير الأربعة + السجلات الثالثة
            'report' => ['required', 'in:aging,account-balances,teacher-liabilities,revenue-sources,invoices,payments,expenses'],
            // XLSX لما ext-zip يبقى متوفر — دلوقتي CSV بس
            'format' => ['nullable', 'in:csv,xlsx'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $format = $validated['format'] ?? 'csv';
        if ($format === 'xlsx') {
            return response()->json([
                'message' => 'تصدير XLSX مش متاح في البيئة الحالية (مكتبة openspout محتاجة ext-zip وغير متاحة) — استخدم format=csv، والإكسل بيفتحه سليم',
            ], 422);
        }

        $user = $request->user();
        $today = Carbon::now();
        $service = new \App\Services\FollowupReportService((int) $user->organization_id);
        $exporter = new \App\Services\SpreadsheetExportService();

        // فلتر الفترة اختياري — على تاريخ الإصدار/التحصيل/الصرف
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;

        switch ($validated['report']) {
            case 'aging':
                $payload = $service->agingExport($today);
                $filename = 'debt_aging_'.$today->toDateString().'.csv';
                break;
            case 'account-balances':
                $balances = $service->accountBalances();
                $payload = [
                    'columns' => ['الخزينة', 'النوع', 'العملة', 'الافتتاحي', 'الحركات المنشورة', 'الرصيد'],
                    'rows' => array_map(fn ($a) => [
                        $a['name'], $a['type'], $a['currency'],
                        number_format($a['opening_balance'], 2, '.', ''),
                        number_format($a['movements_sum'], 2, '.', ''),
                        number_format($a['balance'], 2, '.', ''),
                    ], $balances['accounts']),
                    'total_rows' => array_map(fn ($balance, $currency) => [
                        'الإجمالي', '', $currency, '', '',
                        number_format($balance, 2, '.', ''),
                    ], array_column($balances['totals'], 'balance'), array_keys($balances['totals'])),
                ];
                $filename = 'account_balances_'.$today->toDateString().'.csv';
                break;
            case 'teacher-liabilities':
                $liabilities = $service->teacherLiabilities();
                $payload = [
                    'columns' => ['المعلم', 'العملة', 'المستحقات', 'المدفوع', 'التزام'],
                    'rows' => array_map(fn ($t) => [
                        $t['name'], $t['currency'],
                        number_format($t['gross_earnings'], 2, '.', ''),
                        number_format($t['payments_made'], 2, '.', ''),
                        number_format($t['liability'], 2, '.', ''),
                    ], $liabilities['teachers']),
                    'total_rows' => array_map(fn ($total, $currency) => [
                        'الإجمالي', $currency,
                        number_format($total['gross_earnings'], 2, '.', ''),
                        number_format($total['payments_made'], 2, '.', ''),
                        number_format($total['liability'], 2, '.', ''),
                    ], array_column($liabilities['totals'], 'liability'), array_keys($liabilities['totals'])),
                ];
                $filename = 'teacher_liabilities_'.$today->toDateString().'.csv';
                break;
            case 'revenue-sources':
                $sources = $service->revenueSources();
                $payload = [
                    'columns' => ['النوع', 'الاسم', 'العملة', 'المفوتَر', 'العدد'],
                    'rows' => array_merge(
                        array_map(fn ($p) => ['برنامج', $p['name'], $p['currency'], number_format($p['invoiced'], 2, '.', ''), $p['invoices_count']], $sources['by_program']),
                        array_map(fn ($t) => ['معلم', $t['name'], $t['currency'], number_format($t['invoiced'], 2, '.', ''), $t['invoices_count']], $sources['by_teacher']),
                    ),
                ];
                $filename = 'revenue_sources_'.$today->toDateString().'.csv';
                break;
            case 'invoices':
                $payload = $service->invoiceExportRows($from, $to);
                $filename = 'invoices_'.$today->toDateString().'.csv';
                break;
            case 'payments':
                $payload = $service->paymentExportRows($from, $to);
                $filename = 'payments_'.$today->toDateString().'.csv';
                break;
            case 'expenses':
                $payload = $service->expenseExportRows($from, $to);
                $filename = 'expenses_'.$today->toDateString().'.csv';
                break;
        }

        $rowCount = count($payload['rows']);
        $exportedReport = $validated['report'];

        \Log::info('reports.export', [
            'user_id' => $user->id,
            'report' => $exportedReport,
            'format' => $format,
            'rows' => $rowCount,
        ]);

        app(\App\Services\AuditLogService::class)->log(
            action: 'export',
            entityType: 'report_export',
            entityId: 0,
            oldValue: null,
            newValue: ['report' => $exportedReport, 'format' => $format, 'rows' => $rowCount],
            request: $request,
            userId: $user->id,
        );

        return $exporter->csv($filename, $payload['columns'], $payload['rows'], $payload['total_rows'] ?? []);
    }

    public function academic(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        $lessons = Lesson::query()->whereRange('scheduled_start_at', $start, $end);
        $attendance = LessonAttendance::query()->whereRange('marked_at', $start, $end);
        $memorization = MemorizationRecord::query()->whereRange('recorded_at', $start, $end);

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'lessons' => [
                'total' => (clone $lessons)->count(),
                'completed' => (clone $lessons)->where('status', 'completed')->count(),
                'cancelled' => (clone $lessons)->where('status', 'cancelled')->count(),
                'student_absent' => (clone $lessons)->where('status', 'student_absent')->count(),
                'teacher_absent' => (clone $lessons)->where('status', 'teacher_absent')->count(),
            ],
            'attendance' => [
                'present' => (clone $attendance)->where('status', 'present')->count(),
                'absent' => (clone $attendance)->where('status', 'absent')->count(),
                'late' => (clone $attendance)->where('status', 'late')->count(),
            ],
            'memorization' => ['total' => (clone $memorization)->count(), 'avg_quality' => round((clone $memorization)->avg('quality') ?? 0, 1)],
            'subscriptions' => ['active' => Subscription::where('status', 'active')->count(), 'expired' => Subscription::where('status', 'expired')->count()],
        ]);
    }

    public function sales(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        $leads = Lead::query()->whereRange('created_at', $start, $end);
        $assessments = Assessment::query()->whereRange('scheduled_at', $start, $end);
        $totalLeads = (clone $leads)->count();
        $converted = (clone $leads)->where('status', 'converted')->count();
        $conversionRate = $totalLeads > 0 ? round(($converted / $totalLeads) * 100, 1) : 0;

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            // ⭐ رقم خام — الـ `%` بتتحط في العرض (مش في الداتابيز)
            'leads' => ['total' => $totalLeads, 'converted' => $converted, 'conversion_rate' => $conversionRate],
            'trials' => [
                'total' => (clone $assessments)->count(),
                'ready_to_subscribe' => (clone $assessments)->where('result', 'ready_to_subscribe')->count(),
                'needs_follow_up' => (clone $assessments)->where('result', 'needs_follow_up')->count(),
                'not_suitable' => (clone $assessments)->where('result', 'not_suitable')->count(),
            ],
        ]);
    }

    // ===== NEW REPORTS =====

    // 1. Attendance Report - تقرير الحضور والغياب
    public function attendance(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        /**
         * ⭐⭐⭐ **PR 3.14 — تقارير الحضور مربوطة بالمؤسسة + aggregates.**
         *
         * ⭐ **قبل PR ده** الكود كان موجود بلا مسار، وبدون عزل
         * مؤسسة (حضور أكاديمية تانية كان هيظهر)، والتفصيل
         * للموظفين كان بيبني من تحميل **كل الصفوف** ثم تجميع في
         * PHP collections (يا تضخم مع الأرشيف).
         *
         * ⭐ **دلوقتي** كل التجميع جوا SQL (`selectRaw` + `groupBy`)
         * في استعلامات معدودة، وكلها محكومة بـ`organization_id`
         * للمستخدم الحالي.
         */
        $orgId = (int) $request->user()->organization_id;
        $studentId = $request->filled('student_id') ? $request->integer('student_id') : null;
        $teacherId = $request->filled('teacher_id') ? $request->integer('teacher_id') : null;
        $programId = $request->filled('program_id') ? $request->integer('program_id') : null;

        // ⭐ فلترات الأبعاد — مشتركة بين استعلامات الدروس والحضور.
        $applyLessonFilters = function ($q) use ($orgId, $studentId, $teacherId, $programId) {
            return $q->where('lessons.organization_id', $orgId)
                ->when($studentId, fn ($q2) => $q2->where('lessons.student_id', $studentId))
                ->when($teacherId, fn ($q2) => $q2->where('lessons.teacher_id', $teacherId))
                ->when($programId, fn ($q2) => $q2->where('lessons.program_id', $programId));
        };

        $lessonQuery = Lesson::query()
            ->whereRange('scheduled_start_at', $start, $end)
            ->where($applyLessonFilters);

        // ⭐ الحضور — مصدره الدرس المؤسسي (الحضور نفسه بلا عمود مؤسسة).
        $attendanceScoped = fn () => LessonAttendance::query()
            ->whereRange('lesson_attendance.marked_at', $start, $end)
            ->whereHas('lesson', $applyLessonFilters);

        // ─── الملخص: عامليا count/sum في SQL ───
        $totalLessons = (clone $lessonQuery)->count();
        $completedLessons = (clone $lessonQuery)->where('status', 'completed')->count();
        $cancelledLessons = (clone $lessonQuery)->where('status', 'cancelled')->count();
        $studentAbsentLessons = (clone $lessonQuery)->where('status', 'student_absent')->count();
        $teacherAbsentLessons = (clone $lessonQuery)->where('status', 'teacher_absent')->count();

        $overall = $attendanceScoped()
            ->selectRaw(implode(', ', [
                'COUNT(*) as total_recs',
                "SUM(CASE WHEN lesson_attendance.status = 'present' THEN 1 ELSE 0 END) as present_recs",
                "SUM(CASE WHEN lesson_attendance.status = 'absent' THEN 1 ELSE 0 END) as absent_recs",
                "SUM(CASE WHEN lesson_attendance.status = 'late' THEN 1 ELSE 0 END) as late_recs",
            ]))
            ->first();

        $totalAttendance = (int) ($overall->total_recs ?? 0);
        $presentCount = (int) ($overall->present_recs ?? 0);
        $absentCount = (int) ($overall->absent_recs ?? 0);
        $lateCount = (int) ($overall->late_recs ?? 0);

        $attendanceRate = $totalAttendance > 0 ? round(($presentCount / $totalAttendance) * 100, 1) : 0;

        // ─── تفصيل الطالبين: تجميعة SQL واحدة (Join + Group) ───
        // قبل كانت: تحميل كل سجلات الحضور مع الدروس والطلاب ثم
        // groupBy في collections.
        $studentStats = LessonAttendance::query()
            ->join('lessons as rep_l', 'rep_l.id', '=', 'lesson_attendance.lesson_id')
            ->join('students as rep_s', 'rep_s.id', '=', 'rep_l.student_id')
            ->whereRange('lesson_attendance.marked_at', $start, $end)
            ->where('rep_l.organization_id', $orgId)
            ->when($studentId, fn ($q) => $q->where('rep_l.student_id', $studentId))
            ->when($teacherId, fn ($q) => $q->where('rep_l.teacher_id', $teacherId))
            ->when($programId, fn ($q) => $q->where('rep_l.program_id', $programId))
            ->selectRaw(implode(', ', [
                'rep_l.student_id as student_id',
                'rep_s.first_name as first_name',
                'rep_s.middle_name as middle_name',
                'rep_s.last_name as last_name',
                'rep_s.student_code as student_code',
                'COUNT(*) as total_sessions',
                "SUM(CASE WHEN lesson_attendance.status = 'present' THEN 1 ELSE 0 END) as present_cnt",
                "SUM(CASE WHEN lesson_attendance.status = 'absent' THEN 1 ELSE 0 END) as absent_cnt",
                "SUM(CASE WHEN lesson_attendance.status = 'late' THEN 1 ELSE 0 END) as late_cnt",
            ]))
            ->groupBy('rep_l.student_id', 'rep_s.first_name', 'rep_s.middle_name', 'rep_s.last_name', 'rep_s.student_code')
            ->orderBy('rep_l.student_id')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total_sessions;
                $present = (int) $row->present_cnt;

                return [
                    'student_id' => (int) $row->student_id,
                    // نفس دلالة «full_name» (اسم كامل باربع شعر).
                    'student_name' => trim(($row->first_name ?? '').' '.($row->middle_name ?? '').' '.($row->last_name ?? '')),
                    'student_code' => $row->student_code ?? '—',
                    'total_sessions' => $total,
                    'present' => $present,
                    'absent' => (int) $row->absent_cnt,
                    'late' => (int) $row->late_cnt,
                    'attendance_rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
                ];
            })
            ->values();

        // ─── التفصيل اليومي: تجميعة SQL واحدة بلا تجميع لاحق ───
        $dailyStats = LessonAttendance::query()
            ->join('lessons as rep_d', 'rep_d.id', '=', 'lesson_attendance.lesson_id')
            ->whereRange('lesson_attendance.marked_at', $start, $end)
            ->where('rep_d.organization_id', $orgId)
            ->when($studentId, fn ($q) => $q->where('rep_d.student_id', $studentId))
            ->when($teacherId, fn ($q) => $q->where('rep_d.teacher_id', $teacherId))
            ->when($programId, fn ($q) => $q->where('rep_d.program_id', $programId))
            ->selectRaw(implode(', ', [
                'DATE(lesson_attendance.marked_at) as date',
                'COUNT(*) as total',
                "SUM(CASE WHEN lesson_attendance.status = 'present' THEN 1 ELSE 0 END) as present",
                "SUM(CASE WHEN lesson_attendance.status = 'absent' THEN 1 ELSE 0 END) as absent",
                "SUM(CASE WHEN lesson_attendance.status = 'late' THEN 1 ELSE 0 END) as late",
            ]))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total;
                $present = (int) $row->present;

                return [
                    'date' => $row->date,
                    'total' => $total,
                    'present' => $present,
                    'absent' => (int) $row->absent,
                    'late' => (int) $row->late,
                    'rate' => $total > 0 ? round(($present / $total) * 100, 1) : 0,
                ];
            })
            ->values();

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => [
                'total_lessons_scheduled' => $totalLessons,
                'completed_lessons' => $completedLessons,
                'cancelled_lessons' => $cancelledLessons,
                'student_absent_lessons' => $studentAbsentLessons,
                'teacher_absent_lessons' => $teacherAbsentLessons,
                'total_attendance_records' => $totalAttendance,
                'present' => $presentCount,
                'absent' => $absentCount,
                'late' => $lateCount,
                'attendance_rate' => $attendanceRate.'%',
            ],
            'by_student' => $studentStats,
            'by_date' => $dailyStats,
        ]);
    }

    // 2. Subscription Status Report - تقرير حالة الاشتراكات
        public function subscriptions(Request $request)
    {
        $start = $request->filled('from') ? Carbon::parse($request->string('from')) : Carbon::now()->startOfMonth();
        $end = $request->filled('to') ? Carbon::parse($request->string('to')) : Carbon::now()->endOfMonth();

        /**
         * ⭐⭐⭐ **PR 3.14 — تقرير الاشتراكات: مسار + عزل مؤسسة +
         * aggregates.**
         *
         * ⭐ **قبل PR ده**: الكود كان بلا مسار، بيجمع كل صفوف
         * الاشتراك من **كل المؤسسات**، وبني الملخص/الإيراد
         * للبرامج/المعلمين في collections من الذاكرة.
         *
         * ⭐ **دلوقتي**: العدّادات والإيراد كلهم SQL aggregates
         * (`selectRaw` + `groupBy`)، ومحكومين بالمؤسسة، والإيراد
         * بيمر عبر `MoneyCast::fromAggregate` (القرش → الوحدة
         * الكبرى) لأن `sum()` على الـquery بيتعدي على الـcast.
         */
        $orgId = (int) $request->user()->organization_id;

        $query = Subscription::query()
            ->where('organization_id', $orgId);

        if ($status = $request->filled('status') ? $request->string('status') : null) {
            $query->where('status', $status);
        }
        if ($programId = $request->filled('program_id') ? $request->integer('program_id') : null) {
            $query->where('program_id', $programId);
        }
        if ($teacherId = $request->filled('teacher_id') ? $request->integer('teacher_id') : null) {
            $query->where('teacher_id', $teacherId);
        }

        // ─── الملخص: عدّاد واحد مجمّع بالحالة ───
        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $total = (int) array_sum($statusCounts->all());
        $active = (int) ($statusCounts['active'] ?? 0);
        $expired = (int) ($statusCounts['expired'] ?? 0);
        $paused = (int) ($statusCounts['paused'] ?? 0);
        $cancelled = (int) ($statusCounts['cancelled'] ?? 0);

        // ⭐ نافذة «هتنتهي قريباً» من مركز الإعدادات (PR 3.12).
        $expiryWarningDays = (int) settings()->get('subscriptions.expiry_warning_days', $orgId);
        $expiringSoon = (clone $query)
            ->where('status', 'active')
            ->whereNotNull('end_date')
            ->whereRange('end_date', Carbon::today(), Carbon::today()->copy()->addDays($expiryWarningDays))
            ->count();

        // ─── الإيراد بالعملة: تجميعة SQL واحدة ───
        // ⭐ `SUM(price)` خام بالقرش — بيمر عبر fromAggregate.
        $revenueByCurrency = (clone $query)
            ->whereIn('status', ['active'])
            ->selectRaw('currency, SUM(price) as total_minor, COUNT(*) as cnt')
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(fn ($row) => [
                $row->currency => [
                    'total' => MoneyCast::fromAggregate($row->total_minor, $row->currency),
                    'count' => (int) $row->cnt,
                ],
            ]);

        // ─── محور البرنامج: تجميعة SQL واحدة ───
        $programRows = (clone $query)
            ->selectRaw(implode(', ', [
                'program_id',
                'COUNT(*) as total',
                "SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_cnt",
                "SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_cnt",
                "SUM(CASE WHEN status = 'active' THEN price ELSE 0 END) as revenue_minor",
            ]))
            ->groupBy('program_id')
            ->orderBy('program_id')
            ->get();

        $programNames = \App\Models\Program::query()
            ->whereIn('id', $programRows->pluck('program_id'))
            ->pluck('name', 'id');

        $byProgram = $programRows
            ->map(function ($row) use ($programNames) {
                return [
                    'program_id' => (int) $row->program_id,
                    'program_name' => $programNames[$row->program_id] ?? 'غير معروف',
                    'total' => (int) $row->total,
                    'active' => (int) $row->active_cnt,
                    'expired' => (int) $row->expired_cnt,
                    // ⚠️ سلوك قديم — مجموع العملات معاً داخل البرنامج
                    // (مسجل بالعملة الافتراضية — الافتراض داخلي في
                    // MoneyCast). تفكيك كل عملة على حدة = تحسين
                    // مقترح لاحقاً.
                    'revenue' => MoneyCast::fromAggregate($row->revenue_minor),
                ];
            })
            ->values();

        // ─── محور المعلم: تجميعة SQL واحدة ───
        $teacherRows = (clone $query)
            ->selectRaw(implode(', ', [
                'teacher_id',
                'COUNT(*) as total_students',
                "SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_cnt",
                "SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired_cnt",
            ]))
            ->groupBy('teacher_id')
            ->orderBy('teacher_id')
            ->get();

        $teacherNames = \App\Models\Teacher::query()
            ->whereIn('id', $teacherRows->pluck('teacher_id')->filter()->all())
            ->pluck('display_name', 'id');

        $byTeacher = $teacherRows
            ->map(function ($row) use ($teacherNames) {
                return [
                    'teacher_id' => (int) $row->teacher_id,
                    'teacher_name' => ($row->teacher_id && isset($teacherNames[$row->teacher_id]))
                        ? $teacherNames[$row->teacher_id]
                        : 'بدون معلم',
                    'total_students' => (int) $row->total_students,
                    'active' => (int) $row->active_cnt,
                    'expired' => (int) $row->expired_cnt,
                ];
            })
            ->values();

        // ─── التفاصيل: صفوف بمساندة أسمائها (شكل العقد نفسه) ───
        $details = (clone $query)
            ->with(['student:id,first_name,last_name,student_code,status', 'program:id,name', 'teacher:id,display_name'])
            ->orderBy('id')
            ->get()
            ->map(function ($sub) use ($expiryWarningDays) {
                $daysLeft = $sub->end_date
                    ? (int) Carbon::now()->startOfDay()->diffInDays(Carbon::parse($sub->end_date)->startOfDay())
                    : null;

                return [
                    'id' => $sub->id,
                    'student' => [
                        'id' => $sub->student->id,
                        'name' => $sub->student->full_name,
                        'code' => $sub->student->student_code,
                        'status' => $sub->student->status,
                    ],
                    'program' => $sub->program?->name ?? '—',
                    'teacher' => $sub->teacher?->full_name ?? '—',
                    'start_date' => $sub->start_date,
                    'end_date' => $sub->end_date,
                    'days_left' => $daysLeft,
                    // ⭐ الراية بنفس النافذة من مركز الإعدادات (كانت
                    // الرقم الحبيب 7 — وهي مخالفة للملخص).
                    'is_expiring_soon' => $daysLeft !== null
                        && $daysLeft >= 0
                        && $daysLeft <= $expiryWarningDays,
                    'status' => $sub->status,
                    'billing_type' => $sub->billing_type,
                    'price' => $sub->price,
                    'currency' => $sub->currency,
                    'lessons_included' => $sub->lessons_included,
                    'lesson_duration_minutes' => $sub->lesson_duration_minutes,
                ];
            });

        return response()->json([
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'summary' => [
                'total' => $total,
                'active' => $active,
                'expired' => $expired,
                'paused' => $paused,
                'cancelled' => $cancelled,
                'expiring_soon' => $expiringSoon,
            ],
            'revenue_by_currency' => $revenueByCurrency,
            'by_program' => $byProgram,
            'by_teacher' => $byTeacher,
            'details' => $details,
        ]);
    }
}
