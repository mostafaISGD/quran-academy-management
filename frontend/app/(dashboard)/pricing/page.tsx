"use client";

import { useEffect, useState } from "react";
import {
  fetchMe,
  getPricing,
  getPricingCsvUrl,
  updatePlanPrice,
  type AuthUser,
  type PlanCategory,
  type PricingGroup,
  type PricingPlan,
  type PricingResponse,
} from "@/lib/api";
import { egp, num } from "@/lib/format";
import { useUI } from "@/components/ui";

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
  const { toast } = useUI();
  const [data, setData] = useState<PricingResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  // ⭐ مين يقدر يعدّل؟ هنجيبها من `auth/me` — الأهل مافيش ليهم
  // حساب أصلاً، والأدمن هو اللي عنده `pricing.manage`.
  const [me, setMe] = useState<AuthUser | null>(null);

  useEffect(() => {
    getPricing()
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر تحميل الأسعار"))
      .finally(() => setLoading(false));

    fetchMe().then(setMe).catch(() => setMe(null));
  }, []);

  const canEdit = Boolean(me?.permissions?.includes("pricing.manage"));

  /**
   * ⭐ الحفظ بيحصل في **نفس الصفحة** — مفيش شاشة تانية.
   *
   * بنرجّع السعر الجديد من السيرفر (مش اللي كتبه المستخدم) عشان
   * السيرفر هو اللي بيقرّر شكل الرقم النهائي.
   */
  async function savePrice(planId: number, price: number) {
    try {
      const res = await updatePlanPrice(planId, price);
      setData((prev) =>
        prev
          ? {
              ...prev,
              groups: prev.groups.map((g) => ({
                ...g,
                plans: g.plans.map((p) => (p.id === planId ? res.plan : p)),
              })),
            }
          : prev,
      );
      toast.success(res.message);
    } catch (e) {
      toast.apiError("فشل حفظ السعر", e);
      // ⭐ نرجّع الرقم القديم — الشاشة فضلت على رقم مش موجود
      throw e;
    }
  }

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

        {/* لازم صلاحية — عشان ما نعرضش زرار لحد مش هيقدر يضغطه */}
        {canEdit && (
          <a
            href={getPricingCsvUrl()}
            download
            className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
          >
            تحميل CSV
          </a>
        )}
      </div>

      {/* ===== الفئات جنب بعض ===== */}
      <div className="grid gap-4 lg:grid-cols-3">
        {data.groups.map((g) => (
          <GroupCard key={g.category} group={g} canEdit={canEdit} onSave={savePrice} />
        ))}
      </div>

      {/* ===== ملاحظة ===== */}
      <p className="mt-6 rounded-lg bg-slate-50 px-4 py-3 text-xs leading-relaxed text-slate-500">
        الأسعار اللي فوق <b className="text-slate-700">للباقة كاملة</b> — مش للحصة
        الواحدة. مثال: «30 دقيقة - 8 حصص» معناها 350 ج.م بالثمانية.
        <br />
        <span className="text-emerald-700">
          «60 دقيقة - 16 حصة (مجموعات)» أرخص لأنها حصة جماعية — مش لأن المدة أطول.
        </span>
      </p>
    </div>
  );
}

/** كارت فئة واحدة */
function GroupCard({
  group,
  canEdit,
  onSave,
}: {
  group: PricingGroup;
  canEdit: boolean;
  onSave: (planId: number, price: number) => Promise<void>;
}) {
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
            <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">
              المدة
            </th>
            <th className="px-2 py-2 text-center text-[11px] font-medium text-slate-500">
              الحصص
            </th>
            {/* ⭐ `text-start` مش `text-end` — السعر فيه رقم + «ج.م»،
                والمفروض الرقم يبقى على يمين الكلام العربي */}
            <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">
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
                {/* ⭐ `text-start` — السعر فيه رقم + «ج.م»، والمفروض
                    الرقم يبقى على يمين الكلام العربي */}
                <td className="px-4 py-2 text-start">
                  <PriceCell plan={p} canEdit={canEdit} onSave={onSave} />
                </td>
              </tr>
            )),
          )}
        </tbody>
      </table>
    </div>
  );
}

/**
 * ⭐ خلية السعر — بتتعدّل **في مكانها**.
 *
 * بالضغط على الرقم: يتحوّل لحقل، تكتب الرقم الجديد، تدوس Enter
 * أو تضغط برّه — بيتحفظ. تدوس Esc — يرجع زي ما كان.
 *
 * ليه بالضغط مش بحقل دايماً؟ لأن ٣١ صف في جداول — حقول دايماً
 * هتبقى مرهق. الضغط على اللي عايز تعدّله بس أسرع.
 */
function PriceCell({
  plan,
  canEdit,
  onSave,
}: {
  plan: PricingPlan;
  canEdit: boolean;
  onSave: (planId: number, price: number) => Promise<void>;
}) {
  const [editing, setEditing] = useState(false);
  // ⭐ string مش number — المستخدم بيكتب، ولحد ما يضغط Enter الرقم
  // نص. التحويل لـ number بيحصل عند الحفظ بس.
  const [draft, setDraft] = useState("");
  const [saving, setSaving] = useState(false);

  // ⭐ متى الـ price يتغيّر من برّه (من الحفظ)، نحدّث النص المعروض
  const shown = editing ? draft : egp(plan.price, 0);

  function start() {
    if (!canEdit || saving) return;
    // ⭐ نكتب الرقم **الإنجليزي** في الحقل — الأرقام العربية
    // (٠١٢٣) ما بتتقراش بـ parseFloat، واللي كتبه الأدمن لازم
    // يوصل للسيرفر كرقم.
    setDraft(String(plan.price));
    setEditing(true);
  }

  function cancel() {
    setEditing(false);
    setDraft("");
  }

  async function commit() {
    const value = Number(draft.replace(/[٠-٩]/g, (d) => String(d.charCodeAt(0) - 0x0660)));

    if (!Number.isFinite(value) || value < 0) {
      cancel();
      return;
    }

    // ⭐ ما نبعثش لو ما اتغيّرش — بيملا سجل النشاط ب noise
    if (value === Number(plan.price)) {
      cancel();
      return;
    }

    setSaving(true);
    try {
      await onSave(plan.id, value);
      setEditing(false);
      setDraft("");
    } catch {
      // ⭐ نسيب الحقل مفتوح — الـ toast بيقول العطل، والرقم
      // القديم رجع في الشاشة بالفعل
      cancel();
    } finally {
      setSaving(false);
    }
  }

  // ===== وضع التعديل =====
  if (editing) {
    return (
      <input
        autoFocus
        type="number"
        inputMode="decimal"
        min={0}
        step="0.01"
        dir="ltr"
        value={draft}
        disabled={saving}
        onChange={(e) => setDraft(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") void commit();
          if (e.key === "Escape") cancel();
        }}
        onBlur={() => void commit()}
        className="w-24 rounded-md border border-slate-300 px-2 py-1 text-end text-sm font-bold tabular-nums text-slate-900 focus:border-slate-500 focus:outline-none"
      />
    );
  }

  // ===== وضع العرض =====
  if (!canEdit) {
    return (
      <span className="text-sm font-bold tabular-nums text-slate-900">{shown}</span>
    );
  }

  return (
    <button
      onClick={start}
      title="اضغط للتعديل"
      className="group/cell -mr-1 rounded-md px-1 py-0.5 text-sm font-bold tabular-nums text-slate-900 transition hover:bg-slate-100"
    >
      {shown}
      {/* ⭐ علامة خفيفة بتقول «ده بيتعدّل» — من غير ما نلوّث الرقم */}
      <span className="mr-1 text-[10px] font-normal text-slate-300 opacity-0 transition group-hover/cell:opacity-100">
        ✎
      </span>
    </button>
  );
}
