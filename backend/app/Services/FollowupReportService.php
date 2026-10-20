<?php

namespace App\Services;

use App\Casts\MoneyCast;
use App\Models\Expense;
use App\Models\FinancialAccount;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TeacherEarning;
use App\Models\TeacherPayment;
use Carbon\Carbon;

/**
 * PR 2.16 — تقارير المتابعة اليومية: أعمار الديون، أرصدة
 * الخزائن، التزامات المعلمين، مصادر الإيراد (برنامج/معلم)،
 * وصفوف تصدير الفواتير/المدفوعات/المصروفات.
 *
 * ══════════════════════════════════════════════════════════════
 * نفس عقد 2.15: **aggregates في SQL** (SUM/GROUP BY) بدون تحميل
 * موديلات، والمبالغ بالقرش في قاعدة البيانات فكل عدد يخرج عبر
 * `MoneyCast::fromAggregate()` القاعدة 6. كل استعلام محكوم
 * **بالمنظمة** خالص من تسريب.
 *
 * ⭐ حدود الشرائح في أعمار الديون بتتقص في PHP (نصوص تواريخ
 * `Y-m-d`) وترجّع كـbindings — فمافيش دوال تواريخ خاصة
 * بمحرك (SQLite في الاختبار / PG في الإنتاج).
 */
class FollowupReportService
{
    public function __construct(
        private readonly int $organizationId,
    ) {}

    /**
     * ⭐ القرش ← الجنيه (نص مثل "1650.00") — القاعدة 6:
     * الـSUM بالقرش والعرض بالجنيه عبر الكاست الرسمي.
     */
    private function major(mixed $piastres): float
    {
        return (float) (MoneyCast::fromAggregate($piastres ?? 0) ?? '0');
    }

    /**
     * ⭐ أعمار الديون — صف لكل (طالب، عملة)؛ العمر = عدد الأيام
     * من **تاريخ الاستحقاق** (مش من الإصدار):
     *
     *     not_due    لم تستحق بعد      due_date > اليوم
     *     d0_30      0–30 يوم متأخرة
     *     d31_60     31–60
     *     d61_90     61–90
     *     d90_plus   أكثر من 90
     *
     * الفواتير اللاغية (void) والمدفوعة بالكامل (balance 0) مستبعدة.
     */
    public function aging(Carbon $today): array
    {
        $day0 = $today->toDateString();
        $dayMinus30 = $today->copy()->subDays(30)->toDateString();
        $dayMinus31 = $today->copy()->subDays(31)->toDateString();
        $dayMinus60 = $today->copy()->subDays(60)->toDateString();
        $dayMinus61 = $today->copy()->subDays(61)->toDateString();
        $dayMinus90 = $today->copy()->subDays(90)->toDateString();
        $dayMinus91 = $today->copy()->subDays(91)->toDateString();

        // الشرائح بحدود يوم الاستحقاق:
        // d0_30    ← due_date في [اليوم−30، اليوم]
        // d31_60   ← في [اليوم−60، اليوم−31]
        // d61_90   ← في [اليوم−90، اليوم−61]
        // d90_plus ← قبل اليوم−91
        $rows = Invoice::query()
            ->where('invoices.organization_id', $this->organizationId)
            ->where('invoices.status', '!=', 'void')
            ->where('invoices.balance_due', '>', 0)
            ->leftJoin('students as ag_st', 'ag_st.id', '=', 'invoices.student_id')
            ->selectRaw('invoices.student_id AS student_id')
            ->selectRaw('invoices.currency AS currency')
            ->selectRaw('COALESCE(ag_st.first_name, \'\') AS first_name')
            ->selectRaw('COALESCE(ag_st.last_name, \'\') AS last_name')
            ->selectRaw('COUNT(*) AS invoices_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN invoices.due_date > ? THEN invoices.balance_due ELSE 0 END), 0) AS not_due', [$day0])
            ->selectRaw('COALESCE(SUM(CASE WHEN invoices.due_date <= ? AND invoices.due_date >= ? THEN invoices.balance_due ELSE 0 END), 0) AS d0_30', [$day0, $dayMinus30])
            ->selectRaw('COALESCE(SUM(CASE WHEN invoices.due_date <= ? AND invoices.due_date >= ? THEN invoices.balance_due ELSE 0 END), 0) AS d31_60', [$dayMinus31, $dayMinus60])
            ->selectRaw('COALESCE(SUM(CASE WHEN invoices.due_date <= ? AND invoices.due_date >= ? THEN invoices.balance_due ELSE 0 END), 0) AS d61_90', [$dayMinus61, $dayMinus90])
            ->selectRaw('COALESCE(SUM(CASE WHEN invoices.due_date < ? THEN invoices.balance_due ELSE 0 END), 0) AS d90_plus', [$dayMinus91])
            ->groupBy('invoices.student_id', 'invoices.currency', 'ag_st.first_name', 'ag_st.last_name')
            ->orderBy('ag_st.last_name')
            ->orderBy('invoices.currency')
            ->get();

        $students = $rows->map(fn ($row) => [
            'student_id' => (int) $row->student_id,
            'student_name' => trim($row->first_name.' '.$row->last_name),
            'currency' => (string) $row->currency,
            'invoices_count' => (int) $row->invoices_count,
            'not_due' => $this->major($row->not_due),
            'd0_30' => $this->major($row->d0_30),
            'd31_60' => $this->major($row->d31_60),
            'd61_90' => $this->major($row->d61_90),
            'd90_plus' => $this->major($row->d90_plus),
            'total' => $this->major(
                (int) $row->not_due + (int) $row->d0_30 + (int) $row->d31_60
                + (int) $row->d61_90 + (int) $row->d90_plus,
            ),
        ])->all();

        $totals = [];
        foreach ($students as $student) {
            $currency = $student['currency'];
            $totals[$currency] = $totals[$currency]
                ?? ['not_due' => 0.0, 'd0_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90_plus' => 0.0, 'total' => 0.0];
            foreach (['not_due', 'd0_30', 'd31_60', 'd61_90', 'd90_plus', 'total'] as $field) {
                $totals[$currency][$field] = round($totals[$currency][$field] + $student[$field], 2);
            }
        }

        return [
            'students' => $students,
            'totals' => $totals,
            'generated_at' => $day0,
        ];
    }

    /**
     * ⭐ نسخة التصدير من أعمار الديون: **صف لكل فاتورة** (متأنية
     * للمتابعة — المكالمة على فاتورة مش على مجمع) + صفوف الإجمالي
     * لكل عملة في الآخر.
     *
     * ⭐ المقبولية «مجاميع التقرير = مجاميع الملف»: المجموع في
     * JSON (`aging()[totals]`) هو نفسه مجموع صفوف الفواتير هنا —
     * نفس مصدر البيانات ونفس الشرائح.
     */
    public function agingExport(Carbon $today): array
    {
        $todayStart = $today->copy()->startOfDay();

        $rows = Invoice::query()
            ->where('invoices.organization_id', $this->organizationId)
            ->where('invoices.status', '!=', 'void')
            ->where('invoices.balance_due', '>', 0)
            ->leftJoin('students as ax_st', 'ax_st.id', '=', 'invoices.student_id')
            ->selectRaw('invoices.invoice_number AS invoice_number')
            ->selectRaw('COALESCE(ax_st.first_name, \'\') AS first_name')
            ->selectRaw('COALESCE(ax_st.last_name, \'\') AS last_name')
            ->selectRaw('invoices.currency AS currency')
            ->selectRaw('SUBSTR(invoices.due_date, 1, 10) AS due_day')
            ->selectRaw('invoices.balance_due AS balance_raw')
            ->orderBy('invoices.due_date')
            ->orderBy('invoices.id')
            ->get();

        $dataRows = [];
        $totalsByCurrency = [];
        foreach ($rows as $row) {
            $dueStart = Carbon::parse((string) $row->due_day)->startOfDay();
            $daysPast = (int) floor(($todayStart->timestamp - $dueStart->timestamp) / 86400);
            $totalsByCurrency[$row->currency] = ($totalsByCurrency[$row->currency] ?? 0) + (int) $row->balance_raw;
            $dataRows[] = [
                (string) $row->invoice_number,
                trim($row->first_name.' '.$row->last_name),
                (string) $row->currency,
                (string) $row->due_day,
                // متأخرة (موجب) أو متبقية (سالب) — الرقم السالب
                // معلومات حقيقية: «لسه باقي 12 يوم»
                $daysPast,
                $this->bucketLabel($this->bucketFor($daysPast)),
                number_format($this->major($row->balance_due), 2, '.', ''),
            ];
        }

        $totalRows = [];
        foreach ($totalsByCurrency as $currency => $raw) {
            $totalRows[] = [
                'الإجمالي', '', $currency, '', '', '',
                number_format($this->major($raw), 2, '.', ''),
            ];
        }

        return [
            'columns' => ['رقم الفاتورة', 'الطالب', 'العملة', 'تاريخ الاستحقاق', 'أيام متأخرة', 'الشريحة', 'المتبقي'],
            'rows' => $dataRows,
            'total_rows' => $totalRows,
        ];
    }

    private function bucketFor(int $daysPast): string
    {
        if ($daysPast < 0) {
            return 'not_due';
        }
        if ($daysPast <= 30) {
            return 'd0_30';
        }
        if ($daysPast <= 60) {
            return 'd31_60';
        }
        if ($daysPast <= 90) {
            return 'd61_90';
        }

        return 'd90_plus';
    }

    private function bucketLabel(string $bucket): string
    {
        return match ($bucket) {
            'not_due' => 'لم تستحق',
            'd0_30' => '0–30',
            'd31_60' => '31–60',
            'd61_90' => '61–90',
            default => '+90',
        };
    }

    /**
     * ⭐ أرصدة الخزائن — الانفرج + مجموع الحركات **المنشورة**
     * (نفس دلالة `FinancialAccount::balance()` في aggregates SQL).
     * الحركة المعلقة (pending) فلوسها لسه مش حقيقية — مستبعدة.
     */
    public function accountBalances(): array
    {
        $rows = FinancialAccount::query()
            ->where('financial_accounts.organization_id', $this->organizationId)
            ->leftJoin('account_movements as ab_am', function ($join) {
                $join->on('ab_am.financial_account_id', '=', 'financial_accounts.id')
                    ->where('ab_am.status', 'posted');
            })
            ->selectRaw('financial_accounts.id AS account_id')
            ->selectRaw('financial_accounts.name AS name')
            ->selectRaw('financial_accounts.type AS type')
            ->selectRaw('financial_accounts.currency AS currency')
            ->selectRaw('financial_accounts.opening_balance AS opening_raw')
            ->selectRaw('COUNT(ab_am.id) AS movements_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN ab_am.direction = ? THEN ab_am.amount ELSE -ab_am.amount END), 0) AS movements_sum', ['in'])
            ->groupBy('financial_accounts.id', 'financial_accounts.name', 'financial_accounts.type', 'financial_accounts.currency', 'financial_accounts.opening_balance')
            ->orderBy('financial_accounts.id')
            ->get();

        $accounts = $rows->map(function ($row) {
            // ⭐ الـalias بـ_raw — لو Alias بقى اسم خاصية عليها cast
            // (زي opening_balance) الـaccessor بيتفتح ومقيمتو يبوظ
            // (100000 قرش → "1000.00" → major() → 10!). لاحظي
            // الحسبة نفسها من الـraw الدايم.
            $openingRaw = (int) $row->opening_raw;
            $balanceRaw = $openingRaw + (int) $row->movements_sum;

            return [
                'id' => (int) $row->account_id,
                'name' => (string) $row->name,
                'type' => (string) $row->type,
                'currency' => (string) $row->currency,
                'opening_balance' => $this->major($openingRaw),
                'movements_sum' => $this->major($row->movements_sum),
                'movements_count' => (int) $row->movements_count,
                'balance' => $this->major($balanceRaw),
            ];
        })->all();

        $totals = [];
        foreach ($accounts as $account) {
            $currency = $account['currency'];
            $totals[$currency] = $totals[$currency] ?? ['balance' => 0.0, 'accounts' => 0];
            $totals[$currency]['balance'] = round($totals[$currency]['balance'] + $account['balance'], 2);
            $totals[$currency]['accounts']++;
        }

        return [
            'accounts' => $accounts,
            'totals' => $totals,
        ];
    }

    /**
     * ⭐ التزامات المعلمين — المستحقات المعتمدة/المدفوعة − الدفعات
     * المكتملة. المعلقة (pending) مش التزام — مدير لسه معتمدها.
     *
     * ⭐ اثنين aggregates منفصلين (مفاجئ، لا FULL JOIN في SQLite)
     * فيدمجوا في PHP على (معلم، عملة) — عد الصفوف صغير.
     */
    public function teacherLiabilities(): array
    {
        $gross = TeacherEarning::query()
            ->join('teachers as tl_t', 'tl_t.id', '=', 'teacher_earnings.teacher_id')
            ->where('tl_t.organization_id', $this->organizationId)
            ->whereIn('teacher_earnings.status', ['approved', 'paid'])
            ->selectRaw('teacher_earnings.teacher_id AS teacher_id')
            ->selectRaw('tl_t.display_name AS name')
            ->selectRaw('teacher_earnings.currency AS currency')
            ->selectRaw('COALESCE(SUM(teacher_earnings.amount), 0) AS gross_raw')
            ->groupBy('teacher_earnings.teacher_id', 'tl_t.display_name', 'teacher_earnings.currency')
            ->orderBy('tl_t.display_name')
            ->get();

        $paid = TeacherPayment::query()
            ->join('teachers as tl_p', 'tl_p.id', '=', 'teacher_payments.teacher_id')
            ->where('tl_p.organization_id', $this->organizationId)
            ->where('teacher_payments.status', 'completed')
            ->selectRaw('teacher_payments.teacher_id AS teacher_id')
            ->selectRaw('teacher_payments.currency AS currency')
            ->selectRaw('COALESCE(SUM(teacher_payments.amount), 0) AS paid_raw')
            ->groupBy('teacher_payments.teacher_id', 'teacher_payments.currency')
            ->get();

        $teachers = [];
        foreach ($gross as $row) {
            $key = $row->teacher_id.'|'.$row->currency;
            $teachers[$key] = [
                'teacher_id' => (int) $row->teacher_id,
                'name' => (string) $row->name,
                'currency' => (string) $row->currency,
                'gross_earnings' => $this->major($row->gross_raw),
                'payments_made' => 0.0,
                'gross_raw' => (int) $row->gross_raw,
                'paid_raw' => 0,
            ];
        }
        foreach ($paid as $row) {
            $key = $row->teacher_id.'|'.$row->currency;
            if (! isset($teachers[$key])) {
                // دفعة بلا مستحقات معتمدة — مش التزام، مستبعدة من التقرير
                continue;
            }
            $teachers[$key]['payments_made'] = $this->major($row->paid_raw);
            $teachers[$key]['paid_raw'] = (int) $row->paid_raw;
        }

        $teachers = array_map(function ($t) {
            $t['liability'] = round(max(0.0, $t['gross_earnings'] - $t['payments_made']), 2);
            $t['liability_raw'] = max(0, $t['gross_raw'] - $t['paid_raw']);
            unset($t['gross_raw'], $t['paid_raw']);

            return $t;
        }, $teachers);

        $totals = [];
        foreach ($teachers as $teacher) {
            $currency = $teacher['currency'];
            $totals[$currency] = $totals[$currency] ?? ['gross_earnings' => 0.0, 'payments_made' => 0.0, 'liability' => 0.0];
            foreach (['gross_earnings', 'payments_made', 'liability'] as $field) {
                $totals[$currency][$field] = round($totals[$currency][$field] + $teacher[$field], 2);
            }
        }

        return [
            'teachers' => array_values($teachers),
            'totals' => $totals,
        ];
    }

    /**
     * ⭐ مصادر الإيراد — الفواتير **المتعلقة باشتراك**: برنامج
     * الاشتراك → program، والمعلم المستحق → teacher.
     * فاتورة بلا اشتراك (يدوية) مصدرها غير معروف — مستبعدة وموثقة.
     */
    public function revenueSources(): array
    {
        $base = Invoice::query()
            ->where('invoices.organization_id', $this->organizationId)
            ->where('invoices.status', '!=', 'void')
            ->whereNotNull('invoices.subscription_id')
            ->join('subscriptions as rs_sub', 'rs_sub.id', '=', 'invoices.subscription_id');

        $byProgram = (clone $base)
            ->join('programs as rs_pr', 'rs_pr.id', '=', 'rs_sub.program_id')
            ->selectRaw('rs_sub.program_id AS source_id')
            ->selectRaw('rs_pr.name AS name')
            ->selectRaw('invoices.currency AS currency')
            ->selectRaw('COALESCE(SUM(invoices.total), 0) AS invoiced_raw')
            ->selectRaw('COUNT(*) AS invoices_count')
            ->groupBy('rs_sub.program_id', 'rs_pr.name', 'invoices.currency')
            ->orderBy('rs_pr.name')
            ->get();

        $byTeacher = (clone $base)
            ->join('teachers as rs_tch', 'rs_tch.id', '=', 'rs_sub.teacher_id')
            ->selectRaw('rs_sub.teacher_id AS source_id')
            ->selectRaw('rs_tch.display_name AS name')
            ->selectRaw('invoices.currency AS currency')
            ->selectRaw('COALESCE(SUM(invoices.total), 0) AS invoiced_raw')
            ->selectRaw('COUNT(*) AS invoices_count')
            ->groupBy('rs_sub.teacher_id', 'rs_tch.display_name', 'invoices.currency')
            ->orderBy('rs_tch.display_name')
            ->get();

        $mapRow = fn ($row) => [
            'id' => (int) $row->source_id,
            'name' => (string) $row->name,
            'currency' => (string) $row->currency,
            'invoiced' => $this->major($row->invoiced_raw),
            'invoices_count' => (int) $row->invoices_count,
        ];

        return [
            'by_program' => array_map(fn ($row) => [
                'program_id' => $row['id'],
                'name' => $row['name'],
                'currency' => $row['currency'],
                'invoiced' => $row['invoiced'],
                'invoices_count' => $row['invoices_count'],
            ], $byProgram->map($mapRow)->all()),
            'by_teacher' => array_map(fn ($row) => [
                'teacher_id' => $row['id'],
                'name' => $row['name'],
                'currency' => $row['currency'],
                'invoiced' => $row['invoiced'],
                'invoices_count' => $row['invoices_count'],
            ], $byTeacher->map($mapRow)->all()),
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // تصدير السجلات — الفواتير/المدفوعات/المصروفات
    // ═══════════════════════════════════════════════════════════

    /**
     * ⭐ فواتير جاهزة للتصدير — مع فلتر مختياري على تاريخ
     * الإصدار (from/to). كل الحالات تظهر (عمود الحالة موجود).
     */
    public function invoiceExportRows(?string $from, ?string $to): array
    {
        $rows = Invoice::query()
            ->where('invoices.organization_id', $this->organizationId)
            ->leftJoin('students as ix_st', 'ix_st.id', '=', 'invoices.student_id')
            ->when($from, fn ($q) => $q->where('invoices.issue_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('invoices.issue_date', '<=', $to))
            ->selectRaw('invoices.invoice_number AS invoice_number')
            ->selectRaw('COALESCE(ix_st.first_name, \'\') AS first_name')
            ->selectRaw('COALESCE(ix_st.last_name, \'\') AS last_name')
            ->selectRaw('invoices.currency AS currency')
            ->selectRaw('SUBSTR(invoices.issue_date, 1, 10) AS issued_day')
            ->selectRaw('SUBSTR(invoices.due_date, 1, 10) AS due_day')
            ->selectRaw('invoices.total AS total_raw')
            ->selectRaw('invoices.paid_amount AS paid_raw')
            ->selectRaw('invoices.balance_due AS balance_raw')
            ->selectRaw('invoices.status AS status')
            ->orderBy('invoices.issue_date')
            ->orderBy('invoices.id')
            ->get();

        $dataRows = $rows->map(fn ($row) => [
            (string) $row->invoice_number,
            trim($row->first_name.' '.$row->last_name),
            (string) $row->currency,
            (string) $row->issued_day,
            (string) $row->due_day,
            number_format($this->major($row->total_raw), 2, '.', ''),
            number_format($this->major($row->paid_raw), 2, '.', ''),
            number_format($this->major($row->balance_raw), 2, '.', ''),
            (string) $row->status,
        ])->all();

        return [
            'columns' => ['رقم الفاتورة', 'الطالب', 'العملة', 'الإصدار', 'الاستحقاق', 'الإجمالي', 'المدفوع', 'المتبقي', 'الحالة'],
            'rows' => $dataRows,
        ];
    }

    /**
     * ⭐ مدفوعات جاهزة للتصدير — فلتر على تاريخ التحصيل (from/to).
     */
    public function paymentExportRows(?string $from, ?string $to): array
    {
        $rows = Payment::query()
            ->where('payments.organization_id', $this->organizationId)
            ->when($from, fn ($q) => $q->whereDate('payments.paid_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('payments.paid_at', '<=', $to))
            ->leftJoin('students as px_st', 'px_st.id', '=', 'payments.student_id')
            ->selectRaw('payments.id AS payment_id')
            ->selectRaw('COALESCE(px_st.first_name, \'\') AS first_name')
            ->selectRaw('COALESCE(px_st.last_name, \'\') AS last_name')
            ->selectRaw('SUBSTR(payments.paid_at, 1, 10) AS paid_day')
            ->selectRaw('payments.payment_method AS payment_method')
            ->selectRaw('payments.amount AS amount_raw')
            ->selectRaw('payments.currency AS currency')
            ->selectRaw('payments.status AS status')
            ->selectRaw('payments.transaction_reference AS transaction_reference')
            ->orderBy('payments.paid_at')
            ->orderBy('payments.id')
            ->get();

        $dataRows = $rows->map(fn ($row) => [
            (string) $row->payment_id,
            trim($row->first_name.' '.$row->last_name),
            (string) $row->paid_day,
            (string) $row->payment_method,
            number_format($this->major($row->amount_raw), 2, '.', ''),
            (string) $row->currency,
            (string) $row->status,
            (string) ($row->transaction_reference ?? ''),
        ])->all();

        return [
            'columns' => ['الرقم', 'الطالب', 'التاريخ', 'الطريقة', 'المبلغ', 'العملة', 'الحالة', 'المرجع'],
            'rows' => $dataRows,
        ];
    }

    /**
     * ⭐ مصروفات جاهزة للتصدير — فلتر على تاريخ الصرف (from/to).
     */
    public function expenseExportRows(?string $from, ?string $to): array
    {
        $rows = Expense::query()
            ->where('expenses.organization_id', $this->organizationId)
            ->when($from, fn ($q) => $q->whereDate('expenses.expense_date', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('expenses.expense_date', '<=', $to))
            ->leftJoin('expense_categories as ex_c', 'ex_c.id', '=', 'expenses.category_id')
            ->selectRaw('expenses.id AS expense_id')
            ->selectRaw('SUBSTR(expenses.expense_date, 1, 10) AS expense_day')
            ->selectRaw('COALESCE(ex_c.name, \'\') AS category_name')
            ->selectRaw('expenses.amount AS amount_raw')
            ->selectRaw('expenses.currency AS currency')
            ->selectRaw('COALESCE(expenses.description, \'\') AS description')
            ->selectRaw('expenses.status AS status')
            ->orderBy('expenses.expense_date')
            ->orderBy('expenses.id')
            ->get();

        $dataRows = $rows->map(fn ($row) => [
            (string) $row->expense_id,
            (string) $row->expense_day,
            (string) $row->category_name,
            number_format($this->major($row->amount), 2, '.', ''),
            (string) $row->currency,
            (string) $row->description,
            (string) $row->status,
        ])->all();

        return [
            'columns' => ['الرقم', 'التاريخ', 'الفئة', 'المبلغ', 'العملة', 'الوصف', 'الحالة'],
            'rows' => $dataRows,
        ];
    }
}
