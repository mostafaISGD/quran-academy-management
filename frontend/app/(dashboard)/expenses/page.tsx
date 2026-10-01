"use client";

import { useCallback, useEffect, useState } from "react";
import {
  getExpenses,
  createExpense,
  type Expense,
  type Paginated,
} from "@/lib/api";
import Pagination from "@/components/Pagination";

const PAGE_SIZE = 100;

const STATUS_LABEL: Record<Expense["status"], string> = {
  pending: "معلق",
  approved: "معتمد",
  rejected: "مرفوض",
};

const STATUS_TONE: Record<Expense["status"], string> = {
  pending: "bg-amber-100 text-amber-700",
  approved: "bg-green-100 text-green-700",
  rejected: "bg-red-100 text-red-700",
};

const METHOD_LABEL: Record<string, string> = {
  cash: "نقدي",
  bank_transfer: "تحويل بنكي",
  card: "بطاقة",
  wallet: "محفظة",
};

const fmtMoney = (n: number | undefined) =>
  n === undefined
    ? "—"
    : `${n.toLocaleString("ar-EG", { minimumFractionDigits: 0, maximumFractionDigits: 2 })} ج.م`;

export default function ExpensesPage() {
  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [statusCounts, setStatusCounts] = useState<Record<string, number>>({});
  const [totalAmount, setTotalAmount] = useState(0);

  const [statusFilter, setStatusFilter] = useState("");

  const [form, setForm] = useState({
    amount: "",
    expense_date: new Date().toISOString().slice(0, 10),
    description: "",
    payment_method: "cash",
    category_id: "",
  });
  const [saving, setSaving] = useState(false);

  const load = useCallback(
    async (targetPage = 1, status = "") => {
      setLoading(true);
      setError(null);
      try {
        const r = await getExpenses({
          per_page: PAGE_SIZE,
          page: targetPage,
          status: status || undefined,
        });
        setExpenses(r.data);
        setMeta({ total: r.total, last_page: r.last_page });
        setTotalAmount(r.sums?.amount ?? 0);

        const c = r.counts as Record<string, unknown> | undefined;
        const st = c?.status;
        setStatusCounts((st && typeof st === "object" ? st : {}) as Record<string, number>);
      } catch (err) {
        setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
      } finally {
        setLoading(false);
      }
    },
    [statusFilter],
  );

  useEffect(() => {
    setPage(1);
    load(1, statusFilter);
  }, [load, statusFilter]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    load(target, statusFilter);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setError(null);
    try {
      await createExpense({
        amount: Number(form.amount),
        expense_date: form.expense_date,
        description: form.description.trim(),
        payment_method: form.payment_method || undefined,
        category_id: form.category_id ? Number(form.category_id) : undefined,
      });
      setShowForm(false);
      setForm({
        amount: "",
        expense_date: new Date().toISOString().slice(0, 10),
        description: "",
        payment_method: "cash",
        category_id: "",
      });
      load(1, statusFilter);
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل الإنشاء");
    } finally {
      setSaving(false);
    }
  }

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">المصروفات</h1>
          <p className="text-sm text-slate-500">{meta.total} مصروف مسجّل</p>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
          >
            <option value="">كل الحالات</option>
            {Object.entries(STATUS_LABEL).map(([k, v]) => (
              <option key={k} value={k}>{v} ({statusCounts[k] ?? 0})</option>
            ))}
          </select>
          <button
            onClick={() => setShowForm((v) => !v)}
            className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
          >
            {showForm ? "إخفاء" : "+ إضافة مصروف"}
          </button>
        </div>
      </div>

      {/* إحصائيات — من الداتابيز على كل المصروفات */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">عدد المصروفات</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-red-50 p-4 text-center">
          <p className="text-xs text-red-600">إجمالي المصروفات</p>
          <p className="mt-1 text-lg font-bold text-red-800">{fmtMoney(totalAmount)}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">معتمد</p>
          <p className="mt-1 text-xl font-bold text-green-800">{statusCounts.approved ?? 0}</p>
        </div>
        <div className="rounded-xl bg-amber-50 p-4 text-center">
          <p className="text-xs text-amber-600">معلق</p>
          <p className="mt-1 text-xl font-bold text-amber-800">{statusCounts.pending ?? 0}</p>
        </div>
      </div>

      {error && (
        <div className="mb-4 flex items-center justify-between rounded-lg bg-red-50 px-4 py-2.5">
          <p className="text-sm text-red-700">{error}</p>
          <button onClick={() => load(page, statusFilter)} className="text-sm font-medium text-red-700 underline">
            إعادة المحاولة
          </button>
        </div>
      )}

      {showForm && (
        <form onSubmit={handleCreate} className="mb-5 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">إضافة مصروف</h2>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <input
              type="number" step="0.01" min="0.01" required placeholder="المبلغ *"
              value={form.amount}
              onChange={(e) => setForm({ ...form, amount: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <input
              type="date" required value={form.expense_date}
              onChange={(e) => setForm({ ...form, expense_date: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <select
              value={form.payment_method}
              onChange={(e) => setForm({ ...form, payment_method: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            >
              {Object.entries(METHOD_LABEL).map(([k, v]) => (
                <option key={k} value={k}>{v}</option>
              ))}
            </select>
            <input
              type="number" placeholder="رقم الفئة"
              value={form.category_id}
              onChange={(e) => setForm({ ...form, category_id: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <input
              required placeholder="الوصف *" className="sm:col-span-2 lg:col-span-4 rounded-lg border border-slate-300 px-3 py-2 text-sm"
              value={form.description}
              onChange={(e) => setForm({ ...form, description: e.target.value })}
            />
          </div>
          <div className="mt-3 flex gap-2">
            <button
              type="submit" disabled={saving}
              className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
            >
              {saving ? "جارٍ الحفظ..." : "حفظ"}
            </button>
            <button
              type="button" onClick={() => setShowForm(false)}
              className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200"
            >
              إلغاء
            </button>
          </div>
        </form>
      )}

      {loading ? (
        <div className="space-y-2">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="h-14 animate-pulse rounded-xl bg-slate-100" />
          ))}
        </div>
      ) : expenses.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-4xl">💸</p>
          <p className="mt-3 text-sm text-slate-500">
            {statusFilter ? "مفيش مصروفات بالحالة دي" : "لسه مفيش مصروفات"}
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right font-medium">الوصف</th>
                <th className="px-3 py-3 text-right font-medium">الفئة</th>
                <th className="px-3 py-3 text-center font-medium">المبلغ</th>
                <th className="hidden px-3 py-3 text-right font-medium sm:table-cell">التاريخ</th>
                <th className="hidden px-3 py-3 text-right font-medium md:table-cell">الطريقة</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {expenses.map((exp) => (
                <tr key={exp.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3 font-medium text-slate-800">{exp.description}</td>
                  <td className="px-3 py-3 text-slate-600">{exp.category?.name ?? "—"}</td>
                  <td className="px-3 py-3 text-center font-medium text-slate-800">
                    {fmtMoney(Number(exp.amount))}
                  </td>
                  <td className="hidden px-3 py-3 text-xs text-slate-500 sm:table-cell">
                    {new Date(exp.expense_date).toLocaleDateString("ar-EG")}
                  </td>
                  <td className="hidden px-3 py-3 text-xs text-slate-600 md:table-cell">
                    {METHOD_LABEL[exp.payment_method ?? ""] ?? exp.payment_method ?? "—"}
                  </td>
                  <td className="px-3 py-3 text-center">
                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${STATUS_TONE[exp.status]}`}>
                      {STATUS_LABEL[exp.status]}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="مصروف"
      />
    </div>
  );
}