"use client";

import { useEffect, useState } from "react";
import {
  createProgram,
  previewProgramSlug,
  updateProgram,
  type Program,
  type ProgramCategory,
  type ProgramCategoryFilter,
  type SlugPreview,
} from "@/lib/api";
import { useUI } from "@/components/ui";

/**
 * نموذج إضافة/تعديل برنامج.
 *
 * التصنيفات بتيجي من الجدول (program_categories) مش مكتوبة في الكود —
 * عشان الأكاديمية تقدر تضيف «التفسير» أو «الفقه» من غير deployment.
 */

const PRESET_COLORS = [
  "#059669", "#2563eb", "#7c3aed", "#ea580c",
  "#0891b2", "#be123c", "#64748b", "#4f46e5",
];

const SLUG_HINT: Record<string, string> = {
  "type-": "نوع الخدمة — إيه البرنامج؟ (تحفيظ / تجويد / تلاوة)",
  "field-": "المجال — إيه المادة؟ (قرآن كريم / فقه / عقيدة)",
  "audience-": "الفئة المستهدفة — مين الطالب؟",
  "level-": "مستوى البرنامج — من فين الطالب؟",
};

export default function ProgramFormModal({
  program, categories, onClose, onSaved,
}: {
  /** null = إنشاء جديد */
  program: Program | null;
  categories: ProgramCategoryFilter[];
  onClose: () => void;
  onSaved: () => void;
}) {
  const { toast } = useUI();

  // الحالة بتت initializing من الـ props مباشرة (مش في useEffect) —
  // الأب بيعمل remount بـ key لما البرنامج يتغيّر.
  const [name, setName] = useState(program?.name ?? "");
  const [slug, setSlug] = useState(program?.slug ?? "");
  const [description, setDescription] = useState(program?.description ?? "");
  const [imageUrl, setImageUrl] = useState(program?.image_url ?? "");
  const [color, setColor] = useState(program?.color ?? "#2563eb");
  const [status, setStatus] = useState<"active" | "inactive">(program?.status ?? "active");
  const [selected, setSelected] = useState<number[]>(program?.categories?.map((c) => c.id) ?? []);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // معاينة الـ slug — السيرفر هو اللي بيحوّل العربي لـ latin، فبناديه
  // هو مش نعمل نسخة في الـ frontend (اختلاف مضمون بعدين).
  const [slugPreview, setSlugPreview] = useState<SlugPreview | null>(
    program ? { name: program.name, suggested: program.slug, slug: program.slug, is_custom: true, is_fallback: false } : null,
  );

  useEffect(() => {
    if (!name.trim()) return;
    let active = true;
    // debounce عشان كل حرف متعملش request
    const t = setTimeout(() => {
      previewProgramSlug({
        name: name.trim(),
        slug: slug.trim() || null,
        ignoreId: program?.id,
      })
        .then((p) => { if (active) setSlugPreview(p); })
        .catch(() => { if (active) setSlugPreview(null); });
    }, 250);
    return () => { active = false; clearTimeout(t); };
  }, [name, slug, program?.id]);

  function toggleCategory(id: number) {
    setSelected((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
  }

  /** التصنيفات متجمّعة حسب النوع عشان العرض يبقى مفهوم */
  const grouped = categories.reduce<Record<string, ProgramCategoryFilter[]>>((acc, c) => {
    const key = Object.keys(SLUG_HINT).find((p) => c.slug.startsWith(p)) ?? "other";
    (acc[key] ??= []).push(c);
    return acc;
  }, {});

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!name.trim()) {
      setError("اسم البرنامج مطلوب");
      return;
    }

    setSaving(true);
    setError(null);
    try {
      const payload = {
        name: name.trim(),
        slug: slug.trim() || undefined,
        description: description.trim() || null,
        image_url: imageUrl.trim() || null,
        color,
        status,
        category_ids: selected,
      };

      if (program) {
        await updateProgram(program.id, payload);
        toast.success("تم تحديث البرنامج", name.trim());
      } else {
        await createProgram(payload);
        toast.success("تم إضافة البرنامج", name.trim());
      }

      onSaved();
      onClose();
    } catch (err) {
      const msg = err instanceof Error ? err.message : "فشل الحفظ";
      setError(msg);
      toast.error("فشل الحفظ", msg);
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
      <div className="max-h-[92vh] w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <h2 className="font-semibold text-slate-800">
            {program ? "تعديل البرنامج" : "إضافة برنامج"}
          </h2>
          <button
            onClick={onClose}
            className="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-200"
          >
            إغلاق
          </button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-4 p-5">
          {/* ===== البيانات الأساسية ===== */}
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-slate-600">اسم البرنامج *</span>
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                maxLength={150}
                placeholder="مثال: حفظ القرآن الكريم"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
                required
              />
            </label>

            <label className="block">
              <span className="mb-1 block text-xs font-medium text-slate-600">المعرّف (slug)</span>
              <input
                value={slug}
                onChange={(e) => setSlug(e.target.value)}
                maxLength={150}
                placeholder="مثال: tahfeeq"
                dir="ltr"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </label>
          </div>

          {/* معاينة الـ slug — عشان المستخدم يشوف هيتبعت إيه ويقدر
              يغيّره بدل ما يفاجأ بعد الحفظ. التحويل عربي→latin بيحصل
              على السيرفر عشان يفضل في مكان واحد. */}
          {name.trim() && slugPreview && (
            <div
              className={`flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border px-3 py-2 text-xs ${
                slugPreview.is_fallback
                  ? "border-amber-200 bg-amber-50 text-amber-800"
                  : "border-slate-200 bg-slate-50 text-slate-600"
              }`}
            >
              <span className="text-slate-500">
                {slugPreview.is_custom ? "هيتحفظ باسم:" : "بيتولّد تلقائياً من الاسم:"}
              </span>
              <code className="rounded bg-white px-1.5 py-0.5 font-mono" dir="ltr">
                {slugPreview.slug || "—"}
              </code>
              {slugPreview.is_fallback && (
                <span>الاسم مفيهوش حروف إنجليزية — اكتب معرّف بنفسك.</span>
              )}
              {!slugPreview.is_custom && slugPreview.suggested !== slugPreview.slug && (
                <span className="text-slate-400">
                  (مقترح: <code dir="ltr">{slugPreview.suggested}</code>)
                </span>
              )}
            </div>
          )}

          <label className="block">
            <span className="mb-1 block text-xs font-medium text-slate-600">وصف البرنامج</span>
            <textarea
              value={description}
              onChange={(e) => setDescription(e.target.value)}
              rows={3}
              placeholder="إيه اللي بيقدمه البرنامج ده؟"
              className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
            />
          </label>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label className="block">
              <span className="mb-1 block text-xs font-medium text-slate-600">صورة / أيقونة (رابط)</span>
              <input
                value={imageUrl}
                onChange={(e) => setImageUrl(e.target.value)}
                placeholder="https://…"
                dir="ltr"
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              />
            </label>

            <label className="block">
              <span className="mb-1 block text-xs font-medium text-slate-600">الحالة</span>
              <select
                value={status}
                onChange={(e) => setStatus(e.target.value as "active" | "inactive")}
                className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"
              >
                <option value="active">نشط</option>
                <option value="inactive">غير نشط</option>
              </select>
            </label>
          </div>

          {/* ===== لون الكارت ===== */}
          <div>
            <span className="mb-1.5 block text-xs font-medium text-slate-600">لون البرنامج</span>
            <div className="flex flex-wrap items-center gap-2">
              {PRESET_COLORS.map((c) => (
                <button
                  key={c}
                  type="button"
                  onClick={() => setColor(c)}
                  style={{ backgroundColor: c }}
                  className={`h-7 w-7 rounded-full transition ${
                    color === c ? "ring-2 ring-slate-800 ring-offset-2" : "hover:scale-110"
                  }`}
                  aria-label={c}
                />
              ))}
              <input
                type="color"
                value={color}
                onChange={(e) => setColor(e.target.value)}
                className="h-7 w-12 cursor-pointer rounded border border-slate-300"
              />
            </div>
          </div>

          {/* ===== التصنيفات ===== */}
          <div className="border-t border-slate-100 pt-4">
            <div className="mb-2 flex items-center justify-between">
              <span className="text-xs font-medium text-slate-600">
                التصنيفات
                {selected.length > 0 && (
                  <span className="mr-2 rounded-full bg-slate-100 px-2 py-0.5 text-slate-500">
                    {selected.length}
                  </span>
                )}
              </span>
              <span className="text-[11px] text-slate-400">
                البرنامج ممكن يكون أكتر من تصنيف — النوع والمجال والفئة
              </span>
            </div>

            {categories.length === 0 ? (
              <p className="rounded-lg border border-dashed border-slate-200 py-3 text-center text-xs text-slate-400">
                مفيش تصنيفات معرّفة. تقدر تضيفها من زر «إدارة التصنيفات».
              </p>
            ) : (
              <div className="space-y-2.5">
                {Object.entries(grouped).map(([key, list]) => (
                  <div key={key}>
                    <p className="mb-1 text-[11px] text-slate-400">
                      {SLUG_HINT[key] ?? "تصنيفات أخرى"}
                    </p>
                    <div className="flex flex-wrap gap-1.5">
                      {list.map((c) => {
                        const on = selected.includes(c.id);
                        return (
                          <button
                            key={c.id}
                            type="button"
                            onClick={() => toggleCategory(c.id)}
                            className={`inline-flex items-center gap-1 rounded-lg border px-2 py-1 text-xs transition ${
                              on
                                ? "border-slate-800 bg-slate-800 text-white"
                                : "border-slate-200 bg-white text-slate-600 hover:border-slate-400"
                            }`}
                          >
                            {c.icon && <span>{c.icon}</span>}
                            {c.name}
                          </button>
                        );
                      })}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </div>

          {error && (
            <div className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
              {error}
            </div>
          )}

          <div className="flex items-center justify-end gap-2 border-t border-slate-100 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200"
            >
              إلغاء
            </button>
            <button
              type="submit"
              disabled={saving || !name.trim()}
              className="rounded-lg bg-slate-800 px-5 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-40"
            >
              {saving ? "جارٍ الحفظ…" : program ? "حفظ التعديلات" : "إضافة البرنامج"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

/** إعادة تصدير للتبسيط في صفحة البرامج */
export type { ProgramCategory };