"use client";

import { useCallback, useEffect, useState } from "react";
import {
  getSubscriptions,
  deleteSubscription,
  type Subscription,
} from "@/lib/api";
import Pagination from "@/components/Pagination";

const STATUS_LABEL: Record<Subscription["status"], string> = {
  active: "نشط", expired: "منتهي", paused: "متوقف", cancelled: "ملغي",
};

const BILLING_LABEL: Record<string, string> = {
  monthly: "شهري", per_lesson: "لكل حصة", custom: "مخصص",
};

const STATUS_TONE: Record<Subscription["status"], string> = {
  active: "bg-green-100 text-green-700",
  expired: "bg-red-100 text-red-700",
  paused: "bg-amber-100 text-amber-700",
  cancelled: "bg-slate-100 text-slate-500",
};

const PAGE_SIZE = 100;

const fmtMoney = (n: number | undefined, currency = "EGP") =>
  n === undefined
    ? "—"
    : `${n.toLocaleString("ar-EG", { maximumFractionDigits: 2 })} ${currency === "EGP" ? "ج.م" : currency}`;

export default function SubscriptionsPage() {
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [sums, setSums] = useState<Record<string, number>>({});

  const [statusFilter, setStatusFilter] = useState("");

  const load = useCallback(async (targetPage = 1, status = "") => {
    setLoading(true);
    setError(null);
    try {
      const r = await getSubscriptions({
        per_page: PAGE_SIZE,
        page: targetPage,
        status: status || undefined,
      });
      setSubscriptions(r.data);
      setMeta({ total: r.total, last_page: r.last_page });
      setCounts((r.counts ?? {}) as Record<string, number>);
      setSums(r.sums ?? {});
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load(1, statusFilter);
  }, [load, statusFilter]);

  function goToPage(target: number) {
    if (target < 1 || target > meta.last_page || target === page) return;
    setPage(target);
    load(target, statusFilter);
    window.scrollTo({ top: 0, behavior: "smooth" });
  }

  async function handleDelete(id: number) {
    if (!confirm("هل أنت متأكد من حذف هذا الاشتراك؟")) return;
    try {
      await deleteSubscription(id);
      load(1, statusFilter);
    } catch (err) {
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold text-slate-800">الاشتراكات</h1>
        <select
          value={statusFilter}
          onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          className="rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-700 outline-none focus:border-slate-500"
        >
          <option value="">كل الحالات</option>
          {Object.entries(STATUS_LABEL).map(([k, v]) => (
            <option key={k} value={k}>{v} ({counts[k] ?? 0})</option>
          ))}
        </select>
      </div>

      {/* إحصائيات — محسوبة من الداتابيز على كل الاشتراكات */}
      <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
        <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
          <p className="text-xs text-slate-300">إجمالي الاشتراكات</p>
          <p className="mt-1 text-xl font-bold">{meta.total}</p>
        </div>
        <div className="rounded-xl bg-green-50 p-4 text-center">
          <p className="text-xs text-green-600">نشط</p>
          <p className="mt-1 text-xl font-bold text-green-800">{counts.active ?? 0}</p>
        </div>
        <div className="rounded-xl bg-amber-50 p-4 text-center">
          <p className="text-xs text-amber-600">متوقف</p>
          <p className="mt-1 text-xl font-bold text-amber-800">{counts.paused ?? 0}</p>
        </div>
        <div className="rounded-xl bg-blue-50 p-4 text-center">
          <p className="text-xs text-blue-600">قيمة الاشتراكات</p>
          <p className="mt-1 text-lg font-bold text-blue-800">{fmtMoney(sums.price)}</p>
        </div>
      </div>

      {error && (
        <div className="mb-4 flex items-center justify-between rounded-lg bg-red-50 px-4 py-2.5">
          <p className="text-sm text-red-700">{error}</p>
          <button onClick={() => load(page, statusFilter)} className="text-sm font-medium text-red-700 underline">
            إعادة المحاولة
          </button>
        </div>
      )}

      {loading ? (
        <div className="space-y-2">
          {[0, 1, 2, 3, 4].map((i) => (
            <div key={i} className="h-14 animate-pulse rounded-xl bg-slate-100" />
          ))}
        </div>
      ) : subscriptions.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-sm text-slate-500">
            {statusFilter ? "مفيش اشتراكات بالحالة دي" : "لسه مفيش اشتراكات"}
          </p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right font-medium">الطالب</th>
                <th className="px-3 py-3 text-right font-medium">الباقة</th>
                <th className="px-3 py-3 text-right font-medium">المعلم</th>
                <th className="hidden px-3 py-3 text-right font-medium lg:table-cell">الفترة</th>
                <th className="px-2 py-3 text-center font-medium">الفوترة</th>
                <th className="px-3 py-3 text-center font-medium">السعر</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
                <th className="px-3 py-3 text-left font-medium">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {subscriptions.map((s) => (
                <tr key={s.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-slate-800">{s.student?.full_name ?? `طالب ${s.student_id}`}</p>
                    <p className="text-xs text-slate-400">برنامج {s.program_id}</p>
                  </td>
                  <td className="px-3 py-3 text-slate-600">{s.plan?.name ?? `باقة ${s.plan_id}`}</td>
                  <td className="px-3 py-3 text-slate-600">{s.teacher?.full_name ?? "—"}</td>
                  <td className="hidden px-3 py-3 text-xs text-slate-500 lg:table-cell">
                    {s.start_date} — {s.end_date ?? "مفتوح"}
                  </td>
                  <td className="px-2 py-3 text-center text-xs text-slate-600">
                    {BILLING_LABEL[s.billing_type] ?? s.billing_type}
                  </td>
                  <td className="px-3 py-3 text-center font-medium text-slate-800">
                    {fmtMoney(Number(s.price), s.currency)}
                  </td>
                  <td className="px-3 py-3 text-center">
                    <span className={`rounded-full px-2 py-1 text-xs font-medium ${STATUS_TONE[s.status]}`}>
                      {STATUS_LABEL[s.status]}
                    </span>
                  </td>
                  <td className="px-3 py-3 text-left">
                    <button
                      onClick={() => handleDelete(s.id)}
                      className="rounded bg-red-50 px-2 py-1 text-xs text-red-600 hover:bg-red-100"
                    >
                      حذف
                    </button>
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
        itemLabel="اشتراك"
      />
    </div>
  );
}