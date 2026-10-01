"use client";

import { useEffect, useState } from "react";
import { getDashboardSummary, getFinancialReport, getAcademicReport, getSalesReport } from "@/lib/api";

type Tab = "dashboard" | "financial" | "academic" | "sales";

export default function ReportsPage() {
  const [tab, setTab] = useState<Tab>("dashboard");
  const [dashboard, setDashboard] = useState<Awaited<ReturnType<typeof getDashboardSummary>> | null>(null);
  const [financial, setFinancial] = useState<Awaited<ReturnType<typeof getFinancialReport>> | null>(null);
  const [academic, setAcademic] = useState<Awaited<ReturnType<typeof getAcademicReport>> | null>(null);
  const [sales, setSales] = useState<Awaited<ReturnType<typeof getSalesReport>> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [dateRange, setDateRange] = useState({ from: "", to: "" });

  useEffect(() => {
    setLoading(true);
    Promise.all([getDashboardSummary(), getFinancialReport(dateRange), getAcademicReport(dateRange), getSalesReport(dateRange)])
      .then(([dash, fin, acad, sal]) => { setDashboard(dash); setFinancial(fin); setAcademic(acad); setSales(sal); })
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل التقارير"))
      .finally(() => setLoading(false));
  }, [dateRange]);

  const TABS: { key: Tab; label: string }[] = [
    { key: "dashboard", label: "لوحة التحكم" },
    { key: "financial", label: "مالي" },
    { key: "academic", label: "أكاديمي" },
    { key: "sales", label: "مبيعات" },
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
            <StatCard label="حصص اليوم" value={String(dashboard.today.lessons_total)} />
            <StatCard label="مكتملة" value={String(dashboard.today.lessons_completed)} />
            <StatCard label="قادمة" value={String(dashboard.today.lessons_upcoming)} />
            <StatCard label="إيرادات الشهر" value={dashboard.totals.monthly_revenue} />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="طلاب نشطون" value={String(dashboard.totals.active_students)} />
            <StatCard label="معلمين نشطون" value={String(dashboard.totals.active_teachers)} />
            <StatCard label="اشتراكات قاربت على الانتهاء" value={String(dashboard.alerts.subscriptions_expiring_soon)} alert />
            <StatCard label="مستحقات معلمين معلقة" value={dashboard.alerts.pending_teacher_payments} alert />
          </div>
        </div>
      )}

      {!loading && !error && tab === "financial" && financial && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
            {Object.entries(financial.revenue.by_currency).map(([currency, data]) => (
              <StatCard key={currency} label={`الإيرادات (${currency})`} value={data.total} sub={`${data.count} دفعة`} />
            ))}
            <StatCard label="إجمالي المسترجع" value={financial.revenue.total_refunded} alert />
            <StatCard label="أرباح المعلمين" value={financial.teacher_costs.gross_earnings} />
            <StatCard label="دفعات المعلمين" value={financial.teacher_costs.payments_made} />
          </div>
        </div>
      )}

      {!loading && !error && tab === "academic" && academic && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="إجمالي الحصص" value={String(academic.lessons.total)} />
            <StatCard label="مكتملة" value={String(academic.lessons.completed)} />
            <StatCard label="ملغاة" value={String(academic.lessons.cancelled)} />
            <StatCard label="غياب طالب" value={String(academic.lessons.student_absent)} />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="حاضر" value={String(academic.attendance.present)} />
            <StatCard label="غائب" value={String(academic.attendance.absent)} />
            <StatCard label="متأخر" value={String(academic.attendance.late)} />
            <StatCard label="اشتراكات نشطة" value={String(academic.subscriptions.active)} />
          </div>
        </div>
      )}

      {!loading && !error && tab === "sales" && sales && (
        <div className="space-y-6">
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="إجمالي Leads" value={String(sales.leads.total)} />
            <StatCard label="تم التحويل" value={String(sales.leads.converted)} />
            <StatCard label="معدل التحويل" value={sales.leads.conversion_rate} />
            <StatCard label="تقييمات تجريبية" value={String(sales.trials.total)} />
          </div>
          <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
            <StatCard label="جاهز للاشتراك" value={String(sales.trials.ready_to_subscribe)} />
            <StatCard label="يحتاج متابعة" value={String(sales.trials.needs_follow_up)} />
            <StatCard label="غير مناسب" value={String(sales.trials.not_suitable)} />
          </div>
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
