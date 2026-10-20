"use client";

import { useCallback, useState } from "react";
import {
  ApiError,
  createExpenseCategory,
  deleteExpenseCategory,
  getExpenseCategories,
  updateExpenseCategory,
  type ExpenseCategory,
} from "@/lib/api";
import { usePage } from "@/lib/use-page";

/**
 * ⭐ إدارة فئات المصروفات — قائمة + إضافة + تعديل + حذف.
 *
 * ⭐ الفئة المربوطة بمصروفات مش بتتحذف (الـbackend بيرجّع
 * 409) — فالحذف الفاشل بيرجّع رسالة واضحة بدل ما يفشل بصمت.
 */
export default function ExpenseCategoryManager({
  open,
  onClose,
}: {
  open: boolean;
  onClose: () => void;
}) {
  const fetchCategories = useCallback(() => getExpenseCategories(), []);
  const { data, loading, reload } = usePage<ExpenseCategory[]>(
    "expense-categories",
    fetchCategories,
    { initial: [] },
  );

  const [name, setName] = useState("");
  const [saving, setSaving] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
  /** ⭐ الفئة اللي اختار تعديل عليها */
  const [rename, setRename] = useState<{ id: number; name: string } | null>(null);

  if (!open) return null;

  async function handleCreate(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setActionError(null);
    try {
      await createExpenseCategory({ name: name.trim() });
      setName("");
      reload();
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "فشل الإضافة");
    } finally {
      setSaving(false);
    }
  }

  async function handleRename(e: React.FormEvent) {
    e.preventDefault();
    if (!rename) return;
    setSaving(true);
    setActionError(null);
    try {
      await updateExpenseCategory(rename.id, {
        name: rename.name,
      });
      setRename(null);
      reload();
    } catch (err) {
      setActionError(err instanceof Error ? err.message : "فشل التعديل");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(cat: ExpenseCategory) {
    setActionError(null);
    try {
      await deleteExpenseCategory(cat.id);
      reload();
    } catch (err) {
      setActionError(
        err instanceof ApiError && err.status === 409
          ? `«${cat.name}» مربوطة بمصروفات — مش بتتحذف`
          : err instanceof Error
            ? err.message
            : "فشل الحذف",
      );
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="max-h-[80vh] w-full max-w-md overflow-y-auto rounded-xl bg-white p-5 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-sm font-semibold text-slate-800">فئات المصروفات</h2>
          <button onClick={onClose} className="text-sm text-slate-500 hover:text-slate-700">
            إغلاق ✕
          </button>
        </div>

        {actionError && (
          <div className="mb-3 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{actionError}</div>
        )}

        <form onSubmit={handleCreate} className="mb-4 flex gap-2">
          <input
            required placeholder="اسم الفئة الجديدة"
            value={name}
            onChange={(e) => setName(e.target.value)}
            className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
          />
          <button
            type="submit"
            disabled={saving}
            className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
          >
            إضافة
          </button>
        </form>

        {loading ? (
          <div className="space-y-2">
            {[0, 1, 2].map((i) => (
              <div key={i} className="h-10 animate-pulse rounded-lg bg-slate-100" />
            ))}
          </div>
        ) : (
          <ul className="space-y-2">
            {data.map((cat) => (
              <li
                key={cat.id}
                className="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2"
              >
                <div className="min-w-0">
                  {rename?.id === cat.id ? (
                    <form onSubmit={handleRename} className="flex gap-2">
                      <input
                        required
                        value={rename.name}
                        onChange={(e) => setRename({ ...rename, name: e.target.value })}
                        className="w-36 rounded border border-slate-300 px-2 py-1 text-sm"
                      />
                      <button type="submit" className="text-xs text-green-700 underline">حفظ</button>
                      <button type="button" onClick={() => setRename(null)} className="text-xs text-slate-500 underline">
                        إلغاء
                      </button>
                    </form>
                  ) : (
                    <p className="truncate text-sm text-slate-700">{cat.name}</p>
                  )}
                  <p className="text-xs text-slate-400">
                    {cat.expenses_count ?? 0} مصروف
                    {cat.status === "inactive" ? " · غير نشطة" : ""}
                  </p>
                </div>
                <div className="flex gap-2 text-xs">
                  <button
                    onClick={() => setRename({ id: cat.id, name: cat.name })}
                    className="text-blue-600 underline hover:text-blue-700"
                  >
                    تعديل
                  </button>
                  <button
                    onClick={() => handleDelete(cat)}
                    className="text-red-600 underline hover:text-red-700"
                  >
                    حذف
                  </button>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
