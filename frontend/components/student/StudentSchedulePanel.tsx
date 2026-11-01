import type { StudentLessonView } from "@/lib/api";

import { StudentLessonCard } from "./StudentLessonCard";

/**
 * ⭐ تبويب «جدولي»: القادم والسابق.
 *
 * ⭐ الحصص الفردية وحصص المجموعة الاتنين بيوصلوا من نفس الـAPI —
 * الفلترة على السيرفر (`StudentPortalView::lessonsQuery`).
 */
export function StudentSchedulePanel({
  upcoming,
  past,
}: {
  upcoming: readonly StudentLessonView[];
  past: readonly StudentLessonView[];
}) {
  return (
    <div className="space-y-6">
      <section>
        <h2 className="mb-2 text-sm font-semibold text-slate-700">
          القادم ({upcoming.length})
        </h2>

        {upcoming.length === 0 ? (
          <p className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
            مفيش حصص قادمة — أول ما يتم حجز حصة هتظهر هنا.
          </p>
        ) : (
          <div className="space-y-2">
            {upcoming.map((lesson) => (
              <StudentLessonCard key={lesson.id} lesson={lesson} />
            ))}
          </div>
        )}
      </section>

      <section>
        <h2 className="mb-2 text-sm font-semibold text-slate-700">
          السابق ({past.length})
        </h2>

        {past.length === 0 ? (
          <p className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
            مفيش حصص سابقة لسه.
          </p>
        ) : (
          <div className="space-y-2">
            {past.map((lesson) => (
              <StudentLessonCard key={lesson.id} lesson={lesson} muted />
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
