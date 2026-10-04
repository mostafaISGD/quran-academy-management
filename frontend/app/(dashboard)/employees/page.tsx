"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  getEmployees,
  getRoles,
  createEmployee,
  updateEmployee,
  deleteEmployee,
  type Employee,
  type EmployeeStatus,
  type EmploymentType,
  type RoleOption,
} from "@/lib/api";
import Pagination from "@/components/Pagination";
import { useUI, IconTrash, IconPencil } from "@/components/ui";
import EmployeeProfile from "@/components/EmployeeProfile";
import EmployeeFormModal from "@/components/EmployeeFormModal";

/**
 * شاشة الموظفين.
 *
 * الموظف هو السجل الإداري (وظيفة، قسم، مدير، حالة) — وحساب الدخول
 * اختياري ومرتبط بيه. زي المعلمين بالظبط.
 */

// ---------- تسميات ----------

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

const EMPLOYMENT_LABEL: Record<EmploymentType, string> = {
  full_time: "دوام كامل",
  part_time: "دوام جزئي",
  contract: "عقد",
  volunteer: "متطوع",
};

const PAGE_SIZE = 50;

// ---------- أدوات ----------

const fmtDate = (v: string | null | undefined): string => {
  if (!v) return "—";
  const d = new Date(v);
  return Number.isNaN(d.getTime())
    ? "—"
    : d.toLocaleDateString("ar-EG", { day: "numeric", month: "short", year: "numeric" });
};

const initials = (name: string): string => name.trim().charAt(0) || "؟";

const AVATAR_COLORS = [
  "bg-blue-100 text-blue-700", "bg-emerald-100 text-emerald-700",
  "bg-purple-100 text-purple-700", "bg-amber-100 text-amber-700",
  "bg-rose-100 text-rose-700", "bg-teal-100 text-teal-700",
];

function Avatar({ name, url, size = "md" }: { name: string; url?: string | null; size?: "sm" | "md" | "lg" }) {
  const dims = size === "lg" ? "h-16 w-16 text-xl" : size === "sm" ? "h-8 w-8 text-xs" : "h-10 w-10 text-sm";

  if (url) {
    return (
      // eslint-disable-next-line @next/next/no-img-element
      <img src={url} alt={name} className={`${dims} shrink-0 rounded-full object-cover ring-2 ring-slate-100`} />
    );
  }

  const hash = name.split("").reduce((a, c) => a + c.charCodeAt(0), 0);

  return (
    <div className={`${dims} flex shrink-0 items-center justify-center rounded-full font-bold ${AVATAR_COLORS[hash % AVATAR_COLORS.length]}`}>
      {initials(name)}
    </div>
  );
}

function StatCard({ label, value, tone = "slate", hint }: {
  label: string; value: string | number; tone?: "slate" | "green" | "amber" | "slate2"; hint?: string;
}) {
  const tones = {
    slate: "bg-slate-800 text-white",
    green: "bg-emerald-50 text-emerald-800",
    amber: "bg-amber-50 text-amber-800",
    slate2: "bg-slate-50 text-slate-700",
  } as const;

  return (
    <div className={`rounded-xl p-4 ${tones[tone]}`}>
      <p className={`text-xs ${tone === "slate" ? "text-slate-300" : "opacity-70"}`}>{label}</p>
      <p className="mt-1 text-xl font-bold">{value}</p>
      {hint && <p className={`mt-0.5 text-[11px] ${tone === "slate" ? "text-slate-400" : "opacity-60"}`}>{hint}</p>}
    </div>
  );
}

// ============================================================
// الشاشة
// ============================================================

export default function EmployeesPage() {
  const [employees, setEmployees] = useState<Employee[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [counts, setCounts] = useState({ active: 0, inactive: 0, on_leave: 0 });
  const [filters, setFilters] = useState<{ departments: string[]; job_titles: string[] }>({ departments: [], job_titles: [] });

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [departmentFilter, setDepartmentFilter] = useState("");
  const [jobTitleFilter, setJobTitleFilter] = useState("");

  const [showForm, setShowForm] = useState(false);
  const [editing, setEditing] = useState<Employee | null>(null);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [roles, setRoles] = useState<RoleOption[]>([]);

  const { toast, confirm } = useUI();

  const load = useCallback(async (targetPage = 1) => {
    setLoading(true);
    setError(null);
    try {
      const r = await getEmployees({
        page: targetPage,
        per_page: PAGE_SIZE,
        status: statusFilter || undefined,
        department: departmentFilter || undefined,
        job_title: jobTitleFilter || undefined,
        search: search.trim() || undefined,
      });
      setEmployees(r.data);
      setMeta({ total: r.total, last_page: r.last_page });
      setCounts(r.counts);
      setFilters(r.filters);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل الموظفين");
    } finally {
      setLoading(false);
    }
  }, [statusFilter, departmentFilter, jobTitleFilter, search]);

  // بحث بعد توقف الكتابة
  useEffect(() => {
    const t = window.setTimeout(() => load(1), 300);
    return () => window.clearTimeout(t);
  }, [load]);

  useEffect(() => {
    getRoles().then((r) => setRoles(r.data ?? [])).catch(() => setRoles([]));
  }, []);

  const selected = useMemo(
    () => employees.find((e) => e.id === selectedId) ?? null,
    [employees, selectedId],
  );

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    load(target);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleDelete(employee: Employee) {
    const ok = await confirm({
      title: "حذف الموظف",
      message: `متأكد إنك عايز تحذف «${employee.name}»؟\n\n• سجلات الحضور هتفضل في التقارير.\n• الموظف هيتشال من القوائم النشطة.\n• الحساب المرتبط مش هيتحذف.`,
      confirmLabel: "احذف",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;

    try {
      await deleteEmployee(employee.id);
      toast.success("تم حذف الموظف", employee.name);
      setSelectedId(null);
      load(page);
    } catch (err) {
      toast.error("فشل الحذف", err instanceof Error ? err.message : undefined);
    }
  }

  return (
    <div>
      {/* ===== الترويسة ===== */}
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">الموظفون</h1>
          <p className="text-xs text-slate-500">
            سجل كل شخص شغال في الأكاديمية — الوظيفة والدور والصلاحيات منفصلين
          </p>
        </div>
        <button
          onClick={() => { setEditing(null); setShowForm(true); }}
          className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700"
        >
          + إضافة موظف
        </button>
      </div>

      {/* ===== إحصائيات ===== */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <StatCard label="إجمالي الموظفين" value={meta.total} />
        <StatCard label="نشط" value={counts.active} tone="green" />
        <StatCard label="في إجازة" value={counts.on_leave} tone="amber" />
        <StatCard label="غير نشط" value={counts.inactive} tone="slate2" />
      </div>

      {/* ===== الفلاتر ===== */}
      <div className="mb-5 flex flex-wrap items-center gap-2">
        <input
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1); }}
          placeholder="بحث بالاسم أو الإيميل أو الهاتف..."
          className="min-w-[220px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm outline-none focus:border-slate-500"
        />

        <select value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none">
          <option value="">كل الحالات</option>
          {(Object.keys(STATUS_LABEL) as EmployeeStatus[]).map((k) => (
            <option key={k} value={k}>{STATUS_LABEL[k]} ({counts[k]})</option>
          ))}
        </select>

        <select value={departmentFilter} onChange={(e) => { setDepartmentFilter(e.target.value); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none">
          <option value="">كل الأقسام</option>
          {filters.departments.map((d) => <option key={d} value={d}>{d}</option>)}
        </select>

        <select value={jobTitleFilter} onChange={(e) => { setJobTitleFilter(e.target.value); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none">
          <option value="">كل الوظائف</option>
          {filters.job_titles.map((j) => <option key={j} value={j}>{j}</option>)}
        </select>
      </div>

      {error && (
        <div className="mb-4 flex items-center justify-between rounded-lg bg-red-50 px-4 py-2.5">
          <p className="text-sm text-red-700">{error}</p>
          <button onClick={() => load(page)} className="text-sm font-medium text-red-700 underline">
            إعادة المحاولة
          </button>
        </div>
      )}

      {/* ===== الجدول ===== */}
      {loading ? (
        <div className="space-y-2">
          {[0, 1, 2, 3, 4].map((i) => (
            <div key={i} className="h-14 animate-pulse rounded-xl bg-slate-100" />
          ))}
        </div>
      ) : employees.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-sm text-slate-500">
            {search || statusFilter || departmentFilter || jobTitleFilter
              ? "مفيش موظفين بالفلاتر دي"
              : "لسه مفيش موظفين — اضغط «إضافة موظف»"}
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right font-medium">الموظف</th>
                <th className="px-3 py-3 text-right font-medium">الوظيفة</th>
                <th className="hidden px-3 py-3 text-right font-medium md:table-cell">القسم</th>
                <th className="hidden px-3 py-3 text-right font-medium lg:table-cell">الدور</th>
                <th className="hidden px-3 py-3 text-right font-medium lg:table-cell">المدير</th>
                <th className="hidden px-3 py-3 text-right font-medium sm:table-cell">تاريخ الانضمام</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
                <th className="px-3 py-3 text-left font-medium">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {employees.map((e) => (
                <tr key={e.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <button
                      onClick={() => setSelectedId(e.id)}
                      className="flex items-center gap-2.5 text-right"
                    >
                      <Avatar name={e.name} url={e.photo_url} size="sm" />
                      <span>
                        <span className="block font-medium text-slate-800 hover:underline">{e.name}</span>
                        <span dir="ltr" className="block text-[11px] text-slate-400">
                          {e.email ?? e.phone ?? "—"}
                        </span>
                      </span>
                    </button>
                  </td>
                  <td className="px-3 py-3">
                    <p className="text-slate-700">{e.job_title ?? "—"}</p>
                    <p className="text-[11px] text-slate-400">{EMPLOYMENT_LABEL[e.employment_type]}</p>
                  </td>
                  <td className="hidden px-3 py-3 text-slate-600 md:table-cell">{e.department ?? "—"}</td>
                  <td className="hidden px-3 py-3 lg:table-cell">
                    {e.has_account ? (
                      <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">
                        {e.role_label}
                      </span>
                    ) : (
                      <span className="text-xs text-slate-400">بدون حساب</span>
                    )}
                  </td>
                  <td className="hidden px-3 py-3 text-slate-600 lg:table-cell">{e.manager?.name ?? "—"}</td>
                  <td className="hidden px-3 py-3 text-xs text-slate-500 sm:table-cell">{fmtDate(e.joined_at)}</td>
                  <td className="px-3 py-3 text-center">
                    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_TONE[e.status]}`}>
                      {STATUS_LABEL[e.status]}
                    </span>
                  </td>
                  <td className="px-3 py-3">
                    <div className="flex items-center justify-end gap-1">
                      <button
                        onClick={() => { setEditing(e); setShowForm(true); }}
                        title="تعديل"
                        className="rounded p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                      >
                        <IconPencil size={14} />
                      </button>
                      <button
                        onClick={() => handleDelete(e)}
                        title="حذف"
                        className="rounded p-1.5 text-slate-400 transition hover:bg-red-50 hover:text-red-600"
                      >
                        <IconTrash size={14} />
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Pagination
        page={page}
        lastPage={meta.last_page}
        total={meta.total}
        perPage={PAGE_SIZE}
        onChange={goToPage}
        loading={loading}
        itemLabel="موظف"
      />

      {/* ===== ملف الموظف ===== */}
      {selected && (
        <EmployeeProfile
          employeeId={selected.id}
          onClose={() => setSelectedId(null)}
          onEdit={(emp) => { setSelectedId(null); setEditing(emp); setShowForm(true); }}
          onDelete={(emp) => { setSelectedId(null); handleDelete(emp); }}
          roles={roles}
        />
      )}

      {/* ===== فورم الإضافة/التعديل ===== */}
      {showForm && (
        <EmployeeFormModal
          employee={editing}
          roles={roles}
          employees={employees}
          onClose={() => setShowForm(false)}
          onSaved={(saved) => {
            setShowForm(false);
            if (saved.warning) toast.warning("الموظف اتحفظ", saved.warning, 9000);
            else toast.success(editing ? "تم تعديل الموظف" : "تم إضافة الموظف", saved.name);
            load(1);
          }}
        />
      )}
    </div>
  );
}
