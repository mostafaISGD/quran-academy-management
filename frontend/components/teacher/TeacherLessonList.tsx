"use client";

import type { TeacherPortalLesson } from "@/lib/api";
import TeacherLessonCard from "@/components/teacher/TeacherLessonCard";

/**
 * ⭐ قائمة حصص مشتركة بين «اليوم» و«كل الحصص».
 *
 * ⭐ مفيش فلترة هنا — الفلترة في الصفحة (بالصلاحية/الحالة)،
 * والقائمة بترسم اللي بيوصلها بس.
 */
export default function TeacherLessonList({
  lessons,
  loading,
  error,
  emptyTitle,
  emptyHint,
  onChanged,
}: {
  lessons: TeacherPortalLesson[];
  loading: boolean;
  error: string | null;
  emptyTitle: string;
  emptyHint: string;
  onChanged: () => void;
}) {
  if (loading) {
    return (
      <div className="space-y-3" aria-busy="true" aria-label="جاري تحميل الحصص">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-32 animate-pulse rounded-xl bg-white ring-1 ring-slate-200" />
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

  if (lessons.length === 0) {
    return (
      <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
        <p className="text-sm font-medium text-slate-700">{emptyTitle}</p>
        <p className="mt-1 text-xs leading-relaxed text-slate-500">{emptyHint}</p>
      </div>
    );
  }

  return (
    <div className="space-y-3">
      {lessons.map((lesson) => (
        <TeacherLessonCard key={lesson.id} lesson={lesson} onChanged={onChanged} />
      ))}
    </div>
  );
}
