"use client";

import { useCallback, useEffect, useState } from "react";
import { getLeads, createLead, updateLeadStatus, deleteLead, type Lead } from "@/lib/api";
import Pagination from "@/components/Pagination";
import { useUI } from "@/components/ui";

const PAGE_SIZE = 100;

const STATUS_LABEL: Record<Lead["status"], string> = {
  new: "جديد", contacted: "تم التواصل", qualified: "مؤهل", trial_booked: "حجز تجريبي",
  trial_completed: "اكتمل التجريبي", offer_sent: "تم إرسال العرض", converted: "تم التحويل", lost: "مفقود",
};

const STATUS_COLORS: Record<Lead["status"], string> = {
  new: "bg-blue-100 text-blue-700", contacted: "bg-indigo-100 text-indigo-700", qualified: "bg-purple-100 text-purple-700",
  trial_booked: "bg-amber-100 text-amber-700", trial_completed: "bg-teal-100 text-teal-700",
  offer_sent: "bg-cyan-100 text-cyan-700", converted: "bg-green-100 text-green-700", lost: "bg-red-100 text-red-700",
};

const SOURCE_LABEL: Record<string, string> = { facebook: "فيسبوك", instagram: "إنستغرام", website: "الموقع", referral: "إحالة", other: "أخرى" };

export default function LeadsPage() {
  const [leads, setLeads] = useState<Lead[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [filterStatus, setFilterStatus] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [form, setForm] = useState({ full_name: "", phone: "", email: "", country_code: "", student_age: "", interested_program_id: "", source: "" as Lead["source"] | "" });

  const { toast, confirm } = useUI();

  const loadLeads = useCallback(async (targetPage = 1, status = "", q = "") => {
    setLoading(true);
    setError(null);
    try {
      const r = await getLeads({
        per_page: PAGE_SIZE,
        page: targetPage,
        status: status || undefined,
        search: q.trim() || undefined,
      });
      setLeads(r.data);
      setMeta({ total: r.total, last_page: r.last_page });
      // counts متداخلة تحت اسم العمود — راجع صفحة الاشتراكات
      const c = r.counts as Record<string, unknown> | undefined;
      const st = c?.status;
      setCounts((st && typeof st === "object" ? st : {}) as Record<string, number>);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    const t = setTimeout(() => { setPage(1); loadLeads(1, filterStatus, search); }, 300);
    return () => clearTimeout(t);
  }, [loadLeads, filterStatus, search]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    loadLeads(target, filterStatus, search);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  const refresh = () => loadLeads(page, filterStatus, search);

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    try { await createLead({ ...form, student_age: form.student_age ? Number(form.student_age) : undefined, source: form.source || undefined, email: form.email || undefined, country_code: form.country_code || undefined, interested_program_id: form.interested_program_id || undefined }); toast.success("تمت إضافة العميل المحتمل", form.full_name); setShowForm(false); setForm({ full_name: "", phone: "", email: "", country_code: "", student_age: "", interested_program_id: "", source: "" }); refresh(); }
    catch (err) { toast.error("فشل إنشاء العميل المحتمل", err instanceof Error ? err.message : undefined); setError(err instanceof Error ? err.message : "فشل إنشاء الـ Lead"); }
  }

  async function handleStatusChange(leadId: number, status: Lead["status"]) {
    try { await updateLeadStatus(leadId, status); refresh(); }
    catch (err) { toast.error("فشل تحديث الحالة", err instanceof Error ? err.message : undefined); setError(err instanceof Error ? err.message : "فشل تحديث الحالة"); }
  }

  async function handleDelete(id: number, name: string) {
    const ok = await confirm({
      title: "حذف العميل المحتمل",
      message: `متأكد إنك عايز تحذف «${name}» من قائمة العملاء المحتملين؟`,
      confirmLabel: "احذف",
      tone: "danger",
    });
    if (!ok) return;
    try {
      await deleteLead(id);
      toast.success("تم حذف العميل المحتمل", name);
      refresh();
    } catch (err) {
      toast.error("فشل الحذف", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">العملاء المحتملين (Leads)</h1>
        <button onClick={() => setShowForm(!showForm)} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">{showForm ? "إخفاء" : "+ Lead جديد"}</button>
      </div>
      <div className="mb-4 flex flex-wrap gap-2">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="🔎 ابحث بالاسم أو الهاتف أو البريد..."
          className="min-w-[220px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />
        <select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
          <option value="">كل الحالات</option>
          {Object.entries(STATUS_LABEL).map(([key, label]) => (
            <option key={key} value={key}>{label} ({counts[key] ?? 0})</option>
          ))}
        </select>
      </div>

      {/* إحصائيات — من الداتابيز على كل العملاء المحتملين */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">إجمالي العملاء</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">جديد</p>
          <p className="mt-1 text-xl font-bold text-blue-800">{counts.new ?? 0}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">تم التحويل</p>
          <p className="mt-1 text-xl font-bold text-green-800">{counts.converted ?? 0}</p>
        </div>
        <div className="rounded-xl bg-red-50 p-4 text-center">
          <p className="text-xs text-red-600">مفقود</p>
          <p className="mt-1 text-xl font-bold text-red-800">{counts.lost ?? 0}</p>
        </div>
      </div>
      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">إنشاء Lead</h2>
          <form onSubmit={handleCreate} className="grid grid-cols-2 gap-3">
            <input placeholder="الاسم" value={form.full_name} onChange={(e) => setForm({ ...form, full_name: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="الهاتف" value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="البريد" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input placeholder="كود الدولة" value={form.country_code} onChange={(e) => setForm({ ...form, country_code: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <input type="number" placeholder="عمر الطالب" value={form.student_age} onChange={(e) => setForm({ ...form, student_age: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" />
            <select value={form.source ?? ""} onChange={(e) => setForm({ ...form, source: e.target.value as Lead["source"] })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="">المصدر</option>
              {Object.entries(SOURCE_LABEL).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
            </select>
            <button type="submit" className="col-span-2 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">إنشاء</button>
          </form>
        </div>
      )}
      {!loading && !error && (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
              <tr>
                <th className="px-4 py-3 text-start">الاسم</th>
                <th className="px-4 py-3 text-start">الهاتف</th>
                <th className="px-4 py-3 text-start">البريد</th>
                <th className="px-4 py-3 text-start">البرنامج</th>
                <th className="px-4 py-3 text-start">المصدر</th>
                <th className="px-4 py-3 text-start">الحالة</th>
                <th className="px-4 py-3 text-start">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {leads.length === 0 && <tr><td colSpan={7} className="px-4 py-6 text-center text-slate-400">لا يوجد Leads</td></tr>}
              {leads.map((lead) => (
                <tr key={lead.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-4 py-3 font-medium text-slate-800">{lead.full_name}</td>
                  <td className="px-4 py-3 text-slate-600" dir="ltr" style={{textAlign:'right'}}>{lead.phone}</td>
                  <td className="px-4 py-3 text-slate-600">{lead.email ?? "—"}</td>
                  <td className="px-4 py-3 text-slate-600">{lead.program?.name ?? "—"}</td>
                  <td className="px-4 py-3 text-slate-600">{lead.source ? SOURCE_LABEL[lead.source] : "—"}</td>
                  <td className="px-4 py-3"><span className={`rounded-full px-2 py-1 text-xs ${STATUS_COLORS[lead.status]}`}>{STATUS_LABEL[lead.status]}</span></td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-1">
                      <select value={lead.status} onChange={(e) => handleStatusChange(lead.id, e.target.value as Lead["status"])} className="rounded border border-slate-200 px-2 py-1 text-xs">
                        {Object.entries(STATUS_LABEL).map(([key, label]) => <option key={key} value={key}>{label}</option>)}
                      </select>
                      <button onClick={() => handleDelete(lead.id, lead.full_name)} className="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100">حذف</button>
                    </div>
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
        itemLabel="عميل محتمل"
      />
    </div>
  );
}
