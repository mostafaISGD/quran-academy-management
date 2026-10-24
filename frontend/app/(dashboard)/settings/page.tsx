"use client";

import { useCallback, useState } from "react";
import {
  getSettings,
  saveSetting,
  deleteSetting,
  rollbackSetting,
  type SettingEntry,
} from "@/lib/api";
import { usePage } from "@/lib/use-page";
import { Modal, useUI, IconPencil, IconTrash } from "@/components/ui";

/**
 * ⭐⭐⭐ **PR 3.12 — صفحة الإعدادات على السجل الجديد.**
 *
 * ⭐ **قبلها** كانت الكتابة الحرة (أي مفتاح/قيمة) على شكل مسطح
 * `{key: value}`، وعرض القيمة الخام، والحذف بلا سجل.
 *
 * ⭐ **دلوقتي** الخلفية بيرجع `{key: {value, type, group, label,
 * default, min, max}}` — والقيم المكتوبة بأفعال من المفاتيح
 * المعرّفة في السجل (الأفكار: النوع = int/bool/string/list)،
 * + زر **الرجوع** لنسخة سابقة (سجل النسخ).
 *
 * ⭐ نوع القيمة بالحاقة لازم يتقوم من `elEntry.type`:
 *
 * - `int`  → رقم (بتحول `Number`).
 * - `bool` → قيمة منطقية.
 * - `list` → نص CSV أو مصفوفة.
 * - `string` → نص.
 */
export default function SettingsPage() {
  const [newKey, setNewKey] = useState("");
  const [newValue, setNewValue] = useState("");
  const [editingKey, setEditingKey] = useState<string | null>(null);
  const [editValue, setEditValue] = useState("");

  const { toast, confirm } = useUI();

  /** ⭐ مفاتيح التحرير: الحقل مباشر كنص — والتحويل قبل الإرسال حسب النوع. */
  function entryValueText(entry: SettingEntry | undefined): string {
    if (entry === undefined) return "";
    const value = entry.value;
    if (value === null || value === undefined) return "";
    return String(value);
  }

  /** ⭐ تحويل حسب النوع — `int` يبقى رقم، `bool` Boolean، الباقي سترينغ. */
  function typedValue(key: string, text: string, type?: string): unknown {
    if (type === "int") {
      const trimmed = text.trim();
      // وإيرادات الأرقام: رقم صحيح — الـbackend يرفض غيّه 422.
      return Number(trimmed);
    }

    if (type === "bool") {
      return text.trim().toLowerCase() === "true";
    }

    // list/string: نص (حد CSV — الخلفية بتحول CSV لمصفوفة).
    void key;
    return text;
  }

  const fetchSettings = useCallback(() => getSettings(), []);

  const {
    data: settings,
    loading,
    error,
    reload: loadSettings,
  } = usePage<Record<string, SettingEntry>>("settings", fetchSettings, {
    initial: {},
  });

  /** ⭐ تجميع بالـ`group` من السجل — المشهد: أقسام تحت بعض. */
  const grouped = (() => {
    const entries = settings ?? {};
    const groups: Array<{ group: string; keys: Array<{ key: string; entry: SettingEntry }> }> = [];
    const index = new Map<string, number>();
    for (const [key, entry] of Object.entries(entries)) {
      const group = entry?.group ?? "عام";
      if (!index.has(group)) {
        index.set(group, groups.length);
        groups.push({ group, keys: [] });
      }
      groups[index.get(group)!].keys.push({ key, entry });
    }
    return groups;
  })();

  async function handleSave(e: React.FormEvent) {
    e.preventDefault();
    if (!newKey.trim()) return;
    try {
      await saveSetting(newKey.trim(), newValue);
      toast.success("تم حفظ الإعداد", newKey.trim());
      setNewKey(""); setNewValue(""); loadSettings();
    } catch (err) {
      toast.error("فشل حفظ الإعداد", err instanceof Error ? err.message : undefined);
    }
  }

  async function handleUpdate(e: React.FormEvent) {
    e.preventDefault();
    if (!editingKey) return;
    try {
      const type = settings?.[editingKey]?.type;
      await saveSetting(editingKey, typedValue(editingKey, editValue, type));
      toast.success("تم تعديل الإعداد", editingKey);
      setEditingKey(null); setEditValue(""); loadSettings();
    } catch (err) {
      toast.error("فشل تعديل الإعداد", err instanceof Error ? err.message : undefined);
    }
  }

  async function handleDelete(key: string) {
    const ok = await confirm({
      title: "حذف الإعداد",
      message: `متأكد إنك عايز تحذف الإعداد «${key}»؟\nممكن ترجّع قيمته السابقة من «الرجوع».`,
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
    }
  }

  async function handleRollback(key: string) {
    try {
      await rollbackSetting(key);
      toast.success("تم الرجوع لقيمة سابقة", key);
      loadSettings();
    } catch (err) {
      toast.error("فشل الرجوع", err instanceof Error ? err.message : undefined);
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
            <h2 className="mb-4 text-sm font-semibold text-slate-800">تعديل إعداد</h2>
            <form onSubmit={handleSave} className="flex gap-3">
              <input placeholder="المفتاح (يكون معرّف في السجل)" value={newKey} onChange={(e) => setNewKey(e.target.value)} className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" required />
              <input placeholder="القيمة" value={newValue} onChange={(e) => setNewValue(e.target.value)} className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm" />
              <button type="submit" className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">حفظ</button>
            </form>
            <p className="mt-2 text-xs text-slate-400">مفاتيح غير معرّفة يرفضها الخادم — القواعد التجارية معرّفة في السجل (النوع والحدود والافتراضي).</p>
          </div>

          {Object.keys(settings ?? {}).length === 0 && <p className="text-slate-400">لا توجد إعدادات</p>}

          {grouped.map(({ group, keys }) => (
            <div key={group} className="mb-6">
              <h2 className="mb-3 text-sm font-semibold text-slate-700">{group}</h2>
              <div className="space-y-2">
                {keys.map(({ key, entry }) => (
                  <div key={key} className="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4">
                    <div>
                      <p className="text-sm font-medium text-slate-800">{entry.label}</p>
                      <p className="text-xs text-slate-400" dir="ltr">{key}</p>
                      <p className="text-xs text-slate-500">{entryValueText(entry) || "—"}</p>
                    </div>
                    <div className="flex gap-2">
                      <button onClick={() => { setEditingKey(key); setEditValue(entryValueText(entry)); }} className="inline-flex items-center gap-1 rounded-lg bg-blue-50 px-3 py-1.5 text-xs font-medium text-blue-700 transition hover:bg-blue-100"><IconPencil size={13} /> تعديل</button>
                      <button onClick={() => handleRollback(key)} className="rounded-lg bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-700 transition hover:bg-amber-100">رجوع</button>
                      <button onClick={() => handleDelete(key)} className="inline-flex items-center gap-1 rounded-lg bg-red-50 px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-100"><IconTrash size={13} /> حذف</button>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          ))}
        </>
      )}

      {editingKey && (
        <Modal
          open
          onClose={() => setEditingKey(null)}
          title={settings?.[editingKey]?.label ?? `تعديل: ${editingKey}`}
          icon={<IconPencil size={16} />}
          footer={
            <>
              <button type="button" onClick={() => setEditingKey(null)} className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50">إلغاء</button>
              <button type="submit" form="edit-setting-form" className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700">حفظ</button>
            </>
          }
        >
          <form id="edit-setting-form" onSubmit={handleUpdate}>
            <p className="mb-2 text-xs text-slate-400" dir="ltr">{editingKey}</p>
            <textarea
              autoFocus
              value={editValue}
              onChange={(e) => setEditValue(e.target.value)}
              className="w-full resize-y rounded-lg border border-slate-300 px-3 py-2.5 text-sm leading-relaxed outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
              rows={3}
            />
          </form>
        </Modal>
      )}
    </div>
  );
}
