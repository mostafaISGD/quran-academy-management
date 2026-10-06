"use client";

import { useEffect, useState } from "react";
import {
  getPricing,
  getPricingCsvUrl,
  type PlanCategory,
  type PricingGroup,
  type PricingResponse,
} from "@/lib/api";
import { egp, num } from "@/lib/format";

/**
 * جدول الأسعار — ٢٨ باقة مقسّمة على ٣ فئات.
 *
 * ⭐ **الأسعار الإجمالية بس**. مفيش تقسيم للحصة (كان طلب صريح).
 *
 * ⭐ الصفحة دي **مش محتاجة تسجيل دخول** — الأسعار حاجة الأهالي
 * بيسألوا عنها.
 *
 * الفئات:
 *   تقليدي  = الباقة العادية
 *   ذهبي     = الباقة الذهبية (سعر أعلى)
 *   مجموعات  = حصة جماعية، أرخص بكتير
 */

/** لون كل فئة — عشان الجدول يبان مقسّم بصريًا */
const CATEGORY_STYLE: Record<
  PlanCategory,
  { ring: string; badge: string; head: string; row: string }
> = {
  traditional: {
    ring: "border-slate-200",
    badge: "bg-slate-100 text-slate-700",
    head: "border-slate-200",
    row: "border-slate-100 last:border-0",
  },
  golden: {
    ring: "border-amber-200",
    badge: "bg-amber-100 text-amber-800",
    head: "border-amber-200",
    row: "border-amber-100/60 last:border-0",
  },
  group: {
    ring: "border-emerald-200",
    badge: "bg-emerald-100 text-emerald-800",
    head: "border-emerald-200",
    row: "border-emerald-100/60 last:border-0",
  },
  single: {
    ring: "border-violet-200",
    badge: "bg-violet-100 text-violet-800",
    head: "border-violet-200",
    row: "border-violet-100/60 last:border-0",
  },
};

export default function PricingPage() {
  const [data, setData] = useState<PricingResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getPricing()
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر تحميل الأسعار"))
      .finally(() => setLoading(false));
  }, []);

  if (loading) {
    return (
      <div className="space-y-2">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-32 animate-pulse rounded-xl bg-slate-100" />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
    );
  }

  if (!data || data.groups.length === 0) {
    return <p className="text-sm text-slate-400">مفيش باقات مسجّلة</p>;
  }

  return (
    <div>
      {/* ===== الترويسة ===== */}
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">جدول الأسعار</h1>
          {/* ⭐ كل رقم يعدّي من `num` عشان يطلع بالعربي */}
          <p className="text-xs text-slate-500">
            {num(data.counts.total, 0)} باقة ·{" "}
            {data.durations.map((d) => `${num(d, 0)} دقيقة`).join(" · ")} ·{" "}
            {data.counts.lessons.map((n) => `${num(n, 0)} حصص`).join(" · ")}
          </p>
        </div>

        <a
          href={getPricingCsvUrl()}
          download
          className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
        >
          تحميل CSV
        </a>
      </div>

      {/* ===== الفئات جنب بعض ===== */}
      <div className="grid gap-4 lg:grid-cols-3">
        {data.groups.map((g) => (
          <GroupCard key={g.category} group={g} />
        ))}
      </div>

      {/* ===== ملاحظة ===== */}
      <p className="mt-6 rounded-lg bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
        الأسعار اللي فوق <b className="text-slate-700">للباقة كاملة</b> — مش للحصة
        الواحدة. مثال: «٣٠ دقيقة - ٨ حصص» معناها ٣٥٠ ج.م بالثمانية.
        <br />
        <span className="text-emerald-700">
          «٦٠ دقيقة - ١٦ حصة (مجموعات)» أرخص لأنها حصة جماعية — مش لأن المدة أطول.
        </span>
      </p>
    </div>
  );
}

/** كارت فئة واحدة */
function GroupCard({ group }: { group: PricingGroup }) {
  const style = CATEGORY_STYLE[group.category] ?? CATEGORY_STYLE.traditional;

  // ⭐ الترتيب: المدة (٣٠/٤٥/٦٠) × عدد الحصص (٤/٨/١٢/١٦)
  // السيرفر بيوصل مرتب، بس نرتّب تاني هنا عشان نضمن.
  const plans = [...group.plans].sort(
    (a, b) =>
      a.lesson_duration_minutes - b.lesson_duration_minutes ||
      (a.lessons_count ?? 0) - (b.lessons_count ?? 0),
  );

  // نجمع حسب المدة عشان الجدول يبان «٣٠ دقيقة: ٤ حصص، ٨ حصص...»
  const byDuration = new Map<number, typeof plans>();
  for (const p of plans) {
    const list = byDuration.get(p.lesson_duration_minutes) ?? [];
    list.push(p);
    byDuration.set(p.lesson_duration_minutes, list);
  }

  return (
    <div className={`overflow-hidden rounded-xl border bg-white ${style.ring}`}>
      {/* ترويسة الفئة */}
      <div className={`border-b px-4 py-3 ${style.head}`}>
        <div className="flex items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-800">{group.label}</h2>
          <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${style.badge}`}>
            {num(plans.length, 0)} باقة
          </span>
        </div>
        {group.description && (
          <p className="mt-0.5 text-xs text-slate-500">{group.description}</p>
        )}
      </div>

      {/* الجدول */}
      <table className="w-full text-sm">
        <thead className="bg-slate-50">
          <tr>
            <th className="px-4 py-2 text-right text-[11px] font-medium text-slate-500">
              المدة
            </th>
            <th className="px-2 py-2 text-center text-[11px] font-medium text-slate-500">
              الحصص
            </th>
            <th className="px-4 py-2 text-left text-[11px] font-medium text-slate-500">
              السعر
            </th>
          </tr>
        </thead>
        <tbody>
          {[...byDuration.entries()].map(([duration, rows]) =>
            rows.map((p, i) => (
              <tr key={p.id} className={`border-b ${style.row}`}>
                {/* المدة بتظهر مرة واحدة لكل مجموعة */}
                <td className="px-4 py-2 text-xs tabular-nums text-slate-600">
                  {i === 0 ? (
                    <span className="font-medium">{num(duration, 0)} دقيقة</span>
                  ) : (
                    <span className="text-slate-300">〃</span>
                  )}
                </td>
                <td className="px-2 py-2 text-center text-xs tabular-nums text-slate-600">
                  {p.lessons_count === null ? "—" : num(p.lessons_count, 0)}
                </td>
                <td className="px-4 py-2 text-left">
                  {/* ⭐ السعر الإجمالي — جوه الجنيه */}
                  <span className="text-sm font-bold tabular-nums text-slate-900">
                    {egp(p.price, 0)}
                  </span>
                </td>
              </tr>
            )),
          )}
        </tbody>
      </table>
    </div>
  );
}
