"use client";

import { useState } from "react";

import {
  completeTeacherLesson,
  recordTeacherAttendance,
  startTeacherLesson,
  type TeacherPortalLesson,
} from "@/lib/api";
import { num, time } from "@/lib/format";
import {
  attendancePayload,
  lessonActions,
  PORTAL_STATUS_LABEL,
  portalStatusTone,
  tapAttendanceStatus,
  tapLesson,
  IDLE_ATTENDANCE,
  type AttendanceStatus,
  type AttendanceStep,
  type LessonAction,
  type PortalTone,
} from "@/lib/teacher-portal";
import { useToast } from "@/components/ui";
import TeacherMemorizationForm from "@/components/teacher/TeacherMemorizationForm";

const TONE_CLASS: Record<PortalTone, string> = {
  emerald: "bg-emerald-50 text-emerald-700 ring-emerald-200",
  sky: "bg-sky-50 text-sky-700 ring-sky-200",
  amber: "bg-amber-50 text-amber-800 ring-amber-200",
  red: "bg-red-50 text-red-700 ring-red-200",
  slate: "bg-slate-100 text-slate-600 ring-slate-200",
};

const TYPE_LABEL: Record<string, string> = {
  regular: "حصة عادية",
  trial: "تجريبية",
  makeup: "تعويضية",
  extra: "إضافية",
  free: "مجانية",
  assessment: "تقييم",
};

const ATTENDANCE_LABEL: Record<AttendanceStatus, string> = {
  present: "حاضر",
  absent: "غائب",
  late: "متأخر",
  excused: "بعذر",
};

const ATTENDANCE_TONE: Record<AttendanceStatus, string> = {
  present: "bg-emerald-600 text-white hover:bg-emerald-700",
  absent: "bg-red-600 text-white hover:bg-red-700",
  late: "bg-amber-500 text-white hover:bg-amber-600",
  excused: "bg-slate-600 text-white hover:bg-slate-700",
};

/**
 * ⭐⭐⭐ **كارت الحصة في بوابة المعلم.**
 *
 * ⭐ كل فعل هنا بينادي مسار `/teacher/*` — مفيش مسار إداري واحد.
 * والأزرار بتظهر من `lessonActions()` (مختبرة) مش من شروط
 * مكتوبة في الـJSX.
 *
 * ⭐⭐ الحضور **بنقرتين**: «سجّل الحضور» بتفتح الاختيار، ونقرة
 * الحالة هي اللي بتبعت الطلب — لأن الحضور «حاضر» بيكمّل الحصة
 * ويخصم رصيد ويولّد مستحق (أثر مالي).
 */
export default function TeacherLessonCard({
  lesson,
  onChanged,
}: {
  lesson: TeacherPortalLesson;
  onChanged: () => void;
}) {
  const toast = useToast();

  /** ⭐ آلة الحضور — مشتركة بين الكارت ولوحة الاختيار. */
  const [attendance, setAttendance] = useState<AttendanceStep>(IDLE_ATTENDANCE);
  const [busy, setBusy] = useState<LessonAction | "attend" | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [memorizing, setMemorizing] = useState(false);

  const student = lesson.student;
  const actions = lessonActions(
    lesson.status,
    Boolean(lesson.meeting_url),
    student !== null,
  );
  const statusTone = TONE_CLASS[portalStatusTone(lesson.status)];
  const choosing = attendance.step === "choose" && attendance.lessonId === lesson.id;

  async function run(action: LessonAction) {
    setError(null);
    setBusy(action);

    try {
      if (action === "start") {
        await startTeacherLesson(lesson.id);
        toast.success("بدأت الحصة", student?.name);
        onChanged();
      } else if (action === "complete") {
        await completeTeacherLesson(lesson.id);
        toast.success("تم إتمام الحصة", student?.name);
        onChanged();
      } else if (action === "attendance") {
        // ⭐ النقرة الأولى — فتح الاختيار بس، مفيش طلب.
        setAttendance((current) =>
          tapLesson(current, { id: lesson.id, studentId: student?.id ?? 0 }),
        );
      } else if (action === "memorization") {
        setMemorizing(true);
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تنفيذ العملية");
    } finally {
      setBusy(null);
    }
  }

  async function commitAttendance(status: AttendanceStatus) {
    const next = tapAttendanceStatus(attendance, status);
    const payload = attendancePayload(next);

    // ⭐ حماية إضافية: مفيش حمولة = مفيش طلب. لو الحالة مش `choose`
    // (نقرة تانية سريعة)، بنتجاهل بدل ما نبعت حضور تاني.
    if (payload === null || !student) return;

    setError(null);
    setBusy("attend");
    setAttendance(next);

    try {
      await recordTeacherAttendance(lesson.id, payload);
      toast.success(
        `تم تسجيل «${ATTENDANCE_LABEL[status]}»`,
        status === "present" ? "الحصة اتكملت" : undefined,
      );
      setAttendance(IDLE_ATTENDANCE);
      onChanged();
    } catch (err) {
      setAttendance({ step: "choose", lessonId: lesson.id, studentId: student.id });
      toast.apiError("تعذر تسجيل الحضور", err);
    } finally {
      setBusy(null);
    }
  }

  return (
    <>
      <article className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
        {/* ===== الوقت والحالة ===== */}
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="text-base font-semibold tabular-nums text-slate-800" dir="ltr">
              {time(lesson.scheduled_start_at, lesson.timezone ?? undefined)}
              {" – "}
              {time(lesson.scheduled_end_at, lesson.timezone ?? undefined)}
            </p>
            <p className="mt-0.5 text-xs text-slate-500">
              {TYPE_LABEL[lesson.lesson_type] ?? lesson.lesson_type}
              {" · "}
              {num(lesson.duration_minutes, 0)} دقيقة
            </p>
          </div>

          <span
            className={`rounded-full px-2.5 py-1 text-xs font-medium ring-1 ${statusTone}`}
          >
            {PORTAL_STATUS_LABEL[lesson.status]}
          </span>
        </div>

        {/* ===== الطالب ===== */}
        <div className="mt-3 flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1 border-t border-slate-100 pt-3">
          <p className="text-sm font-medium text-slate-800">
            {student?.name ?? "حصة مجموعة"}
          </p>
          {lesson.parent?.phone && (
            <a
              href={`tel:${lesson.parent.phone}`}
              className="text-xs text-emerald-700 underline-offset-2 hover:underline"
              dir="ltr"
            >
              {lesson.parent.phone}
            </a>
          )}
        </div>

        {error && (
          <p role="alert" className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">
            {error}
          </p>
        )}

        {/* ===== الأزرار ===== */}
        {actions.length > 0 && (
          <div className="mt-3 flex flex-wrap gap-2">
            {actions.map((action) => {
              if (action === "join") {
                return (
                  <a
                    key={action}
                    href={lesson.meeting_url ?? "#"}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex min-h-10 items-center rounded-lg bg-emerald-600 px-4 text-sm font-medium text-white transition hover:bg-emerald-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-600"
                  >
                    انضم للحصة
                  </a>
                );
              }

              const label =
                action === "start"
                  ? "ابدأ الحصة"
                  : action === "complete"
                    ? "أنهِ الحصة"
                    : action === "attendance"
                      ? "سجّل الحضور"
                      : "حفظ / مراجعة";

              const style =
                action === "complete"
                  ? "bg-slate-800 text-white hover:bg-slate-700"
                  : action === "attendance"
                    ? "bg-sky-600 text-white hover:bg-sky-700"
                    : "bg-slate-100 text-slate-700 hover:bg-slate-200";

              return (
                <button
                  key={action}
                  type="button"
                  onClick={() => void run(action)}
                  disabled={busy !== null}
                  className={`inline-flex min-h-10 items-center rounded-lg px-4 text-sm font-medium transition disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 ${style}`}
                >
                  {busy === action ? "…" : label}
                </button>
              );
            })}
          </div>
        )}

        {!student && actions.length > 0 && (
          <p className="mt-2 text-[11px] leading-relaxed text-slate-500">
            حصة مجموعة — الحضور والحفظ بيتسجّلوا من لوحة الإدارة.
          </p>
        )}

        {/* ===== لوحة الحضور — النقرة التانية ===== */}
        {choosing && (
          <div className="mt-3 rounded-lg bg-slate-50 p-3 ring-1 ring-slate-200">
            <p className="mb-2 text-xs font-medium text-slate-600">
              حضور {student?.name ?? "الطالب"} — اختار النتيجة
            </p>
            <div className="grid grid-cols-2 gap-2">
              {(["present", "absent", "late", "excused"] as AttendanceStatus[]).map(
                (status) => (
                  <button
                    key={status}
                    type="button"
                    onClick={() => void commitAttendance(status)}
                    disabled={busy !== null}
                    className={`min-h-11 rounded-lg text-sm font-semibold transition disabled:opacity-60 focus-visible:outline-2 focus-visible:outline-offset-2 ${ATTENDANCE_TONE[status]}`}
                  >
                    {busy === "attend" ? "…" : ATTENDANCE_LABEL[status]}
                  </button>
                ),
              )}
            </div>
            <p className="mt-2 text-[11px] leading-relaxed text-slate-500">
              «حاضر» بيكمّل الحصة ويخصم رصيد الحصة. «غائب» بيسجّل الغياب من غير خصم.
            </p>
          </div>
        )}
      </article>

      {memorizing && (
        <TeacherMemorizationForm
          lesson={lesson}
          onClose={() => setMemorizing(false)}
          onSaved={() => {
            setMemorizing(false);
            onChanged();
          }}
        />
      )}
    </>
  );
}
