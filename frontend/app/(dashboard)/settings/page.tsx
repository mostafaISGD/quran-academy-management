"use client";

import { useEffect, useState } from "react";
import { getSettings, saveSetting, deleteSetting } from "@/lib/api";

export default function SettingsPage() {
  const [settings, setSettings] = useState<Record<string, unknown>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [newKey, setNewKey] = useState("");
  const [newValue, setNewValue] = useState("");
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [editValue, setEditValue] = useState("");

  function loadSettings() {
    setLoading(true);
    getSettings()
      .then(setSettings)
      .catch((err) => setError(err instanceof Error ? err.message : "تعذر التحميل"))
      .finally(() => setLoading(false));
  }

  useEffect(() => { loadSettings(); }, []);

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!newKey.trim()) return;
    try { await saveSetting(newKey.trim(), newValue); setNewKey(""); setNewValue(""); loadSettings(); }
    catch (err) { setError(err instanceof Error ? err.message : "فشل"); }
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault();
    if (!editingKey) return;
    try { await saveSetting(editingKey, editValue); setEditingKey(null); setEditValue(""); loadSettings(); }
    catch (err) { setError(err instanceof Error ? err.message : "فشل"); }
  }

  async function handleDelete(key: string) {
    if (!confirm(`حذف "${key}"؟`)) return;
    try { await deleteSetting(key); loadSettings(); } catch (err) { setError(err instanceof Error ? err.message : "فشل"); }
  }

  return (
    <div>
      <h1 className="mb-6 text-lg font-semibold text-slate-800">الإعدادات</h1>
      {loading && <p className="text-sm text-slate-500">جارٍ التحميل...</p>}
      {error && <p className="mb-4 text-sm text-red-600">{error}</p>}
      {!loading && !error && (
        <>
          <div className="mb-8 rounded-xl border border-slate-200 bg-white p-5">
            <h2 className="mb-4 text-sm font-semibold text-slate-800">إضافة إعداد</h2>
            <form onSubmit={handleSave} className="flex gap-3">
              <input placeholder="المفتاح" value={newKey} onChange={(e) => setNewKey(e.target.value)} className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              <input placeholder="القيمة" value={newValue} onChange={(e) => setNewValue(e.target.value)} className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              <button type="submit" className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">حفظ</button>
            </form>
          </div>
          <div className="space-y-2">
            {Object.keys(settings).length === 0 && <p className="text-slate-400">لا توجد إعدادات</p>}
            {Object.entries(settings).map(([key, value]) => (
              <div key={key} className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                <div>
                  <p className="text-sm font-medium text-slate-800">{key}</p>
                  <p className="text-xs text-slate-500">{typeof value === "string" ? value : JSON.stringify(value)}</p>
                </div>
                <div className="flex gap-2">
                  <button onClick={() => { setEditingKey(key); setEditValue(typeof value === "string" ? value : JSON.stringify(value)); }} className="rounded bg-blue-50 px-3 py-1 text-xs text-blue-600 hover:bg-blue-100">تعديل</button>
                  <button onClick={() => handleDelete(key)} className="rounded bg-red-50 px-3 py-1 text-xs text-red-600 hover:bg-red-100">حذف</button>
                </div>
              </div>
            ))}
          </div>
        </>
      )}
      {editingKey && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
          <div className="w-full max-w-sm rounded-xl bg-white p-6">
            <h3 className="mb-4 text-lg font-semibold text-slate-800">تعديل {editingKey}</h3>
            <form onSubmit={handleUpdate} className="space-y-4">
              <textarea value={editValue} onChange={(e) => setEditValue(e.target.value)} className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" rows={4} />
              <div className="flex gap-2">
                <button type="submit" className="flex-1 rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700">حفظ</button>
                <button type="button" onClick={() => setEditingKey(null)} className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 hover:bg-slate-200">إلغاء</button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
}
