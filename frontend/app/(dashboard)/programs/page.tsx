"use client";

import { useEffect, useState } from "react";
import { getPrograms, createProgram, updateProgram, deleteProgram, type Program } from "@/lib/api";

export default function ProgramsPage() {
  const [programs, setPrograms] = useState<Program[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);
  const [form, setForm] = useState({ name: "", slug: "", description: "", status: "active" as Program["status"] });

  function loadPrograms() {
    setLoading(true);
    getPrograms()
      .then((r) => setPrograms(r.data))
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر تحميل البيانات"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadPrograms(); }, []);

  function openCreate() { setForm({ name: "", slug: "", description: "", status: "active" }); setEditingId(null); setShowForm(true); }
  function openEdit(p: Program) { setForm({ name: p.name, slug: p.slug, description: p.description ?? "", status: p.status }); setEditingId(p.id); setShowForm(true); }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    try {
      if (editingId) { await updateProgram(editingId, form); } else { await createProgram(form); }
      setShowForm(false); loadPrograms();
    } catch (err) { setError(err instanceof Error ? err.message : "فشل الحفظ"); }
  }

  async function handleDelete(id: number) {
    if (!confirm("هل أنت متأكد من حذف هذا البرنامج؟")) return;
    try { await deleteProgram(id); loadPrograms(); } catch (err) { setError(err instanceof Error ? err.message : "فشل الحذف"); }
  }

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <h1 className="text-lg font-semibold text-slate-800">البرامج</h1>
        <button onClick={openCreate} className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">+ برنامج جديد</button>
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
                  <button onClick={() => handleDelete(p.id)} className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100">حذف</button>
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
    </div>
  );
}
