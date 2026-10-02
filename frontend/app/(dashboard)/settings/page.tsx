"use client";

import { useEffect, useState } from "react";
import { getSettings, saveSetting, deleteSetting } from "@/lib/api";
import { Modal, useUI, IconPencil, IconTrash } from "@/components/ui";

export default function SettingsPage() {
  const [settings, setSettings] = useState<Record<string, unknown>>({});
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [newKey, setNewKey] = useState("");
  const [newValue, setNewValue] = useState("");
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [editValue, setEditValue] = useState("");

  const { toast, confirm } = useUI();

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
    try {
      await saveSetting(newKey.trim(), newValue);
      toast.success("تم حفظ الإعداد", newKey.trim());
      setNewKey(""); setNewValue(""); loadSettings();
    } catch (err) {
      toast.error("فشل حفظ الإعداد", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل");
    }
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault();
    if (!editingKey) return;
    try {
      await saveSetting(editingKey, editValue);
      toast.success("تم تعديل الإعداد", editingKey);
      setEditingKey(null); setEditValue(""); loadSettings();
    } catch (err) {
      toast.error("فشل تعديل الإعداد", err instanceof Error ? err.message : undefined);
    }
  }

  async function handleDelete(key: string) {
    const ok = await confirm({
      title: "حذف الإعداد",
      message: `متأكد إنك عايز تحذف الإعداد «${key}»؟\nمش هينفع ترجّعه بعد كده.`,
      confirmLabel: "احذف",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;

    try {
      await deleteSetting(key);
      toast.success("تم حذف الإعداد", key);
      loadSettings();
    } catch (err) {
      toast.error("فشل حذف الإعداد", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل");
    }
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
                  <button onClick={() => { setEditingKey(key); setEditValue(typeof value === "string" ? value : JSON.stringify(value)); }} className="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition hover:bg-blue-100"><IconPencil size={13} /> تعديل</button>
                  <button onClick={() => handleDelete(key)} className="inline-flex items-center gap-1 rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-100"><IconTrash size={13} /> حذف</button>
                </div>
              </div>
            ))}
          </div>
        </>
      )}

      {editingKey && (
        <Modal
          open
          onClose={() => setEditingKey(null)}
          title={`تعديل: ${editingKey}`}
          icon={<IconPencil size={16} />}
          footer={
            <>
              <button type="button" onClick={() => setEditingKey(null)} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">إلغاء</button>
              <button type="submit" form="edit-setting-form" className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">حفظ</button>
            </>
          }
        >
          <form id="edit-setting-form" onSubmit={handleUpdate}>
            <textarea
              autoFocus
              value={editValue}
              onChange={(e) => setEditValue(e.target.value)}
              className="w-full resize-y rounded-lg border border-slate-300 px-3 py-2.5 text-sm leading-relaxed outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
              rows={5}
            />
          </form>
        </Modal>
      )}
    </div>
  );
}
