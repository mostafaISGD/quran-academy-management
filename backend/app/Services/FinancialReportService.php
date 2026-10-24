<?php

namespace App\Services;

use App\Casts\ExchangeRateCast;
use App\Casts\MoneyCast;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PromoCodeUsage;
use App\Models\Refund;
use App\Models\Subscription;
use App\Models\TeacherEarning;
use App\Models\TeacherPayment;
use Carbon\Carbon;

/**
 * PR 2.15 — التقرير المالي **بالـSQL aggregates** — مافيش تحميل
 * سجلات في الذاكرة.
 *
 * ══════════════════════════════════════════════════════════════
 * الوضع قبلها (المواصفة بتطلب إصلاحه):
 *
 * ① `->get()` على كل الجداول وجمع في PHP — آلاف السجلات = ذاكرة
 *    وضياع. **كل الأرقام دلوقتي `SUM`/`GROUP BY`… في القاعدة**.
 * ② **خلطة وحدات**: 600 جنيه + 40 دولار تجمعوا «640»! — **كل
 *    سلسلة للمصادر بتتجمّع لكل عملة لوحدها** (`currencies`)،
 *    والحقيبة المحوّلة (`converted`) بترجع لعملة المنظمة بسعر
 *    الصرف **المسجّل وقت العملية** (آخر `valid_date <= تاريخ
 *    العملية` — نفس دلالة `ExchangeRate::rateFor`).
 * ③ تسريب multi-tenant: مافيش_org scope على أي استعلام — كل
 *    الاستعلامات هنا محكومة بالمنظمة.
 * ④ الفلاتر: `branch_id` على الفواتير/المصروفات/دفعات الطلاب،
 *    `program_id` على الفواتير (عبر اشتراكها)، `teacher_id`
 *    على مرتبات المعلمين — والفرعي القديم `revenues` بيفضل
 *    زي ما هو contract بسعر صرف واقعي.
 * ══════════════════════════════════════════════════════════════
 *
 * ⚠️ `whereHas` ممنوع هنا (بيكوّن `select *` داخلي والنقطة وهي
 * «مافيش تحميل موديلات» حرفياً) — بدلها `whereIn` بـsubquery
 * بـ`selectRaw('id')`.
 */
class FinancialReportService
{
    public function __construct(
        private readonly int $organizationId,
        private readonly string $targetCurrency,
    ) {}

    /**
     * آخر سعر صرف صالح على تاريخ الصف — نفس دلالة
     * `ExchangeRate::rateFor(from, to, date)` لكن في SQL.
     */
    private function rateExpr(string $table, string $dateColumn): string
    {
        return sprintf(
            "(SELECT er.rate FROM exchange_rates er WHERE er.from_currency = %s.currency "
            ."AND er.to_currency = '%s' AND er.valid_date <= DATE(%s.%s) "
            .'ORDER BY er.valid_date DESC LIMIT 1)',
            $table,
            $this->targetCurrency,
            $table,
            $dateColumn,
        );
    }

    /**
     * ⭐ رمز العملة المستهدفة **كـliteral آمن** — رموز العملات
     * حروف لاتينية كبيرة بس (char(3))، فالتضمين المباشر في
     * الـXSQL أضمن من إدارة bindings موزعة على selectRaw كتير.
     */
    private function currencyLiteral(): string
    {
        return "'".preg_replace("/[^A-Z]/", '', $this->targetCurrency)."'";
    }

    /**
     * ⭐ تعبير التحويل لعملة المنظمة — عملة المنظمة نفسها
     * بتعدي بنفسها (rate 1 مضمون):
     *
     *     CASE WHEN currency = 'EGP' THEN amount
     *          ELSE amount * COALESCE(rate, 0) / 1e6   ← السعر مقيّس ×10^6
     *     END
     */
    private function convertedExpr(string $table, string $dateColumn, string $amountColumn): string
    {
        $rate = $this->rateExpr($table, $dateColumn);

        return sprintf(
            'COALESCE(SUM(CASE WHEN %s.currency = %s THEN %1$s.%5$s ELSE %1$s.%5$s * COALESCE(%s, 0) / %d END), 0)',
            $table, $this->currencyLiteral(), $rate, ExchangeRateCast::SCALE, $amountColumn,
        );
    }

    /**
     * عدّاد صفوف بلا سعر مسجّل — بيداوي `missing_rates`.
     */
    private function missingExpr(string $table, string $dateColumn): string
    {
        $rate = $this->rateExpr($table, $dateColumn);

        return sprintf(
            'SUM(CASE WHEN %s.currency != %s AND (%s) IS NULL THEN 1 ELSE 0 END)',
            $table, $this->currencyLiteral(), $rate,
        );
    }

    private function major(int|string|float|null $piastres): float
    {
        return (float) (MoneyCast::fromAggregate($piastres ?? 0) ?? '0');
    }

    // ═══════════════════════════════════════════════════════════
    // المصادر — كل واحد سلسلة {currency: raw | converted}
    // ═══════════════════════════════════════════════════════════

    /**
     * فواتير: مفوتَر + خصم (ترويجي/يدوي) + متبقٍ — **للـvoid مكانها**.
     */
    private function invoices(Carbon $start, Carbon $end, array $f): array
    {
        $rate = $this->rateExpr('invoices', 'issue_date');
        $missing = $this->missingExpr('invoices', 'issue_date');

        $promoSub = \App\Models\PromoCodeUsage::query()
            ->selectRaw('invoice_id, SUM(discount_amount) AS promo_total')
            ->groupBy('invoice_id');

        $rows = Invoice::query()
            ->where('invoices.organization_id', $this->organizationId)
            ->whereRange('issue_date', $start, $end)
            // ⭐ اللغتّة بس اللي بتستبعد — أضمن من قائمة بيضاء
            // تقدر تبلع أرصيد مجهولة غيبها (credited/credited_note…)
            ->where('invoices.status', '!=', 'void')
            ->when(isset($f['branch_id']), fn ($q) => $q->where('invoices.branch_id', $f['branch_id']))
            ->when(isset($f['program_id']), fn ($q) => $q->whereIn('invoices.subscription_id',
                Subscription::query()->selectRaw('id')->where('program_id', $f['program_id'])))
            ->leftJoinSub($promoSub, 'pu', 'invoices.id', '=', 'pu.invoice_id')
            ->selectRaw('currency')
            ->selectRaw('COALESCE(SUM(invoices.total), 0) AS invoiced_raw')
            ->selectRaw('COALESCE(SUM(invoices.balance_due), 0) AS outstanding_raw')
            ->selectRaw('COALESCE(SUM(invoices.discount), 0) AS discounts_raw')
            ->selectRaw('COALESCE(SUM(COALESCE(pu.promo_total, 0)), 0) AS promo_raw')
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw($this->convertedExpr('invoices', 'issue_date', 'total').' AS invoiced_c')
            ->selectRaw($this->convertedExpr('invoices', 'issue_date', 'balance_due').' AS outstanding_c')
            ->selectRaw($this->convertedExpr('invoices', 'issue_date', 'discount').' AS discounts_c')
            ->selectRaw(sprintf(
                'COALESCE(SUM(CASE WHEN invoices.currency = %s THEN COALESCE(pu.promo_total, 0) '
                .'ELSE COALESCE(pu.promo_total, 0) * COALESCE(%s, 0) / %d END), 0) AS promo_c',
                $this->currencyLiteral(), $rate, ExchangeRateCast::SCALE,
            ))
            ->selectRaw($missing.' AS no_rate_count')
            ->groupBy('currency')
            ->get();

        return $rows->mapWithKeys(function ($r) {
            $cur = $r->currency;
            $rawInvoiced = (int) $r->invoiced_raw;
            // الخصم اليدوي = الإجمالي − الترويجي (سخّ في نفس العملة)
            $rawPromo = (int) $r->promo_raw;
            $rawManual = (int) $r->discounts_raw - $rawPromo;

            return [$cur => [
                'invoiced_raw' => $rawInvoiced,
                'invoiced_c' => (int) $r->invoiced_c,
                'discounts_raw' => (int) $r->discounts_raw,
                'discounts_c' => (int) $r->discounts_c,
                'promo_c' => (int) $r->promo_c,
                'manual_c' => (int) $r->discounts_c - (int) $r->promo_c,
                'promo_raw' => $rawPromo,
                'manual_raw' => $rawManual,
                'outstanding_raw' => (int) $r->outstanding_raw,
                'outstanding_c' => (int) $r->outstanding_c,
                'count' => (int) $r->count,
                'invoiced_count' => (int) $r->count,
                'no_rate_count' => (int) $r->no_rate_count,
            ]];
        })->all();
    }

    /**
     * دفعات مكتملة — المحصّل.
     */
    private function payments(Carbon $start, Carbon $end, array $f): array
    {
        $q = Payment::query()
            ->where('payments.organization_id', $this->organizationId)
            ->where('payments.status', 'completed')
            ->whereRange('paid_at', $start, $end)
            ->when(isset($f['branch_id']), function ($q) use ($f) {
                // الفرع عبر طالب الدفعة — students.branch_id
                $q->join('students as pay_st', 'pay_st.id', '=', 'payments.student_id')
                    ->where('pay_st.branch_id', $f['branch_id']);
            });

        return $this->currencySeriesRaw($q, 'payments', 'paid_at', 'amount', 'collected');
    }

    /**
     * دفعات معلّنة بحسب الطريقة الفعلاً (للموقع القديم `by_method`
     * — والمجموع صار **محوّل** بدل خلط تقاس العملات).
     */
    private function paymentsByMethod(Carbon $start, Carbon $end, array $f): array
    {
        $q = Payment::query()
            ->where('payments.organization_id', $this->organizationId)
            ->where('payments.status', 'completed')
            ->whereRange('paid_at', $start, $end)
            ->when(isset($f['branch_id']), fn ($q) => $q
                ->join('students as pm_st', 'pm_st.id', '=', 'payments.student_id')
                ->where('pm_st.branch_id', $f['branch_id']))
            ->selectRaw('payment_method')
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw($this->convertedExpr('payments', 'paid_at', 'amount').' AS converted')
            ->groupBy('payment_method');

        return $q->get()->mapWithKeys(fn ($r) => [
            ((string) ($r->payment_method ?? 'غير معروف')) => [
                'total' => $this->major((int) $r->converted),
                'count' => (int) $r->count,
            ],
        ])->all();
    }

    /**
     * دفعات محصّلة لكل عملة (شكل `by_currency` القديم، خام).
     */
    private function paymentsByCurrency(Carbon $start, Carbon $end, array $f): array
    {
        $q = Payment::query()
            ->where('payments.organization_id', $this->organizationId)
            ->where('payments.status', 'completed')
            ->whereRange('paid_at', $start, $end)
            ->when(isset($f['branch_id']), fn ($q) => $q
                ->join('students as pc_st', 'pc_st.id', '=', 'payments.student_id')
                ->where('pc_st.branch_id', $f['branch_id']))
            ->selectRaw('currency')
            ->selectRaw('COALESCE(SUM(amount), 0) AS raw_total')
            ->selectRaw('COUNT(*) AS count')
            ->groupBy('currency');

        return $q->get()->mapWithKeys(fn ($r) => [
            (string) $r->currency => [
                'raw_total' => (int) $r->raw_total,
                'count' => (int) $r->count,
            ],
        ])->all();
    }

    /**
     * مستردات — للـ**معالَج فقط** (المعلق مش فلوس راجعة)؛
     * الفرع عبر الطالب dial الدفعة الأصلية.
     */
    private function refunds(Carbon $start, Carbon $end, array $f): array
    {
        $q = \App\Models\Refund::query()
            ->join('payments as ref_p', 'ref_p.id', '=', 'refunds.payment_id')
            ->where('ref_p.organization_id', $this->organizationId)
            ->where('refunds.status', 'processed')
            ->whereRange('processed_at', $start, $end)
            ->when(isset($f['branch_id']), fn ($q) => $q
                ->join('students as ref_st', 'ref_st.id', '=', 'ref_p.student_id')
                ->where('ref_st.branch_id', $f['branch_id']));

        return $this->currencySeriesRaw($q, 'refunds', 'processed_at', 'amount', 'refunded');
    }

    /**
     * مصروفات — الاعتمد والمدفوع = كلفة تشغيل (نفس حركة 2.12).
     */
    private function expenses(Carbon $start, Carbon $end, array $f): array
    {
        $q = \App\Models\Expense::query()
            ->where('expenses.organization_id', $this->organizationId)
            ->whereIn('expenses.status', ['approved', 'paid'])
            ->whereRange('expense_date', $start, $end)
            ->when(isset($f['branch_id']), fn ($q) => $q->where('expenses.branch_id', $f['branch_id']));

        return $this->currencySeriesRaw($q, 'expenses', 'expense_date', 'amount', 'expenses');
    }

    /**
     * مرتبات المعلمين — معتمد/مدفوع (المعلق مش كلفة)، والمدفوع
     * **المكتمل فقط** (القديم كان يعقد المعلق في «payments_made»).
     */
    private function teacherPayroll(Carbon $start, Carbon $end, array $f): array
    {
        $earningsQ = \App\Models\TeacherEarning::query()
            ->join('teachers as t_e', 't_e.id', '=', 'teacher_earnings.teacher_id')
            ->where('t_e.organization_id', $this->organizationId)
            ->whereIn('teacher_earnings.status', ['approved', 'paid'])
            ->whereRange('earning_date', $start, $end)
            ->when(isset($f['teacher_id']), fn ($q) => $q
                ->where('teacher_earnings.teacher_id', $f['teacher_id']));

        $paymentsQ = \App\Models\TeacherPayment::query()
            ->join('teachers as t_p', 't_p.id', '=', 'teacher_payments.teacher_id')
            ->where('t_p.organization_id', $this->organizationId)
            ->where('teacher_payments.status', 'completed')
            ->whereRange('paid_at', $start, $end)
            ->when(isset($f['teacher_id']), fn ($q) => $q
                ->where('teacher_payments.teacher_id', $f['teacher_id']));

        return [
            'earnings' => $this->currencySeriesRaw($earningsQ, 'teacher_earnings', 'earning_date', 'amount', 'payroll'),
            'made' => $this->currencySeriesRaw($paymentsQ, 'teacher_payments', 'paid_at', 'amount', 'payments_made'),
        ];
    }

    /**
     * ⭐ المعاَُم الصور للسلسلة — غبس الصف: {currency: {raw, converted، …}},
     * والتحويل محسوب بالـSQL مباشرة (مافيش PHP جمع).
     */
    private function currencySeriesRaw($query, string $table, string $dateColumn, string $amountColumn, string $label): array
    {
        return $query
            ->selectRaw($table.'.currency AS currency')
            ->selectRaw('COALESCE(SUM('.$table.'.'.$amountColumn.'), 0) AS raw_total')
            ->selectRaw('COUNT(*) AS count')
            ->selectRaw($this->convertedExpr($table, $dateColumn, $amountColumn).' AS converted')
            ->selectRaw($this->missingExpr($table, $dateColumn).' AS no_rate_count')
            ->groupBy($table.'.currency')
            ->get()
            ->mapWithKeys(fn ($r) => [
                (string) $r->currency => [
                    $label.'_raw' => (int) $r->raw_total,
                    $label.'_converted' => (int) $r->converted,
                    $label.'_count' => (int) $r->count,
                    'no_rate_count' => (int) $r->no_rate_count,
                ],
            ])
            ->all();
    }

    /*
     * ⭐ دالة الدخول — ترتيب السلاسة وتركيب الـresponse.
     *
     * المفاتيح القديمة (revenue/teacher_costs/invoiced_vs_collected/
     * expenses/net_operating/unpaid_teacher_dues/alerts) **متبقية
     * كسلوك** عشان الـcontract مع الاختبارات والواجهة؛ الجديد:
     * currencies + converted + discounts.
     */
    public function financial(Carbon $start, Carbon $end, array $filters = []): array
    {
        $invoices = $this->invoices($start, $end, $filters);
        $payments = $this->payments($start, $end, $filters);
        $refunds = $this->refunds($start, $end, $filters);
        $expenses = $this->expenses($start, $end, $filters);
        $payroll = $this->teacherPayroll($start, $end, $filters);

        $currencies = $this->mergeCurrencies($invoices, $payments, $refunds, $expenses, $payroll['earnings']);

        // ⭐ الحقيبة المحوّلة = مجموع المحوّل لكل عملة (وحدات معرف للسوء للمها)
        $sumCents = fn (string $field) => (int) array_sum(array_column($currencies, $field));

        $convertedTotals = [
            'invoiced' => $this->major($sumCents('invoiced_c')),
            'collected' => $this->major($sumCents('collected_c')),
            'refunded' => $this->major($sumCents('refunded_c')),
            'discounts' => $this->major($sumCents('discounts_c')),
            'expenses' => $this->major($sumCents('expenses_c')),
            'payroll' => $this->major($sumCents('payroll_c')),
        ];

        $madeConverted = $this->major((int) array_sum(array_map(
            fn ($row) => $row['payments_made_converted'] ?? 0,
            $payroll['made'],
        )));
        $unpaid = round(max(0, $convertedTotals['payroll'] - $madeConverted), 2);

        $convertedTotals['net_operating'] = round(
            $convertedTotals['collected'] - $convertedTotals['expenses'] - $convertedTotals['payroll'], 2,
        );

        // ⭐ العملات اللي صفوفها ملهاش سعر صرف — بتسقط من المحوّل
        $missingRates = [];
        foreach ($currencies as $currency => $bucket) {
            if ($currency !== $this->targetCurrency && ($bucket['no_rate'] ?? 0) > 0) {
                $missingRates[] = $currency;
            }
        }

        // ⭐ buckets القرش الداخلية (حقول `_c`) تفضل في الخدمة —
        // للعميل مجهولات العرض فقط.
        $displayCurrencies = array_map(
            fn (array $bucket) => [
                'invoiced' => $bucket['invoiced'],
                'collected' => $bucket['collected'],
                'refunded' => $bucket['refunded'],
                'discounts' => $bucket['discounts'],
                'expenses' => $bucket['expenses'],
                'payroll' => $bucket['payroll'],
                'net_operating' => $bucket['net_operating'],
                'outstanding' => $bucket['outstanding'],
                'invoices_count' => (int) $bucket['invoices_count'],
                'payments_count' => (int) $bucket['payments_count'],
                'expenses_count' => (int) $bucket['expenses_count'],
            ],
            $currencies,
        );

        return [
            'period' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
            'currencies' => $displayCurrencies,
            'converted' => [
                'currency' => $this->targetCurrency,
                'totals' => $convertedTotals,
                'missing_rates' => $missingRates,
            ],
            'discounts' => [
                'promo' => $this->major($sumCents('promo_c')),
                'manual' => $this->major($sumCents('manual_c')),
                'total' => $convertedTotals['discounts'],
            ],
            'revenue' => [
                // ⭐ خام لكل عملة (contract القديم) — المجموع converted في الأعلى
                'by_currency' => array_map(fn ($row) => [
                    'total' => $this->major($row['raw_total']),
                    'count' => (int) $row['count'],
                ], $this->paymentsByCurrency($start, $end, $filters)),
                'by_method' => $this->paymentsByMethod($start, $end, $filters),
                'total_refunded' => $convertedTotals['refunded'],
            ],
            'teacher_costs' => [
                'gross_earnings' => $convertedTotals['payroll'],
                'payments_made' => $madeConverted,
            ],
            'invoiced_vs_collected' => [
                'invoiced' => $convertedTotals['invoiced'],
                'collected' => $convertedTotals['collected'],
                // ⭐ الرصيد المتبقي من الفواتير — محوّل، من غير الدفعات الممتدة
                'outstanding' => $this->major($sumCents('outstanding_c')),
            ],
            'expenses' => [
                'total' => $convertedTotals['expenses'],
                'count' => (int) array_sum(array_column($currencies, 'expenses_count')),
            ],
            'net_operating' => $convertedTotals['net_operating'],
            'unpaid_teacher_dues' => $unpaid,
            'alerts' => $this->alerts($end->toDateString(), $unpaid),
        ];
    }

    private function mergeCurrencies(array $invoices, array $payments, array $refunds, array $expenses, array $payroll): array
    {
        $currencies = [];

        foreach ([$invoices, $payments, $refunds, $expenses, $payroll] as $series) {
            foreach (array_keys($series) as $cur) {
                $cur = (string) $cur;
                $currencies[$cur] = $currencies[$cur] ?? [];
            }
        }

        foreach (array_keys($currencies) as $cur) {
            $i = $invoices[$cur] ?? [];
            $p = $payments[$cur] ?? [];
            $r = $refunds[$cur] ?? [];
            $e = $expenses[$cur] ?? [];
            $g = $payroll[$cur] ?? [];

            $currencies[$cur] = [
                // ⭐ خام لكل عملة (بالوحدة الكبرى) — مافيش خلطة وحدات
                'invoiced' => $this->major($i['invoiced_raw'] ?? 0),
                'collected' => $this->major($p['collected_raw'] ?? 0),
                'refunded' => $this->major($r['refunded_raw'] ?? 0),
                'discounts' => $this->major($i['discounts_raw'] ?? 0),
                'expenses' => $this->major($e['expenses_raw'] ?? 0),
                'payroll' => $this->major($g['payroll_raw'] ?? 0),
                'net_operating' => round(
                    $this->major($p['collected_raw'] ?? 0)
                    - $this->major($e['expenses_raw'] ?? 0)
                    - $this->major($g['payroll_raw'] ?? 0), 2,
                ),
                'invoices_count' => $i['count'] ?? 0,
                'payments_count' => $p['collected_count'] ?? 0,
                'expenses_count' => $e['expenses_count'] ?? 0,

                // ⭐ خام بالقرش للتحويل — `converted.totals` تجمعها ✓
                'invoiced_c' => $i['invoiced_c'] ?? 0,
                'collected_c' => $p['collected_converted'] ?? 0,
                'refunded_c' => $r['refunded_converted'] ?? 0,
                'discounts_c' => $i['discounts_c'] ?? 0,
                'promo_c' => $i['promo_c'] ?? 0,
                'manual_c' => $i['manual_c'] ?? 0,
                'expenses_c' => $e['expenses_converted'] ?? 0,
                'payroll_c' => $g['payroll_converted'] ?? 0,
                'outstanding_raw' => $i['outstanding_raw'] ?? 0,
                'outstanding' => $this->major($i['outstanding_raw'] ?? 0),
                'outstanding_c' => $i['outstanding_c'] ?? 0,

                // ⭐ رصد العملات بلا سعر مسجّل (متصفر من المحوّل)
                'no_rate' => (int) max(
                    $i['no_rate_count'] ?? 0,
                    $p['no_rate_count'] ?? 0,
                    $r['no_rate_count'] ?? 0,
                    $e['no_rate_count'] ?? 0,
                    $g['no_rate_count'] ?? 0,
                ),
            ];
        }

        return $currencies;
    }

    private function alerts(string $end, float $unpaid): array
    {
        $overdue = Invoice::query()
            ->where('organization_id', $this->organizationId)
            ->whereIn('status', ['draft', 'issued', 'partially_paid', 'overdue'])
            ->where('due_date', '<', $end)
            ->count();

        $expiring = Subscription::query()
            ->where('organization_id', $this->organizationId)
            ->where('status', 'active')
            // ⭐⭐⭐ PR 3.12 — نافذة «وش ينتهي» من مركز الإعدادات
            // (يَسَّم المعلم `subscriptions.expiry_warning_days`).
            ->whereRange('end_date', now()->toDateString(), now()
                ->addDays((int) settings()->get('subscriptions.expiry_warning_days', $this->organizationId))
                ->toDateString())
            ->count();

        $pendingExpenses = \App\Models\Expense::query()
            ->where('organization_id', $this->organizationId)
            ->where('status', 'submitted')
            ->count();

        return [
            'overdue_invoices' => $overdue,
            'expiring_subscriptions' => $expiring,
            'unpaid_teacher_dues' => $unpaid,
            'pending_expenses' => $pendingExpenses,
        ];
    }
}
