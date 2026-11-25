"use client";

import type { TeacherPortalDay } from "@/lib/api";
import { dayKeyDate, dayKeyWeekday } from "@/lib/format";
import { groupLessonsByDay } from "@/lib/teacher-portal";
import TeacherLessonList from "@/components/teacher/TeacherLessonList";

/**
 * ⭐⭐ **تبويب الأسبوع — مجمّع يوم بيوم بمنطقة المعلم.**
 *
 * ⚠️ التجميع في `groupLessonsByDay()` + `dayKey()` (مختبرين):
 * حصة 22:30 UTC لازم تظهر في اليوم اللي بعديه في القاهرة، مش
 * «امبارح».
 */
export default function TeacherWeek({
  day,
  loading,
  error,
  onChanged,
}: {
  day: TeacherPortalDay | null;
  loading: boolean;
  error: string | null;
  onChanged: () => void;
}) {
  if (loading) {
    return (
      <div className="space-y-3" aria-busy="true">
        {[0, 1].map((i) => (
          <div key={i} className="h-40 animate-pulse rounded-xl bg-white ring-1 ring-slate-200" />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div role="alert" className="rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200">
        {error}
      </div>
    );
  }

  if (!day || day.lessons.length === 0) {
    return (
      <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <p className="text-sm font-medium text-slate-700">مفيش حصص الأسبوع ده</p>
        <p className="mt-1 text-xs text-slate-500">لو في حصة مضافة هتظهر هنا أول ما تتجدول.</p>
      </div>
    );
  }

  const groups = groupLessonsByDay(day.lessons, day.timezone);

  return (
    <div className="space-y-6">
      {groups.map((group) => {
        return (
          <section key={group.key}>
            <h2 className="mb-2 flex items-baseline justify-between px-1">
              <span className="text-sm font-semibold text-slate-800">
                {dayKeyWeekday(group.key)}
              </span>
              <span className="text-xs text-slate-500">{dayKeyDate(group.key)}</span>
            </h2>
            <TeacherLessonList
              lessons={group.lessons}
              loading={false}
              error={null}
              emptyTitle=""
              emptyHint=""
              onChanged={onChanged}
            />
          </section>
        );
      })}
    </div>
  );
}
