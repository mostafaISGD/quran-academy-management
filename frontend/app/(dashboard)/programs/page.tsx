"use client";

import { useCallback, useEffect, useState } from "react";
import { getPrograms, createProgram, updateProgram, deleteProgram, type Program } from "@/lib/api";
import Pagination from "@/components/Pagination";
import { useUI } from "@/components/ui";

const PAGE_SIZE = 100;

export default function ProgramsPage() {
  const [programs, setPrograms] = useState<Program[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState({ name: "", slug: "", description: "", status: "active" as Program["status"] });

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [statusCounts, setStatusCounts] = useState<Record<string, number>>({});
  const [statusFilter, setStatusFilter] = useState("");

  const { toast, confirm } = useUI();

  const loadPrograms = useCallback(async (targetPage = 1, status = "") => {
    setLoading(true);
    setError(null);
    try {
      const r = await getPrograms({ per_page: PAGE_SIZE, page: targetPage, status: status || undefined });
      setPrograms(r.data);
      setMeta({ total: r.total, last_page: r.last_page });
      const c = r.counts as Record<string, unknown> | undefined;
      const st = c?.status;
      setStatusCounts((st && typeof st === "object" ? st : {}) as Record<string, number>);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { loadPrograms(1, statusFilter); }, [loadPrograms, statusFilter]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    loadPrograms(target, statusFilter);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  const refresh = () => loadPrograms(page, statusFilter);

  function openCreate() { setForm({ name: "", slug: "", description: "", status: "active" }); setEditingId(null); setShowForm(true); }
  function openEdit(p: Program) { setForm({ name: p.name, slug: p.slug, description: p.description ?? "", status: p.status }); setEditingId(p.id); setShowForm(true); }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingId) { await updateProgram(editingId, form); toast.success("تم تحديث البرنامج", form.name); }
      else { await createProgram(form); toast.success("تم إضافة البرنامج", form.name); }
      setShowForm(false); loadPrograms(1, statusFilter);
    } catch (err) { toast.error("فشل الحفظ", err instanceof Error ? err.message : undefined); setError(err instanceof Error ? err.message : "فشل الحفظ"); }
  }

  async function handleDelete(id: number, name: string) {
    const ok = await confirm({
      title: "حذف البرنامج",
      message: `متأكد إنك عايز تحذف البرنامج «${name}»؟\nالاشتراكات والحصص المرتبطة بيه مش هتتحذف.`,
      confirmLabel: "احذف البرنامج",
      tone: "danger",
    });
    if (!ok) return;
    try {
      await deleteProgram(id);
      toast.success("تم حذف البرنامج", name);
      loadPrograms(1, statusFilter);
    } catch (err) {
      toast.error("فشل حذف البرنامج", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  }

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">البرامج</h1>
          <p className="text-sm text-slate-500">{meta.total} برنامج في النظام</p>
        </div>
        <div className="flex items-center gap-2">
          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
          >
            <option value="">كل الحالات</option>
            <option value="active">نشط ({statusCounts.active ?? 0})</option>
            <option value="inactive">غير نشط ({statusCounts.inactive ?? 0})</option>
          </select>
          <button onClick={openCreate} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
            + برنامج جديد
          </button>
        </div>
      </div>

      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-3">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">إجمالي البرامج</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">نشط</p>
          <p className="mt-1 text-xl font-bold text-green-800">{statusCounts.active ?? 0}</p>
        </div>
        <div className="rounded-xl bg-red-50 p-4 text-center">
          <p className="text-xs text-red-600">غير نشط</p>
          <p className="mt-1 text-xl font-bold text-red-800">{statusCounts.inactive ?? 0}</p>
        </div>
      </div>

      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

      {showForm && (
        <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
          <h2 className="mb-4 text-sm font-semibold text-slate-800">{editingId ? "تعديل برنامج" : "إنشاء برنامج"}</h2>
          <form onSubmit={handleSubmit} className="grid grid-cols-2 gap-3">
            <input placeholder="اسم البرنامج" value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <input placeholder="Slug (إنجليزي)" value={form.slug} onChange={(e) => setForm({ ...form, slug: e.target.value })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
            <textarea placeholder="الوصف" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} className="col-span-2 rounded-lg border border-slate-300 px-3 py-2 text-sm" rows={2} />
            <select value={form.status} onChange={(e) => setForm({ ...form, status: e.target.value as Program["status"] })} className="rounded-lg border border-slate-300 px-3 py-2 text-sm">
              <option value="active">نشط</option><option value="inactive">غير نشط</option>
            </select>
            <div className="col-span-2 flex gap-2">
              <button type="submit" className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">حفظ</button>
              <button type="button" onClick={() => setShowForm(false)} className="rounded-lg bg-slate-100 px-4 py-2 text-sm text-slate-600 hover:bg-slate-200">إلغاء</button>
            </div>
          </form>
        </div>
      )}

      {!loading && !error && (
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
          {programs.map((p) => (
            <div key={p.id} className="rounded-xl border border-slate-200 bg-white p-4">
              <div className="mb-2 flex items-center justify-between">
                <h3 className="font-medium text-slate-800">{p.name}</h3>
                <div className="flex gap-1">
                  <button onClick={() => openEdit(p)} className="rounded bg-blue-50 px-2 py-1 text-xs text-blue-600 hover:bg-blue-100">تعديل</button>
                  <button onClick={() => handleDelete(p.id, p.name)} className="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-100">حذف</button>
                </div>
              </div>
              {p.description && <p className="mb-3 text-sm text-slate-500">{p.description}</p>}
              <div className="flex items-center justify-between">
                <span className={`rounded-full px-2 py-1 text-xs ${p.status === "active" ? "bg-green-100 text-green-700" : "bg-red-100 text-red-700"}`}>{p.status === "active" ? "نشط" : "غير نشط"}</span>
                {p.levels && <span className="text-xs text-slate-400">{p.levels.length} مستويات</span>}
              </div>
            </div>
          ))}
        </div>
      )}

      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="برنامج"
      />
    </div>
  );
}
