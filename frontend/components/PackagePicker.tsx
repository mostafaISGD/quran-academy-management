"use client";

import { useEffect, useMemo, useState } from "react";
import { getPricing, type GroupRow, type PricingGroup } from "@/lib/api";
import { money } from "@/lib/format";

/**
 * ⭐ اختيار باقة المجموعة — **مقفول** لو فيها أعضاء.
 *
 * ⭐ ليه القفل؟
 *
 * الباقة = **سعر** اشتراك كل عضو في المجموعة. تغييرها معناه
 * تغيير سعرهم من غير ما حد يوافق، والحساب آخر الشهر بيطلع
 * مش متفق. فمقفل لحد ما الأعضاء يخلصوا اشتراكاتهم وفواتيرهم
 * تتسدّد.
 *
 * ⭐ الشاشة بتقول **ليه** مقفولة من أول ما تفتح — عشان الأدمن
 * يفهم من غير ما يجرّب ويترفض.
 */
export default function PackagePicker({
  group,
  onClose,
  onSave,
}: {
  group: GroupRow;
  onClose: () => void;
  onSave: (planId: number | null) => void | Promise<void>;
}) {
  const [plans, setPlans] = useState<PricingGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [picked, setPicked] = useState<number | null>(group.package_id);
  const [saving, setSaving] = useState(false);

  const locked = group.package_lock.length > 0;
  const current = group.package_id;

  useEffect(() => {
    let alive = true;

    (async () => {
      try {
        const r = await getPricing();
        if (alive) setPlans(r.groups);
      } catch {
        if (alive) setPlans([]);
      } finally {
        if (alive) setLoading(false);
      }
    })();

    return () => {
      alive = false;
    };
  }, []);

  /**
   * ⭐ المجموعات **شهرية بس** قرار.
   *
   * الباقات غير الشهرية في المشروع: `per_lesson` (حصة مفردة) و
   * `custom`. أي واحدة فيهم ما ينفعش تتاخد اشتراك شهري عليها،
   * فبنخفيها من الاختيار بدل ما نخلي الأدمن يختار وبعدين
   * يفهم بعدين.
   */
  const grouped = useMemo(
    () =>
      plans
        .map((g) => ({
          label: g.label,
          plans: g.plans.filter((p) => p.billing_type === "monthly"),
        }))
        .filter((g) => g.plans.length > 0),
    [plans],
  );

  const changed = picked !== current;

  async function submit() {
    setSaving(true);
    try {
      await onSave(picked);
      onClose();
    } finally {
      setSaving(false);
    }
  }

  return (
    <div
      className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/50 p-4"
      onClick={onClose}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-2xl"
      >
        {/* ===== الترويسة ===== */}
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
          <div>
            <h2 className="text-base font-semibold text-slate-800">باقة المجموعة</h2>
            <p className="mt-0.5 text-xs text-slate-500">{group.name}</p>
          </div>
          <button
            onClick={onClose}
            aria-label="اقفل"
            className="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-100"
          >
            ✕
          </button>
        </div>

        <div className="space-y-4 p-5">
          {/* ============================================================
              ⭐ القفل — في الأول، قبل الاختيار
              ============================================================ */}
          {locked && (
            <div className="rounded-xl border border-amber-300 bg-amber-50 p-3.5">
              <p className="flex items-center gap-2 text-sm font-medium text-amber-900">
                <span>🔒</span>
                الباقة مقفولة دلوقتي
              </p>
              <ul className="mt-2 space-y-1.5">
                {group.package_lock.map((b, i) => (
                  <li key={i} className="flex gap-2 text-xs leading-relaxed text-amber-800">
                    <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-amber-500" />
                    {b.message}
                  </li>
                ))}
              </ul>
              <p className="mt-2.5 text-[11px] text-amber-700">
                الباقة = سعر اشتراك كل طالب في المجموعة. تغييرها دلوقتي معناه تغيير
                سعر ناس داخلين أصلاً.
              </p>
            </div>
          )}

          {/* ===== الباقة الحالية ===== */}
          {!locked && (
            <div className="rounded-lg bg-slate-50 px-3 py-2.5">
              <p className="text-[11px] text-slate-500">الباقة دلوقتي</p>
              <p className="mt-0.5 text-sm font-medium text-slate-800">
                {group.package ? (
                  <>
                    {group.package.name}
                    {" · "}
                    {money(group.package.price, group.package.currency)}
                  </>
                ) : (
                  "من غير باقة — أي طالب يدخل بياخد باقة البرنامج الشهرية"
                )}
              </p>
            </div>
          )}

          {/* ===== الاختيار ===== */}
          {!locked && (
            <div className="space-y-3">
              <label className="mb-1 block text-[11px] font-medium text-slate-600">
                الباقة الجديدة
                <span className="block text-[11px] font-normal text-slate-400">
                  بتطبّق على كل طالب يدخل المجموعة من دلوقتي
                </span>
              </label>

              {loading ? (
                <div className="space-y-2">
                  {[0, 1, 2].map((i) => (
                    <div key={i} className="h-11 animate-pulse rounded-lg bg-slate-100" />
                  ))}
                </div>
              ) : (
                <select
                  value={picked === null ? "" : String(picked)}
                  onChange={(e) =>
                    setPicked(e.target.value ? Number(e.target.value) : null)
                  }
                  className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
                >
                  <option value="">— من غير باقة —</option>
                  {grouped.map((g) => (
                    <optgroup key={g.label} label={g.label}>
                      {g.plans.map((p) => (
                        <option key={p.id} value={String(p.id)}>
                          {p.name} — {money(p.price, p.currency)}
                        </option>
                      ))}
                    </optgroup>
                  ))}
                </select>
              )}

              {picked !== null && (
                <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500">
                  أي طالب يدخل من دلوقتي هيتعمله اشتراك بـ
                  {(() => {
                    const flat = grouped.flatMap((g) => g.plans);
                    const p = flat.find((x) => x.id === picked);
                    return p ? ` ${money(p.price, p.currency)}` : "";
                  })()}
                  {" "}وفاتورة عليه. اللي داخلين قبل كده أسعارهم زي ما هي.
                </p>
              )}
            </div>
          )}
        </div>

        {/* ===== الأزرار ===== */}
        <div className="flex justify-end gap-2 border-t border-slate-100 px-5 py-3.5">
          <button
            onClick={onClose}
            className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
          >
            {locked ? "اقفل" : "إلغاء"}
          </button>
          {!locked && (
            <button
              onClick={submit}
              disabled={!changed || saving}
              className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
            >
              {saving ? "جاري الحفظ..." : "احفظ"}
            </button>
          )}
        </div>
      </div>
    </div>
  );
}