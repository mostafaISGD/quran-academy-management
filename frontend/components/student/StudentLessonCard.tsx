import type { StudentLessonView } from "@/lib/api";
import { dateTime, num } from "@/lib/format";

import { JoinLessonButton } from "./JoinLessonButton";

/**
 * ⭐ كارت حصة واحدة — بيستخدم في «نظرة عامة» و«جدولي».
 *
 * ⭐ مفيش أي بيانات طالب تاني هنا: الرد نفسه متفلتر على صاحب
 * الجلسة على السيرفر.
 */

const STATUS_STYLE: Record<string, string> = {
  scheduled: "bg-sky-50 text-sky-700 ring-sky-200",
  confirmed: "bg-emerald-50 text-emerald-700 ring-emerald-200",
  in_progress: "bg-amber-50 text-amber-700 ring-amber-200",
  completed: "bg-slate-100 text-slate-600 ring-slate-200",
  cancelled: "bg-red-50 text-red-600 ring-red-200",
  postponed: "bg-amber-50 text-amber-700 ring-amber-200",
  student_absent: "bg-red-50 text-red-600 ring-red-200",
};

export function StudentLessonCard({
  lesson,
  muted = false,
}: {
  lesson: StudentLessonView;
  muted?: boolean;
}) {
  return (
    <article
      className={`flex flex-wrap items-center justify-between gap-3 rounded-xl bg-white px-4 py-3 ring-1 ring-slate-200 ${
        muted ? "opacity-80" : ""
      }`}
    >
      <div className="min-w-0">
        <p className="truncate text-sm font-medium text-slate-800">
          {lesson.program_name ?? "حصة"}
          {lesson.level_name ? ` · ${lesson.level_name}` : ""}
        </p>
        <p className="mt-0.5 text-xs text-slate-500">
          {dateTime(lesson.scheduled_start_at)} · {num(lesson.duration_minutes)} دقيقة
        </p>
        <p className="mt-0.5 text-xs text-slate-400">
          المعلم: {lesson.teacher_name ?? "—"}
        </p>
      </div>

      <div className="flex items-center gap-2">
        <span
          className={`rounded-full px-2.5 py-1 text-[11px] font-medium ring-1 ${
            STATUS_STYLE[lesson.status] ?? "bg-slate-100 text-slate-600 ring-slate-200"
          }`}
        >
          {lesson.status_label}
        </span>
        <JoinLessonButton lesson={lesson} />
      </div>
    </article>
  );
}
