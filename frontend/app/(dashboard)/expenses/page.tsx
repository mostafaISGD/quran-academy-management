"use client";

import { useCallback, useState } from "react";
import { date, egp } from "@/lib/format";
import { usePage } from "@/lib/use-page";
import {
  getExpenses,
  createExpense,
  type Expense,
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

/** ⭐ alias — التنسيق في `lib/format` (كان `toLocaleString` مكرر هنا) */
const fmtMoney = egp;

/**
 * ⭐ كل حاجة بيجيبها الطلب في كومة واحدة — عشان `usePage`
 * بترجع `data` واحد بس.
 */
type ExpensesBundle = {
  items: Expense[];
  total: number;
  lastPage: number;
  totalAmount: number;
  statusCounts: Record<string, number>;
};

const EMPTY_BUNDLE: ExpensesBundle = {
  items: [],
  total: 0,
  lastPage: 1,
  totalAmount: 0,
  statusCounts: {},
};

export default function ExpensesPage() {
  const [showForm, setShowForm] = useState(false);

  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState("");

  const [form, setForm] = useState({
    amount: "",
    expense_date: new Date().toISOString().slice(0, 10),
    description: "",
    payment_method: "cash",
    category_id: "",
  });
  const [saving, setSaving] = useState(false);
  /** ⭐ خطأ النموذج — منفصل عن `error` (اللي بقى خاص بالجلب) */
  const [formError, setFormError] = useState<string | null>(null);

  /**
   * ⭐ الجلب عن طريق `usePage` بدل `useEffect`.
   *
   * الـ `key` بيتضمن الصفحة **والفلتر** — أي تغيير
   * فيهم بيحدّث البيانات. والأرقام الإحصائية
   * (`statusCounts` و`totalAmount`) بتيجي من
   * نفس الطلب.
   *
   * @see usePage
   */
  const fetchExpenses = useCallback(async (): Promise<ExpensesBundle> => {
    const r = await getExpenses({
      per_page: PAGE_SIZE,
      page,
      status: statusFilter || undefined,
    });
    const c = r.counts as Record<string, unknown> | undefined;
    const st = c?.status;
    return {
      items: r.data,
      total: r.total,
      lastPage: r.last_page,
      totalAmount: r.sums?.amount ?? 0,
      statusCounts: (st && typeof st === "object" ? st : {}) as Record<string, number>,
    };
  }, [page, statusFilter]);

  const {
    data: bundle,
    loading,
    error,
    reload,
  } = usePage<ExpensesBundle>(`expenses:${page}:${statusFilter}`, fetchExpenses, {
    initial: EMPTY_BUNDLE,
  });

  const expenses = bundle.items;
  const meta = { total: bundle.total, last_page: bundle.lastPage };
  const statusCounts = bundle.statusCounts;
  const totalAmount = bundle.totalAmount;

  /**
   * ⭐ الفلتر الجديد بيرجّعنا لأول صفحة — **أثناء الرسم**.
   *
   * ده كان بيتعمل في الـ `useEffect` القديم
   * (`setPage(1)`). دلوقتي بنقارن الفلتر الحالي
   * باللي قبله، ولو اتغيّر نصفّر الصفحة فوراً —
   * React بيحسب الرسم التاني قبل ما يلمّس الـ
   * DOM، فالمستخدم مش هيشوف صفحة غلط.
   */
  const [filterFor, setFilterFor] = useState(statusFilter);

  if (statusFilter !== filterFor) {
    setFilterFor(statusFilter);
    setPage(1);
  }

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setFormError(null);
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
      // ⭐ نرجع لأول صفحة ونعيد الجلب — لو كنا
      // فيها أصلاً `reload` لوحدها كافية
      setPage(1);
      reload();
    } catch (err) {
      setFormError(err instanceof Error ? err.message : "فشل الإنشاء");
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
          <button onClick={reload} className="text-sm font-medium text-red-700 underline">
            إعادة المحاولة
          </button>
        </div>
      )}

      {showForm && (
        <form onSubmit={handleCreate} className="mb-5 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">إضافة مصروف</h2>
          {formError && (
            <div className="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700">{formError}</div>
          )}
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
                <th className="px-4 py-3 text-start font-medium">الوصف</th>
                <th className="px-3 py-3 text-start font-medium">الفئة</th>
                <th className="px-3 py-3 text-center font-medium">المبلغ</th>
                <th className="hidden px-3 py-3 text-start font-medium sm:table-cell">التاريخ</th>
                <th className="hidden px-3 py-3 text-start font-medium md:table-cell">الطريقة</th>
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
                    {date(exp.expense_date)}
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