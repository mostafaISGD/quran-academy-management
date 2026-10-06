"use client";

import { useEffect, useState } from "react";
import { getDashboardSummary } from "@/lib/api";
import { egp, num } from "@/lib/format";

export default function DashboardPage() {
  // ⭐ `monthly_revenue` **رقم** — كان نص من `number_format`
  const [summary, setSummary] = useState<{
    today: { lessons_total: number; lessons_completed: number; lessons_upcoming: number };
    totals: { active_students: number; active_teachers: number; monthly_revenue: number };
    alerts: { subscriptions_expiring_soon: number; pending_teacher_payments: number; unscheduled_leads: number };
  } | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    getDashboardSummary()
      .then(setSummary)
      .catch(() => {})
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="text-sm text-slate-500">جارٍ التحميل...</p>;
  if (!summary) return <p className="text-sm text-red-600">تعذر تحميل البيانات</p>;

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">لوحة التحكم</h1>

      <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        {/* ⭐ `num` و `egp` — الأرقام خام من السيرفر دلوقتي */}
        <StatCard label="حصص اليوم" value={num(summary.today.lessons_total, 0)} />
        <StatCard label="مكتملة" value={num(summary.today.lessons_completed, 0)} />
        <StatCard label="قادمة" value={num(summary.today.lessons_upcoming, 0)} />
        <StatCard label="إيرادات الشهر" value={egp(summary.totals.monthly_revenue)} />
      </div>

      <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <StatCard label="طلاب نشطون" value={num(summary.totals.active_students, 0)} />
        <StatCard label="معلمين نشطون" value={num(summary.totals.active_teachers, 0)} />
        <StatCard label="اشتراكات قاربت على الانتهاء" value={num(summary.alerts.subscriptions_expiring_soon, 0)} alert />
        <StatCard label="مستحقات معلمين معلقة" value={egp(summary.alerts.pending_teacher_payments)} alert />
      </div>

      <div className="rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-4 text-sm font-semibold text-slate-800">تنبيهات تحتاج تصرف</h2>
        <div className="space-y-2">
          {summary.alerts.subscriptions_expiring_soon > 0 && (
            <div className="flex items-center justify-between rounded-lg bg-amber-50 px-4 py-2">
              <span className="text-sm text-amber-700">اشتراكات قاربت على الانتهاء</span>
              <span className="text-sm font-medium text-amber-800">
                {num(summary.alerts.subscriptions_expiring_soon, 0)}
              </span>
            </div>
          )}
          {summary.alerts.unscheduled_leads > 0 && (
            <div className="flex items-center justify-between rounded-lg bg-blue-50 px-4 py-2">
              <span className="text-sm text-blue-700">Leads بدون جدولة</span>
              <span className="text-sm font-medium text-blue-800">
                {num(summary.alerts.unscheduled_leads, 0)}
              </span>
            </div>
          )}
          {/* ⭐ كان بيقارن بالنص `"0.00"` — دلوقتي رقم خام */}
          {summary.alerts.pending_teacher_payments > 0 && (
            <div className="flex items-center justify-between rounded-lg bg-red-50 px-4 py-2">
              <span className="text-sm text-red-700">مستحقات معلمين معلقة</span>
              <span className="text-sm font-medium text-red-800">
                {egp(summary.alerts.pending_teacher_payments)}
              </span>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}

function StatCard({ label, value, alert }: { label: string; value: string; alert?: boolean }) {
  return (
    <div className={`rounded-xl border p-4 ${alert ? "border-amber-200 bg-amber-50" : "border-slate-200 bg-white"}`}>
      <p className="text-sm text-slate-500">{label}</p>
      <p className="mt-2 text-2xl font-semibold text-slate-800">{value}</p>
    </div>
  );
}
