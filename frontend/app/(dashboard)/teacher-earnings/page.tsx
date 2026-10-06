"use client";

import { useCallback, useEffect, useState } from "react";
import { getTeacherEarnings, getTeachers, type TeacherEarning, type Teacher } from "@/lib/api";
import { date } from "@/lib/format";
import Pagination from "@/components/Pagination";

const PAGE_SIZE = 100;

const STATUS_LABEL: Record<TeacherEarning["status"], string> = {
  pending: "معلق",
  approved: "معتمد",
  paid: "مدفوع",
  cancelled: "ملغي",
};

const STATUS_TONE: Record<TeacherEarning["status"], string> = {
  pending: "bg-amber-100 text-amber-700",
  approved: "bg-blue-100 text-blue-700",
  paid: "bg-green-100 text-green-700",
  cancelled: "bg-slate-100 text-slate-500",
};

const fmtMoney = (n: number | undefined) =>
  n === undefined
    ? "—"
    : `${n.toLocaleString("ar-EG", { maximumFractionDigits: 2 })} ج.م`;

export default function TeacherEarningsPage() {
  const [earnings, setEarnings] = useState<TeacherEarning[]>([]);
  const [teachers, setTeachers] = useState<Teacher[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [statusCounts, setStatusCounts] = useState<Record<string, number>>({});
  const [totalAmount, setTotalAmount] = useState(0);

  const [statusFilter, setStatusFilter] = useState("");
  const [teacherFilter, setTeacherFilter] = useState("");

  const load = useCallback(
    async (targetPage = 1, status = "", teacherId = "") => {
      setLoading(true);
      setError(null);
      try {
        const r = await getTeacherEarnings({
          per_page: PAGE_SIZE,
          page: targetPage,
          status: status || undefined,
          teacher_id: teacherId ? Number(teacherId) : undefined,
        });
        setEarnings(r.data);
        setMeta({ total: r.total, last_page: r.last_page });
        setTotalAmount(r.sums?.amount ?? 0);

        const c = r.counts as Record<string, unknown> | undefined;
        const st = c?.status;
        setStatusCounts((st && typeof st === "object" ? st : {}) as Record<string, number>);
      } catch (err) {
        setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
      } finally {
        setLoading(false);
      }
    },
    [],
  );

  useEffect(() => {
    load(1, statusFilter, teacherFilter);
    getTeachers({ per_page: 100 })
      .then((r) => setTeachers(r.data))
      .catch(() => {});
  }, [load, statusFilter, teacherFilter]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    load(target, statusFilter, teacherFilter);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">مستحقات المعلمين</h1>
          <p className="text-sm text-slate-500">مستحقات المعلمين من الحصص والرواتب</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <select
            value={teacherFilter}
            onChange={(e) => { setTeacherFilter(e.target.value); setPage(1); }}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
          >
            <option value="">كل المعلمين</option>
            {teachers.map((t) => (
              <option key={t.id} value={t.id}>{t.full_name}</option>
            ))}
          </select>
          <select
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
            className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700"
          >
            <option value="">كل الحالات</option>
            {Object.entries(STATUS_LABEL).map(([k, v]) => (
              <option key={k} value={k}>{v} ({statusCounts[k] ?? 0})</option>
            ))}
          </select>
        </div>
      </div>

      {/* إحصائيات */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">عدد السجلات</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">إجمالي المستحقات</p>
          <p className="mt-1 text-lg font-bold text-blue-800">{fmtMoney(totalAmount)}</p>
        </div>
        <div className="rounded-xl bg-amber-50 p-4 text-center">
          <p className="text-xs text-amber-600">معلق</p>
          <p className="mt-1 text-xl font-bold text-amber-800">{statusCounts.pending ?? 0}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">مدفوع</p>
          <p className="mt-1 text-xl font-bold text-green-800">{statusCounts.paid ?? 0}</p>
        </div>
      </div>

      {error && (
        <div className="mb-4 flex items-center justify-between rounded-lg bg-red-50 px-4 py-2.5">
          <p className="text-sm text-red-700">{error}</p>
          <button onClick={() => load(page, statusFilter, teacherFilter)} className="text-sm font-medium text-red-700 underline">
            إعادة المحاولة
          </button>
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {[0, 1, 2, 3].map((i) => (
            <div key={i} className="h-14 animate-pulse rounded-xl bg-slate-100" />
          ))}
        </div>
      ) : earnings.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-4xl">👨‍🏫</p>
          <p className="mt-3 text-sm text-slate-500">لا توجد مستحقات</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right font-medium">المعلم</th>
                <th className="px-3 py-3 text-right font-medium">النوع</th>
                <th className="px-3 py-3 text-center font-medium">المبلغ</th>
                <th className="px-3 py-3 text-right font-medium">التاريخ</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {earnings.map((e) => (
                <tr key={e.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3 font-medium text-slate-800">
                    {e.teacher?.full_name ?? `معلم ${e.teacher_id}`}
                  </td>
                  <td className="px-3 py-3 text-xs text-slate-600">
                    {e.lesson_id ? "حصة" : "راتب شهري"}
                  </td>
                  <td className="px-3 py-3 text-center font-medium text-slate-800">
                    {fmtMoney(Number(e.amount))}
                  </td>
                  <td className="px-3 py-3 text-xs text-slate-500">
                    {date(e.earning_date)}
                  </td>
                  <td className="px-3 py-3 text-center">
                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${STATUS_TONE[e.status]}`}>
                      {STATUS_LABEL[e.status]}
                    </span>
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
        itemLabel="مستحق"
      />
    </div>
  );
}