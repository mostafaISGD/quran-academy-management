"use client";

import { useCallback, useEffect, useState } from "react";
import {
  getSubscriptions,
  deleteSubscription,
  processSubscriptionDay,
  type Subscription,
  type ProcessDayResult,
} from "@/lib/api";
import { money } from "@/lib/format";
import Pagination from "@/components/Pagination";
import { useUI, IconRefresh } from "@/components/ui";

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

/** ⭐ alias — `money` من `lib/format` بتعمل كل ده (ج.م + التحويل) */
const fmtMoney = money;

export default function SubscriptionsPage() {
  const [subscriptions, setSubscriptions] = useState<Subscription[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState({ total: 0, last_page: 1 });
  const [counts, setCounts] = useState<Record<string, number>>({});
  const [sums, setSums] = useState<Record<string, number>>({});

  const [statusFilter, setStatusFilter] = useState("");

  const [processing, setProcessing] = useState(false);
  const [lastRun, setLastRun] = useState<ProcessDayResult | null>(null);

  const { toast, confirm } = useUI();

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
      // counts متداخلة تحت اسم العمود: { status: {...}, billing_type: {...} }
      // قراءة r.counts كأنها مسطّحة بتدي أصفار صامتة
      const c = r.counts as Record<string, unknown> | undefined;
      const st = c?.status;
      setCounts((st && typeof st === "object" ? st : {}) as Record<string, number>);
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

  async function handleDelete(id: number, studentName: string) {
    const ok = await confirm({
      title: "حذف الاشتراك",
      message: `متأكد إنك عايز تحذف اشتراك «${studentName}»؟\nالفواتير المرتبطة بيه مش هتتحذف.`,
      confirmLabel: "احذف",
      tone: "danger",
    });
    if (!ok) return;
    try {
      await deleteSubscription(id);
      toast.success("تم حذف الاشتراك", studentName);
      load(1, statusFilter);
    } catch (err) {
      toast.error("فشل حذف الاشتراك", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشل الحذف");
    }
  }

  /**
   * تشغيل المعالجة اليومية بإيدنا.
   *
   * نفس الأمر اللي بيشتغل وحده كل ٠١:١٥ — يعني ضغطة الزرار مالهاش
   * أي أثر جانبي زيادة. بس بناخد confirmation الأول عشان العملية
   * بتعمل فواتير وبيقفل اشتراكات.
   */
  async function handleProcessDay() {
    const ok = await confirm({
      title: "معالجة يوم",
      message:
        "هيتعمل الآتي:\n" +
        "• تجديد الاشتراكات اللي عليها تجديد تلقاعي ووصلت لنهايتها\n" +
        "• إشعار بالأشتركات اللي هتنتهي خلال 3 أيام\n" +
        "• إقفال الاشتراكات اللي انتهت فعلاً (وحصصها المجدولة بتتغيّ)\n\n" +
        "تقدر تضغط الزرار أكتر من مرة — مش هيكرّر حاجة.",
      confirmLabel: "ابدأ المعالجة",
    });
    if (!ok) return;

    setProcessing(true);
    try {
      const r = await processSubscriptionDay();
      setLastRun(r);
      load(page, statusFilter);

      const parts = [
        `${r.renewed.length} تجديد`,
        `${r.expiring_notified.length} إشعار`,
        `${r.expired.length} إقفال`,
      ];

      // كل حاجة خلصت من أول — يبقى مفيش لازم نعمل حاجة
      if (r.renewed.length === 0 && r.expiring_notified.length === 0 && r.expired.length === 0) {
        toast.info("مفيش حاجة تحتاج معالجة", "كل الاشتراكات مظبوطة النهاردة.");
      } else {
        toast.success(
          `معالجة ${r.date} خلصت`,
          `${parts.join(" · ")} · ${r.lessons_created} حصة اتجدولت · ${r.notifications_created} إشعار`,
        );
      }
    } catch (err) {
      toast.error("فشلت المعالجة", err instanceof Error ? err.message : undefined);
      setError(err instanceof Error ? err.message : "فشلت المعالجة");
    } finally {
      setProcessing(false);
    }
  }

  return (
    <div>
      <div className="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">الاشتراكات</h1>
          <p className="text-xs text-slate-500">
            المعالجة اليومية بتشتغل لوحدها كل يوم 1:15 فجراً
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
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
          <button
            onClick={handleProcessDay}
            disabled={processing}
            className="flex items-center gap-1.5 rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
          >
            <IconRefresh size={14} className={processing ? "animate-spin" : ""} />
            {processing ? "جاري المعالجة…" : "معالجة يوم"}
          </button>
        </div>
      </div>

      {/* نتيجة آخر معالجة — فوق الإحصائيات عشان تبان */}
      {lastRun && <ProcessDaySummary result={lastRun} />}

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
                <th className="px-4 py-3 text-start font-medium">الطالب</th>
                <th className="px-3 py-3 text-start font-medium">البرنامج</th>
                <th className="px-3 py-3 text-start font-medium">المعلم</th>
                <th className="hidden px-3 py-3 text-start font-medium lg:table-cell">الفترة</th>
                <th className="px-2 py-3 text-center font-medium">الفوترة</th>
                <th className="px-3 py-3 text-center font-medium">السعر</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
                <th className="px-3 py-3 text-end font-medium">إجراءات</th>
              </tr>
            </thead>
            <tbody>
              {subscriptions.map((s) => (
                <tr key={s.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-slate-800">{s.student?.full_name ?? `طالب ${s.student_id}`}</p>
                    {s.plan?.name && <p className="text-xs text-slate-400">{s.plan.name}</p>}
                  </td>
                  <td className="px-3 py-3 text-slate-600">{s.program?.name ?? `برنامج ${s.program_id}`}</td>
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
                  <td className="px-3 py-3 text-end">
                    <button
                      onClick={() => handleDelete(s.id, s.student?.full_name ?? `اشتراك #${s.id}`)}
                      className="rounded-lg bg-red-50 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-100"
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

/**
 * ملخّص نتيجة آخر «معالجة يوم».
 *
 * بيقول كل حاجة حصلتها العملية في صفحة واحدة، لأن الأرقام لوحدها
 * مش بتوضح حاجة — «٢١ تجديد» يعني إيه لو مش عارف مين اتجدّد.
 */
function ProcessDaySummary({ result }: { result: ProcessDayResult }) {
  const nothing =
    result.renewed.length === 0 &&
    result.expiring_notified.length === 0 &&
    result.expired.length === 0;

  if (nothing) {
    return (
      <div className="mb-5 rounded-xl bg-emerald-50 p-4 ring-1 ring-emerald-200">
        <p className="text-sm font-medium text-emerald-900">
          معالجة {result.date} — كل الاشتراكات مظبوطة
        </p>
        <p className="mt-0.5 text-xs text-emerald-700">
          مفيش تجديدات مستحقة، ومفيش اشتراكات هتنتهي خلال 3 أيام،
          ومفيش اشتراكات اتقفلت.
        </p>
      </div>
    );
  }

  return (
    <div className="mb-5 space-y-3 rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
      <div className="flex flex-wrap items-center gap-4 text-sm">
        <span className="font-medium text-slate-700">معالجة {result.date}</span>
        <Chip tone="emerald" n={result.renewed.length} label="تجديد" />
        <Chip tone="amber" n={result.expiring_notified.length} label="إشعار" />
        <Chip tone="red" n={result.expired.length} label="إقفال" />
        <span className="text-xs text-slate-500">
          {result.invoices_created} فاتورة · {result.lessons_created} حصة اتجدولت
          {result.lessons_skipped > 0 ? ` · ${result.lessons_skipped} اتخطّت` : ""}
          {" · "}
          {result.notifications_created} إشعار
        </span>
      </div>

      {/* المواعيد اللي اتخطّت — دي اللي الأدمن لازم يعرفها */}
      {Object.keys(result.skipped_reasons).length > 0 && (
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800">
          مواعيد اتخطّت:{" "}
          {Object.entries(result.skipped_reasons)
            .map(([reason, count]) => `${count}× ${reason}`)
            .join(" · ")}
        </p>
      )}

      <div className="grid gap-3 md:grid-cols-3">
        <ListBlock
          title="اتجدّد"
          tone="emerald"
          empty="مفيش"
          rows={result.renewed.map((r) => ({
            key: r.subscription_id,
            main: r.student ?? `اشتراك #${r.subscription_id}`,
            sub: `${r.old_end_date} ← ${r.new_end_date}${
              r.invoice_id ? ` · فاتورة #${r.invoice_id}` : ""
            }`,
            note: r.lessons_created > 0 ? `${r.lessons_created} حصة` : undefined,
          }))}
        />

        <ListBlock
          title="هنتهي قريب"
          tone="amber"
          empty="مفيش"
          rows={result.expiring_notified.map((r) => ({
            key: r.subscription_id,
            main: r.student ?? `اشتراك #${r.subscription_id}`,
            sub: `فاضل ${r.days_left} يوم (${r.end_date})`,
            note: `${r.recipients} مستلم`,
          }))}
        />

        <ListBlock
          title="اتقفل"
          tone="red"
          empty="مفيش"
          rows={result.expired.map((r) => ({
            key: r.subscription_id,
            main: r.student ?? `اشتراك #${r.subscription_id}`,
            sub: `انتهى ${r.end_date}`,
            note: r.lessons_cancelled > 0 ? `اتلغت ${r.lessons_cancelled} حصة` : undefined,
          }))}
        />
      </div>
    </div>
  );
}

function Chip({
  n,
  label,
  tone,
}: {
  n: number;
  label: string;
  tone: "emerald" | "amber" | "red";
}) {
  if (n === 0) return null;
  const tones = {
    emerald: "bg-emerald-100 text-emerald-700",
    amber: "bg-amber-100 text-amber-700",
    red: "bg-red-100 text-red-700",
  };
  return (
    <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>
      {n} {label}
    </span>
  );
}

function ListBlock({
  title,
  tone,
  rows,
  empty,
}: {
  title: string;
  tone: "emerald" | "amber" | "red";
  rows: { key: number; main: string; sub: string; note?: string }[];
  empty: string;
}) {
  const heads = {
    emerald: "text-emerald-700",
    amber: "text-amber-700",
    red: "text-red-700",
  };

  return (
    <div className="rounded-lg bg-slate-50 p-3">
      <p className={`mb-1.5 text-xs font-medium ${heads[tone]}`}>
        {title} ({rows.length})
      </p>
      {rows.length === 0 ? (
        <p className="text-xs text-slate-400">{empty}</p>
      ) : (
        <ul className="space-y-1.5">
          {rows.map((r) => (
            <li key={r.key} className="text-xs">
              <div className="flex items-baseline justify-between gap-2">
                <span className="truncate font-medium text-slate-700">{r.main}</span>
                {r.note && (
                  <span className="shrink-0 text-slate-400">{r.note}</span>
                )}
              </div>
              <span className="text-slate-500">{r.sub}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}