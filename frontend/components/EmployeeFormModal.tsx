"use client";

import { useState } from "react";
import {
  createEmployee,
  updateEmployee,
  type Employee,
  type EmployeePayload,
  type EmploymentType,
  type RoleOption,
} from "@/lib/api";

/**
 * فورم إضافة/تعديل موظف.
 *
 * القاعدة الأساسية: لو اخترت دور، الحساب بيتعمل لوحده. ولو طلبت
 * حساب من غير دور، الـ backend بيرجع تحذير («مش هيقدر يفتح أي صفحة»)
 * وده بيتبان هنا كتحذير أصفر قبل الحفظ حتى.
 */

const EMPLOYMENT_OPTIONS: { value: EmploymentType; label: string }[] = [
  { value: "full_time", label: "دوام كامل" },
  { value: "part_time", label: "دوام جزئي" },
  { value: "contract", label: "عقد" },
  { value: "volunteer", label: "متطوع" },
];

const STATUS_OPTIONS: { value: string; label: string }[] = [
  { value: "active", label: "نشط" },
  { value: "on_leave", label: "في إجازة" },
  { value: "inactive", label: "غير نشط" },
];

const FIELD =
  "w-full rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500";
const LABEL = "mb-1 block text-xs font-medium text-slate-600";

export default function EmployeeFormModal({
  employee, roles, employees, onClose, onSaved,
}: {
  employee: Employee | null;
  roles: RoleOption[];
  employees: Employee[];
  onClose: () => void;
  onSaved: (saved: Employee & { warning?: string }) => void;
}) {
  const isEdit = employee !== null;

  const [form, setForm] = useState<EmployeePayload>({
    name: employee?.name ?? "",
    phone: employee?.phone ?? "",
    country_code: employee?.country_code ?? "+20",
    email: employee?.email ?? "",
    gender: employee?.gender ?? "",
    date_of_birth: employee?.date_of_birth ?? "",
    nationality: employee?.nationality ?? "",
    address: employee?.address ?? "",
    job_title: employee?.job_title ?? "",
    department: employee?.department ?? "",
    employment_type: employee?.employment_type ?? "full_time",
    manager_id: employee?.manager_id ?? null,
    joined_at: employee?.joined_at ?? new Date().toISOString().slice(0, 10),
    status: employee?.status ?? "active",
    notes: employee?.notes ?? "",
    // الموظف الجديد: الحساب اختياري — بس لو اخترنا دور هيتعمل تلقائي
    create_account: false,
    role_id: null,
  });

  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const set = <K extends keyof EmployeePayload>(key: K, value: EmployeePayload[K]) =>
    setForm((f) => ({ ...f, [key]: value }));

  // دور موجود بالفعل؟ (وضع التعديل)
  const existingRoleId =
    isEdit && employee?.role
      ? roles.find((r) => r.name === employee.role)?.id ?? null
      : null;

  // الدور المختار — من الفورم أو الموجود فعلاً
  const chosenRoleId = form.role_id ?? existingRoleId;
  const willHaveAccount = Boolean(form.create_account) || Boolean(chosenRoleId);
  const accountWarning = willHaveAccount && !chosenRoleId;

  // التنبيه قبل الحفظ — نفس اللي الـ backend بيرجّعه
  const warning =
    accountWarning && !isEdit
      ? "الموظف ده هيكون ليه حساب دخول بدون صلاحيات — مش هيقدر يفتح أي صفحة."
      : null;

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (saving) return;

    setSaving(true);
    setError(null);

    try {
      const payload: EmployeePayload = {
        ...form,
        // التاريخ بيشيل الوقت لو Carbon رجعه
        joined_at: form.joined_at || null,
        date_of_birth: form.date_of_birth || null,
      };

      const saved = isEdit
        ? await updateEmployee(employee!.id, payload)
        : await createEmployee(payload);

      // الـ backend بيرجع warning لما الحساب يتعمل من غير دور
      onSaved({ ...saved, warning: (saved as { warning?: string }).warning });
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل الحفظ");
    } finally {
      setSaving(false);
    }
  }

  // المدير المباشر مش نفسه
  const managerOptions = employees.filter((e) => e.id !== employee?.id);

  return (
    <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4">
      <div className="my-8 w-full max-w-2xl rounded-xl bg-white shadow-2xl">
        <div className="flex items-center justify-between border-b border-slate-200 px-5 py-3">
          <h3 className="font-semibold text-slate-800">
            {isEdit ? `تعديل — ${employee!.name}` : "إضافة موظف"}
          </h3>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600">✕</button>
        </div>

        <form onSubmit={handleSubmit} className="space-y-5 px-5 py-4">
          {/* ===== البيانات الشخصية ===== */}
          <fieldset className="rounded-lg bg-slate-50 p-4">
            <legend className="px-1 text-xs font-semibold text-slate-700">البيانات الشخصية</legend>

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <label className={LABEL}>الاسم الكامل *</label>
                <input required value={form.name} onChange={(e) => set("name", e.target.value)} className={FIELD} />
              </div>
              <div>
                <label className={LABEL}>رقم الهاتف</label>
                <div className="flex gap-2">
                  <select value={form.country_code ?? "+20"} onChange={(e) => set("country_code", e.target.value)}
                    className="w-20 rounded-lg border border-slate-300 px-2 py-2 text-sm">
                    {["+20", "+966", "+971", "+974", "+212", "+253"].map((c) => (
                      <option key={c} value={c}>{c}</option>
                    ))}
                  </select>
                  <input dir="ltr" value={form.phone ?? ""} onChange={(e) => set("phone", e.target.value)} className={FIELD} />
                </div>
              </div>
              <div>
                <label className={LABEL}>البريد الإلكتروني</label>
                <input dir="ltr" type="email" value={form.email ?? ""} onChange={(e) => set("email", e.target.value)}
                  className={FIELD} placeholder="يتطلب لازم لو عايز حساب دخول" />
              </div>
              <div className="flex gap-3">
                <div className="flex-1">
                  <label className={LABEL}>الجنس</label>
                  <select value={form.gender ?? ""} onChange={(e) => set("gender", e.target.value)} className={FIELD}>
                    <option value="">—</option>
                    <option value="male">ذكر</option>
                    <option value="female">أنثى</option>
                  </select>
                </div>
                <div className="flex-1">
                  <label className={LABEL}>تاريخ الميلاد</label>
                  <input type="date" value={(form.date_of_birth ?? "").slice(0, 10)} onChange={(e) => set("date_of_birth", e.target.value)} className={FIELD} />
                </div>
              </div>
              <div>
                <label className={LABEL}>الجنسية</label>
                <input value={form.nationality ?? ""} onChange={(e) => set("nationality", e.target.value)} className={FIELD} />
              </div>
              <div>
                <label className={LABEL}>العنوان</label>
                <input value={form.address ?? ""} onChange={(e) => set("address", e.target.value)} className={FIELD} />
              </div>
            </div>
          </fieldset>

          {/* ===== بيانات العمل ===== */}
          <fieldset className="rounded-lg bg-blue-50/40 p-4">
            <legend className="px-1 text-xs font-semibold text-slate-700">بيانات العمل</legend>

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <label className={LABEL}>الوظيفة</label>
                <input value={form.job_title ?? ""} onChange={(e) => set("job_title", e.target.value)}
                  className={FIELD} list="job-titles" placeholder="مثال: محاسب" />
                <datalist id="job-titles">
                  {["محاسب", "موظف استقبال", "مشرف أكاديمية", "خدمة عملاء", "مسؤول متابعة", "مبيعات", "مسؤول تقنية"]
                    .map((j) => <option key={j} value={j} />)}
                </datalist>
              </div>
              <div>
                <label className={LABEL}>القسم</label>
                <input value={form.department ?? ""} onChange={(e) => set("department", e.target.value)}
                  className={FIELD} list="departments" placeholder="مثال: المالية" />
                <datalist id="departments">
                  {["الإدارة", "الاستقبال", "المالية", "المبيعات", "التسويق", "تقنية المعلومات"]
                    .map((d) => <option key={d} value={d} />)}
                </datalist>
              </div>
              <div>
                <label className={LABEL}>نوع التوظيف</label>
                <select value={form.employment_type} onChange={(e) => set("employment_type", e.target.value as EmploymentType)} className={FIELD}>
                  {EMPLOYMENT_OPTIONS.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                  ))}
                </select>
                {form.employment_type === "volunteer" && (
                  <p className="mt-1 text-[11px] text-amber-600">
                    المتطوع مش بيشتغل بدوام — مش ليه حضور ومش محتاج حساب.
                  </p>
                )}
              </div>
              <div>
                <label className={LABEL}>الحالة</label>
                <select value={form.status} onChange={(e) => set("status", e.target.value as EmployeePayload["status"])} className={FIELD}>
                  {STATUS_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                </select>
              </div>
              <div>
                <label className={LABEL}>المدير المباشر</label>
                <select value={form.manager_id ?? ""} onChange={(e) => set("manager_id", e.target.value ? Number(e.target.value) : null)} className={FIELD}>
                  <option value="">— بدون —</option>
                  {managerOptions.map((m) => (
                    <option key={m.id} value={m.id}>{m.name}{m.job_title ? ` — ${m.job_title}` : ""}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className={LABEL}>تاريخ الانضمام</label>
                <input type="date" value={(form.joined_at ?? "").slice(0, 10)} onChange={(e) => set("joined_at", e.target.value)} className={FIELD} />
              </div>
            </div>
          </fieldset>

          {/* ===== حساب الدخول والدور ===== */}
          <fieldset className="rounded-lg bg-purple-50/40 p-4">
            <legend className="px-1 text-xs font-semibold text-slate-700">حساب الدخول والدور</legend>

            {!isEdit && (
              <label className="mb-3 flex cursor-pointer items-center gap-2">
                <input
                  type="checkbox"
                  checked={Boolean(form.create_account)}
                  onChange={(e) => set("create_account", e.target.checked)}
                  className="h-4 w-4 rounded border-slate-300"
                />
                <span className="text-sm text-slate-700">إنشاء حساب دخول لهذا الموظف</span>
              </label>
            )}

            <div className="grid gap-3 sm:grid-cols-2">
              <div>
                <label className={LABEL}>الدور في النظام</label>
                <select
                  value={chosenRoleId ?? ""}
                  onChange={(e) => set("role_id", e.target.value ? Number(e.target.value) : null)}
                  disabled={isEdit && !employee?.has_account}
                  className={`${FIELD} disabled:bg-slate-100 disabled:text-slate-400`}
                >
                  <option value="">— بدون دور —</option>
                  {roles
                    .filter((r) => !["teacher", "parent"].includes(r.name))
                    .map((r) => (
                      <option key={r.id} value={r.id}>{r.name}</option>
                    ))}
                </select>
              </div>

              <div>
                <label className={LABEL}>ملاحظات</label>
                <input value={form.notes ?? ""} onChange={(e) => set("notes", e.target.value)} className={FIELD} />
              </div>
            </div>

            {/* القاعدة: دور بلا حساب = بلا فايدة */}
            <p className="mt-3 rounded-lg bg-white/70 px-3 py-2 text-[11px] leading-relaxed text-slate-600">
              {employee?.has_account ? (
                <>الحساب موجود بالفعل — تغيير الدور هيعيّنه فوراً.</>
              ) : chosenRoleId ? (
                <>اختارة دور معناها الحساب هيتعمل تلقائياً — حساب بدون صلاحيات ما بيعملش حاجة.</>
              ) : (
                <>من غير دور، الموظف هيكون سجل إداري بس (من غير حساب دخول).</>
              )}
            </p>

            {warning && (
              <p className="mt-2 rounded-lg bg-amber-100 px-3 py-2 text-xs text-amber-800">
                ⚠️ {warning}
              </p>
            )}
          </fieldset>

          {error && (
            <div className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{error}</div>
          )}

          {/* ===== الأزرار ===== */}
          <div className="flex justify-end gap-2 border-t border-slate-200 pt-4">
            <button type="button" onClick={onClose}
              className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-600 hover:bg-slate-200">
              إلغاء
            </button>
            <button type="submit" disabled={saving}
              className="rounded-lg bg-slate-800 px-5 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">
              {saving ? "جاري الحفظ…" : isEdit ? "حفظ التعديلات" : "إضافة الموظف"}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
