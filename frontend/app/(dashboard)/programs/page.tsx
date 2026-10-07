"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  deleteProgram,
  getPrograms,
  type Program,
  type ProgramCategoryFilter,
} from "@/lib/api";
import Pagination from "@/components/Pagination";
import { useUI } from "@/components/ui";
import ProgramCard from "@/components/ProgramCard";
import ProgramCategoriesPanel from "@/components/ProgramCategoriesPanel";
import ProgramFormModal from "@/components/ProgramFormModal";
import ProgramProfile from "@/components/ProgramProfile";
import { Pill, Skeleton, alpha } from "@/components/ProgramPrimitives";

/**
 * شاشة البرامج.
 *
 * البرنامج = تعريف الخدمة التعليمية. الشاشة دي بتعرّف البرامج
 * وبتعرض اللي مرتبط بيها — الجدول والحضور والدفع ليها أقسامها.
 */

const PAGE_SIZE = 12;

type View = "cards" | "table";

export default function ProgramsPage() {
  const { toast, confirm } = useUI();

  const [programs, setPrograms] = useState<Program[]>([]);
  const [categories, setCategories] = useState<ProgramCategoryFilter[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [categoryFilter, setCategoryFilter] = useState<number | null>(null);
  const [onlyMismatched, setOnlyMismatched] = useState(false);
  const [view, setView] = useState<View>("cards");

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [counts, setCounts] = useState({ active: 0, inactive: 0 });
  const [mismatchPrograms, setMismatchPrograms] = useState(0);

  const [openId, setOpenId] = useState<number | null>(null);
  const [editing, setEditing] = useState<Program | null | undefined>(undefined);
  const [showCats, setShowCats] = useState(false);

  const load = useCallback(
    async (
      targetPage = 1,
      filters: { status?: string; category_id?: number; search?: string; has_mismatches?: boolean },
    ) => {
      setLoading(true);
      setError(null);
      try {
        const r = await getPrograms({ ...filters, page: targetPage, per_page: PAGE_SIZE });
        setPrograms(r.data);
        setCategories(r.filters?.categories ?? []);
        setMeta({ total: r.total, last_page: r.last_page });
        setCounts({ active: r.counts?.active ?? 0, inactive: r.counts?.inactive ?? 0 });
        setMismatchPrograms(r.mismatch_programs ?? 0);
      } catch (e) {
        setError(e instanceof Error ? e.message : "تعذر تحميل البيانات");
      } finally {
        setLoading(false);
      }
    },
    [],
  );

  // ندز الفلتر الحالي مرة واحدة في كل حقل — نتفادى تكرار ternary
  // في ٣ أماكن اللي كل واحد طوّل.
  const currentFilters = useCallback(
    () => ({
      status: statusFilter || undefined,
      category_id: categoryFilter ?? undefined,
      search: search.trim() || undefined,
      has_mismatches: onlyMismatched || undefined,
    }),
    [statusFilter, categoryFilter, search, onlyMismatched],
  );

  // debounce للبحث — عشان كل حرف متعملش request
  useEffect(() => {
    const t = setTimeout(
      () => load(1, currentFilters()),
      search ? 300 : 0,
    );
    return () => clearTimeout(t);
  }, [load, currentFilters, search]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    load(target, currentFilters());
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  const refresh = () => load(page, currentFilters());

  async function handleDelete(p: Program) {
    const ok = await confirm({
      title: "حذف البرنامج",
      message: `متأكد إنك عايز تحذف «${p.name}»؟\nالاشتراكات والحصص المرتبطة بيه مش هتتحذف.`,
      confirmLabel: "احذف البرنامج",
      tone: "danger",
    });
    if (!ok) return;

    try {
      await deleteProgram(p.id);
      toast.success("تم حذف البرنامج", p.name);
      if (openId === p.id) setOpenId(null);
      refresh();
    } catch (e) {
      toast.apiError("فشل حذف البرنامج", e);
    }
  }

  /** التصنيفات المستخدمة في الفلترة فقط — اللي ليها برامج */
  const activeFilters = useMemo(
    () => categories.filter((c) => c.programs_count > 0),
    [categories],
  );

  return (
    <div>
      {/* ============ الترويسة ============ */}
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">البرامج التعليمية</h1>
          <p className="text-sm text-slate-500">
            تعريف الخدمة التعليمية اللي بتقدمها الأكاديمية ومستويات ومعلمين وباقاتها
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          {/* مبدّل العرض: كارت / جدول */}
          <div className="flex overflow-hidden rounded-lg border border-slate-300">
            <button
              onClick={() => setView("cards")}
              className={`px-2.5 py-2 text-xs ${view === "cards" ? "bg-slate-800 text-white" : "bg-white text-slate-600 hover:bg-slate-50"}`}
              title="عرض كروت"
            >
              ▦ كارت
            </button>
            <button
              onClick={() => setView("table")}
              className={`px-2.5 py-2 text-xs ${view === "table" ? "bg-slate-800 text-white" : "bg-white text-slate-600 hover:bg-slate-50"}`}
              title="عرض جدول"
            >
              ☰ جدول
            </button>
          </div>

          <button
            onClick={() => setShowCats(true)}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-50"
          >
            إدارة التصنيفات
          </button>

          <button
            onClick={() => setEditing(null)}
            className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700"
          >
            + إضافة برنامج
          </button>
        </div>
      </div>

      {/* ============ المؤشرات ============ */}
      <div className="mb-4 grid grid-cols-4 gap-3">
        {[
          { label: "إجمالي البرامج", value: counts.active + counts.inactive, tone: "bg-slate-800 text-white", sub: "text-slate-300" },
          { label: "البرامج النشطة", value: counts.active, tone: "bg-emerald-50 text-emerald-800", sub: "text-emerald-600" },
          { label: "البرامج غير النشطة", value: counts.inactive, tone: "bg-slate-100 text-slate-600", sub: "text-slate-400" },
          { label: "فيها عدم تطابق", value: mismatchPrograms, tone: "bg-amber-50 text-amber-800", sub: "text-amber-600" },
        ].map((s) => (
          <div key={s.label} className={`rounded-xl p-4 text-center ${s.tone}`}>
            <p className={`text-xs ${s.sub}`}>{s.label}</p>
            <p className="mt-1 text-xl font-bold">{s.value}</p>
          </div>
        ))}
      </div>

      {/* ============ البحث والفلاتر ============ */}
      <div className="mb-5 flex flex-wrap items-center gap-2">
        <input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="ابحث باسم البرنامج أو الوصف…"
          className="min-w-[220px] flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm"
        />

        <select
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          <option value="">كل الحالات</option>
          <option value="active">نشط ({counts.active})</option>
          <option value="inactive">غير نشط ({counts.inactive})</option>
        </select>

        <select
          value={categoryFilter ?? ""}
          onChange={(e) => { setCategoryFilter(e.target.value ? Number(e.target.value) : null); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
        >
          <option value="">كل التصنيفات</option>
          {activeFilters.map((c) => (
            <option key={c.id} value={c.id}>
              {c.icon} {c.name} ({c.programs_count})
            </option>
          ))}
        </select>

        {(statusFilter || categoryFilter || search || onlyMismatched) && (
          <button
            onClick={() => {
              setStatusFilter(""); setCategoryFilter(null); setSearch(""); setOnlyMismatched(false); setPage(1);
            }}
            className="rounded-lg bg-slate-100 px-3 py-2 text-sm text-slate-600 hover:bg-slate-200"
          >
            مسح الفلاتر
          </button>
        )}
      </div>

      {/* ============ فلتر عدم التطابق ============ */}
      {mismatchPrograms > 0 && (
        <label className="mb-4 flex cursor-pointer items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
          <input
            type="checkbox"
            checked={onlyMismatched}
            onChange={(e) => { setOnlyMismatched(e.target.checked); setPage(1); }}
            className="mt-0.5 h-4 w-4 rounded border-amber-300 text-amber-600"
          />
          <span className="text-sm text-amber-800">
            <b>{mismatchPrograms}</b> برنامج ربط معلميه مختلف عن حصصهم الفعلية.
            <span className="block text-xs text-amber-700">
              افتح البرنامج لتثبيت الوضع — أو استخدم الأزرار الجماعية جوّه الملف.
            </span>
          </span>
        </label>
      )}

      {/* ============ المحتوى ============ */}
      {loading ? (
        <Skeleton rows={3} />
      ) : error ? (
        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">{error}</div>
      ) : programs.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-12 text-center">
          <p className="text-sm text-slate-400">
            {search || statusFilter || categoryFilter || onlyMismatched
              ? "مفيش نتائج للفلاتر دي"
              : "لسه مفيش برامج — اضغط «إضافة برنامج»"}
          </p>
        </div>
      ) : view === "cards" ? (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
          {programs.map((p) => (
            <ProgramCard key={p.id} program={p} onOpen={(x) => setOpenId(x.id)} />
          ))}
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-slate-200 bg-slate-50 text-start text-xs text-slate-500">
                <th className="px-3 py-2.5 font-medium">البرنامج</th>
                <th className="px-3 py-2.5 font-medium">التصنيفات</th>
                <th className="px-3 py-2.5 font-medium">طلاب</th>
                <th className="px-3 py-2.5 font-medium">معلمون</th>
                <th className="px-3 py-2.5 font-medium">مستويات</th>
                <th className="px-3 py-2.5 font-medium">الحالة</th>
                <th className="px-3 py-2.5 font-medium">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {programs.map((p) => {
                const color = p.color ?? "#64748b";
                return (
                  <tr key={p.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-3 py-2">
                      <button
                        onClick={() => setOpenId(p.id)}
                        className="flex items-center gap-2 text-start"
                      >
                        <span
                          className="flex h-7 w-7 shrink-0 items-center justify-center rounded text-xs font-bold"
                          style={{ backgroundColor: alpha(color, 0.13), color }}
                        >
                          {p.name.trim().charAt(0)}
                        </span>
                        <span className="min-w-0">
                          <span className="block truncate font-medium text-slate-800">{p.name}</span>
                          <span className="block truncate text-xs text-slate-400" dir="ltr">{p.slug}</span>
                        </span>
                      </button>
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex flex-wrap gap-1">
                        {(p.categories ?? []).slice(0, 2).map((c) => (
                          <span key={c.id} className="inline-flex items-center gap-0.5 rounded bg-slate-50 px-1.5 py-0.5 text-[11px] text-slate-600">
                            {c.icon && <span>{c.icon}</span>}{c.name}
                          </span>
                        ))}
                        {(p.categories?.length ?? 0) > 2 && (
                          <span className="text-[11px] text-slate-400">+{(p.categories?.length ?? 0) - 2}</span>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2 font-medium text-slate-700">{p.students_count ?? 0}</td>
                    <td className="px-3 py-2 font-medium text-slate-700">{p.teachers_count ?? 0}</td>
                    <td className="px-3 py-2 font-medium text-slate-700">{p.levels_count ?? 0}</td>
                    <td className="px-3 py-2">
                      <div className="flex items-center gap-1.5">
                        <Pill tone={p.status === "active" ? "green" : "red"}>
                          {p.status === "active" ? "نشط" : "غير نشط"}
                        </Pill>
                        {p.mismatches && p.mismatches.total > 0 && (
                          <span
                            className="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-700"
                            title={`${p.mismatches.unlinked} غير مسجّل · ${p.mismatches.idle} بلا حصص`}
                          >
                            ⚠ {p.mismatches.total}
                          </span>
                        )}
                      </div>
                    </td>
                    <td className="px-3 py-2">
                      <div className="flex gap-1">
                        <button onClick={() => setEditing(p)} className="rounded bg-blue-50 px-2 py-1 text-xs text-blue-600 hover:bg-blue-100">تعديل</button>
                        <button onClick={() => handleDelete(p)} className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100">حذف</button>
                      </div>
                    </td>
                  </tr>
                );
              })}
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
        itemLabel="برنامج"
      />

      {/* ============ الملف ============ */}
      {openId !== null && (
        <ProgramProfile
          key={openId}
          programId={openId}
          onClose={() => setOpenId(null)}
          onEdit={(p) => setEditing(p)}
          onChanged={refresh}
        />
      )}

      {/* ============ النموذج ============ */}
      {editing !== undefined && (
        <ProgramFormModal
          // remount عند تغيير البرنامج عشان الـ form يتفInitialize من جديد
          key={editing ? `edit-${editing.id}` : "create"}
          program={editing}
          categories={categories}
          onClose={() => setEditing(undefined)}
          onSaved={refresh}
        />
      )}

      {/* ============ التصنيفات ============ */}
      {showCats && (
        <ProgramCategoriesPanel
          items={categories}
          onClose={() => setShowCats(false)}
          onChanged={refresh}
        />
      )}
    </div>
  );
}