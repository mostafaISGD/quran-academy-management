"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  fetchMe,
  getStoredUser,
  getTeacherLessons,
  logout,
  type Lesson,
} from "@/lib/api";
import TeacherScheduleEditor from "@/components/TeacherScheduleEditor";

/**
 * صفحة المعلّم: «جدولي».
 *
 * المعلّم مستقل بيشتغل في أكاديميّاتseveral، فبيسجّل commitments بتاعه هنا
 * بنفسه — وهو اللي بتتبني عليه فلترة المعلمين لما تيجي تضيف طالب.
 */
export default function MySchedulePage() {
  const router = useRouter();
  const [teacher, setTeacher] = useState<ReturnType<typeof getStoredUser>>(() =>
    getStoredUser(),
  );
  const [lessons, setLessons] = useState<Lesson[]>([]);
  const [loadingLessons, setLoadingLessons] = useState(true);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    // نجيب من السيرفر (هو المصدر) وبعدها نوجّه
    fetchMe()
      .then((user) => {
        setTeacher(user);
        if (!user.teacher) {
          router.replace("/dashboard");
          return;
        }
        return getTeacherLessons(user.teacher.id)
          .then((r) => setLessons(r.data ?? []))
          .catch(() => {});
      })
      .catch(() => {})
      .finally(() => {
        setLoadingLessons(false);
        setReady(true);
      });
  }, [router]);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  if (!ready || !teacher?.teacher) return null;

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">جدولي</h1>
          <p className="text-sm text-slate-500">
            {teacher.teacher.display_name}
            {teacher.teacher.specialization ? ` — ${teacher.teacher.specialization}` : ""}
          </p>
        </div>
        <button
          onClick={handleLogout}
          className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200"
        >
          🚪 تسجيل الخروج
        </button>
      </div>

      <div className="mb-5 rounded-xl border border-blue-200 bg-blue-50 p-4">
        <p className="text-sm leading-relaxed text-blue-900">
          سجّل هنا <strong>كل مواعيدك</strong> — حصص الأكاديمية والشغل في
          الأكاديميات التانية. أي وقت مش مسجّل بيتحسب فاضي في الحجز، فالجدول
          بيوصّل الساعات اللي تقدر تعلّم فيها بالظبط.
        </p>
      </div>

      <TeacherScheduleEditor
        teacherId={teacher.teacher.id}
        lessons={lessons}
        loadingLessons={loadingLessons}
      />
    </div>
  );
}
