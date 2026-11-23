"use client";

import { useCallback, useState } from "react";

import {
  approveStudentRequest,
  getStudentRequests,
  rejectStudentRequest,
  type StudentRequestInbox,
  type StudentRequestRow,
} from "@/lib/api";
import { date, dateTime, num } from "@/lib/format";
import { useCurrentUser } from "@/lib/use-current-user";
import { usePage } from "@/lib/use-page";
import { useUI } from "@/components/ui";
import { Badge, Empty, ErrorBox, LoadingRows } from "@/components/parent/parent-ui";
import {
  REQUEST_STATUS_LABEL,
  REQUEST_STATUS_TONE,
  type StudentRequestStatus,
} from "@/lib/parent-portal";

/**
 * ⭐⭐⭐ **PR 4.5 — صندوق طلبات أولياء الأمور (الإدارة).**
 *
 * ⭐ الولي بيرفع طلب (إلغاء / تأجيل / تعليق)، والطلب بيظهر هنا
 * افتراضياً بحالة «قيد المراجعة». `student_requests.view` للصندوق،
 * و`student_requests.manage` للقرار — منفصلين عمداً: الاستقبال
 * بيشوف ويرد على التليفون، مش بيوافق.
 *
 * ⭐⭐ الموافقة **قرار مسجّل + إشعار** — مش تنفيذ تلقائي على الحصة.
 * التطبيق على مسار الحصة/الاشتراك خطوة منفصلة موثّقة في ROADMAP.
 */

const STATUS_FILTERS: Array<{ id: StudentRequestStatus | "all"; label: string }> = [
  { id: "pending", label: "قيد المراجعة" },
  { id: "approved", label: "موافق عليها" },
  { id: "rejected", label: "مرفوضة" },
  { id: "withdrawn", label: "مسحوبة" },
  { id: "all", label: "الكل" },
];

export default function StudentRequestsPage() {
  const { user, loading: userLoading } = useCurrentUser();
  const { toast, prompt } = useUI();

  const [status, setStatus] = useState<StudentRequestStatus | "all">("pending");
  const [page, setPage] = useState(1);
  const [busyId, setBusyId] = useState<number | null>(null);

  const canManage = user?.permissions.includes("student_requests.manage") ?? false;

  const fetchInbox = useCallback(
    () => getStudentRequests({ status, page }),
    [status, page],
  );
  const inbox = usePage<StudentRequestInbox | null>(
    `student-requests:${status}:${page}`,
    fetchInbox,
    { initial: null },
  );

  const data = inbox.data;
  const rows = data?.data ?? [];
  const statusCounts = data?.counts.status ?? {};
  const allCount = Object.values(statusCounts).reduce((sum, value) => sum + value, 0);

  function changeStatus(next: StudentRequestStatus | "all") {
    setStatus(next);
    setPage(1);
  }

  async function approve(row: StudentRequestRow) {
    const notes = await prompt({
      title: "الموافقة على الطلب",
      message: `${row.type_label} — ${row.student?.full_name ?? "طالب"}`,
      label: "ملاحظات للإدارة والولي (اختياري)",
      multiline: true,
      confirmLabel: "موافقة",
    });

    if (notes === null) return;

    setBusyId(row.id);

    try {
      await approveStudentRequest(row.id, notes.trim() || undefined);
      toast.success("تمت الموافقة على الطلب", "اتسجّل القرار واتبعث إشعار لولي الأمر.");
      inbox.reload();
    } catch (err) {
      toast.apiError("تعذر تنفيذ الموافقة", err);
    } finally {
      setBusyId(null);
    }
  }

  async function reject(row: StudentRequestRow) {
    const notes = await prompt({
      title: "رفض الطلب",
      message: `${row.type_label} — ${row.student?.full_name ?? "طالب"}`,
      label: "سبب الرفض (بيوصل لولي الأمر)",
      multiline: true,
      required: true,
      tone: "danger",
      confirmLabel: "رفض الطلب",
      validate: (value) =>
        value.trim().length < 3 ? "اكتب سبباً واضحاً (3 حروف على الأقل)" : null,
    });

    if (notes === null) return;

    setBusyId(row.id);

    try {
      await rejectStudentRequest(row.id, notes.trim());
      toast.success("تم رفض الطلب", "اتبعث إشعار لولي الأمر بالسبب.");
      inbox.reload();
    } catch (err) {
      toast.apiError("تعذر رفض الطلب", err);
    } finally {
      setBusyId(null);
    }
  }

  if (userLoading) {
    return (
      <div className="mx-auto max-w-4xl p-6">
        <p className="py-20 text-center text-sm text-slate-500">جاري التحميل…</p>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-4xl p-4 sm:p-6">
      <header className="mb-4">
        <h1 className="text-lg font-semibold text-slate-800">طلبات أولياء الأمور</h1>
        <p className="text-xs text-slate-500">
          إلغاء أو تأجيل حصة أو تعليق اشتراك — القرار بيتسجّل ويتبعث إشعار للولي.
        </p>
      </header>

      {/* ⭐ فلاتر الحالة + العدّادات */}
      <div className="-mx-1 mb-4 flex gap-2 overflow-x-auto px-1 pb-1">
        {STATUS_FILTERS.map((filter) => {
          const active = status === filter.id;
          const count =
            filter.id === "all" ? allCount : (statusCounts[filter.id] ?? 0);

          return (
            <button
              key={filter.id}
              type="button"
              onClick={() => changeStatus(filter.id)}
              aria-pressed={active}
              className={`min-h-9 shrink-0 rounded-full px-3 text-xs font-medium ring-1 transition ${
                active
                  ? "bg-slate-800 text-white ring-slate-800"
                  : "bg-white text-slate-600 ring-slate-200 hover:bg-slate-50"
              }`}
            >
              {filter.label}
              {data && count > 0 ? ` (${num(count, 0)})` : ""}
            </button>
          );
        })}
      </div>

      {inbox.error ? (
        <ErrorBox message={inbox.error} />
      ) : inbox.loading && !data ? (
        <LoadingRows count={4} />
      ) : rows.length === 0 ? (
        <Empty
          title="مفيش طلبات في الفلتر ده"
          hint={status === "pending" ? "كل الطلبات المعلّقة اتراجعت." : "جرّب فلتر تاني."}
        />
      ) : (
        <ul className="space-y-3">
          {rows.map((row) => (
            <li
              key={row.id}
              className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
            >
              <div className="flex flex-wrap items-start justify-between gap-2">
                <div>
                  <p className="text-sm font-semibold text-slate-800">{row.type_label}</p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {row.student
                      ? `${row.student.full_name} · ${row.student.student_code}`
                      : "طالب محذوف"}
                    {row.parent ? ` · ${row.parent.name}` : ""}
                    {row.parent?.phone ? ` · ${row.parent.phone}` : ""}
                  </p>
                </div>

                <Badge tone={REQUEST_STATUS_TONE[row.status]}>
                  {row.status_label || REQUEST_STATUS_LABEL[row.status]}
                </Badge>
              </div>

              <p className="mt-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700">
                {row.reason}
              </p>

              <p className="mt-2 text-xs text-slate-500">
                أُرسل {dateTime(row.created_at)}
                {row.lesson?.scheduled_start_at
                  ? ` · الحصة ${dateTime(row.lesson.scheduled_start_at)}`
                  : ""}
                {row.requested_date ? ` · التاريخ المطلوب ${date(row.requested_date)}` : ""}
                {row.subscription ? ` · اشتراك #${row.subscription.id}` : ""}
              </p>

              {row.admin_notes && (
                <p className="mt-2 rounded-lg bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                  قرار الإدارة: {row.admin_notes}
                  {row.reviewed_at ? ` — ${date(row.reviewed_at)}` : ""}
                </p>
              )}

              {canManage && row.status === "pending" && (
                <div className="mt-3 flex gap-2">
                  <button
                    type="button"
                    onClick={() => approve(row)}
                    disabled={busyId === row.id}
                    className="min-h-9 rounded-lg bg-emerald-700 px-4 text-xs font-medium text-white transition hover:bg-emerald-800 disabled:opacity-50"
                  >
                    موافقة
                  </button>
                  <button
                    type="button"
                    onClick={() => reject(row)}
                    disabled={busyId === row.id}
                    className="min-h-9 rounded-lg border border-red-200 bg-red-50 px-4 text-xs font-medium text-red-700 transition hover:bg-red-100 disabled:opacity-50"
                  >
                    رفض
                  </button>
                </div>
              )}
            </li>
          ))}
        </ul>
      )}

      {/* ⭐ ترقيم بسيط */}
      {data && data.last_page > 1 && (
        <div className="mt-4 flex items-center justify-between text-xs text-slate-600">
          <button
            type="button"
            onClick={() => setPage((current) => Math.max(1, current - 1))}
            disabled={data.current_page <= 1}
            className="min-h-9 rounded-lg border border-slate-300 px-3 transition hover:bg-slate-50 disabled:opacity-40"
          >
            السابق
          </button>

          <span>
            صفحة {num(data.current_page, 0)} من {num(data.last_page, 0)} · {num(data.total, 0)} طلب
          </span>

          <button
            type="button"
            onClick={() => setPage((current) => Math.min(data.last_page, current + 1))}
            disabled={data.current_page >= data.last_page}
            className="min-h-9 rounded-lg border border-slate-300 px-3 transition hover:bg-slate-50 disabled:opacity-40"
          >
            التالي
          </button>
        </div>
      )}
    </div>
  );
}
