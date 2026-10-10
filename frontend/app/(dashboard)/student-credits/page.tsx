"use client";

import { useCallback, useEffect, useState } from "react";
import {
  applyCreditNote,
  createStudentCredit,
  getStudentCredits,
  refundStudentCredit,
  type StudentCredit,
} from "@/lib/api";
import { egp, date, text } from "@/lib/format";
import { useUI } from "@/components/ui";

/**
 * ⭐⭐ إدارة الرصيد الدائن.
 *
 * ⭐ الطالب دفع زيادة، أو فيه رصيد من دورة سابقة — ده بيسجل
 * كـ student_credit. بعدين يقدر يتطبق على فاتورة أو يسترد نقدياً.
 */

const FIELD =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
const BTN_PRIMARY =
  "rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50";
const BTN_DANGER =
  "rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50";
const BTN_GHOST =
  "rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50";

export default function StudentCreditsPage() {
  const { toast } = useUI();
  const [credits, setCredits] = useState<StudentCredit[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modal, setModal] = useState<"create" | "apply" | "refund" | null>(null);
  const [saving, setSaving] = useState(false);
  const [selectedCredit, setSelectedCredit] = useState<StudentCredit | null>(null);
  const [invoices, setInvoices] = useState<{ id: number; invoice_number: string; balance_due: number }[]>([]);

  // إنشاء رصيد
  const [form, setForm] = useState({
    student_id: 0,
    currency: "EGP",
    total: 0,
    reason: "",
    source_type: "manual",
    expires_at: "",
  });

  // تطبيق رصيد
  const [applyForm, setApplyForm] = useState({
    invoice_id: 0,
    amount: 0,
  });

  // استرداد
  const [refundForm, setRefundForm] = useState({
    amount: 0,
    reason: "",
  });

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await getStudentCredits();
      setCredits(data.data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل الأرصدة");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    getStudentCredits()
      .then((data) => {
        setCredits(data.data);
      })
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر تحميل الأرصدة"))
      .finally(() => setLoading(false));
  }, []);

  const handleCreate = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      await createStudentCredit(form);
      toast.success("تم إنشاء الرصيد الدائن");
      setModal(null);
      setForm({
        student_id: 0,
        currency: "EGP",
        total: 0,
        reason: "",
        source_type: "manual",
        expires_at: "",
      });
      await load();
    } catch (e) {
      toast.apiError("فشل إنشاء الرصيد", e);
    } finally {
      setSaving(false);
    }
  };

  const handleApply = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedCredit) return;
    setSaving(true);
    try {
      await applyCreditNote(selectedCredit.id, applyForm);
      toast.success("تم تطبيق الرصيد على الفاتورة");
      setModal(null);
      setApplyForm({ invoice_id: 0, amount: 0 });
      setSelectedCredit(null);
      await load();
    } catch (e) {
      toast.apiError("فشل تطبيق الرصيد", e);
    } finally {
      setSaving(false);
    }
  };

  const handleRefund = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedCredit) return;
    setSaving(true);
    try {
      await refundStudentCredit(selectedCredit.id, refundForm);
      toast.success("تم استرداد الرصيد نقدياً");
      setModal(null);
      setRefundForm({ amount: 0, reason: "" });
      setSelectedCredit(null);
      await load();
    } catch (e) {
      toast.apiError("فشل الاسترداد", e);
    } finally {
      setSaving(false);
    }
  };

  const openApply = async (credit: StudentCredit) => {
    setSelectedCredit(credit);
    // جلب فواتير الطالب المفتوحة
    try {
      const res = await fetch("/api/invoices?student_id=" + credit.student?.id + "&status=issued,partially_paid");
      const data = await res.json();
      setInvoices(data.data || []);
    } catch {
      setInvoices([]);
    }
    setApplyForm({ invoice_id: 0, amount: credit.balance });
    setModal("apply");
  };

  const openRefund = (credit: StudentCredit) => {
    setSelectedCredit(credit);
    setRefundForm({ amount: credit.balance, reason: "" });
    setModal("refund");
  };

  if (loading) {
    return (
      <div className="space-y-4">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-32 animate-pulse rounded-xl bg-slate-100" />
        ))}
      </div>
    );
  }

  if (error) {
    return <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>;
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">الرصيد الدائن</h1>
          <p className="mt-0.5 text-xs text-slate-500">
            {credits.length} رصيد — الإجمالي:{" "}
            <strong>{egp(credits.reduce((s, c) => s + c.balance, 0), 0)}</strong>
          </p>
        </div>
        <button className={BTN_PRIMARY} onClick={() => setModal("create")}>
          رصيد دائن جديد
        </button>
      </div>

      {modal === "create" && (
        <CreateCreditModal
          form={form}
          setForm={setForm}
          onSubmit={handleCreate}
          onClose={() => setModal(null)}
          saving={saving}
        />
      )}

      {modal === "apply" && selectedCredit && (
        <ApplyCreditModal
          credit={selectedCredit}
          invoices={invoices}
          form={applyForm}
          setForm={setApplyForm}
          onSubmit={handleApply}
          onClose={() => {
            setModal(null);
            setSelectedCredit(null);
            setApplyForm({ invoice_id: 0, amount: 0 });
          }}
          saving={saving}
        />
      )}

      {modal === "refund" && selectedCredit && (
        <RefundCreditModal
          credit={selectedCredit}
          form={refundForm}
          setForm={setRefundForm}
          onSubmit={handleRefund}
          onClose={() => {
            setModal(null);
            setSelectedCredit(null);
            setRefundForm({ amount: 0, reason: "" });
          }}
          saving={saving}
        />
      )}

      {credits.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
          مفيش أرصدة دائنة. اعمل «رصيد دائن جديد» للطلاب اللي فيهم زيادة.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">
                  الطالب
                </th>
                <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">
                  السبب
                </th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">
                  الإجمالي
                </th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">
                  مستهلك
                </th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">
                  مسترد
                </th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">
                  الرصيد
                </th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">
                  الحالة
                </th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">
                  ينتهي
                </th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">
                  إجراءات
                </th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {credits.map((c) => (
                <tr key={c.id}>
                  <td className="px-4 py-2 text-slate-700">{text(c.student?.name)}</td>
                  <td className="px-4 py-2 text-slate-600 max-w-[12rem] truncate">
                    {text(c.reason)}
                  </td>
                  <td className="px-4 py-2 text-center tabular-nums text-slate-600">
                    {egp(c.total, 0)}
                  </td>
                  <td className="px-4 py-2 text-center tabular-nums text-amber-600">
                    {egp(c.used, 0)}
                  </td>
                  <td className="px-4 py-2 text-center tabular-nums text-rose-600">
                    {egp(c.refunded, 0)}
                  </td>
                  <td className="px-4 py-2 text-center tabular-nums font-bold text-emerald-700">
                    {egp(c.balance, 0)}
                  </td>
                  <td className="px-4 py-2 text-center">
                    <span
                      className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                        c.status === "active"
                          ? "bg-emerald-100 text-emerald-700"
                          : c.status === "depleted"
                          ? "bg-slate-100 text-slate-600"
                          : "bg-rose-100 text-rose-700"
                      }`}
                    >
                      {c.status === "active" ? "نشط" : c.status === "depleted" ? "مستهلك" : "مسترد"}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-center text-xs text-slate-500">
                    {c.expires_at ? date(c.expires_at) : "—"}
                  </td>
                  <td className="px-4 py-2 text-center">
                    <div className="flex items-center justify-center gap-1">
                      {c.balance > 0 && c.status === "active" && (
                        <>
                          <button
                            className={BTN_GHOST}
                            onClick={() => openApply(c)}
                            title="تطبيق على فاتورة"
                          >
                            تطبيق
                          </button>
                          <button
                            className={BTN_GHOST.replace("text-slate-700", "text-amber-700 hover:bg-amber-50")}
                            onClick={() => openRefund(c)}
                            title="استرداد نقدي"
                          >
                            استرداد
                          </button>
                        </>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {modal === "create" && (
        <CreateCreditModal
          form={form}
          setForm={setForm}
          onSubmit={handleCreate}
          onClose={() => setModal(null)}
          saving={saving}
        />
      )}

      {modal === "apply" && selectedCredit && (
        <ApplyCreditModal
          credit={selectedCredit}
          invoices={invoices}
          form={applyForm}
          setForm={setApplyForm}
          onSubmit={handleApply}
          onClose={() => {
            setModal(null);
            setSelectedCredit(null);
            setApplyForm({ invoice_id: 0, amount: 0 });
          }}
          saving={saving}
        />
      )}

      {modal === "refund" && selectedCredit && (
        <RefundCreditModal
          credit={selectedCredit}
          form={refundForm}
          setForm={setRefundForm}
          onSubmit={handleRefund}
          onClose={() => {
            setModal(null);
            setSelectedCredit(null);
            setRefundForm({ amount: 0, reason: "" });
          }}
          saving={saving}
        />
      )}
    </div>
  );
}

function CreateCreditModal({
  form,
  setForm,
  onSubmit,
  onClose,
  saving,
}: {
  form: {
    student_id: number;
    currency: string;
    total: number;
    reason: string;
    source_type: string;
    expires_at: string;
  };
  setForm: (v: typeof form) => void;
  onSubmit: (e: React.FormEvent) => void;
  onClose: () => void;
  saving: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
        <form onSubmit={onSubmit}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-base font-semibold text-slate-800">رصيد دائن جديد</h2>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-700">
              ✕
            </button>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-600">الطالب</label>
              <input
                type="number"
                min="1"
                value={form.student_id}
                onChange={(e) => setForm({ ...form, student_id: Number(e.target.value) || 0 })}
                placeholder="ID الطالب"
                className={FIELD}
                required
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">العملة</label>
              <input
                value={form.currency}
                onChange={(e) => setForm({ ...form, currency: e.target.value })}
                className={FIELD}
                maxLength={3}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">المبلغ</label>
              <input
                type="number"
                step="0.01"
                min="0.01"
                value={form.total}
                onChange={(e) => setForm({ ...form, total: Number(e.target.value) || 0 })}
                className={FIELD}
                required
              />
            </div>

            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-600">السبب</label>
              <input
                value={form.reason}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
                placeholder="مثال: زيادة دفع، رصيد من دورة سابقة"
                className={FIELD}
                required
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">المصدر</label>
              <input
                value={form.source_type}
                onChange={(e) => setForm({ ...form, source_type: e.target.value })}
                placeholder="manual, overpayment, adjustment"
                className={FIELD}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">ينتهي في</label>
              <input
                type="date"
                value={form.expires_at}
                onChange={(e) => setForm({ ...form, expires_at: e.target.value })}
                className={FIELD}
              />
            </div>
          </div>

          <div className="mt-5 flex justify-end gap-2">
            <button
              type="button"
              className={BTN_DANGER.replace("bg-red-600", "bg-slate-200 hover:bg-slate-300 text-slate-700")}
              onClick={onClose}
            >
              إلغاء
            </button>
            <button
              type="submit"
              className={BTN_PRIMARY}
              disabled={saving}
            >
              {saving ? "…" : "اعمل الرصيد"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function ApplyCreditModal({
  credit,
  invoices,
  form,
  setForm,
  onSubmit,
  onClose,
  saving,
}: {
  credit: StudentCredit;
  invoices: { id: number; invoice_number: string; balance_due: number }[];
  form: { invoice_id: number; amount: number };
  setForm: (v: typeof form) => void;
  onSubmit: (e: React.FormEvent) => void;
  onClose: () => void;
  saving: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
        <form onSubmit={onSubmit}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-base font-semibold text-slate-800">تطبيق رصيد على فاتورة</h2>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-700">
              ✕
            </button>
          </div>

          <p className="mb-4 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
            رصيد الطالب: <strong>{egp(credit.balance, 0)}</strong> —
            السبب: {text(credit.reason)}
          </p>

          <div className="grid gap-3">
            <div>
              <label className="mb-1 block text-xs text-slate-600">الفاتورة</label>
              <select
                value={form.invoice_id}
                onChange={(e) => setForm({ ...form, invoice_id: Number(e.target.value) || 0 })}
                className={FIELD}
              >
                <option value="0">اختار الفاتورة...</option>
                {invoices.map((inv) => (
                  <option key={inv.id} value={inv.id}>
                    {inv.invoice_number} — متبقي {egp(inv.balance_due, 0)}
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">المبلغ</label>
              <input
                type="number"
                step="0.01"
                min="0.01"
                max={credit.balance}
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: Number(e.target.value) || 0 })}
                className={FIELD}
                required
              />
              <p className="mt-1 text-[11px] text-slate-500">
                المتاح: {egp(credit.balance, 0)}
              </p>
            </div>
          </div>

          <div className="mt-5 flex justify-end gap-2">
            <button type="button" className={BTN_GHOST} onClick={onClose}>
              إلغاء
            </button>
            <button
              type="submit"
              className={BTN_PRIMARY}
              disabled={saving || !form.invoice_id || form.amount <= 0}
            >
              {saving ? "…" : "طبّق"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function RefundCreditModal({
  credit,
  form,
  setForm,
  onSubmit,
  onClose,
  saving,
}: {
  credit: StudentCredit;
  form: { amount: number; reason: string };
  setForm: (v: typeof form) => void;
  onSubmit: (e: React.FormEvent) => void;
  onClose: () => void;
  saving: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
        <form onSubmit={onSubmit}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-base font-semibold text-slate-800">استرداد رصيد نقدياً</h2>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-700">
              ✕
            </button>
          </div>

          <p className="mb-4 rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-800">
            رصيد الطالب: <strong>{egp(credit.balance, 0)}</strong> —
            السبب: {text(credit.reason)}
          </p>

          <p className="mb-4 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
            ⭐ الاسترداد = فلوس ترجع للطالب نقداً. الرصيد الدائن بينقص.
          </p>

          <div className="grid gap-3">
            <div>
              <label className="mb-1 block text-xs text-slate-600">المبلغ</label>
              <input
                type="number"
                step="0.01"
                min="0.01"
                max={credit.balance}
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: Number(e.target.value) || 0 })}
                className={FIELD}
                required
              />
              <p className="mt-1 text-[11px] text-slate-500">
                المتاح: {egp(credit.balance, 0)}
              </p>
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">السبب</label>
              <input
                value={form.reason}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
                placeholder="مثال: ألغى الاشتراك، طلب استرداد"
                className={FIELD}
                required
              />
            </div>
          </div>

          <div className="mt-5 flex justify-end gap-2">
            <button type="button" className={BTN_GHOST} onClick={onClose}>
              إلغاء
            </button>
            <button
              type="submit"
              className={BTN_DANGER}
              disabled={saving || form.amount <= 0 || !form.reason.trim()}
            >
              {saving ? "…" : "استرد نقداً"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}