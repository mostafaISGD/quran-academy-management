"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";

type TeacherEarning = {
  id: number;
  teacher_id: number;
  lesson_id: number | null;
  amount: string;
  currency: string;
  earning_date: string;
  status: "pending" | "approved" | "paid" | "cancelled";
  teacher?: { id: number; full_name: string };
};

export default function TeacherEarningsPage() {
  const [earnings, setEarnings] = useState<TeacherEarning[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  function loadEarnings() {
    setLoading(true);
    apiFetch<{ data: TeacherEarning[] }>("/teacher-earnings")
      .then((r) => setEarnings(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadEarnings(); }, []);

  const totalEarnings = earnings.reduce((sum, e) => sum + Number(e.amount), 0);

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">مستحقات المعلمين</h1>

      <div className="mb-4 flex gap-4 text-sm">
        <span className="rounded-lg bg-slate-100 px-3 py-1">{earnings.length} سجل</span>
        <span className="rounded-lg bg-blue-100 px-3 py-1 text-blue-700">إجمالي: {totalEarnings.toLocaleString()} EGP</span>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {!loading && !error && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right">المعلم</th>
                <th className="px-4 py-3 text-right">المبلغ</th>
                <th className="px-4 py-3 text-right">التاريخ</th>
                <th className="px-4 py-3 text-right">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {earnings.length === 0 && <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">لا توجد مستحقات</td></tr>}
              {earnings.map((e) => (
                <tr key={e.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-800">{e.teacher?.full_name ?? `معلم ${e.teacher_id}`}</td>
                  <td className="px-4 py-3 font-medium text-slate-800">{e.amount} {e.currency}</td>
                  <td className="px-4 py-3 text-slate-600">{e.earning_date}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-1 text-xs ${e.status === "paid" ? "bg-green-100 text-green-700" : e.status === "pending" ? "bg-amber-100 text-amber-700" : "bg-blue-100 text-blue-700"}`}>
                      {e.status === "paid" ? "مدفوع" : e.status === "pending" ? "معلق" : e.status === "approved" ? "معتمد" : "ملغي"}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
