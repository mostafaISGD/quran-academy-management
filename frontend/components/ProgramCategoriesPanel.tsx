"use client";

import { useState } from "react";
import {
  createProgramCategory,
  deleteProgramCategory,
  updateProgramCategory,
  type ProgramCategory,
} from "@/lib/api";
import { useUI } from "@/components/ui";
import { Empty, Pill } from "./ProgramPrimitives";

/**
 * إدارة تصنيفات البرامج.
 *
 * دي الشاشة اللي بتخلي التصنيفات «قابلة للإدارة من النظام بدل تثبيتها
 * داخل الكود» — بتضيف «التفسير» أو «الفقه» من هنا من غير ما تلمس
 * الكود أو تعمل deployment.
 *
 * القائمة بتيجي من الأب (مع شاشة البرامج) — متكررش الـ fetch هنا.
 */

/** الشكل اللي محتاجه الشاشة بس — نفس اللي بيرجع مع قائمة البرامج */
type Row = Pick<ProgramCategory, "id" | "name" | "slug" | "icon" | "programs_count">;

const GROUP_LABEL: Record<string, string> = {
  type: "نوع الخدمة",
  field: "المجال",
  audience: "الفئة المستهدفة",
  level: "المستوى",
  other: "تصنيفات أخرى",
};

export default function ProgramCategoriesPanel({ items, onClose, onChanged }: {
  /** الأب بيجيبهم مع قائمة البرامج */
  items: Row[];
  onClose: () => void;
  /** بعد أي إضافة/تعديل/حذف — عشان الأب يعمل refetch */
  onChanged: () => void;
}) {
  const { toast, confirm } = useUI();

  const [adding, setAdding] = useState(false);
  const [name, setName] = useState("");
  const [icon, setIcon] = useState("");
  const [saving, setSaving] = useState(false);
  const [editingId, setEditingId] = useState<number | null>(null);

  async function handleAdd(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim()) return;

    setSaving(true);
    try {
      await createProgramCategory({ name: name.trim(), icon: icon.trim() || null });
      toast.success("تمت إضافة التصنيف", name.trim());
      setName(""); setIcon(""); setAdding(false);
      onChanged();
    } catch (err) {
      toast.error("فشل الإضافة", err instanceof Error ? err.message : undefined);
    } finally {
      setSaving(false);
    }
  }

  async function handleRename(c: Row) {
    if (!name.trim()) return;
    setSaving(true);
    try {
      await updateProgramCategory(c.id, { name: name.trim(), icon: icon.trim() || null });
      toast.success("تم التحديث", name.trim());
      setEditingId(null); setName(""); setIcon("");
      onChanged();
    } catch (err) {
      toast.error("فشل التحديث", err instanceof Error ? err.message : undefined);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(c: Row) {
    const used = c.programs_count ?? 0;
    const ok = await confirm({
      title: "حذف التصنيف",
      message: used > 0
        ? `«${c.name}» متربوط بـ ${used} برنامج. الحذف هيفك الربط بس مش هيأثر على البرامج نفسها. متأكد؟`
        : `متأكد إنك عايز تحذف «${c.name}»؟`,
      confirmLabel: "احذف التصنيف",
      tone: "danger",
    });
    if (!ok) return;

    try {
      const res = await deleteProgramCategory(c.id);
      toast.success("تم حذف التصنيف", c.name);
      if (res.detached_from_programs > 0) {
        toast.info(`اتفصل من ${res.detached_from_programs} برنامج`, "البرامج نفسها لسه موجودة");
      }
      onChanged();
    } catch (err) {
      toast.error("فشل الحذف", err instanceof Error ? err.message : undefined);
    }
  }

  function startEdit(c: Row) {
    setEditingId(c.id);
    setName(c.name);
    setIcon(c.icon ?? "");
  }

  function cancelEdit() {
    setEditingId(null); setName(""); setIcon(""); setAdding(false);
  }

  const grouped = items.reduce<Record<string, Row[]>>((acc, c) => {
    const key = ["type", "field", "audience", "level"].find((p) => c.slug.startsWith(`${p}-`)) ?? "other";
    (acc[key] ??= []).push(c);
    return acc;
  }, {});

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="max-h-[88vh] w-full max-w-lg overflow-y-auto rounded-xl bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <div>
            <h2 className="font-semibold text-slate-800">تصنيفات البرامج</h2>
            <p className="text-xs text-slate-500">
              من هنا بتضيف تصنيف جديد — مش محتاج تعديل الكود
            </p>
          </div>
          <button
            onClick={onClose}
            className="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-200"
          >
            إغلاق
          </button>
        </div>

        <div className="space-y-4 p-5">
          {/* ===== إضافة ===== */}
          {editingId === null && !adding && (
            <button
              onClick={() => setAdding(true)}
              className="w-full rounded-lg border border-dashed border-slate-300 py-2.5 text-sm text-slate-500 hover:border-slate-500 hover:text-slate-700"
            >
              + إضافة تصنيف
            </button>
          )}

          {adding && (
            <form onSubmit={handleAdd} className="rounded-lg border border-slate-200 bg-slate-50 p-3">
              <div className="mb-2 flex gap-2">
                <input
                  value={icon}
                  onChange={(e) => setIcon(e.target.value)}
                  placeholder="أيقونة"
                  maxLength={20}
                  className="w-16 rounded-lg border border-slate-300 px-2 py-1.5 text-center text-sm"
                />
                <input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="اسم التصنيف"
                  maxLength={100}
                  autoFocus
                  className="flex-1 rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                  required
                />
              </div>
              <div className="flex justify-end gap-2">
                <button
                  type="button" onClick={cancelEdit}
                  className="rounded-lg bg-white px-3 py-1.5 text-xs text-slate-600 hover:bg-slate-100"
                >
                  إلغاء
                </button>
                <button
                  type="submit" disabled={saving || !name.trim()}
                  className="rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-700 disabled:opacity-40"
                >
                  إضافة
                </button>
              </div>
            </form>
          )}

          {/* ===== القائمة ===== */}
          {items.length === 0 ? (
            <Empty text="مفيش تصنيفات لسه" hint="ابدأ بإضافة أول تصنيف" />
          ) : (
            Object.entries(grouped).map(([group, list]) => (
              <div key={group}>
                <p className="mb-1.5 text-xs font-medium text-slate-600">{GROUP_LABEL[group]}</p>
                <ul className="space-y-1">
                  {list.map((c) => (
                    <li
                      key={c.id}
                      className="flex items-center gap-2 rounded-lg border border-slate-100 px-3 py-2"
                    >
                      {editingId === c.id ? (
                        <form
                          onSubmit={(e) => { e.preventDefault(); handleRename(c); }}
                          className="flex w-full gap-2"
                        >
                          <input
                            value={icon}
                            onChange={(e) => setIcon(e.target.value)}
                            placeholder="أيقونة"
                            maxLength={20}
                            className="w-14 rounded-lg border border-slate-300 px-2 py-1 text-center text-sm"
                          />
                          <input
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            maxLength={100}
                            autoFocus
                            className="flex-1 rounded-lg border border-slate-300 px-2 py-1 text-sm"
                          />
                          <button
                            type="submit" disabled={saving || !name.trim()}
                            className="rounded-lg bg-slate-800 px-2.5 py-1 text-xs text-white disabled:opacity-40"
                          >
                            حفظ
                          </button>
                          <button
                            type="button" onClick={cancelEdit}
                            className="rounded-lg bg-slate-100 px-2.5 py-1 text-xs text-slate-600"
                          >
                            إلغاء
                          </button>
                        </form>
                      ) : (
                        <>
                          <span className="text-base">{c.icon ?? "•"}</span>
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-medium text-slate-800">{c.name}</p>
                            <p className="truncate text-[11px] text-slate-400" dir="ltr">{c.slug}</p>
                          </div>
                          {c.programs_count !== undefined && (
                            <Pill tone={c.programs_count > 0 ? "blue" : "slate"}>
                              {c.programs_count} برنامج
                            </Pill>
                          )}
                          <button
                            onClick={() => startEdit(c)}
                            className="shrink-0 rounded bg-blue-50 px-2 py-1 text-xs text-blue-600 hover:bg-blue-100"
                          >
                            تعديل
                          </button>
                          <button
                            onClick={() => handleDelete(c)}
                            className="shrink-0 rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100"
                          >
                            حذف
                          </button>
                        </>
                      )}
                    </li>
                  ))}
                </ul>
              </div>
            ))
          )}
        </div>
      </div>
    </div>
  );
}