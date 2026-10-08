"use client";

import { useEffect, useState } from "react";
import {
  createPricingPlan,
  deletePricingPlan,
  getPricing,
  type PricingGroup,
  type PricingPlan,
  type PlanCategory,
} from "@/lib/api";
import { egp, num } from "@/lib/format";
import { useUI } from "@/components/ui";

/**
 * ⭐⭐ إدارة الباقات — إنشاء/تعديل/حذف.
 *
 * ⭐ نفس البيانات اللي في صفحة الأسعار العامة، بس مع إجراءات.
 */

const CATEGORY_ORDER: PlanCategory[] = ["traditional", "golden", "group", "single"];
const CATEGORY_LABELS: Record<PlanCategory, string> = {
  traditional: "تقليدي",
  golden: "ذهبي",
  group: "مجموعات",
  single: "حصة مفردة",
};

const DURATION_OPTIONS = [30, 45, 60];
const LESSONS_OPTIONS = [4, 8, 12, 16];

const FIELD =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
const BTN_PRIMARY =
  "rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50";
const BTN_DANGER =
  "rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50";

export default function PricingAdminPage() {
  const { toast } = useUI();
  const [groups, setGroups] = useState<PricingGroup[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modal, setModal] = useState<"create" | null>(null);
  const [saving, setSaving] = useState(false);

  // إنشاء باقة جديدة
  const [form, setForm] = useState({
    name: "",
    category: "traditional" as PlanCategory,
    lesson_duration_minutes: 30,
    lessons_count: 4,
    price: 0,
    currency: "EGP",
    billing_type: "monthly" as "monthly" | "per_lesson",
    sort_order: 0,
    description: "",
  });

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const data = await getPricing();
      setGroups(data.groups);
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل الباقات");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    getPricing()
      .then((data) => {
        setGroups(data.groups);
      })
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر تحميل الباقات"))
      .finally(() => setLoading(false));
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      await createPricingPlan(form);
      toast.success("تم إنشاء الباقة");
      setModal(null);
      setForm({
        name: "",
        category: "traditional",
        lesson_duration_minutes: 30,
        lessons_count: 4,
        price: 0,
        currency: "EGP",
        billing_type: "monthly",
        sort_order: 0,
        description: "",
      });
      await load();
    } catch (e) {
      toast.apiError("فشل إنشاء الباقة", e);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (plan: PricingPlan) => {
    if (!window.confirm(`حذف «${plan.name}»؟`)) return;
    try {
      await deletePricingPlan(plan.id);
      toast.success("تم الحذف");
      await load();
    } catch (e) {
      toast.apiError("فشل الحذف", e);
    }
  };

  if (loading) {
    return (
      <div className="space-y-4">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-32 animate-pulse rounded-xl bg-slate-100" />
        ))}
      </div>
    );
  }

  if (error) {
    return <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>;
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">إدارة الباقات</h1>
          <p className="mt-0.5 text-xs text-slate-500">
            {groups.reduce((sum, g) => sum + g.plans.length, 0)} باقة —
            3 فئات × 3 مدد × 4 أعداد حصص
          </p>
        </div>
        <button className={BTN_PRIMARY} onClick={() => setModal("create")}>
          باقة جديدة
        </button>
      </div>

      {modal === "create" && (
        <CreatePlanModal
          form={form}
          setForm={setForm}
          onSubmit={handleSubmit}
          onClose={() => setModal(null)}
          saving={saving}
        />
      )}

      <div className="space-y-6">
        {CATEGORY_ORDER.map((cat) => {
          const group = groups.find((g) => g.category === cat);
          if (!group || group.plans.length === 0) return null;

          return (
            <CategorySection
              key={cat}
              group={group}
              onDelete={handleDelete}
            />
          );
        })}
      </div>
    </div>
  );
}

function CategorySection({
  group,
  onDelete,
}: {
  group: PricingGroup;
  onDelete: (plan: PricingPlan) => void;
}) {
  const cat = group.category;
  const isGroup = cat === "group";

  // ترتيب: المدة 30 → 4٥ → ٦0، ثم الحصص 4 → 8 → ١٢ → ١٦
  const plans = [...group.plans].sort(
    (a, b) =>
      a.lesson_duration_minutes - b.lesson_duration_minutes ||
      (a.lessons_count ?? 0) - (b.lessons_count ?? 0),
  );

  // تجميع حسب المدة
  const byDuration = new Map<number, typeof plans>();
  for (const p of plans) {
    const list = byDuration.get(p.lesson_duration_minutes) ?? [];
    list.push(p);
    byDuration.set(p.lesson_duration_minutes, list);
  }

  return (
    <div className="rounded-xl border border-slate-200 bg-white overflow-hidden">
      <div className="border-b border-slate-200 px-4 py-3 flex items-center justify-between">
        <div>
          <h2 className="text-sm font-semibold text-slate-800">
            {CATEGORY_LABELS[cat]} <span className="text-xs font-normal text-slate-500 ml-2">({group.plans.length} باقة)</span>
          </h2>
          {group.description && (
            <p className="text-xs text-slate-500 mt-0.5">{group.description}</p>
          )}
        </div>
        <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] text-emerald-700">
          {group.plans.length} باقة
        </span>
      </div>

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-slate-50">
            <tr>
              <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">المدة</th>
              <th className="px-2 py-2 text-center text-[11px] font-medium text-slate-500">الحصص</th>
              <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">السعر</th>
              <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">إجراءات</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {[...byDuration.entries()].map(([duration, rows]) => (
              rows.map((p, i) => (
                <tr key={p.id} className={isGroup ? "bg-emerald-50/30" : ""}>
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
                  <td className="px-4 py-2 text-start">
                    <PriceCell plan={p} />
                  </td>
                  <td className="px-4 py-2 text-center">
                    <DeleteButton plan={p} onDelete={onDelete} />
                  </td>
                </tr>
              ))
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function PriceCell({ plan }: { plan: PricingPlan }) {
  // ⭐ السعر الإجمالي مع العملة
  return <span className="text-sm font-bold tabular-nums text-slate-900">{egp(plan.price, 0)}</span>;
}

function DeleteButton({
  plan,
  onDelete,
}: {
  plan: PricingPlan;
  onDelete: (plan: PricingPlan) => void;
}) {
  const [deleting, setDeleting] = useState(false);

  const handleClick = async () => {
    setDeleting(true);
    try {
      await onDelete(plan);
    } finally {
      setDeleting(false);
    }
  };

  return (
    <button
      className={BTN_DANGER}
      disabled={deleting}
      onClick={handleClick}
      title="حذف الباقة"
    >
      {deleting ? "…" : "حذف"}
    </button>
  );
}

function CreatePlanModal({
  form,
  setForm,
  onSubmit,
  onClose,
  saving,
}: {
  form: {
    name: string;
    category: PlanCategory;
    lesson_duration_minutes: number;
    lessons_count: number;
    price: number;
    currency: string;
    billing_type: "monthly" | "per_lesson";
    sort_order: number;
    description: string;
  };
  setForm: (v: typeof form) => void;
  onSubmit: (e: React.FormEvent) => void;
  onClose: () => void;
  saving: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl">
        <form onSubmit={onSubmit}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-base font-semibold text-slate-800">باقة جديدة</h2>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-700">
              ✕
            </button>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-600">الاسم</label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="مثال: تقليدي — 30 دقيقة — 8 حصص"
                className={FIELD}
                required
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">الفئة</label>
              <select
                value={form.category}
                onChange={(e) => setForm({ ...form, category: e.target.value as PlanCategory })}
                className={FIELD}
              >
                <option value="traditional">تقليدي</option>
                <option value="golden">ذهبي</option>
                <option value="group">مجموعات</option>
                <option value="single">حصة مفردة</option>
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">مدة الحصة</label>
              <select
                value={form.lesson_duration_minutes}
                onChange={(e) => setForm({ ...form, lesson_duration_minutes: Number(e.target.value) })}
                className={FIELD}
              >
                {DURATION_OPTIONS.map((d) => (
                  <option key={d} value={d}>
                    {d} دقيقة
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">عدد الحصص</label>
              <select
                value={form.lessons_count}
                onChange={(e) => setForm({ ...form, lessons_count: Number(e.target.value) })}
                className={FIELD}
              >
                {LESSONS_OPTIONS.map((l) => (
                  <option key={l} value={l}>
                    {l} حصة
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">السعر</label>
              <input
                type="number"
                step="0.01"
                min="0"
                value={form.price}
                onChange={(e) => setForm({ ...form, price: Number(e.target.value) || 0 })}
                className={FIELD}
                required
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">العملة</label>
              <input
                value={form.currency}
                onChange={(e) => setForm({ ...form, currency: e.target.value })}
                className={FIELD}
                maxLength={3}
              />
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">نوع الفوترة</label>
              <select
                value={form.billing_type}
                onChange={(e) => setForm({ ...form, billing_type: e.target.value as "monthly" | "per_lesson" })}
                className={FIELD}
              >
                <option value="monthly">شهري</option>
                <option value="per_lesson">حصة مفردة</option>
              </select>
            </div>

            <div>
              <label className="mb-1 block text-xs text-slate-600">الترتيب</label>
              <input
                type="number"
                min="0"
                value={form.sort_order}
                onChange={(e) => setForm({ ...form, sort_order: Number(e.target.value) || 0 })}
                className={FIELD}
              />
            </div>

            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-600">وصف</label>
              <textarea
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                rows={2}
                className={FIELD}
              />
            </div>
          </div>

          <div className="mt-5 flex justify-end gap-2">
            <button
              type="button"
              className={BTN_DANGER.replace("bg-red-600", "bg-slate-200 hover:bg-slate-300 text-slate-700")}
              onClick={onClose}
            >
              إلغاء
            </button>
            <button
              type="submit"
              className={BTN_PRIMARY}
              disabled={saving}
            >
              {saving ? "…" : "اعمل الباقة"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}