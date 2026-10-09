"use client";

import { useCallback, useEffect, useState } from "react";
import {
  createPromoCode,
  deletePromoCode,
  getPromoCodes,
  getPromoCodeStats,
  updatePromoCode,
  type PromoCode,
  type PromoCodeStats,
} from "@/lib/api";
import { date, egp, num, text } from "@/lib/format";
import { useUI } from "@/components/ui";

/**
 * ⭐⭐ إدارة الأكواد الترويجية.
 *
 * ⭐ إنشاء/تعديل/حذف أكواد الخصم + إحصائيات الاستخدام.
 */

const FIELD =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
const BTN_PRIMARY =
  "rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50";
const BTN_DANGER =
  "rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-50";
const BTN_GHOST =
  "rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50 disabled:opacity-50";

type FormState = {
  id?: number;
  code: string;
  name: string;
  description: string;
  type: "percent" | "fixed";
  value: string;
  max_uses: string;
  max_uses_per_student: string;
  starts_at: string;
  expires_at: string;
  applies_to: "all" | "program" | "category";
  program_id: string;
  category: "traditional" | "golden" | "group" | "single" | "";
  new_students_only: boolean;
  is_active: boolean;
};

const EMPTY_FORM: FormState = {
  code: "",
  name: "",
  description: "",
  type: "percent",
  value: "",
  max_uses: "",
  max_uses_per_student: "1",
  starts_at: "",
  expires_at: "",
  applies_to: "all",
  program_id: "",
  category: "",
  new_students_only: false,
  is_active: true,
};

export default function PromoCodesPage() {
  const { toast } = useUI();
  const [promos, setPromos] = useState<PromoCode[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [modal, setModal] = useState<"create" | "edit" | null>(null);
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState<FormState>(EMPTY_FORM);
  const [stats, setStats] = useState<PromoCodeStats | null>(null);
  const [statsPromo, setStatsPromo] = useState<PromoCode | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await getPromoCodes({ per_page: 100 });
      setPromos(res.data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل الأكواد");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    getPromoCodes({ per_page: 100 })
      .then((res) => setPromos(res.data))
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر تحميل الأكواد"))
      .finally(() => setLoading(false));
  }, []);

  const openCreate = () => {
    setForm(EMPTY_FORM);
    setModal("create");
  };

  const openEdit = (promo: PromoCode) => {
    setForm({
      code: promo.code,
      name: promo.name,
      description: promo.description ?? "",
      type: promo.type,
      value: String(promo.value),
      max_uses: promo.max_uses ? String(promo.max_uses) : "",
      max_uses_per_student: String(promo.max_uses_per_student),
      starts_at: promo.starts_at ? promo.starts_at.slice(0, 10) : "",
      expires_at: promo.expires_at ? promo.expires_at.slice(0, 10) : "",
      applies_to: promo.applies_to,
      program_id: promo.program ? String(promo.program.id) : "",
      category: (promo.category ?? "") as FormState["category"],
      new_students_only: promo.new_students_only,
      is_active: promo.is_active,
    });
    setModal("edit");
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setSaving(true);
    try {
      const payload = {
        code: form.code.toUpperCase(),
        name: form.name,
        description: form.description || undefined,
        type: form.type,
        value: Number(form.value),
        max_uses: form.max_uses ? Number(form.max_uses) : undefined,
        max_uses_per_student: Number(form.max_uses_per_student) || 1,
        starts_at: form.starts_at || undefined,
        expires_at: form.expires_at || undefined,
        applies_to: form.applies_to,
        program_id: form.applies_to === "program" && form.program_id ? Number(form.program_id) : undefined,
        category: form.applies_to === "category" && form.category ? form.category : undefined,
        new_students_only: form.new_students_only,
        is_active: form.is_active,
      };

      if (modal === "create") {
        await createPromoCode(payload);
        toast.success("تم إنشاء الكود");
      } else if (form.id) {
        await updatePromoCode(form.id, payload);
        toast.success("تم تحديث الكود");
      }
      setModal(null);
      await load();
    } catch (e) {
      toast.apiError("فشل الحفظ", e);
    } finally {
      setSaving(false);
    }
  };

  const handleDelete = async (promo: PromoCode) => {
    if (!window.confirm(`حذف كود «${promo.code}»؟`)) return;
    try {
      await deletePromoCode(promo.id);
      toast.success("تم الحذف");
      await load();
    } catch (e) {
      toast.apiError("فشل الحذف", e);
    }
  };

  const showStats = async (promo: PromoCode) => {
    setStatsPromo(promo);
    try {
      const res = await getPromoCodeStats(promo.id);
      setStats(res.data);
    } catch {
      setStats(null);
    }
  };

  if (loading) {
    return (
      <div className="space-y-4">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-24 animate-pulse rounded-xl bg-slate-100" />
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
          <h1 className="text-lg font-semibold text-slate-800">الأكواد الترويجية</h1>
          <p className="mt-0.5 text-xs text-slate-500">
            {promos.length} كود — خصومات على الفواتير
          </p>
        </div>
        <button className={BTN_PRIMARY} onClick={openCreate}>
          كود جديد
        </button>
      </div>

      {modal && (
        <PromoCodeModal
          form={form}
          setForm={setForm}
          onSubmit={handleSubmit}
          onClose={() => setModal(null)}
          saving={saving}
          isEdit={modal === "edit"}
        />
      )}

      {statsPromo && (
        <StatsModal
          promo={statsPromo}
          stats={stats}
          onClose={() => {
            setStatsPromo(null);
            setStats(null);
          }}
        />
      )}

      {promos.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
          مفيش أكواد ترويجية. اعمل «كود جديد» لإنشاء خصومات.
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">الكود</th>
                <th className="px-4 py-2 text-start text-[11px] font-medium text-slate-500">الاسم</th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">الخصم</th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">الاستخدام</th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">النطاق</th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">الحالة</th>
                <th className="px-4 py-2 text-center text-[11px] font-medium text-slate-500">إجراءات</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {promos.map((p) => (
                <tr key={p.id} className={!p.is_active ? "opacity-50" : ""}>
                  <td className="px-4 py-2">
                    <span className="rounded bg-slate-100 px-2 py-0.5 font-mono text-xs font-bold text-slate-800">
                      {p.code}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-slate-700">{text(p.name)}</td>
                  <td className="px-4 py-2 text-center">
                    <span className="font-medium text-emerald-700">
                      {p.type === "percent" ? `${num(p.value, 0)}%` : egp(p.value, 0)}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-center text-slate-600">
                    {num(p.used_count, 0)}
                    {p.max_uses ? ` / ${num(p.max_uses, 0)}` : ""}
                  </td>
                  <td className="px-4 py-2 text-center text-xs text-slate-500">
                    {p.applies_to_label}
                    {p.category_label && <span className="block">{p.category_label}</span>}
                  </td>
                  <td className="px-4 py-2 text-center">
                    <span
                      className={`rounded-full px-2 py-0.5 text-[10px] font-medium ${
                        p.is_valid_now
                          ? "bg-emerald-100 text-emerald-700"
                          : "bg-slate-100 text-slate-500"
                      }`}
                    >
                      {p.is_valid_now ? "نشط" : "غير نشط"}
                    </span>
                  </td>
                  <td className="px-4 py-2 text-center">
                    <div className="flex items-center justify-center gap-1">
                      <button className={BTN_GHOST} onClick={() => showStats(p)}>
                        إحصائيات
                      </button>
                      <button className={BTN_GHOST} onClick={() => openEdit(p)}>
                        تعديل
                      </button>
                      <button className={BTN_DANGER} onClick={() => handleDelete(p)}>
                        حذف
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}

function PromoCodeModal({
  form,
  setForm,
  onSubmit,
  onClose,
  saving,
  isEdit,
}: {
  form: FormState;
  setForm: (v: FormState) => void;
  onSubmit: (e: React.FormEvent) => void;
  onClose: () => void;
  saving: boolean;
  isEdit: boolean;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl max-h-[90vh] overflow-y-auto">
        <form onSubmit={onSubmit}>
          <div className="mb-4 flex items-center justify-between">
            <h2 className="text-base font-semibold text-slate-800">
              {isEdit ? "تعديل كود" : "كود ترويجي جديد"}
            </h2>
            <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-700">
              ✕
            </button>
          </div>

          <div className="grid gap-3 sm:grid-cols-2">
            <div>
              <label className="mb-1 block text-xs text-slate-600">الكود</label>
              <input
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value.toUpperCase() })}
                placeholder="RAMADAN20"
                className={FIELD}
                required
                maxLength={50}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">الاسم</label>
              <input
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="خصم رمضان"
                className={FIELD}
                required
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">النوع</label>
              <select
                value={form.type}
                onChange={(e) => setForm({ ...form, type: e.target.value as "percent" | "fixed" })}
                className={FIELD}
              >
                <option value="percent">نسبة مئوية</option>
                <option value="fixed">مبلغ ثابت</option>
              </select>
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">القيمة</label>
              <input
                type="number"
                step="0.01"
                min="0.01"
                value={form.value}
                onChange={(e) => setForm({ ...form, value: e.target.value })}
                placeholder={form.type === "percent" ? "20" : "100"}
                className={FIELD}
                required
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">الحد الأقصى للاستخدام</label>
              <input
                type="number"
                min="1"
                value={form.max_uses}
                onChange={(e) => setForm({ ...form, max_uses: e.target.value })}
                placeholder="بدون حد"
                className={FIELD}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">حد الاستخدام لكل طالب</label>
              <input
                type="number"
                min="1"
                value={form.max_uses_per_student}
                onChange={(e) => setForm({ ...form, max_uses_per_student: e.target.value })}
                className={FIELD}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">يبدأ في</label>
              <input
                type="date"
                value={form.starts_at}
                onChange={(e) => setForm({ ...form, starts_at: e.target.value })}
                className={FIELD}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">ينتهي في</label>
              <input
                type="date"
                value={form.expires_at}
                onChange={(e) => setForm({ ...form, expires_at: e.target.value })}
                className={FIELD}
              />
            </div>
            <div>
              <label className="mb-1 block text-xs text-slate-600">النطاق</label>
              <select
                value={form.applies_to}
                onChange={(e) => setForm({ ...form, applies_to: e.target.value as "all" | "program" | "category" })}
                className={FIELD}
              >
                <option value="all">كل البرامج والباقات</option>
                <option value="program">برنامج محدد</option>
                <option value="category">فئة باقة</option>
              </select>
            </div>
            {form.applies_to === "category" && (
              <div>
                <label className="mb-1 block text-xs text-slate-600">الفئة</label>
                <select
                  value={form.category}
                  onChange={(e) => setForm({ ...form, category: e.target.value as FormState["category"] })}
                  className={FIELD}
                >
                  <option value="">اختار...</option>
                  <option value="traditional">تقليدي</option>
                  <option value="golden">ذهبي</option>
                  <option value="group">مجموعات</option>
                  <option value="single">حصة مفردة</option>
                </select>
              </div>
            )}
            <div className="sm:col-span-2">
              <label className="mb-1 block text-xs text-slate-600">الوصف</label>
              <textarea
                value={form.description}
                onChange={(e) => setForm({ ...form, description: e.target.value })}
                rows={2}
                className={FIELD}
              />
            </div>
            <label className="flex items-center gap-2 sm:col-span-2 cursor-pointer">
              <input
                type="checkbox"
                checked={form.new_students_only}
                onChange={(e) => setForm({ ...form, new_students_only: e.target.checked })}
                className="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
              />
              <span className="text-sm text-slate-700">طلاب جدد فقط</span>
            </label>
            <label className="flex items-center gap-2 sm:col-span-2 cursor-pointer">
              <input
                type="checkbox"
                checked={form.is_active}
                onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                className="w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
              />
              <span className="text-sm text-slate-700">نشط</span>
            </label>
          </div>

          <div className="mt-5 flex justify-end gap-2">
            <button
              type="button"
              className={BTN_DANGER.replace("bg-red-600", "bg-slate-200 hover:bg-slate-300 text-slate-700")}
              onClick={onClose}
            >
              إلغاء
            </button>
            <button type="submit" className={BTN_PRIMARY} disabled={saving}>
              {saving ? "…" : isEdit ? "حفظ التعديلات" : "إنشاء الكود"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}

function StatsModal({
  promo,
  stats,
  onClose,
}: {
  promo: PromoCode;
  stats: PromoCodeStats | null;
  onClose: () => void;
}) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
      <div className="w-full max-w-sm rounded-2xl bg-white p-5 shadow-xl">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-base font-semibold text-slate-800">
            إحصائيات {promo.code}
          </h2>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-700">
            ✕
          </button>
        </div>

        {stats ? (
          <div className="space-y-3">
            <StatRow label="إجمالي الاستخدام" value={num(stats.total_usage, 0)} />
            <StatRow label="إجمالي الخصم" value={egp(stats.total_discount, 0)} />
            <StatRow label="طلاب فريدين" value={num(stats.unique_students, 0)} />
            <StatRow
              label="استخدامات متبقية"
              value={stats.remaining_uses !== null ? num(stats.remaining_uses, 0) : "لا حد"}
            />
          </div>
        ) : (
          <p className="text-sm text-slate-500">جاري التحميل...</p>
        )}

        <div className="mt-5 flex justify-end">
          <button className={BTN_GHOST} onClick={onClose}>
            إغلاق
          </button>
        </div>
      </div>
    </div>
  );
}

function StatRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2">
      <span className="text-xs text-slate-600">{label}</span>
      <span className="text-sm font-bold text-slate-800">{value}</span>
    </div>
  );
}