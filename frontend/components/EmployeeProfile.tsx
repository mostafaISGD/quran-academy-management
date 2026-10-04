"use client";

import { useEffect, useState } from "react";
import {
  getEmployee,
  getEmployeeActivity,
  getEmployeeAttendanceMonth,
  type Employee,
  type EmployeeActivity,
  type EmployeeStatus,
  type AttendanceMonth,
  type AttendanceStatus,
  type RoleOption,
} from "@/lib/api";

/**
 * ملف الموظف — Drawer جانبي، صفحة واحدة مقسّمة sections.
 *
 * مفيش tabs: كل المعلومات في scroll واحد، عشان الأدمن يشوف الموظف
 * ويعدّل بياناته من غير ما يتنقل.
 */

const STATUS_LABEL: Record<EmployeeStatus, string> = {
  active: "نشط",
  inactive: "غير نشط",
  on_leave: "في إجازة",
};

const STATUS_TONE: Record<EmployeeStatus, string> = {
  active: "bg-emerald-100 text-emerald-700",
  inactive: "bg-slate-100 text-slate-600",
  on_leave: "bg-amber-100 text-amber-700",
};

const EMPLOYMENT_LABEL: Record<string, string> = {
  full_time: "دوام كامل",
  part_time: "دوام جزئي",
  contract: "عقد",
  volunteer: "متطوع",
};

const ATT_LABEL: Record<AttendanceStatus, string> = {
  present: "حاضر",
  absent: "غائب",
  late: "متأخر",
  on_leave: "إجازة",
  half_day: "نصف يوم",
};

const ATT_DOT: Record<AttendanceStatus, string> = {
  present: "bg-emerald-500",
  absent: "bg-red-500",
  late: "bg-amber-500",
  on_leave: "bg-blue-500",
  half_day: "bg-purple-500",
};

const ACTION_LABEL: Record<string, string> = {
  create: "أنشأ",
  update: "عدّل",
  delete: "حذف",
  login: "سجّل دخول",
  refund: "استرجع",
};

const ENTITY_LABEL: Record<string, string> = {
  student: "طالب",
  teacher: "معلم",
  subscription: "اشتراك",
  invoice: "فاتورة",
  payment: "دفعة",
  employee: "موظف",
  lesson: "حصة",
  program: "برنامج",
  lead: "عميل محتمل",
  user: "مستخدم",
  attendance: "حضور",
};

const fmtDate = (v?: string | null): string => {
  if (!v) return "—";
  const d = new Date(v);
  return Number.isNaN(d.getTime())
    ? "—"
    : d.toLocaleDateString("ar-EG", { day: "numeric", month: "short", year: "numeric" });
};

const fmtDateTime = (v?: string | null): string => {
  if (!v) return "—";
  const d = new Date(v);
  return Number.isNaN(d.getTime())
    ? "—"
    : d.toLocaleString("ar-EG", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
};

const AVATAR_COLORS = [
  "bg-blue-100 text-blue-700", "bg-emerald-100 text-emerald-700",
  "bg-purple-100 text-purple-700", "bg-amber-100 text-amber-700",
  "bg-rose-100 text-rose-700", "bg-teal-100 text-teal-700",
];

function Section({ title, icon, children, action }: {
  title: string; icon: string; children: React.ReactNode; action?: React.ReactNode;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white">
      <header className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
        <h3 className="text-sm font-semibold text-slate-800"><span className="ml-1.5">{icon}</span>{title}</h3>
        {action}
      </header>
      <div className="p-4">{children}</div>
    </section>
  );
}

function InfoGrid({ rows }: { rows: { label: string; value: string; tone?: "warn" | "muted" }[] }) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2.5 sm:grid-cols-2">
      {rows.map((r) => (
        <div key={r.label} className="flex items-baseline justify-between gap-3 border-b border-dashed border-slate-100 pb-2">
          <dt className="shrink-0 text-xs text-slate-500">{r.label}</dt>
          <dd
            className={`truncate text-sm font-medium ${
              r.tone === "warn" ? "text-amber-600" : r.tone === "muted" ? "text-slate-400" : "text-slate-800"
            }`}
          >
            {r.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}

function Empty({ text }: { text: string }) {
  return (
    <div className="rounded-lg border border-dashed border-slate-200 py-6 text-center">
      <p className="text-sm text-slate-400">{text}</p>
    </div>
  );
}

function Skeleton() {
  return (
    <div className="space-y-3">
      <div className="h-24 animate-pulse rounded-xl bg-slate-100" />
      <div className="h-40 animate-pulse rounded-xl bg-slate-100" />
      <div className="h-32 animate-pulse rounded-xl bg-slate-100" />
    </div>
  );
}

// ============================================================

export default function EmployeeProfile({
  employeeId, onClose, onEdit, onDelete, roles,
}: {
  employeeId: number;
  onClose: () => void;
  onEdit: (e: Employee) => void;
  onDelete: (e: Employee) => void;
  roles: RoleOption[];
}) {
  const [employee, setEmployee] = useState<Employee | null>(null);
  const [granted, setGranted] = useState<Record<string, { name: string; granted: boolean }[]>>({});
  const [month, setMonth] = useState<AttendanceMonth | null>(null);
  const [activity, setActivity] = useState<EmployeeActivity[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError(null);

    Promise.all([
      getEmployee(employeeId),
      getEmployeeAttendanceMonth(employeeId).catch(() => null),
      getEmployeeActivity(employeeId).catch(() => null),
    ])
      .then(([detail, att, act]) => {
        if (!active) return;
        setEmployee(detail.employee);
        setGranted(detail.granted);
        setMonth(att);
        setActivity(act?.data ?? []);
      })
      .catch((e) => {
        if (active) setError(e instanceof Error ? e.message : "تعذر تحميل الملف");
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [employeeId]);

  const hash = (employee?.name ?? "").split("").reduce((a, c) => a + c.charCodeAt(0), 0);
  const stats = month?.stats;

  return (
    <div className="fixed inset-0 z-50 flex">
      <div className="flex-1 bg-black/50" onClick={onClose} />
      <div className="flex h-full w-full max-w-2xl flex-col bg-white shadow-2xl">
        {/* ===== الترويسة ===== */}
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 p-4">
          <div className="flex items-center gap-3">
            {employee?.photo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={employee.photo_url} alt={employee.name} className="h-12 w-12 rounded-full object-cover" />
            ) : (
              <div className={`flex h-12 w-12 items-center justify-center rounded-full text-lg font-bold ${AVATAR_COLORS[hash % AVATAR_COLORS.length]}`}>
                {employee?.name.trim().charAt(0) ?? "؟"}
              </div>
            )}
            <div>
              <h2 className="font-bold text-slate-800">{employee?.name ?? "…"}</h2>
              <p className="text-xs text-slate-500">
                {employee?.job_title ?? "—"}
                {employee?.department ? ` · ${employee.department}` : ""}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2">
            {employee && (
              <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_TONE[employee.status]}`}>
                {STATUS_LABEL[employee.status]}
              </span>
            )}
            <button onClick={onClose} className="text-slate-400 hover:text-slate-600">✕</button>
          </div>
        </div>

        {/* ===== المحتوى ===== */}
        <div className="flex-1 space-y-4 overflow-y-auto p-4">
          {loading ? (
            <Skeleton />
          ) : error ? (
            <div className="rounded-lg bg-red-50 p-4 text-sm text-red-700">{error}</div>
          ) : employee ? (
            <>
              {/* ===== نظرة عامة ===== */}
              <Section title="نظرة عامة" icon="👁️">
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  <Mini label="تاريخ الانضمام" value={fmtDate(employee.joined_at)} />
                  <Mini label="القسم" value={employee.department ?? "—"} />
                  <Mini label="الوظيفة" value={employee.job_title ?? "—"} />
                  <Mini
                    label="آخر دخول"
                    value={employee.has_account ? fmtDate(employee.last_login_at) : "—"}
                  />
                </div>

                {employee.manager && (
                  <p className="mt-3 text-xs text-slate-500">
                    المدير المباشر:{" "}
                    <span className="font-medium text-slate-700">{employee.manager.name}</span>
                    {employee.manager.job_title ? ` (${employee.manager.job_title})` : ""}
                  </p>
                )}
              </Section>

              {/* ===== البيانات الشخصية ===== */}
              <Section title="البيانات الشخصية" icon="👤">
                <InfoGrid
                  rows={[
                    { label: "الهاتف", value: `${employee.country_code ?? ""} ${employee.phone ?? ""}`.trim() || "—" },
                    { label: "البريد الإلكتروني", value: employee.email ?? "—" },
                    { label: "الجنس", value: employee.gender === "male" ? "ذكر" : employee.gender === "female" ? "أنثى" : "—" },
                    { label: "تاريخ الميلاد", value: fmtDate(employee.date_of_birth) },
                    { label: "الجنسية", value: employee.nationality ?? "—" },
                    { label: "العنوان", value: employee.address ?? "—" },
                  ]}
                />
              </Section>

              {/* ===== بيانات العمل ===== */}
              <Section title="بيانات العمل" icon="💼">
                <InfoGrid
                  rows={[
                    { label: "نوع التوظيف", value: EMPLOYMENT_LABEL[employee.employment_type] ?? employee.employment_type },
                    { label: "الحالة", value: STATUS_LABEL[employee.status] },
                    { label: "المدير المباشر", value: employee.manager?.name ?? "—" },
                    { label: "ملاحظات", value: employee.notes ?? "—" },
                  ]}
                />
              </Section>

              {/* ===== الدور والصلاحيات ===== */}
              <Section
                title="الدور والصلاحيات"
                icon="🔐"
                action={
                  <span className="text-xs text-slate-400">إدارتها من الإعدادات</span>
                }
              >
                {!employee.has_account ? (
                  <div className="rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                    <p className="font-medium">⚠️ بدون حساب دخول</p>
                    <p className="mt-0.5 text-xs">
                      الموظف ده مش هيقدر يستخدم النظام — عيّن له دور وهو هيتبعتله حساب.
                    </p>
                  </div>
                ) : (
                  <>
                    <div className="mb-3 flex items-center gap-2">
                      <span className="text-xs text-slate-500">الدور:</span>
                      <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-medium text-blue-700">
                        {employee.role_label}
                      </span>
                    </div>

                    <ul className="space-y-1.5">
                      {Object.entries(granted).map(([unit, permissions]) => {
                        const grantedCount = permissions.filter((p) => p.granted).length;
                        const all = grantedCount === permissions.length;
                        const none = grantedCount === 0;

                        return (
                          <li
                            key={unit}
                            className={`flex items-center justify-between rounded-lg px-3 py-2 ${
                              all ? "bg-emerald-50" : none ? "bg-slate-50" : "bg-amber-50"
                            }`}
                          >
                            <span className="text-sm font-medium text-slate-700">{unit}</span>
                            <span className="flex items-center gap-1.5">
                              <span className="text-xs text-slate-500">
                                {none ? "لا يوجد" : `${grantedCount}/${permissions.length}`}
                              </span>
                              <span className={`text-sm font-bold ${all ? "text-emerald-600" : none ? "text-slate-300" : "text-amber-500"}`}>
                                {all ? "✓" : none ? "✗" : "partially"}
                              </span>
                            </span>
                          </li>
                        );
                      })}
                    </ul>
                  </>
                )}
              </Section>

              {/* ===== الحضور (آخر شهر) ===== */}
              {employee.employment_type !== "volunteer" && (
                <Section title="الحضور — آخر ٣٠ يوم" icon="🕐"
                  action={
                    stats?.attendance_rate !== null && stats?.attendance_rate !== undefined ? (
                      <span className="text-xs font-medium text-slate-600">نسبة الحضور {stats.attendance_rate}%</span>
                    ) : null
                  }
                >
                  {!month ? (
                    <Empty text="مفيش بيانات حضور" />
                  ) : (
                    <>
                      <div className="mb-3 grid grid-cols-4 gap-2">
                        <Mini label="حاضر" value={stats?.present ?? 0} />
                        <Mini label="غائب" value={stats?.absent ?? 0} />
                        <Mini label="إجازة" value={stats?.on_leave ?? 0} />
                        <Mini label="ساعات" value={stats?.worked_hours ?? 0} />
                      </div>

                      {/* شبكة الشهر */}
                      <div className="flex flex-wrap gap-1">
                        {month.days.map((d) => (
                          <div
                            key={d.date}
                            title={
                              d.record
                                ? `${d.date} — ${ATT_LABEL[d.record.status]}` +
                                  (d.record.check_in ? ` (${d.record.check_in} - ${d.record.check_out ?? "؟"})` : "")
                                : `${d.date} — لم يُسجّل`
                            }
                            className={`flex h-8 w-8 items-center justify-center rounded text-[11px] font-medium ${
                              d.is_friday
                                ? "bg-slate-100 text-slate-400"
                                : d.record
                                  ? `${ATT_DOT[d.record.status]} text-white`
                                  : d.future
                                    ? "bg-slate-50 text-slate-300"
                                    : "bg-slate-100 text-slate-400"
                            }`}
                          >
                            {d.day}
                          </div>
                        ))}
                      </div>

                      <div className="mt-3 flex flex-wrap gap-3 text-[11px] text-slate-500">
                        {(["present", "late", "half_day", "absent", "on_leave"] as AttendanceStatus[]).map((s) => (
                          <span key={s} className="flex items-center gap-1">
                            <span className={`h-2 w-2 rounded-full ${ATT_DOT[s]}`} />
                            {ATT_LABEL[s]}
                          </span>
                        ))}
                      </div>
                    </>
                  )}
                </Section>
              )}

              {/* ===== سجل النشاط ===== */}
              <Section title="آخر النشاطات" icon="📋">
                {!employee.has_account ? (
                  <Empty text="الموظف مالوش حساب دخول — مفيش نشاط مسجّل" />
                ) : activity.length === 0 ? (
                  <Empty text="لسه مفيش نشاط مسجّل" />
                ) : (
                  <ul className="space-y-2">
                    {activity.map((a) => (
                      <li key={a.id} className="flex items-start gap-2 border-b border-dashed border-slate-100 pb-2 last:border-0">
                        <span className="mt-0.5 shrink-0 text-xs text-slate-400">{fmtDateTime(a.created_at)}</span>
                        <span className="text-sm text-slate-700">
                          {ACTION_LABEL[a.action] ?? a.action}{" "}
                          <span className="text-slate-500">
                            {ENTITY_LABEL[a.entity_type] ?? a.entity_type}
                            {a.entity_id ? ` #${a.entity_id}` : ""}
                          </span>
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </Section>
            </>
          ) : null}
        </div>

        {/* ===== الأزرار ===== */}
        {employee && (
          <div className="flex gap-2 border-t border-slate-200 p-4">
            <button
              onClick={() => onEdit(employee)}
              className="flex-1 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700"
            >
              تعديل
            </button>
            <button
              onClick={() => onDelete(employee)}
              className="rounded-lg bg-red-50 px-4 py-2 text-sm font-medium text-red-600 transition hover:bg-red-100"
            >
              حذف
            </button>
          </div>
        )}
      </div>
    </div>
  );
}

function Mini({ label, value }: { label: string; value: string | number }) {
  return (
    <div className="rounded-lg bg-slate-50 p-2.5 text-center">
      <p className="text-[11px] text-slate-500">{label}</p>
      <p className="mt-0.5 text-sm font-bold text-slate-800">{value}</p>
    </div>
  );
}
