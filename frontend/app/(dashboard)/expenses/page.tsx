"use client";

import { useEffect, useState } from "react";
import { apiFetch } from "@/lib/api";

type Expense = {
  id: number;
  amount: string;
  currency: string;
  expense_date: string;
  description: string;
  payment_method: string | null;
  status: "pending" | "approved" | "rejected";
  category?: { id: number; name: string };
};

export default function ExpensesPage() {
  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({ amount: "", expense_date: "", description: "", payment_method: "" });

  function loadExpenses() {
    setLoading(true);
    apiFetch<{ data: Expense[] }>("/expenses")
      .then((r) => setExpenses(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadExpenses(); }, []);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    try {
      await apiFetch("/expenses", { method: "POST", body: JSON.stringify(form) });
      setShowForm(false);
      setForm({ amount: "", expense_date: "", description: "", payment_method: "" });
      loadExpenses();
    } catch (err) { setError(err instanceof Error ? err.message : "فشل الإنشاء"); }
  }

  const totalAmount = expenses.reduce((sum, e) => sum + Number(e.amount), 0);

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">المصروفات</h1>
        <button onClick={() => setShowForm(!showForm)} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
          {showForm ? "إخفاء" : "+ إضافة مصروف"}
        </button>
      </div>

      <div className="mb-4 flex gap-4 text-sm">
        <span className="rounded-lg bg-slate-100 px-3 py-1">{expenses.length} مصروف</span>
        <span className="rounded-lg bg-red-100 px-3 py-1 text-red-700">إجمالي: {totalAmount.toLocaleString()} EGP</span>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">إضافة مصروف</h2>
          <form onSubmit={handleCreate} className="grid grid-cols-2 gap-3">
            <input type="number" step="0.01" placeholder="المبلغ" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input type="date" value={form.expense_date} onChange={(e) => setForm({ ...form, expense_date: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="الوصف" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="col-span-2 rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="طريقة الدفع" value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <button type="submit" className="col-span-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">حفظ</button>
          </form>
        </div>
      )}

      {!loading && !error && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right">الوصف</th>
                <th className="px-4 py-3 text-right">المبلغ</th>
                <th className="px-4 py-3 text-right">التاريخ</th>
                <th className="px-4 py-3 text-right">الطريقة</th>
                <th className="px-4 py-3 text-right">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {expenses.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">لا توجد مصروفات</td></tr>}
              {expenses.map((exp) => (
                <tr key={exp.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-800">{exp.description}</td>
                  <td className="px-4 py-3 font-medium text-slate-800">{exp.amount} {exp.currency}</td>
                  <td className="px-4 py-3 text-slate-600">{exp.expense_date}</td>
                  <td className="px-4 py-3 text-slate-600">{exp.payment_method ?? "—"}</td>
                  <td className="px-4 py-3">
                    <span className={`rounded-full px-2 py-1 text-xs ${exp.status === "approved" ? "bg-green-100 text-green-700" : exp.status === "pending" ? "bg-amber-100 text-amber-700" : "bg-red-100 text-red-700"}`}>
                      {exp.status === "approved" ? "معتمد" : exp.status === "pending" ? "معلق" : "مرفوض"}
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
