"use client";

import { useEffect, useState } from "react";
import { getInvoices, createInvoice, deleteInvoice, type Invoice } from "@/lib/api";
import { egp, money } from "@/lib/format";
import Pagination from "@/components/Pagination";
import { useUI } from "@/components/ui";

const STATUS_LABEL: Record<Invoice["status"], string> = {
  draft: "مسودة", issued: "مصدرة", partially_paid: "مدفوعة جزئياً",
  paid: "مدفوعة", overdue: "متأخرة", void: "ملغاة",
};

export default function InvoicesPage() {
  const [invoices, setInvoices] = useState<Invoice[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [form, setForm] = useState({
    student_id: "", issue_date: "", due_date: "",
    items: [{ description: "", quantity: 1, unit_price: 0 }] as { description: string; quantity: number; unit_price: number }[],
  });

  // Pagination + إحصائيات من الداتابيز
  const PAGE_SIZE = 100;
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [sums, setSums] = useState<Record<string, number>>({});

  const { toast, confirm } = useUI();

  function loadInvoices(targetPage = 1) {
    setLoading(true);
    getInvoices({ per_page: PAGE_SIZE, page: targetPage })
      .then((r) => {
        setInvoices(r.data);
        setMeta({ total: r.total, last_page: r.last_page });
        setSums(r.sums ?? {});
      })
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    loadInvoices(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  useEffect(() => { loadInvoices(1); }, []);

  // ⭐ `egp` من `lib/format` — بتعمل التحويل لـ«ج.م» جواها
  const fmt = egp;


  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    try {
      await createInvoice({
        student_id: Number(form.student_id),
        issue_date: form.issue_date,
        due_date: form.due_date || undefined,
        items: form.items,
      });
      setShowForm(false);
      toast.success("تم إنشاء الفاتورة");
      loadInvoices();
    } catch (err) { toast.error("فشل إنشاء الفاتورة", err instanceof Error ? err.message : undefined); setError(err instanceof Error ? err.message : "فشل إنشاء الفاتورة"); }
  }

  async function handleDelete(id: number, number: string, studentName: string) {
    const ok = await confirm({
      title: "حذف الفاتورة",
      message: `متأكد إنك عايز تحذف الفاتورة «${number}»\nبتاعت ${studentName}؟\nمش هينفع ترجّعها بعد كده.`,
      confirmLabel: "احذف الفاتورة",
      tone: "danger",
    });
    if (!ok) return;
    try {
      await deleteInvoice(id);
      toast.success("تم حذف الفاتورة", number);
      loadInvoices();
    } catch (err) {
      toast.error("فشل حذف الفاتورة", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">الفواتير</h1>
        <button onClick={() => setShowForm(!showForm)} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">{showForm ? "إخفاء" : "+ فاتورة جديدة"}</button>
      </div>

      {/* إحصائيات — كلها محسوبة من الداتابيز على كل الفواتير */}
      <div className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">عدد الفواتير</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">إجمالي الفواتير</p>
          <p className="mt-1 text-xl font-bold text-blue-800">{fmt(sums.total)}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">إجمالي المحصّل</p>
          <p className="mt-1 text-xl font-bold text-green-800">{fmt(sums.paid_amount)}</p>
        </div>
        <div className="rounded-xl bg-red-50 p-4 text-center">
          <p className="text-xs text-red-600">المتبقي</p>
          <p className="mt-1 text-xl font-bold text-red-800">{fmt(sums.balance_due)}</p>
        </div>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">إنشاء فاتورة</h2>
          <form onSubmit={handleCreate} className="space-y-4">
            <div className="grid grid-cols-2 gap-3">
              <input placeholder="معرّف الطالب" value={form.student_id} onChange={(e) => setForm({ ...form, student_id: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              <input type="date" value={form.issue_date} onChange={(e) => setForm({ ...form, issue_date: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              <input type="date" value={form.due_date} onChange={(e) => setForm({ ...form, due_date: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            </div>
            {form.items.map((item, i) => (
              <div key={i} className="grid grid-cols-3 gap-2">
                <input placeholder="الوصف" value={item.description} onChange={(e) => { const items = [...form.items]; items[i].description = e.target.value; setForm({ ...form, items }); }} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
                <input type="number" placeholder="الكمية" value={item.quantity} onChange={(e) => { const items = [...form.items]; items[i].quantity = Number(e.target.value); setForm({ ...form, items }); }} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
                <input type="number" placeholder="السعر" value={item.unit_price} onChange={(e) => { const items = [...form.items]; items[i].unit_price = Number(e.target.value); setForm({ ...form, items }); }} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              </div>
            ))}
            <button type="button" onClick={() => setForm({ ...form, items: [...form.items, { description: "", quantity: 1, unit_price: 0 }] })} className="text-sm text-blue-600">+ إضافة بند</button>
            <button type="submit" className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">إنشاء الفاتورة</button>
          </form>
        </div>
      )}
      {!loading && !error && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 text-start">الرقم</th>
                <th className="px-4 py-3 text-start">الطالب</th>
                <th className="px-4 py-3 text-start">الإجمالي</th>
                <th className="px-4 py-3 text-start">المدفوع</th>
                <th className="px-4 py-3 text-start">المتبقي</th>
                <th className="px-4 py-3 text-start">الحالة</th>
                <th className="px-4 py-3 text-start">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {invoices.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">لا توجد فواتير</td></tr>}
              {invoices.map((inv) => (
                <tr key={inv.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 text-slate-500">{inv.invoice_number}</td>
                  <td className="px-4 py-3 font-medium text-slate-800">{inv.student?.full_name ?? `طالب ${inv.student_id}`}</td>
                  {/* ⭐ `money` — الشكل القديم كان بيطبع `EGP`
                      إنجليزي ورقم من غير فاصلة آلاف */}
                  <td className="px-4 py-3 font-medium text-slate-800">{money(inv.total, inv.currency)}</td>
                  <td className="px-4 py-3 text-green-600">{money(inv.paid_amount, inv.currency)}</td>
                  <td className="px-4 py-3 text-red-600">{money(inv.balance_due, inv.currency)}</td>
                  <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs ${inv.status === "paid" ? "bg-green-100 text-green-700" : inv.status === "overdue" ? "bg-red-100 text-red-700" : inv.status === "partially_paid" ? "bg-amber-100 text-amber-700" : "bg-slate-100 text-slate-500"}`}>{STATUS_LABEL[inv.status]}</span></td>
                  <td className="px-4 py-3"><button onClick={() => handleDelete(inv.id, inv.invoice_number, inv.student?.full_name ?? "—")} className="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">حذف</button></td>
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
        itemLabel="فاتورة"
      />
    </div>
  );
}
