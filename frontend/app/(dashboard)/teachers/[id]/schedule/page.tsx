"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { getTeacher, getTeacherLessons, type Teacher, type Lesson } from "@/lib/api";
import TeacherScheduleEditor from "@/components/TeacherScheduleEditor";

/** الأدمن بيفتح جدول معلم معيّن */
export default function TeacherSchedulePage({ params }: { params: Promise<{ id: string }> }) {
  const [teacherId, setTeacherId] = useState<number | null>(null);
  const [teacher, setTeacher] = useState<Teacher | null>(null);
  const [lessons, setLessons] = useState<Lesson[]>([]);
  const [loadingLessons, setLoadingLessons] = useState(true);

  useEffect(() => {
    params.then((p) => {
      const id = Number(p.id);
      setTeacherId(id);
      getTeacher(id).then(setTeacher).catch(() => {});
      getTeacherLessons(id)
        .then((r) => setLessons(r.data ?? []))
        .catch(() => {})
        .finally(() => setLoadingLessons(false));
    });
  }, [params]);

  if (teacherId === null) return null;

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
