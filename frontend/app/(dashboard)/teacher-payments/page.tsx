"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";

type TeacherPayment = {
  id: number;
  teacher_id: number;
  amount: string;
  currency: string;
  payment_method: string | null;
  paid_at: string | null;
  status: "pending" | "completed" | "failed";
  teacher?: { id: number; full_name: string };
};

export default function TeacherPaymentsPage() {
  const [payments, setPayments] = useState<TeacherPayment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  function loadPayments() {
    setLoading(true);
    apiFetch<{ data: TeacherPayment[] }>("/teacher-payments")
      .then((r) => setPayments(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadPayments(); }, []);

  const totalPaid = payments.reduce((sum, p) => sum + Number(p.amount), 0);

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">مدفوعات المعلمين</h1>

      <div className="mb-4 flex gap-4 text-sm">
        <span className="rounded-lg bg-slate-100 px-3 py-1">{payments.length} دفعة</span>
        <span className="rounded-lg bg-green-100 px-3 py-1 text-green-700">إجمالي: {totalPaid.toLocaleString()} EGP</span>
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
                <th className="px-4 py-3 text-right">الطريقة</th>
                <th className="px-4 py-3 text-right">التاريخ</th>
                <th className="px-4 py-3 text-right">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {payments.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">لا توجد مدفوعات</td></tr>}
              {payments.map((p) => (
                <tr key={p.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-800">{p.teacher?.full_name ?? `معلم ${p.teacher_id}`}</td>
                  <td className="px-4 py-3 font-medium text-slate-800">{p.amount} {p.currency}</td>
                  <td className="px-4 py-3 text-slate-600">{p.payment_method ?? "—"}</td>
                  <td className="px-4 py-3 text-slate-600">{p.paid_at ? new Date(p.paid_at).toLocaleDateString("ar-EG") : "—"}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-1 text-xs ${p.status === "completed" ? "bg-green-100 text-green-700" : p.status === "pending" ? "bg-amber-100 text-amber-700" : "bg-red-100 text-red-700"}`}>
                      {p.status === "completed" ? "مكتمل" : p.status === "pending" ? "معلق" : "فاشل"}
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
