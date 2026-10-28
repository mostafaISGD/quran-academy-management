"use client";

import { useCallback, useState } from "react";

import {
  getTeacherSurahs,
  recordTeacherMemorization,
  type QuranSurah,
  type TeacherPortalLesson,
} from "@/lib/api";
import { num } from "@/lib/format";
import { usePage } from "@/lib/use-page";
import {
  needsAyahRange,
  PROGRESS_CATEGORIES,
  validateMemorization,
  type ProgressCategory,
} from "@/lib/teacher-portal";
import { Modal, useToast } from "@/components/ui";

/**
 * ⭐⭐⭐ **نموذج الحفظ/المراجعة من البوابة.**
 *
 * ⭐ الفئة الافتراضية «حفظ جديد» — ونطاق الآيات بيظهر للحفظ بس،
 * مطابق لـ`required_if:category,memorization` في السيرفر.
 *
 * ⚠️ السور بتتجاب من `/teacher/surahs` (أُضيف في PR 4.4) — قبل
 * كده مكانش في أي راو بيرجّع `quran_surahs` للواجهة.
 *
 * ⭐ المكوّن **بيتركّب وقت الفتح بس**، فكل حقول النموذج بتبدأ
 * نضيفة من غير `useEffect` لإعادة التصفير.
 */
export default function TeacherMemorizationForm({
  lesson,
  onClose,
  onSaved,
}: {
  lesson: TeacherPortalLesson;
  onClose: () => void;
  onSaved: () => void;
}) {
  const toast = useToast();

  const [category, setCategory] = useState<ProgressCategory>("memorization");
  const [surahId, setSurahId] = useState("");
  const [fromAyah, setFromAyah] = useState("");
  const [toAyah, setToAyah] = useState("");
  const [quality, setQuality] = useState("");
  const [notes, setNotes] = useState("");
  const [errors, setErrors] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);

  const fetchSurahs = useCallback(() => getTeacherSurahs(), []);
  const { data: catalog, loading: loadingSurahs } = usePage<{ surahs: QuranSurah[] }>(
    "teacher-surahs",
    fetchSurahs,
    { initial: { surahs: [] } },
  );

  const selected = catalog.surahs.find((s) => s.id === Number(surahId));
  const ranges = needsAyahRange(category);

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();

    const input = {
      category,
      surahId: ranges ? Number(surahId) || null : null,
      fromAyah: ranges ? Number(fromAyah) || null : null,
      toAyah: ranges ? Number(toAyah) || null : null,
      quality: quality ? Number(quality) : null,
      notes,
    };

    const found = validateMemorization(input);

    // ⭐ نفس قاعدة السيرفر: الآية لا تتجاوز عدد آيات السورة.
    if (selected && input.toAyah && input.toAyah > selected.ayah_count) {
      found.push(
        `«${selected.name_ar}» فيها ${num(selected.ayah_count, 0)} آية بس — راجع «إلى آية»`,
      );
    }

    if (found.length > 0) {
      setErrors(found);
      return;
    }

    setErrors([]);
    setSaving(true);

    try {
      await recordTeacherMemorization(lesson.id, {
        category,
        ...(ranges
          ? {
              surah_id: input.surahId ?? undefined,
              from_ayah: input.fromAyah ?? undefined,
              to_ayah: input.toAyah ?? undefined,
            }
          : {}),
        ...(input.quality ? { quality: input.quality } : {}),
        ...(notes.trim() ? { notes: notes.trim() } : {}),
      });

      toast.success(
        category === "memorization" ? "تم تسجيل الحفظ" : "تم تسجيل التقدم",
        lesson.student?.name,
      );
      onSaved();
    } catch (err) {
      toast.apiError("تعذر تسجيل الحفظ", err);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open
      onClose={onClose}
      title={`حفظ ومراجعة — ${lesson.student?.name ?? "الطالب"}`}
      width="max-w-lg"
      footer={
        <div className="flex gap-2">
          <button
            type="button"
            onClick={onClose}
            className="min-h-10 flex-1 rounded-lg bg-slate-100 px-4 text-sm font-medium text-slate-700 hover:bg-slate-200"
          >
            إلغاء
          </button>
          <button
            type="submit"
            form="teacher-memorization-form"
            disabled={saving}
            className="min-h-10 flex-1 rounded-lg bg-slate-800 px-4 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-60"
          >
            {saving ? "…" : "سجّل"}
          </button>
        </div>
      }
    >
      <form id="teacher-memorization-form" onSubmit={handleSubmit} className="space-y-4">
        {/* الفئة */}
        <div>
          <label htmlFor="mem-category" className="mb-1 block text-xs font-medium text-slate-600">
            نوع التسجيل
          </label>
          <select
            id="mem-category"
            value={category}
            onChange={(e) => {
              setCategory(e.target.value as ProgressCategory);
              setErrors([]);
            }}
            className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-600"
          >
            {PROGRESS_CATEGORIES.map((c) => (
              <option key={c.id} value={c.id}>
                {c.label}
              </option>
            ))}
          </select>
        </div>

        {/* السورة + النطاق — للحفظ بس */}
        {ranges && (
          <>
            <div>
              <label htmlFor="mem-surah" className="mb-1 block text-xs font-medium text-slate-600">
                السورة
              </label>
              <select
                id="mem-surah"
                value={surahId}
                onChange={(e) => {
                  setSurahId(e.target.value);
                  setErrors([]);
                }}
                className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-600"
              >
                <option value="">
                  {loadingSurahs ? "جاري التحميل…" : "اختار السورة"}
                </option>
                {catalog.surahs.map((s) => (
                  <option key={s.id} value={s.id}>
                    {s.number}. {s.name_ar} ({num(s.ayah_count, 0)} آية)
                  </option>
                ))}
              </select>
              {selected && (
                <p className="mt-1 text-[11px] text-slate-500">
                  {selected.revelation_type === "meccan" ? "مكية" : "مدنية"} —{" "}
                  {num(selected.ayah_count, 0)} آية
                </p>
              )}
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label htmlFor="mem-from" className="mb-1 block text-xs font-medium text-slate-600">
                  من آية
                </label>
                <input
                  id="mem-from"
                  type="number"
                  inputMode="numeric"
                  min={1}
                  max={selected?.ayah_count ?? undefined}
                  value={fromAyah}
                  onChange={(e) => {
                    setFromAyah(e.target.value);
                    setErrors([]);
                  }}
                  className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm tabular-nums text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-600"
                />
              </div>
              <div>
                <label htmlFor="mem-to" className="mb-1 block text-xs font-medium text-slate-600">
                  إلى آية
                </label>
                <input
                  id="mem-to"
                  type="number"
                  inputMode="numeric"
                  min={1}
                  max={selected?.ayah_count ?? undefined}
                  value={toAyah}
                  onChange={(e) => {
                    setToAyah(e.target.value);
                    setErrors([]);
                  }}
                  className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm tabular-nums text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-600"
                />
              </div>
            </div>
          </>
        )}

        {/* التقييم */}
        <div>
          <label htmlFor="mem-quality" className="mb-1 block text-xs font-medium text-slate-600">
            التقييم (1–5، اختياري)
          </label>
          <select
            id="mem-quality"
            value={quality}
            onChange={(e) => setQuality(e.target.value)}
            className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-600"
          >
            <option value="">بدون تقييم</option>
            {[1, 2, 3, 4, 5].map((q) => (
              <option key={q} value={q}>
                {num(q, 0)} / 5
              </option>
            ))}
          </select>
        </div>

        {/* ملاحظات */}
        <div>
          <label htmlFor="mem-notes" className="mb-1 block text-xs font-medium text-slate-600">
            ملاحظات
          </label>
          <textarea
            id="mem-notes"
            rows={2}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            placeholder="أخطاء متكررة، ملاحظة للمراجعة…"
            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-800 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-emerald-600"
          />
        </div>

        {errors.length > 0 && (
          <ul role="alert" className="space-y-1 rounded-lg bg-red-50 p-3 text-xs text-red-700">
            {errors.map((message) => (
              <li key={message}>• {message}</li>
            ))}
          </ul>
        )}
      </form>
    </Modal>
  );
}
