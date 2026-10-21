"use client";

import { useCallback, useState } from "react";
import { getPayments, createPayment, refundPayment, downloadPaymentReceipt, type Payment } from "@/lib/api";
import { usePage } from "@/lib/use-page";
import { date, egp, money } from "@/lib/format";
import Pagination from "@/components/Pagination";

const STATUS_LABEL: Record<Payment["status"], string> = {
  pending: "معلق", completed: "مكتمل", failed: "فاشل", voided: "ملغي",
  // ⭐ PR 2.8 — عكس دفعة خاطئة (مش ملغي)
  reversed: "معكوس",
};

const METHOD_LABEL: Record<Payment["payment_method"], string> = {
  cash: "نقدي", bank_transfer: "تحويل بنكي", wallet: "محفظة", online_payment: "دفع أونلاين", card: "بطاقة", other: "أخرى",
};

/**
 * ⭐ كل حاجة بيجيبها الطلب في كومة واحدة —
 * عشان `usePage` بترجع `data` واحد بس.
 */
type PaymentsBundle = {
  items: Payment[];
  total: number;
  lastPage: number;
  sums: Record<string, number>;
  methodCounts: Record<string, number>;
};

const EMPTY_PAYMENTS: PaymentsBundle = {
  items: [],
  total: 0,
  lastPage: 1,
  sums: {},
  methodCounts: {},
};

export default function PaymentsPage() {
  const [showForm, setShowForm] = useState(false);
  const [refundPaymentId, setRefundPaymentId] = useState<number | null>(null);
  /** ⭐ خطأ الإجراءات (إنشاء/استرجاع) — منفصل عن `error` (الجلب) */
  const [actionError, setActionError] = useState<string | null>(null);

  /**
   * ⭐⭐ PR 2.7 — مفتاح منع التكرار.
   *
   * بيتبني مرة لكل session فتح للنموذج: لو المستخدم داس
   * "ادفع" مرتين (دبل-كليك أو شبكة ضعيفة)، الـbackend
   * بيرجّع نفس الدفعة بدل ما يسجّل دفعة جديدة. والنموذج
   * الجديد بعد النجاح = مفتاح جديد = دفعة جديدة شرعية.
   */
  const [form, setForm] = useState<{ student_id: string; invoice_id: string; amount: string; net_amount: string; currency: string; payment_method: Payment["payment_method"]; paid_at: string; idempotency_key: string }>({ student_id: "", invoice_id: "", amount: "", net_amount: "", currency: "EGP", payment_method: "cash", paid_at: "", idempotency_key: crypto.randomUUID() });

  const [refundForm, setRefundForm] = useState({ amount: "", reason: "", mode: "cash" });

  // Pagination + إحصائيات من الداتابيس
  const PAGE_SIZE = 100;
  const [page, setPage] = useState(1);

  /**
   * ⭐ الجلب عن طريق `usePage` بدل `useEffect`.
   *
   * الـ `key` هو الصفحة — والإحصائيات (`sums`
   * و`methodCounts`) بتيجي من نفس الطلب.
   *
   * @see usePage
   */
  const fetchPayments = useCallback(async (): Promise<PaymentsBundle> => {
    const r = await getPayments({ per_page: PAGE_SIZE, page });
    const c = r.counts as Record<string, unknown> | undefined;
    const m = c?.payment_method;
    return {
      items: r.data,
      total: r.total,
      lastPage: r.last_page,
      sums: r.sums ?? {},
      methodCounts: (m && typeof m === "object" ? m : {}) as Record<string, number>,
    };
  }, [page]);

  const {
    data: bundle,
    loading,
    error,
    reload,
  } = usePage<PaymentsBundle>(`payments:${page}`, fetchPayments, {
    initial: EMPTY_PAYMENTS,
  });

  const payments = bundle.items;
  const meta = { total: bundle.total, last_page: bundle.lastPage };
  const sums = bundle.sums;
  const methodCounts = bundle.methodCounts;

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  // ⭐ `egp` من `lib/format` — بتعمل التحويل لـ«ج.م» جواها
  const fmt = egp;

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    try {
      await createPayment({
        student_id: Number(form.student_id),
        invoice_id: form.invoice_id || undefined,
        amount: Number(form.amount),
        net_amount: form.net_amount ? Number(form.net_amount) : undefined,
        idempotency_key: form.idempotency_key,
        currency: form.currency as string,
        payment_method: form.payment_method,
        paid_at: form.paid_at,
      });
      setShowForm(false);
      setForm({ student_id: "", invoice_id: "", amount: "", net_amount: "", currency: "EGP", payment_method: "cash", paid_at: "", idempotency_key: crypto.randomUUID() });
      // ⭐ نرجع لأول صفحة ونعيد الجلب
      setPage(1);
      reload();
    } catch (err) { setActionError(err instanceof Error ? err.message : "فشل إنشاء الدفعة"); }
  }

  async function handleRefund(e: React.FormEvent) {
    e.preventDefault();
    if (!refundPaymentId) return;
    try {
      const res = await refundPayment(refundPaymentId, Number(refundForm.amount), refundForm.reason, {
        // ⭐ PR 2.8 — نقدي من الخزينة أو رصيد دائن للاشتراكات الجاية
        mode: refundForm.mode as "cash" | "credit_note",
      });
      setRefundPaymentId(null);
      setRefundForm({ amount: "", reason: "", mode: "cash" });
      // ⭐ نفس الحاجة — نعيد الجلب من أول صفحة
      setPage(1);
      reload();
      // ⭐ فوق سقف الاعتماد بيرجع `pending_approval` — الرسالة توضح
      if (res.pending_approval) {
        setActionError("الاسترداد فوق سقف الاعتماد — اتسجل ومستني موافقة تانية قبل ما تنفذ");
      }
    } catch (err) { setActionError(err instanceof Error ? err.message : "فشل الاسترجاع"); }
  }

  // ⭐ PR 2.17 — إيصال الدفعة PDF (عربي RTL): نفس نمط نمط التقارير
  // (blob/anchor)؛ الأخطاء تسقط في سطر أحمر على التاب نفسه.
  const [receiptError, setReceiptError] = useState("");
  const [receiptBusy, setReceiptBusy] = useState<number | null>(null);

  async function handleDownloadReceipt(id: number) {
    setReceiptBusy(id);
    try {
      const blob = await downloadPaymentReceipt(id);
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = `RCP-${id}.pdf`;
      a.click();
      URL.revokeObjectURL(url);
      setReceiptError("");
    } catch (e) {
      setReceiptError(e instanceof Error ? e.message : "تعذّر تحميل الإيصال PDF");
    } finally {
      setReceiptBusy(null);
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">المدفوعات</h1>
        <button onClick={() => setShowForm(!showForm)} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">{showForm ? "إخفاء" : "+ تسجيل دفعة"}</button>
      </div>

      {/* إحصائيات — من الداتابيز على كل المدفوعات */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">عدد المدفوعات</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">إجمالي المحصّل</p>
          <p className="mt-1 text-xl font-bold text-green-800">{fmt(sums.amount)}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">نقدي</p>
          <p className="mt-1 text-xl font-bold text-blue-800">{methodCounts.cash ?? 0}</p>
        </div>
        <div className="rounded-xl bg-purple-50 p-4 text-center">
          <p className="text-xs text-purple-600">تحويل / إلكتروني</p>
          <p className="mt-1 text-xl font-bold text-purple-800">
            {(methodCounts.bank_transfer ?? 0) + (methodCounts.wallet ?? 0) + (methodCounts.online_payment ?? 0) + (methodCounts.card ?? 0)}
          </p>
        </div>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {actionError && <p className="mb-4 text-sm text-red-600">{actionError}</p>}
      {receiptError && <p className="mb-4 text-sm text-red-600">{receiptError}</p>}
      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">تسجيل دفعة</h2>
          <form onSubmit={handleCreate} className="grid grid-cols-2 gap-3">
            <input placeholder="معرّف الطالب" value={form.student_id} onChange={(e) => setForm({ ...form, student_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="معرّف الفاتورة (اختياري)" value={form.invoice_id} onChange={(e) => setForm({ ...form, invoice_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input type="number" step="0.01" placeholder="المبلغ" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input type="number" step="0.01" placeholder="المبلغ الواصل (بعد العمولة)" value={form.net_amount} onChange={(e) => setForm({ ...form, net_amount: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value as string })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              {/* ⭐ `currencyLabel` — الكود الإنجليزي كان بيظهر للمستخدم */}
              <option value="EGP">جنيه مصري</option>
              <option value="SAR">ريال سعودي</option>
              <option value="AED">درهم إماراتي</option>
              <option value="USD">دولار أمريكي</option>
            </select>
            <select value={form.payment_method} onChange={(e) => setForm({ ...form, payment_method: e.target.value as Payment["payment_method"] })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              {Object.entries(METHOD_LABEL).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
            <input type="datetime-local" value={form.paid_at} onChange={(e) => setForm({ ...form, paid_at: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <button type="submit" className="col-span-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">تسجيل الدفعة</button>
          </form>
        </div>
      )}
      {!loading && !error && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 text-start">الطالب</th>
                <th className="px-4 py-3 text-start">المبلغ</th>
                <th className="px-4 py-3 text-start">الطريقة</th>
                <th className="px-4 py-3 text-start">المرجع</th>
                <th className="px-4 py-3 text-start">الحالة</th>
                <th className="px-4 py-3 text-start">التاريخ</th>
                <th className="px-4 py-3 text-start">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {payments.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">لا توجد مدفوعات</td></tr>}
              {payments.map((p) => (
                <tr key={p.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-800">{p.student?.full_name ?? `طالب ${p.student_id}`}</td>
                  {/* ⭐ `money` — كان بيطبع `EGP` إنجليزي */}
                  <td className="px-4 py-3 font-medium text-slate-800">
              {money(p.amount, p.currency)}
              {p.net_amount && Number(p.net_amount) < Number(p.amount) && (
                <span className="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-700">
                  وصل: {money(Number(p.amount) - Number(p.net_amount), p.currency)} عمولة
                </span>
              )}
            </td>
                  <td className="px-4 py-3 text-slate-600">{METHOD_LABEL[p.payment_method]}</td>
                  <td className="px-4 py-3 text-slate-600">{p.transaction_reference ?? "—"}</td>
                  <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs ${p.status === "completed" ? "bg-green-100 text-green-700" : p.status === "pending" ? "bg-amber-100 text-amber-700" : p.status === "failed" ? "bg-red-100 text-red-700" : "bg-slate-100 text-slate-500"}`}>{STATUS_LABEL[p.status]}</span></td>
                  <td className="px-4 py-3 text-slate-500">{date(p.paid_at)}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      {p.status === "completed" && <button onClick={() => setRefundPaymentId(p.id)} className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100">استرجاع</button>}
                      {p.status === "completed" && <button onClick={() => handleDownloadReceipt(p.id)} disabled={receiptBusy === p.id} className="rounded bg-teal-50 px-2 py-1 text-xs text-teal-700 hover:bg-teal-100 disabled:opacity-50">{receiptBusy === p.id ? "..." : "إيصال PDF"}</button>}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Pagination */}
      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="دفعة"
      />

      {refundPaymentId && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-sm rounded-xl bg-white p-6">
            <h3 className="mb-4 text-lg font-semibold text-slate-800">استرجاع دفعة</h3>
            <form onSubmit={handleRefund} className="space-y-4">
              <input type="number" step="0.01" placeholder="مبلغ الاسترجاع" value={refundForm.amount} onChange={(e) => setRefundForm({ ...refundForm, amount: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              <textarea placeholder="سبب الاسترجاع" value={refundForm.reason} onChange={(e) => setRefundForm({ ...refundForm, reason: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" rows={3} required />
              {/* ⭐ PR 2.8 — نقدي (حركة من الخزينة) أو رصيد دائن يخصم من اشتراك جاي */}
              <select value={refundForm.mode} onChange={(e) => setRefundForm({ ...refundForm, mode: e.target.value })} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <option value="cash">نقدي — يخرج من الخزينة</option>
                <option value="credit_note">رصيد دائن — يخصم من اشتراك قادم</option>
              </select>
              <div className="flex gap-2">
                <button type="submit" className="flex-1 rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white hover:bg-red-700">تأكيد</button>
                <button type="button" onClick={() => setRefundPaymentId(null)} className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 hover:bg-slate-200">إلغاء</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
