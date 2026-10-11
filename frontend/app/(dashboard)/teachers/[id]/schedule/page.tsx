"use client";

import { use, useCallback } from "react";
import Link from "next/link";
import { getTeacher, getTeacherLessons, type Teacher, type Lesson } from "@/lib/api";
import { usePage } from "@/lib/use-page";
import TeacherScheduleEditor from "@/components/TeacherScheduleEditor";

/** الأدمن بيفتح جدول معلم معيّن */
export default function TeacherSchedulePage({ params }: { params: Promise<{ id: string }> }) {
  /**
   * ⭐⭐⭐ `params` بقى **فوري** بدل `useEffect`.
   *
   * `params` في Next.js عبارة عن `Promise`، والطريقة القديمة
   * كانت بتحلّه في effect وبعدين تستنى:
   *
   *     useEffect(() => { params.then(p => setTeacherId(Number(p.id))) }, [params])
   *
   * ⭐ `use()` بيفكّ الـ promise **جوه الرسم** — فالمعرف جاهز
   * من أول render، والصفحة بتتجيب البيانات في نفس_LOAD.
   *
   * ⭐ النتيجة: شغلنا كان بيبدأ بعد رسم فاضي (الصفحة بتقول
   * `null` لثانية). دلوقتي بتبدأ فورًا.
   *
   * @see usePage
   */
  const teacherId = Number(use(params).id);

  const fetchTeacher = useCallback(() => getTeacher(teacherId), [teacherId]);

  const { data: teacher } = usePage<Teacher | null>(
    `teacher:${teacherId}`,
    fetchTeacher,
    { initial: null },
  );

  const fetchLessons = useCallback(async () => {
    const r = await getTeacherLessons(teacherId);
    return r.data ?? [];
  }, [teacherId]);

  const { data: lessons, loading: loadingLessons } = usePage<Lesson[]>(
    `teacher-schedule:${teacherId}`,
    fetchLessons,
    { initial: [] },
  );

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link
            href="/teachers"
            className="mb-1 inline-block text-xs text-slate-500 hover:text-slate-700"
          >
            ← رجوع للمعلمين
          </Link>
          <h1 className="text-lg font-semibold text-slate-800">
            جدول {teacher?.display_name ?? "المعلم"}
          </h1>
          <p className="text-sm text-slate-500">
            {teacher?.specialization ?? "—"} · سجّل كل مواعيده — الأكاديمية والشغل الخارجي
          </p>
        </div>
      </div>

      <TeacherScheduleEditor
        teacherId={teacherId}
        emptyTitle="جدول المعلم فاضي"
        emptyHint="لازم يسجّل مواعيده (الأكاديمية والشغل الخارجي) قبل ما يتبعت له طلاب جدد. أي وقت مش مسجّل بيتحسب فاضي."
        lessons={lessons}
        loadingLessons={loadingLessons}
      />
    </div>
  );
}
