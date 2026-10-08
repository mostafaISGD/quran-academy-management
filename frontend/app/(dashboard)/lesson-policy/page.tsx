"use client";

import { useEffect, useState } from "react";
import {
  getLessonPolicy,
  updateLessonPolicy,
  type LessonPolicy,
} from "@/lib/api";
import { num } from "@/lib/format";
import { useUI } from "@/components/ui";

/**
 * ⭐ سياسات الحصة — غياب الطالب، غياب المعلم، التعويض.
 *
 * ⭐ صف واحد لكل أكاديمية. السياسات بتتحفظ وتترجع من API.
 */

const FIELD =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none";
const BTN_PRIMARY =
  "rounded-lg bg-emerald-600 px-4 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50";

type PolicyForm = {
  student_absence_consumes_lesson: boolean;
  student_absence_teacher_paid: boolean;
  teacher_absence_consumes_lesson: boolean;
  teacher_absence_teacher_paid: boolean;
  makeup_consumes_lesson: boolean;
  makeup_teacher_paid: boolean;
  makeup_deducts_from_package: boolean;
  max_makeup_per_month: number;
  max_makeup_days: number;
};

export default function LessonPolicyPage() {
  const { toast } = useUI();
  const [policy, setPolicy] = useState<LessonPolicy | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const load = async () => {
    setLoading(true);
    setError(null);
    try {
      const res = await getLessonPolicy();
      setPolicy(res.data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل السياسات");
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    getLessonPolicy()
      .then((res: { data: LessonPolicy }) => setPolicy(res.data))
      .catch((e: Error) => setError(e.message || "تعذر تحميل السياسات"))
      .finally(() => setLoading(false));
  }, []);

  const handleChange = <K extends keyof PolicyForm>(
    key: K,
    value: PolicyForm[K],
  ) => {
    setPolicy((prev: LessonPolicy | null) =>
      prev ? { ...prev, [key]: value } : null,
    );
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!policy) return;

    setSaving(true);
    try {
      const payload: Partial<PolicyForm> = {
        student_absence_consumes_lesson: policy.student_absence_consumes_lesson,
        student_absence_teacher_paid: policy.student_absence_teacher_paid,
        teacher_absence_consumes_lesson: policy.teacher_absence_consumes_lesson,
        teacher_absence_teacher_paid: policy.teacher_absence_teacher_paid,
        makeup_consumes_lesson: policy.makeup_consumes_lesson,
        makeup_teacher_paid: policy.makeup_teacher_paid,
        makeup_deducts_from_package: policy.makeup_deducts_from_package,
        max_makeup_per_month: policy.max_makeup_per_month,
        max_makeup_days: policy.max_makeup_days,
      };

      await updateLessonPolicy(payload);
      toast.success("تم حفظ السياسات");
    } catch (e) {
      toast.apiError("فشل الحفظ", e);
    } finally {
      setSaving(false);
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

  if (!policy) return null;

  return (
    <div>
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">سياسات الحصة</h1>
          <p className="mt-0.5 text-xs text-slate-500">
            تتحكم في استهلاك الحصة وأجر المعلم لكل حالة
          </p>
        </div>
      </div>

      <form onSubmit={handleSubmit}>
        <div className="space-y-6">
          {/* غياب الطالب */}
          <PolicySection title="غياب الطالب" icon="👤">
            <CheckboxRow
              label="الحصة تُستهلك من رصيد الطالب"
              checked={policy.student_absence_consumes_lesson}
              onChange={(v) => handleChange("student_absence_consumes_lesson", v)}
              help="لو مفعل: الطالب الغايب بتتخصم حصة من باقته"
            />
            <CheckboxRow
              label="المعلم يتقاضى أجره على الغياب"
              checked={policy.student_absence_teacher_paid}
              onChange={(v) => handleChange("student_absence_teacher_paid", v)}
              help="لو مفعل: المعلم بياخد أجر الحصة حتى لو الطالب غاب"
            />
          </PolicySection>

          {/* غياب المعلم */}
          <PolicySection title="غياب المعلم" icon="👨‍🏫">
            <CheckboxRow
              label="الحصة تُستهلك من رصيد الطالب"
              checked={policy.teacher_absence_consumes_lesson}
              onChange={(v) => handleChange("teacher_absence_consumes_lesson", v)}
              help="لو مفعل: الحصة الملغية بتتخصم من باقة الطالب"
            />
            <CheckboxRow
              label="المعلم يتقاضى أجره"
              checked={policy.teacher_absence_teacher_paid}
              onChange={(v) => handleChange("teacher_absence_teacher_paid", v)}
              help="لو مفعل: المعلم بياخد أجره حتى لو هو الغايب"
            />
          </PolicySection>

          {/* الحصة التعويضية */}
          <PolicySection title="الحصة التعويضية" icon="🔁">
            <CheckboxRow
              label="تُستهلك من رصيد الطالب"
              checked={policy.makeup_consumes_lesson}
              onChange={(v) => handleChange("makeup_consumes_lesson", v)}
              help="لو مفعل: حصة التعويض بتخصم من باقة الطالب الأصلية"
            />
            <CheckboxRow
              label="المعلم يتقاضى أجره"
              checked={policy.makeup_teacher_paid}
              onChange={(v) => handleChange("makeup_teacher_paid", v)}
              help="لو مفعل: المعلم بياخد أجر على حصة التعويض"
            />
            <CheckboxRow
              label="تُخصم من الباقة الأصلية (مش مجانية)"
              checked={policy.makeup_deducts_from_package}
              onChange={(v) => handleChange("makeup_deducts_from_package", v)}
              help="لو مفعل: التعويض بيتخصم من الباقة، مش حصة إضافية مجانية"
            />
          </PolicySection>

          {/* حدود التعويض */}
          <PolicySection title="حدود التعويض" icon="⏱️">
            <NumberRow
              label="الحد الأقصى للتعويض في الشهر"
              value={policy.max_makeup_per_month}
              onChange={(v) => handleChange("max_makeup_per_month", v)}
              min={0}
              max={31}
              help="أقصى عدد حصص تعويضية مسموحة للطالب في الشهر الواحد"
            />
            <NumberRow
              label="مهلة التعويض (بالأيام)"
              value={policy.max_makeup_days}
              onChange={(v) => handleChange("max_makeup_days", v)}
              min={0}
              max={365}
              help="الطالب يقدر يطلب تعويض خلال كم يوم من تاريخ الحصة الأصلية"
            />
          </PolicySection>
        </div>

        <div className="mt-8 pt-6 border-t border-slate-200 flex justify-end">
          <button
            type="submit"
            className={BTN_PRIMARY}
            disabled={saving}
          >
            {saving ? "جاري الحفظ…" : "حفظ السياسات"}
          </button>
        </div>
      </form>
    </div>
  );
}

function PolicySection({
  title,
  icon,
  children,
}: { title: string; icon: string; children: React.ReactNode }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <h2 className="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-800">
        <span className="text-lg">{icon}</span>
        {title}
      </h2>
      <div className="space-y-3">{children}</div>
    </div>
  );
}

function CheckboxRow({
  label,
  checked,
  onChange,
  help,
}: {
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
  help?: string;
}) {
  return (
    <label className="flex items-start gap-3 cursor-pointer">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="mt-1 w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
      />
      <div className="flex-1">
        <span className="text-sm text-slate-700">{label}</span>
        {help && <p className="mt-0.5 text-[11px] text-slate-500">{help}</p>}
      </div>
    </label>
  );
}

function NumberRow({
  label,
  value,
  onChange,
  min = 0,
  max = 999,
  help,
}: {
  label: string;
  value: number;
  onChange: (v: number) => void;
  min?: number;
  max?: number;
  help?: string;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-sm text-slate-600">{label}</label>
      <input
        type="number"
        min={min}
        max={max}
        value={value}
        onChange={(e) => onChange(Number(e.target.value) || 0)}
        className={FIELD}
      />
      {help && <p className="text-[11px] text-slate-500">{help}</p>}
    </div>
  );
}