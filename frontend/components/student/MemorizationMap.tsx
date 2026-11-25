import type { StudentMemorizationSurah } from "@/lib/api";
import { date } from "@/lib/format";

/**
 * ⭐⭐ خريطة الحفظ — سورة لكل كارت، والمقاطع زي ما اتسجلت.
 *
 * ⚠️ مفيش «من 1 لـ7» لو فيه فجوة: المقاطع هي الحقيقة. الواجهة
 * بتعرض كل مقطع لوحده عشان الطالب يشوف اللي حفظه فعلاً.
 */
export function MemorizationMap({
  surahs,
}: {
  surahs: readonly StudentMemorizationSurah[];
}) {
  if (surahs.length === 0) {
    return (
      <p className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
        لسه مفيش حفظ مسجّل — أول ما المعلم يسجّل، الخريطة هتظهر هنا.
      </p>
    );
  }

  return (
    <div className="grid gap-3 sm:grid-cols-2">
      {surahs.map((surah) => (
        <section
          key={surah.surah_id}
          className="rounded-xl bg-white p-4 ring-1 ring-slate-200"
        >
          <div className="mb-2 flex items-baseline justify-between gap-2">
            <h4 className="text-sm font-semibold text-slate-800">
              سورة {surah.surah_name ?? `#${surah.surah_id}`}
            </h4>
            <span className="text-[11px] text-slate-400">
              {surah.surah_number ? `رقم ${surah.surah_number}` : ""}
            </span>
          </div>

          <p className="mb-3 text-xs text-slate-500">
            من الآية {surah.from_ayah} إلى {surah.to_ayah}
          </p>

          <div className="flex flex-wrap gap-1.5">
            {surah.ayahs.map((range, index) => (
              <span
                key={`${range.from_ayah}-${range.to_ayah}-${index}`}
                title={range.recorded_at ? date(range.recorded_at) : undefined}
                className="rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-medium text-emerald-700 ring-1 ring-emerald-100"
              >
                {range.from_ayah === range.to_ayah
                  ? `آية ${range.from_ayah}`
                  : `${range.from_ayah}–${range.to_ayah}`}
                {range.quality !== null ? ` · ${range.quality}/5` : ""}
              </span>
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}
