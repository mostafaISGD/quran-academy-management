"use client";

import { useCallback, useEffect } from "react";
import { useRouter } from "next/navigation";
import { usePage } from "@/lib/use-page";
import { useCurrentUser } from "@/lib/use-current-user";
import {
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
  const { user: teacher } = useCurrentUser();

  /**
   * ⭐ الجدول بيتجلب بس لو المستخدم **معلّم فعلاً**.
   *
   * ⭐ `enabled` بيمنع طلب جدول معلم لحد مش معلم.
   *
   * @see usePage
   */
  const teacherId = teacher?.teacher?.id ?? null;

  const fetchLessons = useCallback(async () => {
    if (!teacherId) return [];
    const r = await getTeacherLessons(teacherId);
    return r.data ?? [];
  }, [teacherId]);

  const { data: lessons, loading: loadingLessons } = usePage<Lesson[]>(
    `my-schedule:${teacherId ?? "none"}`,
    fetchLessons,
    { initial: [], enabled: teacherId !== null },
  );

  /** ⭐ اللي مش معلم — يرجع لصفحته */
  useEffect(() => {
    if (!teacher) return;
    if (!teacher.teacher) router.replace("/dashboard");
  }, [teacher, router]);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  if (!teacher?.teacher) return null;

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
