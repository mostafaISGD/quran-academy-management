"use client";

import { useCallback, useState } from "react";
import { getDashboardSummary, getFinancialReport, getAcademicReport, getSalesReport, getDiscountsReport, getAgingReport, getAccountBalancesReport, getTeacherLiabilitiesReport, getRevenueSourcesReport, exportReport } from "@/lib/api";
import { usePage } from "@/lib/use-page";
import { currencyLabel, egp, money, num } from "@/lib/format";

type Tab = "dashboard" | "financial" | "academic" | "sales" | "discounts" | "followup";

type Dashboard = Awaited<ReturnType<typeof getDashboardSummary>>;
type Financial = Awaited<ReturnType<typeof getFinancialReport>>;
type Academic = Awaited<ReturnType<typeof getAcademicReport>>;
type Sales = Awaited<ReturnType<typeof getSalesReport>>;
type Discounts = Awaited<ReturnType<typeof getDiscountsReport>>;
type Aging = Awaited<ReturnType<typeof getAgingReport>>;
type Balances = Awaited<ReturnType<typeof getAccountBalancesReport>>;
type Liabilities = Awaited<ReturnType<typeof getTeacherLiabilitiesReport>>;
type RevenueSources = Awaited<ReturnType<typeof getRevenueSourcesReport>>;

export default function ReportsPage() {
  const [tab, setTab] = useState<Tab>("dashboard");
  const [dateRange, setDateRange] = useState({ from: "", to: "" });

  /**
   * ⭐⭐⭐ **التقارير الأربعة مدمجين في وعد واحد.**
   *
   * كلهم بيتبعتوا في نفس اللحظة للمستخدم، والـ API بيرجّعهم
   * لنفس الفترة — فجمعهم في `Promise.all` **صح** هنا (بعكس
   * القوائم المرجعية في `schedule` اللي بتتغيّر بتاعتهم).
   *
   * ⭐ كل `setState` بيحصل **بعد await** جوّه الـ hook، فمفيش
   * رسم إضافي.
   *
   * @see usePage
   */
  const fetchReports = useCallback(async () => {
    const [dash, fin, acad, sal, disc] = await Promise.all([
      getDashboardSummary(),
      getFinancialReport(dateRange),
      getAcademicReport(dateRange),
      getSalesReport(dateRange),
      // ⭐ PR 2.14 — «كام ضاع في الخصومات» بيدخل في نفس الدفعة
      getDiscountsReport(dateRange),
    ]);
    return { dashboard: dash, financial: fin, academic: acad, sales: sal, discounts: disc };
  }, [dateRange]);

  const {
    data,
    loading,
    error,
  } = usePage(
    `reports:${dateRange.from}:${dateRange.to}`,
    fetchReports,
    {
      initial: {
        dashboard: null,
        financial: null,
        academic: null,
        sales: null,
        discounts: null,
      } as {
        dashboard: Dashboard | null;
        financial: Financial | null;
        academic: Academic | null;
        sales: Sales | null;
        discounts: Discounts | null;
      },
    },
  );

  const { dashboard, financial, academic, sales, discounts } = data;

  /**
   * ⭐⭐ PR 2.16 — تقارير المتابعة **فورية** (أعمار الديون/
   * الأرصدة/التزامات المعلمين/مصادر الإيراد) — بتطلب تقريرها
   * مرة واحدة، بعكس التقارير فوق اللي يتغيّروا بفترة التاريخ.
   */
  const fetchFollowup = useCallback(async () => {
    const [aging, balances, liabilities, revenueSources] = await Promise.all([
      getAgingReport(),
      getAccountBalancesReport(),
      getTeacherLiabilitiesReport(),
      getRevenueSourcesReport(),
    ]);
    return { aging, balances, liabilities, revenueSources };
  }, []);

  const {
    data: followup,
    loading: followupLoading,
    error: followupError,
  } = usePage("reports:followup", fetchFollowup, {
    initial: {
      aging: null,
      balances: null,
      liabilities: null,
      revenueSources: null,
    } as {
      aging: Aging | null;
      balances: Balances | null;
      liabilities: Liabilities | null;
      revenueSources: RevenueSources | null;
    },
  });

  // ⭐ narrowing كامل للمقاطع — خصائص `followup` الداخليّة مطمورة
  // وهنا بنفتحها مرة واحدة كمراجع محلية غير null (ناروينج خصائص
  // ما بيفوتش جوه الcallback الأصغر في `.map`/`onClick`).
  const followupData = followup && followup.aging && followup.balances && followup.liabilities && followup.revenueSources
    ? { aging: followup.aging, balances: followup.balances, liabilities: followup.liabilities, revenueSources: followup.revenueSources }
    : null;

  // ⭕ التصدير بيحصل في المتصفح (fetch blob + anchor download) —
  // أخطائه محلي على التاب
  const [exportError, setExportError] = useState("");
  const [exporting, setExporting] = useState("");

  const downloadCsv = async (report: string, filename: string) => {
    try {
      setExporting(report);
      setExportError("");
      const blob = await exportReport({ report, format: "csv" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = filename;
      a.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      setExportError(e instanceof Error ? e.message : "التصدير فشل");
    } finally {
      setExporting("");
    }
  };

  const TABS: { key: Tab; label: string }[] = [
    { key: "dashboard", label: "لوحة التحكم" },
    { key: "financial", label: "مالي" },
    { key: "academic", label: "أكاديمي" },
    { key: "sales", label: "مبيعات" },
    { key: "discounts", label: "الخصومات" },
    { key: "followup", label: "المتابعة" },
  ];

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">التقارير</h1>
        <div className="flex gap-2">
          <input type="date" value={dateRange.from} onChange={(e) => setDateRange({ ...dateRange, from: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
          <input type="date" value={dateRange.to} onChange={(e) => setDateRange({ ...dateRange, to: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
        </div>
      </div>

      <div className="mb-6 flex gap-2">
        {TABS.map((t) => (
          <button key={t.key} onClick={() => setTab(t.key)} className={`rounded-lg px-4 py-2 text-sm ${tab === t.key ? "bg-slate-800 text-white" : "bg-slate-100 text-slate-600 hover:bg-slate-200"}`}>{t.label}</button>
        ))}
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {!loading && !error && tab === "dashboard" && dashboard && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            {/* ⭐ `num` و `egp` من `lib/format` — الأرقام أرقام خام
                من السيرفر دلوقتي (مش نصوص منسّقة) */}
            <StatCard label="حصص اليوم" value={num(dashboard.today.lessons_total, 0)} />
            <StatCard label="مكتملة" value={num(dashboard.today.lessons_completed, 0)} />
            <StatCard label="قادمة" value={num(dashboard.today.lessons_upcoming, 0)} />
            <StatCard label="إيرادات الشهر" value={egp(dashboard.totals.monthly_revenue)} />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="طلاب نشطون" value={num(dashboard.totals.active_students, 0)} />
            <StatCard label="معلمين نشطون" value={num(dashboard.totals.active_teachers, 0)} />
            <StatCard label="اشتراكات قاربت على الانتهاء" value={num(dashboard.alerts.subscriptions_expiring_soon, 0)} alert />
            <StatCard label="مستحقات معلمين معلقة" value={egp(dashboard.alerts.pending_teacher_payments)} alert />
          </div>
        </div>
      )}

      {!loading && !error && tab === "financial" && financial && (
        <div className="space-y-6">
          {/* ⭐ PR 2.15 — الحقيبة المحوّلة بسعر الصرف وقت العملية */}
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
            <StatCard label={`مفوتَر (${currencyLabel(financial.converted.currency)})`} value={money(financial.converted.totals.invoiced, financial.converted.currency)} />
            <StatCard label={`محصّل (${currencyLabel(financial.converted.currency)})`} value={money(financial.converted.totals.collected, financial.converted.currency)} />
            <StatCard label="المفوتَر المتبقي" value={money(financial.invoiced_vs_collected.outstanding, financial.converted.currency)} alert />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
            {Object.entries(financial.revenue.by_currency).map(([currency, data]) => (
              // ⭐ `money` + `currencyLabel` — الكود `EGP` كان بيظهر
              // للمستخدم العربي في عنوان الكارت نفسه
              <StatCard
                key={currency}
                label={`الإيرادات (${currencyLabel(currency)})`}
                value={money(data.total, currency)}
                sub={`${num(data.count, 0)} دفعة`}
              />
            ))}
            <StatCard label="إجمالي المسترجع" value={egp(financial.revenue.total_refunded)} alert />
            <StatCard label="أرباح المعلمين" value={egp(financial.teacher_costs.gross_earnings)} />
            <StatCard label="دفعات المعلمين" value={egp(financial.teacher_costs.payments_made)} />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
            {/* ⭐ الخصومات وصافي التشغيل — دخلوا التقرير في 2.15 */}
            <StatCard label="خصومات ترويجية" value={money(financial.discounts.promo, financial.converted.currency)} />
            <StatCard label="خصومات يدوية" value={money(financial.discounts.manual, financial.converted.currency)} />
            <StatCard label="إجمالي الخصومات" value={money(financial.discounts.total, financial.converted.currency)} alert />
            <StatCard label={`مصروفات (${currencyLabel(financial.converted.currency)})`} value={money(financial.expenses.total, financial.converted.currency)} />
            <StatCard label="مستحقات معلمين غير مدفوعة" value={money(financial.unpaid_teacher_dues, financial.converted.currency)} alert />
            <StatCard
              label={`صافي التشغيل (${currencyLabel(financial.converted.currency)})`}
              value={money(financial.net_operating, financial.converted.currency)}
            />
          </div>
          {/* ⭐ عملات مالهاش سعر صرف مسجّل في نافذة التقرير — بتسقط من المحوّل */}
          {financial.converted.missing_rates.length > 0 && (
            <p className="text-sm text-amber-600">
              عملات بدون سعر صرف مسجّل (استُبعدت من الحقيبة المحوّلة): {financial.converted.missing_rates.map(currencyLabel).join("، ")}
            </p>
          )}
        </div>
      )}

      {!loading && !error && tab === "academic" && academic && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="إجمالي الحصص" value={num(academic.lessons.total, 0)} />
            <StatCard label="مكتملة" value={num(academic.lessons.completed, 0)} />
            <StatCard label="ملغاة" value={num(academic.lessons.cancelled, 0)} />
            <StatCard label="غياب طالب" value={num(academic.lessons.student_absent, 0)} />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="حاضر" value={num(academic.attendance.present, 0)} />
            <StatCard label="غائب" value={num(academic.attendance.absent, 0)} />
            <StatCard label="متأخر" value={num(academic.attendance.late, 0)} />
            <StatCard label="اشتراكات نشطة" value={num(academic.subscriptions.active, 0)} />
          </div>
        </div>
      )}

      {!loading && !error && tab === "sales" && sales && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="إجمالي Leads" value={num(sales.leads.total, 0)} />
            <StatCard label="تم التحويل" value={num(sales.leads.converted, 0)} />
            {/* ⭐ النسبة **رقم** دلوقتي — الـ `%` بتتحط هنا */}
            <StatCard label="معدل التحويل" value={`${num(sales.leads.conversion_rate, 1)}%`} />
            <StatCard label="تقييمات تجريبية" value={num(sales.trials.total, 0)} />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="جاهز للاشتراك" value={num(sales.trials.ready_to_subscribe, 0)} />
            <StatCard label="يحتاج متابعة" value={num(sales.trials.needs_follow_up, 0)} />
            <StatCard label="غير مناسب" value={num(sales.trials.not_suitable, 0)} />
          </div>
        </div>
      )}

      {!loading && !error && tab === "discounts" && discounts && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            {/* ⭐ PR 2.14 — «كام ضاع في الخصومات»: ترويجي (كود) vs يدوي (قرار) */}
            <StatCard label="خصومات ترويجية" value={egp(discounts.totals.promo_discounts)} />
            <StatCard label="خصومات يدوية" value={egp(discounts.totals.manual_discounts)} />
            <StatCard label="إجمالي المخل" value={egp(discounts.totals.total_discounts)} alert />
            <StatCard label="فواتير مخفَّضة" value={num(discounts.totals.invoices, 0)} />
          </div>

          {discounts.by_code.length > 0 && (
            <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
              <table className="w-full text-sm">
                <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
                  <tr>
                    <th className="px-4 py-3 text-start">الكود</th>
                    <th className="px-4 py-3 text-start">الاسم</th>
                    <th className="px-4 py-3 text-start">النوع</th>
                    <th className="px-4 py-3 text-start">عدد الفواتير</th>
                    <th className="px-4 py-3 text-start">إجمالي الخصم</th>
                  </tr>
                </thead>
                <tbody>
                  {discounts.by_code.map((c) => (
                    <tr key={c.id} className="border-b border-slate-100 last:border-0">
                      <td className="px-4 py-3 font-mono text-slate-800">{c.code}</td>
                      <td className="px-4 py-3 text-slate-600">{c.name}</td>
                      <td className="px-4 py-3 text-slate-500">{c.type === "percent" ? `${num(c.value, 1)}%` : money(c.value, "EGP")}</td>
                      <td className="px-4 py-3 text-slate-600">{num(c.invoices_count, 0)}</td>
                      <td className="px-4 py-3 font-medium text-red-700">{egp(c.promo_discounts)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </div>
      )}

      {!loading && !error && tab === "followup" && (
        <div className="space-y-6">
          {/* ⭐ PR 2.16 — تصدير CSV: التقرير نفسه مجاميعه = مجاميع الملف
              والملف بيفتح بالإكسل بالعربي سلِيم (BOM) */}
          {followupError && <p className="text-sm text-red-600">{followupError}</p>}
          {exportError && <p className="text-sm text-red-600">{exportError}</p>}

          {!followupLoading && followupData && (
            <>
              <section className="rounded-xl border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                  <h3 className="font-semibold text-slate-800">أعمار الديون (بالأيام من تاريخ الاستحقاق)</h3>
                  <button
                    onClick={() => downloadCsv("aging", `debt_aging_${followupData.aging.generated_at}.csv`)}
                    disabled={exporting === "aging"}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  >
                    {exporting === "aging" ? "جاري..." : "تصدير CSV"}
                  </button>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-slate-500">
                      <tr>
                        <th className="px-4 py-3 text-start">الطالب</th>
                        <th className="px-4 py-3 text-start">العملة</th>
                        <th className="px-4 py-3 text-start">لم تستحق</th>
                        <th className="px-4 py-3 text-start">0–30</th>
                        <th className="px-4 py-3 text-start">31–60</th>
                        <th className="px-4 py-3 text-start">61–90</th>
                        <th className="px-4 py-3 text-start">+90</th>
                        <th className="px-4 py-3 text-start">الإجمالي</th>
                      </tr>
                    </thead>
                    <tbody>
                      {followupData.aging.students.map((row) => (
                        <tr key={`${row.student_id}-${row.currency}`} className="border-t border-slate-100">
                          <td className="px-4 py-3 text-slate-800">{row.student_name}</td>
                          <td className="px-4 py-3 text-slate-500">{currencyLabel(row.currency)}</td>
                          <td className="px-4 py-3 text-slate-600">{money(row.not_due, row.currency)}</td>
                          <td className={`px-4 py-3 ${row.d0_30 > 0 ? "text-amber-700" : "text-slate-400"}`}>{money(row.d0_30, row.currency)}</td>
                          <td className={`px-4 py-3 ${row.d31_60 > 0 ? "text-orange-700" : "text-slate-400"}`}>{money(row.d31_60, row.currency)}</td>
                          <td className={`px-4 py-3 ${row.d61_90 > 0 ? "text-red-700" : "text-slate-400"}`}>{money(row.d61_90, row.currency)}</td>
                          <td className={`px-4 py-3 ${row.d90_plus > 0 ? "font-semibold text-red-800" : "text-slate-400"}`}>{money(row.d90_plus, row.currency)}</td>
                          <td className="px-4 py-3 font-medium text-slate-800">{money(row.total, row.currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                    <tfoot className="border-t-2 border-slate-200 bg-slate-50 font-medium text-slate-700">
                      {Object.entries(followupData.aging.totals).map(([currency, t]) => (
                        <tr key={currency}>
                          <td className="px-4 py-3" colSpan={2}>المجموع ({currencyLabel(currency)})</td>
                          <td className="px-4 py-3">{money(t.not_due, currency)}</td>
                          <td className="px-4 py-3">{money(t.d0_30, currency)}</td>
                          <td className="px-4 py-3">{money(t.d31_60, currency)}</td>
                          <td className="px-4 py-3">{money(t.d61_90, currency)}</td>
                          <td className="px-4 py-3">{money(t.d90_plus, currency)}</td>
                          <td className="px-4 py-3">{money(t.total, currency)}</td>
                        </tr>
                      ))}
                    </tfoot>
                  </table>
                </div>
              </section>

              <section className="rounded-xl border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                  <h3 className="font-semibold text-slate-800">أرصدة الخزائن</h3>
                  <button
                    onClick={() => downloadCsv("account-balances", "account_balances.csv")}
                    disabled={exporting === "account-balances"}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  >
                    {exporting === "account-balances" ? "جاري..." : "تصدير CSV"}
                  </button>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-slate-500">
                      <tr>
                        <th className="px-4 py-3 text-start">الخزينة</th>
                        <th className="px-4 py-3 text-start">العملة</th>
                        <th className="px-4 py-3 text-start">الافتتاحي</th>
                        <th className="px-4 py-3 text-start">الحركات المنشورة</th>
                        <th className="px-4 py-3 text-start">الرصيد</th>
                      </tr>
                    </thead>
                    <tbody>
                      {followupData.balances.accounts.map((a) => (
                        <tr key={a.id} className="border-t border-slate-100">
                          <td className="px-4 py-3 text-slate-800">{a.name}</td>
                          <td className="px-4 py-3 text-slate-500">{currencyLabel(a.currency)}</td>
                          <td className="px-4 py-3 text-slate-600">{money(a.opening_balance, a.currency)}</td>
                          <td className="px-4 py-3 text-slate-600">{money(a.movements_sum, a.currency)}</td>
                          <td className="px-4 py-3 font-medium text-slate-800">{money(a.balance, a.currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>

              <section className="rounded-xl border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                  <h3 className="font-semibold text-slate-800">التزامات المعلمين (المعتمد − المدفوع)</h3>
                  <button
                    onClick={() => downloadCsv("teacher-liabilities", "teacher_liabilities.csv")}
                    disabled={exporting === "teacher-liabilities"}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  >
                    {exporting === "teacher-liabilities" ? "جاري..." : "تصدير CSV"}
                  </button>
                </div>
                <div className="overflow-x-auto">
                  <table className="w-full text-sm">
                    <thead className="bg-slate-50 text-slate-500">
                      <tr>
                        <th className="px-4 py-3 text-start">المعلم</th>
                        <th className="px-4 py-3 text-start">العملة</th>
                        <th className="px-4 py-3 text-start">المستحقات</th>
                        <th className="px-4 py-3 text-start">المدفوع</th>
                        <th className="px-4 py-3 text-start">التزام</th>
                      </tr>
                    </thead>
                    <tbody>
                      {followupData.liabilities.teachers.map((t) => (
                        <tr key={`${t.teacher_id}-${t.currency}`} className="border-t border-slate-100">
                          <td className="px-4 py-3 text-slate-800">{t.name}</td>
                          <td className="px-4 py-3 text-slate-500">{currencyLabel(t.currency)}</td>
                          <td className="px-4 py-3 text-slate-600">{money(t.gross_earnings, t.currency)}</td>
                          <td className="px-4 py-3 text-slate-600">{money(t.payments_made, t.currency)}</td>
                          <td className={`px-4 py-3 font-medium ${t.liability > 0 ? "text-amber-700" : "text-slate-800"}`}>{money(t.liability, t.currency)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </section>

              <section className="rounded-xl border border-slate-200 bg-white">
                <div className="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                  <h3 className="font-semibold text-slate-800">مصادر الإيراد (برنامج/معلم)</h3>
                  <button
                    onClick={() => downloadCsv("revenue-sources", "revenue_sources.csv")}
                    disabled={exporting === "revenue-sources"}
                    className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                  >
                    {exporting === "revenue-sources" ? "جاري..." : "تصدير CSV"}
                  </button>
                </div>
                <div className="grid gap-4 p-4 md:grid-cols-2">
                  <div>
                    <p className="mb-2 text-sm text-slate-500">حسب البرنامج</p>
                    <table className="w-full text-sm">
                      <tbody>
                        {followupData.revenueSources.by_program.map((p) => (
                          <tr key={`${p.program_id}-${p.currency}`} className="border-b border-slate-100 last:border-0">
                            <td className="py-2 text-slate-700">{p.name}</td>
                            <td className="py-2 text-slate-500">{currencyLabel(p.currency)}</td>
                            <td className="py-2 text-end font-medium text-slate-800">{money(p.invoiced, p.currency)}</td>
                          </tr>
                        ))}
                        {followupData.revenueSources.by_program.length === 0 && (
                          <tr><td className="py-2 text-slate-400">مافيش فواتير منسوبة</td><td /><td /></tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                  <div>
                    <p className="mb-2 text-sm text-slate-500">حسب المعلم</p>
                    <table className="w-full text-sm">
                      <tbody>
                        {followupData.revenueSources.by_teacher.map((t) => (
                          <tr key={`${t.teacher_id}-${t.currency}`} className="border-b border-slate-100 last:border-0">
                            <td className="py-2 text-slate-700">{t.name}</td>
                            <td className="py-2 text-slate-500">{currencyLabel(t.currency)}</td>
                            <td className="py-2 text-end font-medium text-slate-800">{money(t.invoiced, t.currency)}</td>
                          </tr>
                        ))}
                        {followupData.revenueSources.by_teacher.length === 0 && (
                          <tr><td className="py-2 text-slate-400">مافيش فواتير منسوبة</td><td /><td /></tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                </div>
              </section>
            </>
          )}
        </div>
      )}
    </div>
  );
}

function StatCard({ label, value, sub, alert }: { label: string; value: string; sub?: string; alert?: boolean }) {
  return (
    <div className={`rounded-xl border p-4 ${alert ? "border-amber-200 bg-amber-50" : "border-slate-200 bg-white"}`}>
      <p className="text-sm text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-800">{value}</p>
      {sub && <p className="mt-1 text-xs text-slate-400">{sub}</p>}
    </div>
  );
}
