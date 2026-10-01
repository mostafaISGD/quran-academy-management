"use client";

import { useEffect, useState } from "react";
import { getPayments, createPayment, refundPayment, type Payment } from "@/lib/api";
import Pagination from "@/components/Pagination";

const STATUS_LABEL: Record<Payment["status"], string> = {
  pending: "معلق", completed: "مكتمل", failed: "فاشل", voided: "ملغي",
};

const METHOD_LABEL: Record<Payment["payment_method"], string> = {
  cash: "نقدي", bank_transfer: "تحويل بنكي", wallet: "محفظة", online_payment: "دفع أونلاين", card: "بطاقة", other: "أخرى",
};

export default function PaymentsPage() {
  const [payments, setPayments] = useState<Payment[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [refundPaymentId, setRefundPaymentId] = useState<number | null>(null);

  const [form, setForm] = useState<{ student_id: string; invoice_id: string; amount: string; currency: string; payment_method: Payment["payment_method"]; paid_at: string }>({ student_id: "", invoice_id: "", amount: "", currency: "EGP", payment_method: "cash", paid_at: "" });

  const [refundForm, setRefundForm] = useState({ amount: "", reason: "" });

  // Pagination + إحصائيات من الداتابيز
  const PAGE_SIZE = 100;
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [sums, setSums] = useState<Record<string, number>>({});
  const [methodCounts, setMethodCounts] = useState<Record<string, number>>({});

  function loadPayments(targetPage = 1) {
    setLoading(true);
    getPayments({ per_page: PAGE_SIZE, page: targetPage })
      .then((r) => {
        setPayments(r.data);
        setMeta({ total: r.total, last_page: r.last_page });
        setSums(r.sums ?? {});
        const c = r.counts as Record<string, unknown> | undefined;
        const m = c?.payment_method;
        setMethodCounts((m && typeof m === "object" ? m : {}) as Record<string, number>);
      })
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    loadPayments(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  useEffect(() => { loadPayments(1); }, []);

  const fmt = (n: number | undefined) =>
    n === undefined ? "—" : n.toLocaleString("ar-EG", { maximumFractionDigits: 2 }) + " ج.م";

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    try {
      await createPayment({
        student_id: Number(form.student_id),
        invoice_id: form.invoice_id || undefined,
        amount: Number(form.amount),
        currency: form.currency as string,
        payment_method: form.payment_method,
        paid_at: form.paid_at,
      });
      setShowForm(false);
      loadPayments();
    } catch (err) { setError(err instanceof Error ? err.message : "فشل إنشاء الدفعة"); }
  }

  async function handleRefund(e: React.FormEvent) {
    e.preventDefault();
    if (!refundPaymentId) return;
    try {
      await refundPayment(refundPaymentId, Number(refundForm.amount), refundForm.reason);
      setRefundPaymentId(null);
      setRefundForm({ amount: "", reason: "" });
      loadPayments();
    } catch (err) { setError(err instanceof Error ? err.message : "فشل الاسترجاع"); }
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
      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">تسجيل دفعة</h2>
          <form onSubmit={handleCreate} className="grid grid-cols-2 gap-3">
            <input placeholder="معرّف الطالب" value={form.student_id} onChange={(e) => setForm({ ...form, student_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="معرّف الفاتورة (اختياري)" value={form.invoice_id} onChange={(e) => setForm({ ...form, invoice_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input type="number" step="0.01" placeholder="المبلغ" value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <select value={form.currency} onChange={(e) => setForm({ ...form, currency: e.target.value as string })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="EGP">EGP</option><option value="SAR">SAR</option><option value="AED">AED</option><option value="USD">USD</option>
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
                <th className="px-4 py-3 text-right">الطالب</th>
                <th className="px-4 py-3 text-right">المبلغ</th>
                <th className="px-4 py-3 text-right">الطريقة</th>
                <th className="px-4 py-3 text-right">المرجع</th>
                <th className="px-4 py-3 text-right">الحالة</th>
                <th className="px-4 py-3 text-right">التاريخ</th>
                <th className="px-4 py-3 text-right">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {payments.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">لا توجد مدفوعات</td></tr>}
              {payments.map((p) => (
                <tr key={p.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-800">{p.student?.full_name ?? `طالب ${p.student_id}`}</td>
                  <td className="px-4 py-3 font-medium text-slate-800">{p.amount} {p.currency}</td>
                  <td className="px-4 py-3 text-slate-600">{METHOD_LABEL[p.payment_method]}</td>
                  <td className="px-4 py-3 text-slate-600">{p.transaction_reference ?? "—"}</td>
                  <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs ${p.status === "completed" ? "bg-green-100 text-green-700" : p.status === "pending" ? "bg-amber-100 text-amber-700" : p.status === "failed" ? "bg-red-100 text-red-700" : "bg-slate-100 text-slate-500"}`}>{STATUS_LABEL[p.status]}</span></td>
                  <td className="px-4 py-3 text-slate-500">{new Date(p.paid_at).toLocaleDateString("ar-EG")}</td>
                  <td className="px-4 py-3">{p.status === "completed" && <button onClick={() => setRefundPaymentId(p.id)} className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100">استرجاع</button>}</td>
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
