"use client";

import { useEffect, useState } from "react";
import { getDashboardSummary, getFinancialReport, getAcademicReport, getSalesReport } from "@/lib/api";
import { egp, num } from "@/lib/format";

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
          <div className="grid grid-cols-2 gap-4 md:grid-cols-3">
            {Object.entries(financial.revenue.by_currency).map(([currency, data]) => (
              <StatCard
                key={currency}
                label={`الإيرادات (${currency})`}
                value={currency === "EGP" ? egp(data.total) : `${num(data.total)} ${currency}`}
                sub={`${num(data.count, 0)} دفعة`}
              />
            ))}
            <StatCard label="إجمالي المسترجع" value={egp(financial.revenue.total_refunded)} alert />
            <StatCard label="أرباح المعلمين" value={egp(financial.teacher_costs.gross_earnings)} />
            <StatCard label="دفعات المعلمين" value={egp(financial.teacher_costs.payments_made)} />
          </div>
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
