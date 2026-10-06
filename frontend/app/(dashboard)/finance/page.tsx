"use client";

import { useEffect, useState } from "react";
import { getFinancialReport, getAcademicReport, getSalesReport } from "@/lib/api";

/**
 * تنسيق الجنيه المصري.
 *
 * ⭐ كان `toLocaleString()` من غير locale → رقم إنجليزي مع
 * فاصلة، و«EGP» بدل «ج.م».
 *
 * ⚠️ ملاحظة على المصدر: السيرفر بيرجع `number_format($x, 2)`
 * (نص بفاصلة) فالواجهة بتشيل الفاصلة وترجع رقم. ده **اقتباس**
 * (نص منسّق → رقم → نص منسّق). المظبوط إن السيرفر يرجع رقم
 * خام. ده شغل مرحلة لوحده.
 */
const egp = (n: number) =>
  `${n.toLocaleString("ar-EG", { minimumFractionDigits: 0, maximumFractionDigits: 2 })} ج.م`;

export default function FinancePage() {
  const [financial, setFinancial] = useState<Awaited<ReturnType<typeof getFinancialReport>> | null>(null);
  const [academic, setAcademic] = useState<Awaited<ReturnType<typeof getAcademicReport>> | null>(null);
  const [sales, setSales] = useState<Awaited<ReturnType<typeof getSalesReport>> | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    Promise.all([getFinancialReport(), getAcademicReport(), getSalesReport()])
      .then(([fin, acad, sal]) => { setFinancial(fin); setAcademic(acad); setSales(sal); })
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }, []);

  if (loading) return <p className="text-sm text-slate-500">جارٍ التحميل...</p>;
  if (error) return <p className="text-sm text-red-600">{error}</p>;
  if (!financial || !academic || !sales) return null;

  const totalRevenue = Object.values(financial.revenue.by_currency).reduce((sum, c) => sum + Number(c.total.replace(/,/g, "")), 0);
  const totalRefunded = Number(financial.revenue.total_refunded.replace(/,/g, ""));
  const teacherCosts = Number(financial.teacher_costs.gross_earnings.replace(/,/g, ""));
  const netRevenue = totalRevenue - totalRefunded - teacherCosts;

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">لوحة المالية</h1>

      {/* Top Stats */}
      <div className="mb-6 grid grid-cols-2 gap-4 md:grid-cols-4">
        <div className="rounded-xl border border-green-200 bg-green-50 p-4">
          <p className="text-sm text-green-600">إيرادات الطلاب</p>
          <p className="mt-2 text-2xl font-bold text-green-800">{egp(totalRevenue)}</p>
        </div>
        <div className="rounded-xl border border-blue-200 bg-blue-50 p-4">
          <p className="text-sm text-blue-600">مستحقات المعلمين</p>
          <p className="mt-2 text-2xl font-bold text-blue-800">{egp(teacherCosts)}</p>
        </div>
        <div className="rounded-xl border border-red-200 bg-red-50 p-4">
          <p className="text-sm text-red-600">المصروفات</p>
          <p className="mt-2 text-2xl font-bold text-red-800">{egp(totalRefunded)}</p>
        </div>
        <div className="rounded-xl border border-purple-200 bg-purple-50 p-4">
          <p className="text-sm text-purple-600">صافي الإيرادات</p>
          <p className="mt-2 text-2xl font-bold text-purple-800">{egp(netRevenue)}</p>
        </div>
      </div>

      {/* Revenue by Method */}
      <div className="mb-6 rounded-xl border border-slate-200 bg-white p-5">
        <h2 className="mb-4 text-sm font-semibold text-slate-800">الإيرادات حسب طريقة الدفع</h2>
        <div className="space-y-2">
          {Object.entries(financial.revenue.by_method).map(([method, data]) => (
            <div key={method} className="flex items-center justify-between rounded-lg bg-slate-50 px-4 py-2">
              <span className="text-sm text-slate-600">{method}</span>
              <span className="text-sm font-medium text-slate-800">{data.total} ({data.count} دفعة)</span>
            </div>
          ))}
        </div>
      </div>

      {/* Alerts */}
      <div className="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
        <h2 className="mb-3 text-sm font-semibold text-amber-800">⚠ تنبيهات مالية</h2>
        <div className="space-y-2">
          <div className="flex items-center justify-between rounded-lg bg-white px-4 py-2">
            <span className="text-sm text-slate-600">فواتير متأخرة</span>
            <span className="text-sm font-medium text-red-600">{academic.subscriptions.expired} اشتراك منتهي</span>
          </div>
          <div className="flex items-center justify-between rounded-lg bg-white px-4 py-2">
            <span className="text-sm text-slate-600">مستحقات معلمين غير مدفوعة</span>
            <span className="text-sm font-medium text-amber-600">{financial.teacher_costs.gross_earnings} EGP</span>
          </div>
        </div>
      </div>

      {/* Quick Links */}
      <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
        <a href="/subscriptions" className="rounded-xl border border-slate-200 bg-white p-4 text-center hover:shadow-md">
          <p className="text-2xl">📋</p>
          <p className="mt-2 text-sm font-medium text-slate-800">اشتراكات الطلاب</p>
        </a>
        <a href="/invoices" className="rounded-xl border border-slate-200 bg-white p-4 text-center hover:shadow-md">
          <p className="text-2xl">🧾</p>
          <p className="mt-2 text-sm font-medium text-slate-800">فواتير الطلاب</p>
        </a>
        <a href="/payments" className="rounded-xl border border-slate-200 bg-white p-4 text-center hover:shadow-md">
          <p className="text-2xl">💳</p>
          <p className="mt-2 text-sm font-medium text-slate-800">مدفوعات الطلاب</p>
        </a>
        <a href="/teacher-earnings" className="rounded-xl border border-slate-200 bg-white p-4 text-center hover:shadow-md">
          <p className="text-2xl">👨‍🏫</p>
          <p className="mt-2 text-sm font-medium text-slate-800">مستحقات المعلمين</p>
        </a>
      </div>
    </div>
  );
}
