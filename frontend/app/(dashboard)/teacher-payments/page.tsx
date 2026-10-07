"use client";

import { useCallback, useEffect, useState } from "react";
import { getTeacherPayments, getTeachers, type TeacherPayment, type Teacher } from "@/lib/api";
import { date, egp } from "@/lib/format";
import Pagination from "@/components/Pagination";

const PAGE_SIZE = 100;

const STATUS_LABEL: Record<TeacherPayment["status"], string> = {
  pending: "معلق",
  completed: "مكتمل",
  failed: "فاشل",
};

const STATUS_TONE: Record<TeacherPayment["status"], string> = {
  pending: "bg-amber-100 text-amber-700",
  completed: "bg-green-100 text-green-700",
  failed: "bg-red-100 text-red-700",
};

const METHOD_LABEL: Record<string, string> = {
  cash: "نقدي",
  bank_transfer: "تحويل بنكي",
  wallet: "محفظة",
};

/** ⭐ alias — التنسيق في `lib/format` (كان `toLocaleString` مكرر هنا) */
const fmtMoney = egp;

export default function TeacherPaymentsPage() {
  const [payments, setPayments] = useState<TeacherPayment[]>([]);
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
        const r = await getTeacherPayments({
          per_page: PAGE_SIZE,
          page: targetPage,
          status: status || undefined,
          teacher_id: teacherId ? Number(teacherId) : undefined,
        });
        setPayments(r.data);
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
          <h1 className="text-lg font-semibold text-slate-800">مدفوعات المعلمين</h1>
          <p className="text-sm text-slate-500">دفعات الرواتب للمعلمين</p>
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
          <p className="text-xs text-slate-300">عدد الدفعات</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">إجمالي المدفوع</p>
          <p className="mt-1 text-lg font-bold text-green-800">{fmtMoney(totalAmount)}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">مكتمل</p>
          <p className="mt-1 text-xl font-bold text-blue-800">{statusCounts.completed ?? 0}</p>
        </div>
        <div className="rounded-xl bg-amber-50 p-4 text-center">
          <p className="text-xs text-amber-600">معلق</p>
          <p className="mt-1 text-xl font-bold text-amber-800">{statusCounts.pending ?? 0}</p>
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
      ) : payments.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-4xl">💸</p>
          <p className="mt-3 text-sm text-slate-500">لا توجد مدفوعات</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-start font-medium">المعلم</th>
                <th className="px-3 py-3 text-start font-medium">فترة الرواتب</th>
                <th className="px-3 py-3 text-center font-medium">المبلغ</th>
                <th className="hidden px-3 py-3 text-start font-medium sm:table-cell">تاريخ الدفع</th>
                <th className="hidden px-3 py-3 text-start font-medium md:table-cell">الطريقة</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
              </tr>
            </thead>
            <tbody>
              {payments.map((p) => (
                <tr key={p.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3 font-medium text-slate-800">
                    {p.teacher?.full_name ?? `معلم ${p.teacher_id}`}
                  </td>
                  <td className="px-3 py-3 text-xs text-slate-600">
                    {p.payrollPeriod?.name ?? "—"}
                  </td>
                  <td className="px-3 py-3 text-center font-medium text-slate-800">
                    {fmtMoney(Number(p.amount))}
                  </td>
                  <td className="hidden px-3 py-3 text-xs text-slate-500 sm:table-cell">
                    {p.paid_at ? p.paid_at ? date(p.paid_at) : "—" : "—"}
                  </td>
                  <td className="hidden px-3 py-3 text-xs text-slate-600 md:table-cell">
                    {METHOD_LABEL[p.payment_method ?? ""] ?? p.payment_method ?? "—"}
                  </td>
                  <td className="px-3 py-3 text-center">
                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${STATUS_TONE[p.status]}`}>
                      {STATUS_LABEL[p.status]}
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
        itemLabel="دفعة"
      />
    </div>
  );
}