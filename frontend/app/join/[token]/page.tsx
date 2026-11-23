"use client";

import { useCallback } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";

import { getJoinPreview, type StudentJoinPreview } from "@/lib/api";
import { dateTime, num } from "@/lib/format";
import {
  joinPreviewState,
  lessonStatusLabel,
  type JoinPreviewState,
} from "@/lib/student-portal";
import { usePage } from "@/lib/use-page";

/**
 * ⭐⭐⭐ **PR 4.6 — صفحة رابط الطفل (< 13).**
 *
 * ⭐ الطفل مالوش حساب، فبيدخل من رابط/PIN ولي الأمر. الصفحة دي
 * **عامة** (في `PUBLIC_PATHS` كبادئة) وبتعرض بيانات الحصة بس:
 * المعلم، البرنامج، الموعد، والمدة.
 *
 * ⚠️ مفيش اسم طالب ولا كوده ولا أي بيانات ولي أمر — حتى لو
 * الرمز نفسه مربوط بطالب معيّن. الـendpoint العام بيرجّع بيانات
 * الحصة فقط.
 */
export default function JoinByTokenPage() {
  const params = useParams<{ token: string | string[] }>();
  const token = Array.isArray(params.token)
    ? (params.token[0] ?? "")
    : (params.token ?? "");

  const fetchPreview = useCallback(
    () => getJoinPreview(token).then((response) => response.data),
    [token],
  );

  const { data, loading, error } = usePage<StudentJoinPreview | null>(
    `join-preview:${token}`,
    fetchPreview,
    { enabled: token.length > 0 },
  );

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200">
        <div className="mb-4 text-center">
          <p className="text-3xl">📖</p>
          <h1 className="mt-2 text-lg font-semibold text-slate-800">
            دخول الحصة
          </h1>
          <p className="text-xs text-slate-500">
            الرابط ده من ولي أمرك — بيدخّلك الحصة مرة واحدة.
          </p>
        </div>

        {loading && (
          <p className="py-8 text-center text-sm text-slate-500">جاري التحقق من الرابط…</p>
        )}

        {!loading && error && (
          <div className="rounded-xl bg-red-50 p-4 text-center text-sm text-red-700 ring-1 ring-red-200">
            <p>{error}</p>
            <Link
              href="/join"
              className="mt-3 inline-block text-xs font-medium text-red-800 underline"
            >
              عندك PIN؟ ادخل من هنا
            </Link>
          </div>
        )}

        {!loading && data && (
          <JoinPreviewBody preview={data} state={joinPreviewState(data)} />
        )}
      </div>
    </main>
  );
}

const STATE_MESSAGE: Record<Exclude<JoinPreviewState, "ready">, string> = {
  expired: "الرابط ده انتهت صلاحيته — اطلب من ولي أمرك رابط جديد.",
  used: "الرابط ده اتستخدم قبل كده — كل رابط بيدخّل الحصة مرة واحدة.",
  closed: "الحصة دي مش متاحة للدخول دلوقتي (ملغاة أو مكتملة).",
  no_link: "الحصة لسه مالهاش رابط اجتماع — كلّم المعلم.",
};

function JoinPreviewBody({
  preview,
  state,
}: {
  preview: StudentJoinPreview;
  state: JoinPreviewState;
}) {
  // ⭐ لو السيرفر قال «جاهز» بس ما بعتش رابط، ده مش زرار نضغطه —
  // الرسالة لازم تقول الحقيقة بدل ما تطلع `undefined`.
  const message =
    state === "ready" ? STATE_MESSAGE.no_link : STATE_MESSAGE[state];

  return (
    <div className="space-y-4">
      <dl className="space-y-2 rounded-xl bg-slate-50 p-4 text-sm">
        <Row label="المعلم" value={preview.teacher_name ?? "—"} />
        <Row label="البرنامج" value={preview.program_name ?? "—"} />
        {preview.level_name && <Row label="المستوى" value={preview.level_name} />}
        <Row label="الموعد" value={dateTime(preview.scheduled_start_at)} />
        <Row label="المدة" value={`${num(preview.duration_minutes)} دقيقة`} />
        <Row label="الحالة" value={lessonStatusLabel(preview.status)} />
      </dl>

      {state === "ready" && preview.join_url ? (
        <a
          href={preview.join_url}
          className="block rounded-xl bg-emerald-600 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-emerald-700"
        >
          🎥 ادخل الحصة دلوقتي
        </a>
      ) : (
        <p
          role="status"
          className="rounded-xl bg-amber-50 p-4 text-center text-sm leading-6 text-amber-800 ring-1 ring-amber-200"
        >
          {message}
        </p>
      )}

      <p className="text-center text-[11px] leading-5 text-slate-400">
        الصفحة دي بتعرض بيانات الحصة بس — مفيش أي بيانات شخصية.
      </p>
    </div>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-baseline justify-between gap-3">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-end font-medium text-slate-700">{value}</dd>
    </div>
  );
}
