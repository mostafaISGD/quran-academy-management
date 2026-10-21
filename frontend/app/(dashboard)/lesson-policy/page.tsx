"use client";

import { useCallback, useState } from "react";
import {
  getLessonPolicy,
  updateLessonPolicy,
  type LessonPolicy,
} from "@/lib/api";
import { useUI } from "@/components/ui";
import { usePage } from "@/lib/use-page";

/**
 * ⭐⭐⭐ **سياسات الحصة (PR 3.2).**
 *
 * ⭐ **صف = (نتيجة × نطاق)** — والجدول بيعرض الصفين جنب بعض:
 * النطاق العام (`global`) ونطاق البرنامج (`program`) لو موجود.
 *
 * ⭐ **التعديل على صف واحد** (`PUT /api/lesson-policy/{id}`) — مش
 * على كائن كامل: عشان تغيير سياسة برنامج مايمسّش بسياسة الأكاديمية.
 *
 * ⭐ **التعديل بيغيّر المستقبل بس** — الحصة اللي اتقبلت قبل
 * التعديل محفوظة بلقطة سياستها.
 */

const BTN_SAVE =
  "rounded-lg bg-emerald-50 px-3 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-100 disabled:opacity-50";

const OUTCOME_LABEL: Record<string, string> = {
  completed: "الحصة مكتملة",
  student_absent: "الطالب غائب",
  teacher_absent: "المعلم غائب",
  cancelled_by_academy: "ملغاة من الأكاديمية",
  cancelled_late: "ملغاة متأخرة",
};

const SCOPE_LABEL: Record<string, string> = {
  global: "كل الأكاديمية",
  program: "برنامج محدد",
};

/** ⭐ الحقول القابلة للتعديل في صف واحد. */
type Editable = Pick<
  LessonPolicy,
  | "deduct_credit"
  | "pay_teacher"
  | "allow_makeup"
  | "makeup_limit"
  | "makeup_window_days"
  | "notify_parent"
  | "late_cancel_notice_hours"
  | "makeup_deducts_from_package"
>;

export default function LessonPolicyPage() {
  const { toast } = useUI();
  const [savingId, setSavingId] = useState<number | null>(null);

  const fetchPolicy = useCallback(() => getLessonPolicy(), []);

  const { data: policyRes, loading, error, reload } = usePage<
    { data: { policies: LessonPolicy[]; defaults: Record<string, Partial<LessonPolicy>> } } | null
  >("lesson-policy", fetchPolicy, { initial: null });

  const policies = policyRes?.data?.policies ?? [];

  async function save(id: number, patch: Partial<Editable>) {
    setSavingId(id);
    try {
      await updateLessonPolicy(id, patch);
      toast.success("تم حفظ السياسة");
      reload();
    } catch (e) {
      toast.apiError("فشل الحفظ", e);
    } finally {
      setSavingId(null);
    }
  }

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
      <div className="mb-6 flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">سياسات الحصة</h1>
          <p className="mt-0.5 text-xs text-slate-500">
            تتحكم في استهلاك الحصة وأجر المعلم لكل نتيجة — والتعديل بيغيّر
            المستقبل بس، والحصص القديمة محفوظة بلقطة سياستها.
          </p>
        </div>
      </div>

      {policies.length === 0 && (
        <p className="rounded-lg bg-slate-50 px-4 py-3 text-sm text-slate-500">
          مفيش سياسات معرّفة بعد.
        </p>
      )}

      <div className="space-y-4">
        {policies.map((policy) => (
          <PolicyRow
            key={policy.id}
            policy={policy}
            saving={savingId === policy.id}
            onSave={(patch) => save(policy.id, patch)}
          />
        ))}
      </div>
    </div>
  );
}

/**
 * ⭐ صف واحد — كل خانة بتتحفظ لوحدها (المهمة الصغيرة أأمن من
 * حفظ كائن كامل فيه صفوف تانية).
 */
function PolicyRow({
  policy,
  saving,
  onSave,
}: {
  policy: LessonPolicy;
  saving: boolean;
  onSave: (patch: Partial<Editable>) => void;
}) {
  const [draft, setDraft] = useState<Editable>({
    deduct_credit: policy.deduct_credit,
    pay_teacher: policy.pay_teacher,
    allow_makeup: policy.allow_makeup,
    makeup_limit: policy.makeup_limit,
    makeup_window_days: policy.makeup_window_days,
    notify_parent: policy.notify_parent,
    late_cancel_notice_hours: policy.late_cancel_notice_hours,
    makeup_deducts_from_package: policy.makeup_deducts_from_package,
  });

  const dirty =
    draft.deduct_credit !== policy.deduct_credit ||
    draft.pay_teacher !== policy.pay_teacher ||
    draft.allow_makeup !== policy.allow_makeup ||
    draft.makeup_limit !== policy.makeup_limit ||
    draft.makeup_window_days !== policy.makeup_window_days ||
    draft.notify_parent !== policy.notify_parent ||
    draft.late_cancel_notice_hours !== policy.late_cancel_notice_hours ||
    draft.makeup_deducts_from_package !== policy.makeup_deducts_from_package;

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-5">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <h2 className="text-sm font-semibold text-slate-800">
            {OUTCOME_LABEL[policy.outcome] ?? policy.outcome}
          </h2>
          <p className="text-[11px] text-slate-500">
            {SCOPE_LABEL[policy.scope] ?? policy.scope}
            {policy.program_name ? ` — ${policy.program_name}` : ""}
          </p>
        </div>
        <button
          type="button"
          onClick={() => onSave(draft)}
          disabled={!dirty || saving}
          className={BTN_SAVE}
        >
          {saving ? "جاري الحفظ…" : "حفظ"}
        </button>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Toggle
          label="تُخصم من رصيد الطالب"
          checked={draft.deduct_credit}
          onChange={(v) => setDraft({ ...draft, deduct_credit: v })}
        />
        <Toggle
          label="المعلم يتقاضى أجره"
          checked={draft.pay_teacher}
          onChange={(v) => setDraft({ ...draft, pay_teacher: v })}
        />
        <Toggle
          label="يُسمح بحصة تعويضية"
          checked={draft.allow_makeup}
          onChange={(v) => setDraft({ ...draft, allow_makeup: v })}
        />
        <Toggle
          label="إشعار ولي الأمر"
          checked={draft.notify_parent}
          onChange={(v) => setDraft({ ...draft, notify_parent: v })}
        />
        <Toggle
          label="التعويض يُخصم من الباقة الأصلية"
          checked={draft.makeup_deducts_from_package}
          onChange={(v) => setDraft({ ...draft, makeup_deducts_from_package: v })}
        />
        <NumberField
          label="حد التعويضات"
          value={draft.makeup_limit}
          max={60}
          onChange={(v) => setDraft({ ...draft, makeup_limit: v })}
        />
        <NumberField
          label="نافذة التعويض (أيام)"
          value={draft.makeup_window_days}
          max={365}
          onChange={(v) => setDraft({ ...draft, makeup_window_days: v })}
        />
        <NumberField
          label="إشعار الإلغاء المتأخر (ساعات)"
          value={draft.late_cancel_notice_hours}
          max={168}
          onChange={(v) => setDraft({ ...draft, late_cancel_notice_hours: v })}
        />
      </div>
    </div>
  );
}

function Toggle({
  label,
  checked,
  onChange,
}: {
  label: string;
  checked: boolean;
  onChange: (v: boolean) => void;
}) {
  return (
    <label className="flex items-start gap-2 cursor-pointer">
      <input
        type="checkbox"
        checked={checked}
        onChange={(e) => onChange(e.target.checked)}
        className="mt-1 w-4 h-4 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500"
      />
      <span className="text-sm text-slate-700">{label}</span>
    </label>
  );
}

function NumberField({
  label,
  value,
  max,
  onChange,
}: {
  label: string;
  value: number;
  max: number;
  onChange: (v: number) => void;
}) {
  return (
    <div className="flex flex-col gap-1">
      <label className="text-sm text-slate-600">{label}</label>
      <input
        type="number"
        min={0}
        max={max}
        value={value}
        onChange={(e) => onChange(Number(e.target.value) || 0)}
        className="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
      />
    </div>
  );
}
