"use client";

import { useState, type FormEvent } from "react";

import {
  createParentRequest,
  withdrawParentRequest,
  type ParentChildDetail,
  type StudentRequestRow,
} from "@/lib/api";
import { date, dateTime } from "@/lib/format";
import {
  REQUEST_STATUS_LABEL,
  REQUEST_STATUS_TONE,
  REQUEST_TYPES,
  requestNeeds,
  requestPayload,
  validateStudentRequest,
  type StudentRequestDraft,
  type StudentRequestKind,
} from "@/lib/parent-portal";
import { useUI } from "@/components/ui";
import {
  Badge,
  Card,
  Empty,
  ErrorBox,
  LoadingRows,
  labelOf,
} from "./parent-ui";

/**
 * ⭐⭐⭐ **PR 4.5 — طلبات ولي الأمر (إلغاء / تأجيل / تعليق).**
 *
 * ⭐ الولي بيرفع طلب، الإدارة بتراجعه في صندوق `/student-requests`،
 * والولي بيشوف القرار هنا. الطلب **مش** بينفّذ نفسه — الموافقة قرار
 * مسجّل + إشعار (موثّق في ROADMAP).
 *
 * ⭐ الحصة بتتاخد من `upcoming_lessons` في ملف الابن، والاشتراك من
 * `subscription` — مفيش endpoint منفصل للحصص القادمة.
 */
export default function ChildRequests({
  child,
  requests,
  loading,
  error,
  onChanged,
}: {
  child: ParentChildDetail;
  requests: StudentRequestRow[];
  loading: boolean;
  error: string | null;
  onChanged: () => void;
}) {
  const { toast, confirm } = useUI();

  const [type, setType] = useState<StudentRequestKind>("cancel");
  const [lessonId, setLessonId] = useState<number | null>(null);
  const [requestedDate, setRequestedDate] = useState("");
  const [reason, setReason] = useState("");
  const [errors, setErrors] = useState<string[]>([]);
  const [busy, setBusy] = useState(false);

  const needs = requestNeeds(type);
  const subscription = child.subscription;

  const draft: StudentRequestDraft = {
    type,
    lessonId: needs.lesson ? lessonId : null,
    subscriptionId: needs.subscription ? (subscription?.id ?? null) : null,
    requestedDate: needs.date ? requestedDate : null,
    reason,
  };

  /** ⭐ اليوم المحلي — للحد الأدنى في حقل التاريخ. */
  const today = (() => {
    const now = new Date();
    const pad = (value: number) => String(value).padStart(2, "0");
    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  })();

  function changeType(next: StudentRequestKind) {
    setType(next);
    setLessonId(null);
    setRequestedDate("");
    setErrors([]);
  }

  async function submit(event: FormEvent) {
    event.preventDefault();

    const found = validateStudentRequest(draft);
    setErrors(found);

    if (found.length > 0) return;

    setBusy(true);

    try {
      await createParentRequest(child.student.id, requestPayload(draft));
      toast.success("تم إرسال الطلب للإدارة", "هتشوف القرار هنا أول ما تتم مراجعته.");
      setReason("");
      setLessonId(null);
      setRequestedDate("");
      setErrors([]);
      onChanged();
    } catch (err) {
      toast.apiError("تعذر إرسال الطلب", err);
    } finally {
      setBusy(false);
    }
  }

  async function withdraw(row: StudentRequestRow) {
    const ok = await confirm({
      title: "سحب الطلب",
      message: "الطلب هيتشال من صندوق الإدارة ومش هيتنفّذ. متأكد؟",
      confirmLabel: "اسحب الطلب",
      tone: "warning",
    });

    if (!ok) return;

    try {
      await withdrawParentRequest(row.id);
      toast.success("تم سحب الطلب");
      onChanged();
    } catch (err) {
      toast.apiError("تعذر سحب الطلب", err);
    }
  }

  return (
    <div className="space-y-4">
      {/* ===== طلب جديد ===== */}
      <Card title="طلب جديد" hint="الإدارة بتراجع الطلب وترد عليك">
        <form onSubmit={submit} className="space-y-3">
          <div className="flex flex-wrap gap-2">
            {REQUEST_TYPES.map((option) => {
              const active = option.id === type;

              return (
                <button
                  key={option.id}
                  type="button"
                  onClick={() => changeType(option.id)}
                  aria-pressed={active}
                  className={`min-h-9 rounded-full px-3 text-xs font-medium ring-1 transition ${
                    active
                      ? "bg-slate-800 text-white ring-slate-800"
                      : "bg-white text-slate-600 ring-slate-200 hover:bg-slate-50"
                  }`}
                >
                  {option.label}
                </button>
              );
            })}
          </div>

          {needs.lesson && (
            <label className="block text-xs text-slate-600">
              الحصة
              <select
                value={lessonId ?? ""}
                onChange={(event) =>
                  setLessonId(event.target.value ? Number(event.target.value) : null)
                }
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              >
                <option value="">— اختر الحصة —</option>
                {child.upcoming_lessons.map((lesson) => (
                  <option key={lesson.id} value={lesson.id}>
                    {dateTime(lesson.scheduled_start_at, child.profile.timezone ?? undefined)}
                    {lesson.teacher_name ? ` — ${lesson.teacher_name}` : ""}
                  </option>
                ))}
              </select>
              {child.upcoming_lessons.length === 0 && (
                <span className="mt-1 block text-[11px] text-amber-700">
                  مفيش حصص قادمة متاحة للطلب حالياً.
                </span>
              )}
            </label>
          )}

          {needs.subscription && (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">
              {subscription
                ? `هيتم طلب تعليق اشتراك «${subscription.program_name}» — من ${date(subscription.start_date)}`
                : "مفيش اشتراك نشط لتعليقه."}
            </p>
          )}

          {needs.date && (
            <label className="block text-xs text-slate-600">
              التاريخ الجديد
              <input
                type="date"
                value={requestedDate}
                min={today}
                onChange={(event) => setRequestedDate(event.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
              />
            </label>
          )}

          <label className="block text-xs text-slate-600">
            السبب
            <textarea
              value={reason}
              onChange={(event) => setReason(event.target.value)}
              rows={3}
              maxLength={1000}
              placeholder="اكتب سبباً واضحاً يساعد الإدارة على المراجعة"
              className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-emerald-500 focus:outline-none"
            />
          </label>

          {errors.length > 0 && (
            <ul role="alert" className="space-y-1 rounded-lg bg-red-50 p-3 text-xs text-red-700">
              {errors.map((message) => (
                <li key={message}>• {message}</li>
              ))}
            </ul>
          )}

          <button
            type="submit"
            disabled={busy}
            className="min-h-11 w-full rounded-lg bg-emerald-700 px-4 text-sm font-medium text-white transition hover:bg-emerald-800 disabled:opacity-50"
          >
            {busy ? "جاري الإرسال…" : "إرسال الطلب"}
          </button>
        </form>
      </Card>

      {/* ===== الطلبات السابقة ===== */}
      <Card title="طلباتي" hint="كل الطلبات على الابن ده">
        {loading && requests.length === 0 ? (
          <LoadingRows count={2} />
        ) : error ? (
          <ErrorBox message={error} />
        ) : requests.length === 0 ? (
          <Empty title="مفيش طلبات لسه" hint="أول طلب هتقدمه هيظهر هنا مع حالته." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {requests.map((row) => (
              <li key={row.id} className="py-3">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm font-medium text-slate-800">{row.type_label}</p>
                  <Badge tone={REQUEST_STATUS_TONE[row.status]}>
                    {row.status_label || labelOf(REQUEST_STATUS_LABEL, row.status)}
                  </Badge>
                </div>

                <p className="mt-0.5 text-xs text-slate-500">
                  أُرسل {date(row.created_at)}
                  {row.lesson?.scheduled_start_at
                    ? ` · الحصة ${dateTime(row.lesson.scheduled_start_at, child.profile.timezone ?? undefined)}`
                    : ""}
                  {row.requested_date ? ` · التاريخ المطلوب ${date(row.requested_date)}` : ""}
                </p>

                <p className="mt-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600">
                  {row.reason}
                </p>

                {row.admin_notes && (
                  <p className="mt-1 rounded-lg bg-emerald-50 px-2 py-1 text-xs text-emerald-800">
                    رد الإدارة: {row.admin_notes}
                  </p>
                )}

                {row.status === "pending" && (
                  <button
                    type="button"
                    onClick={() => withdraw(row)}
                    className="mt-2 min-h-9 rounded-lg border border-slate-300 px-3 text-xs text-slate-700 transition hover:bg-slate-50"
                  >
                    سحب الطلب
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
