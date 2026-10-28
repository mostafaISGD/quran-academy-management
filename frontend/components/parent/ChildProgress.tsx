"use client";

import type { ParentProgress } from "@/lib/api";
import { date, num } from "@/lib/format";
import { Badge, Card, Empty, ErrorBox, LoadingRows, PROGRESS_CATEGORY_LABEL, labelOf } from "./parent-ui";

/**
 * ⭐⭐⭐ **PR 4.5 — التقدم والحفظ.**
 *
 * ⭐ تقييمات المعلم (فئة + درجة + ملاحظات) وسجل الحفظ (سورة +
 * نطاق الآيات + جودة). البيانات من `progress_records` و
 * `memorization_records` — نفس اللي المعلم بيسجله من بوابة PR 3.13.
 */
export default function ChildProgress({
  data,
  loading,
  error,
}: {
  data: ParentProgress | null;
  loading: boolean;
  error: string | null;
}) {
  if (loading && !data) return <LoadingRows count={4} />;
  if (error) return <ErrorBox message={error} />;

  const progress = data?.progress ?? [];
  const memorization = data?.memorization ?? [];

  return (
    <div className="space-y-4">
      <Card title="تقييمات التقدم" hint="الأحدث الأول — آخر 100 تقييم">
        {progress.length === 0 ? (
          <Empty title="مفيش تقييمات لسه" hint="المعلم بيسجّل التقييم بعد كل حصة أو مرحلة." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {progress.map((row) => (
              <li key={row.id} className="py-2.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">
                    {labelOf(PROGRESS_CATEGORY_LABEL, row.category)}
                  </p>
                  <div className="flex items-center gap-2">
                    {row.score !== null && <Badge tone="emerald">الدرجة {num(row.score)}</Badge>}
                    <span className="text-xs text-slate-500">{date(row.recorded_at)}</span>
                  </div>
                </div>

                <p className="mt-0.5 text-xs text-slate-500">
                  {[row.program_name, row.level_name, row.teacher_name]
                    .filter(Boolean)
                    .join(" · ") || "—"}
                </p>

                {row.notes && (
                  <p className="mt-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600">
                    {row.notes}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="سجل الحفظ" hint="آخر 100 تسجيل">
        {memorization.length === 0 ? (
          <Empty title="مفيش سجل حفظ لسه" hint="هيظهر هنا أول ما يسجّل المعلم حفظاً جديداً." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {memorization.map((row) => (
              <li key={row.id} className="py-2.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">
                    {row.surah_name ?? "—"}
                    <span className="mr-1 text-xs font-normal text-slate-500">
                      (الآيات {num(row.from_ayah, 0)}–{num(row.to_ayah, 0)})
                    </span>
                  </p>
                  <span className="text-xs text-slate-500">{date(row.recorded_at)}</span>
                </div>

                <p className="mt-0.5 text-xs text-slate-500">
                  {[row.teacher_name, row.quality !== null ? `الجودة ${num(row.quality)}` : null]
                    .filter(Boolean)
                    .join(" · ") || "—"}
                </p>

                {row.notes && (
                  <p className="mt-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600">
                    {row.notes}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
