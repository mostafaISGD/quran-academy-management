"use client";

import { useCallback, useState } from "react";
import { date, egp } from "@/lib/format";
import { usePage } from "@/lib/use-page";
import {
  approveExpense,
  createExpense,
  getExpenseCategories,
  getExpenses,
  getFinancialAccounts,
  payExpense,
  rejectExpense,
  uploadExpenseAttachment,
  type Expense,
  type ExpenseCategory,
  type ExpenseStatus,
  type FinancialAccount,
} from "@/lib/api";
import Pagination from "@/components/Pagination";
import ExpenseCategoryManager from "@/components/ExpenseCategoryManager";

const PAGE_SIZE = 100;

const STATUS_LABEL: Record<ExpenseStatus, string> = {
  draft: "مسودة",
  submitted: "مُرسَل",
  approved: "معتمد",
  rejected: "مرفوض",
  paid: "مدفوع",
};

const STATUS_TONE: Record<ExpenseStatus, string> = {
  draft: "bg-slate-100 text-slate-600",
  submitted: "bg-amber-100 text-amber-700",
  approved: "bg-green-100 text-green-700",
  rejected: "bg-red-100 text-red-700",
  paid: "bg-blue-100 text-blue-700",
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

/** ⭐ الفئة إجبارية — القائمة بتتراكب فئاتها من الـbackend */
export default function ExpensesPage() {
  const [showForm, setShowForm] = useState(false);
  const [showCategories, setShowCategories] = useState(false);

  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState("");

  const [form, setForm] = useState({
    amount: "",
    expense_date: new Date().toISOString().slice(0, 10),
    description: "",
    payment_method: "cash",
    category_id: "",
    payee: "",
  });
  const [saving, setSaving] = useState(false);
  /** ⭐ خطأ النموذج — منفصل عن `error` (اللي بقى خاص بالجلب) */
  const [formError, setFormError] = useState<string | null>(null);

  /** ⭐ فئات القائمة المنسدلة — تُجلب مرة عند فتح الفورم */
  const [categories, setCategories] = useState<ExpenseCategory[]>([]);

  /** ⭐ نافذة الصرف — اختيار الحساب (اختياري) */
  const [payFor, setPayFor] = useState<Expense | null>(null);
  const [accounts, setAccounts] = useState<FinancialAccount[]>([]);
  const [accountId, setAccountId] = useState("");
  const [rowError, setRowError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

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

  async function openForm() {
    setShowForm((v) => !v);
    if (categories.length === 0) {
      try {
        setCategories(await getExpenseCategories());
      } catch {
        /* الفورم هيصحب خطأ (فئة إجبارية) من الـsubmit — مش هنرطل هنا */
      }
    }
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
        category_id: Number(form.category_id),
        payee: form.payee.trim() || undefined,
      });
      setShowForm(false);
      setForm({
        amount: "",
        expense_date: new Date().toISOString().slice(0, 10),
        description: "",
        payment_method: "cash",
        category_id: "",
        payee: "",
      });
      setPage(1);
      reload();
    } catch (err) {
      setFormError(err instanceof Error ? err.message : "فشل الإنشاء");
    } finally {
      setSaving(false);
    }
  }

  // ═══════════════════════════════════════════════════════════
  // PR 2.12 — أزرار الدورة: اعتماد / رفض / صرف / مرفقات
  // ═══════════════════════════════════════════════════════════

  async function runAction(
    exp: Expense,
    fn: () => Promise<unknown>,
  ) {
    setBusyId(exp.id);
    setRowError(null);
    try {
      await fn();
      reload();
    } catch (err) {
      setRowError(err instanceof Error ? err.message : "عملية فشلت");
    } finally {
      setBusyId(null);
    }
  }

  async function openPayDialog(exp: Expense) {
    setPayFor(exp);
    setRowError(null);
    setAccountId("");
    if (accounts.length === 0) {
      try {
        const r = await getFinancialAccounts();
        setAccounts(r.data);
      } catch {
        /* الحساب اختياري — الصرف بيشتغل بالحساب الافتراضي */
      }
    }
  }

  async function handlePay(e: React.FormEvent) {
    e.preventDefault();
    if (!payFor) return;
    setBusyId(payFor.id);
    setRowError(null);
    try {
      const res = await payExpense(payFor.id, {
        financial_account_id: accountId ? Number(accountId) : undefined,
      });
      if (res.warning) {
        window.alert(res.warning);
      }
      setPayFor(null);
      reload();
    } catch (err) {
      setRowError(err instanceof Error ? err.message : "فشل الصرف");
    } finally {
      setBusyId(null);
    }
  }

  async function handleUpload(exp: Expense, file: File) {
    await runAction(exp, () => uploadExpenseAttachment(exp.id, file));
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
            onClick={() => setShowCategories(true)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-600 hover:bg-slate-50"
          >
            الفئات
          </button>
          <button
            onClick={openForm}
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
          <p className="text-xs text-green-600">قيد المراجعة</p>
          <p className="mt-1 text-xl font-bold text-green-800">{statusCounts.submitted ?? 0}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">مدفوع</p>
          <p className="mt-1 text-xl font-bold text-blue-800">{statusCounts.paid ?? 0}</p>
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
            <select
              required value={form.category_id}
              onChange={(e) => setForm({ ...form, category_id: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">الفئة *</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
            <input
              placeholder="الجهة المقبوضة منها (اختياري)"
              value={form.payee}
              onChange={(e) => setForm({ ...form, payee: e.target.value })}
              className="rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
            <input
              required placeholder="الوصف *" className="sm:col-span-2 lg:col-span-3 rounded-lg border border-slate-300 px-3 py-2 text-sm"
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

      {rowError && (
        <div className="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700">{rowError}</div>
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
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
                <th className="px-3 py-3 text-center font-medium">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {expenses.map((exp) => (
                <tr key={exp.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3 font-medium text-slate-800">
                    {exp.description}
                    {exp.payee && <span className="ms-2 text-xs text-slate-400">· {exp.payee}</span>}
                    {(exp.attachments?.length ?? 0) > 0 && (
                      <span className="ms-2 text-xs text-slate-400">
                        {exp.attachments!.map((a) => (
                          <a
                            key={a.id}
                            href={a.download_url ?? "#"}
                            className="me-1 text-blue-600 underline"
                            title={`تنزيل ${a.original_name}`}
                          >
                            📎
                          </a>
                        ))}
                      </span>
                    )}
                  </td>
                  <td className="px-3 py-3 text-slate-600">{exp.category?.name ?? "—"}</td>
                  <td className="px-3 py-3 text-center font-medium text-slate-800">
                    {fmtMoney(Number(exp.amount))}
                  </td>
                  <td className="hidden px-3 py-3 text-xs text-slate-500 sm:table-cell">
                    {date(exp.expense_date)}
                  </td>
                  <td className="px-3 py-3 text-center">
                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${STATUS_TONE[exp.status]}`}>
                      {STATUS_LABEL[exp.status]}
                    </span>
                  </td>
                  <td className="px-3 py-3 text-center">
                    <div className="flex items-center justify-center gap-2 text-xs whitespace-nowrap">
                      {exp.status === "submitted" && (
                        <>
                          <button
                            disabled={busyId === exp.id}
                            onClick={() => runAction(exp, () => approveExpense(exp.id))}
                            className="rounded bg-green-50 px-2 py-1 font-medium text-green-700 hover:bg-green-100 disabled:opacity-60"
                          >
                            اعتماد
                          </button>
                          <button
                            disabled={busyId === exp.id}
                            onClick={() => runAction(exp, () => rejectExpense(exp.id))}
                            className="rounded bg-red-50 px-2 py-1 font-medium text-red-700 hover:bg-red-100 disabled:opacity-60"
                          >
                            رفض
                          </button>
                        </>
                      )}
                      {exp.status === "approved" && (
                        <button
                          disabled={busyId === exp.id}
                          onClick={() => void openPayDialog(exp)}
                          className="rounded bg-blue-50 px-2 py-1 font-medium text-blue-700 hover:bg-blue-100 disabled:opacity-60"
                        >
                          صرف
                        </button>
                      )}
                      {exp.status !== "paid" && (
                        <label className="cursor-pointer text-slate-500 hover:text-slate-700">
                          📎
                          <input
                            type="file"
                            accept=".png,.jpg,.jpeg,.webp,.pdf"
                            className="hidden"
                            onChange={(e) => {
                              const f = e.target.files?.[0];
                              if (f) void handleUpload(exp, f);
                              e.target.value = "";
                            }}
                          />
                        </label>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <ExpenseCategoryManager open={showCategories} onClose={() => setShowCategories(false)} />

      {/* نافذة الصرف — اختيار الحساب وقت الخروج */}
      {payFor && (
        <form onSubmit={handlePay} className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-sm rounded-xl bg-white p-5 shadow-xl">
            <h2 className="mb-3 text-sm font-semibold text-slate-800">
              صرف مصروف — {fmtMoney(Number(payFor.amount))}
            </h2>
            <select
              value={accountId}
              onChange={(e) => setAccountId(e.target.value)}
              className="mb-3 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            >
              <option value="">الحساب الافتراضي</option>
              {accounts.map((a) => (
                <option key={a.id} value={a.id}>{a.name} ({a.balance})</option>
              ))}
            </select>
            <div className="flex gap-2">
              <button
                type="submit" disabled={busyId === payFor.id}
                className="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-60"
              >
                تأكيد الصرف
              </button>
              <button
                type="button" onClick={() => setPayFor(null)}
                className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200"
              >
                إلغاء
              </button>
            </div>
          </div>
        </form>
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
