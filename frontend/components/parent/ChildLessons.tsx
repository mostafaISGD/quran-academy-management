"use client";

import type { ParentLessons } from "@/lib/api";
import { dateTime, time } from "@/lib/format";
import {
  ATTENDANCE_LABEL,
  ATTENDANCE_TONE,
} from "@/lib/parent-portal";
import {
  Badge,
  Card,
  Empty,
  ErrorBox,
  LESSON_STATUS_LABEL,
  LESSON_STATUS_TONE,
  LESSON_TYPE_LABEL,
  LoadingRows,
  labelOf,
} from "./parent-ui";

/**
 * ⭐⭐⭐ **PR 4.5 — الحصص السابقة.**
 *
 * ⭐ اللي فات موعده بس (السيرفر بيفلتر `scheduled_start_at < now`)،
 * الأحدث الأول. حالة الحصة وحالة الحضور بيظهروا مع بعض — لأن
 * «مكتملة» مش معناها بالضرورة «حضر».
 */
export default function ChildLessons({
  data,
  loading,
  error,
  timeZone,
}: {
  data: ParentLessons | null;
  loading: boolean;
  error: string | null;
  timeZone?: string;
}) {
  if (loading && !data) return <LoadingRows count={4} />;
  if (error) return <ErrorBox message={error} />;

  const lessons = data?.lessons ?? [];

  return (
    <Card title="الحصص السابقة" hint="الأحدث الأول — آخر 50 حصة">
      {lessons.length === 0 ? (
        <Empty title="مفيش حصص سابقة" hint="هتظهر هنا بعد أول حصة." />
      ) : (
        <ul className="divide-y divide-slate-100">
          {lessons.map((lesson) => (
            <li key={lesson.id} className="py-2.5">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="min-w-0">
                  <p className="text-sm text-slate-800">
                    {dateTime(lesson.scheduled_start_at, timeZone)}
                  </p>
                  <p className="mt-0.5 text-xs text-slate-500">
                    {[
                      lesson.scheduled_end_at ? `حتى ${time(lesson.scheduled_end_at, timeZone)}` : null,
                      lesson.program_name,
                      lesson.teacher_name,
                      labelOf(LESSON_TYPE_LABEL, lesson.lesson_type),
                    ]
                      .filter(Boolean)
                      .join(" · ")}
                  </p>
                </div>

                <div className="flex flex-wrap items-center gap-1.5">
                  <Badge tone={LESSON_STATUS_TONE[lesson.status] ?? "slate"}>
                    {labelOf(LESSON_STATUS_LABEL, lesson.status)}
                  </Badge>
                  {lesson.attendance_status && (
                    <Badge tone={ATTENDANCE_TONE[lesson.attendance_status]}>
                      {ATTENDANCE_LABEL[lesson.attendance_status]}
                    </Badge>
                  )}
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
