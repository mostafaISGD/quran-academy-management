"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import {
  getStudentOverview,
  getStudentProgress,
  getStudentSchedule,
  logout,
  type StudentOverview,
  type StudentProgress,
  type StudentSchedule,
} from "@/lib/api";
import { num } from "@/lib/format";
import { homeFor, isStudentOnly } from "@/lib/route-access";
import {
  canOpenStudentPortal,
  STUDENT_TABS,
  type StudentTabKey,
} from "@/lib/student-portal";
import { useCurrentUser } from "@/lib/use-current-user";
import { usePage } from "@/lib/use-page";
import { StudentOverviewPanel } from "@/components/student/StudentOverviewPanel";
import { StudentProgressPanel } from "@/components/student/StudentProgressPanel";
import { StudentSchedulePanel } from "@/components/student/StudentSchedulePanel";

/**
 * ⭐⭐⭐ **PR 4.6 — بوابة الطالب (`/student`).**
 *
 * ⭐ الطالب المستقل (≥ 13 بموافقة مسجّلة) بيشوف **نفسه فقط**:
 * جدوله، حضوره، تقدمه، وحفظه. مفيش أي مسار مالي في الصفحة،
 * ومفيش أي `student_id` في الطلبات — السيرفر بيحدد الطالب من
 * الجلسة.
 *
 * ⭐ الطفل < 13 مالوش حساب، فبوابته `/join/{token}` أو `/join`
 * بالـPIN — صفحة عامة بلا أي بيانات شخصية.
 *
 * ⭐ الصفحة بتحمّل التبويبات التلاتة مرة واحدة: البيانات صغيرة
 * (جدول الطالب نفسه)، والتنقل بينها بيبقى فوري.
 */
export default function StudentPortalPage() {
  const router = useRouter();
  const { user, loading: userLoading } = useCurrentUser();

  const studentOnly = isStudentOnly(user);
  const allowed = canOpenStudentPortal(user);

  const [tab, setTab] = useState<StudentTabKey>("overview");

  const fetchOverview = useCallback(() => getStudentOverview(), []);
  const fetchSchedule = useCallback(() => getStudentSchedule(), []);
  const fetchProgress = useCallback(() => getStudentProgress(), []);

  const overview = usePage<StudentOverview | null>("student:overview", fetchOverview, {
    enabled: allowed,
  });
  const schedule = usePage<StudentSchedule | null>("student:schedule", fetchSchedule, {
    enabled: allowed,
  });
  const progress = usePage<StudentProgress | null>("student:progress", fetchProgress, {
    enabled: allowed,
  });

  /**
   * ⭐ اللي مش طالب (مدير فتح الرابط مثلاً) بيرجع لبيته.
   *
   * ⚠️ الطالب اللي عنده بروفايل بس **من غير** `student.portal`
   * مابنوديهوش لـ`/student` تاني — ده loop. بنعرضله رسالة صريحة.
   */
  useEffect(() => {
    if (!user) return;
    if (!studentOnly) router.replace(homeFor(user));
  }, [user, studentOnly, router]);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  if (userLoading) {
    return <p className="p-8 text-center text-sm text-slate-500">جاري التحميل…</p>;
  }

  if (user && !studentOnly) return null;

  if (studentOnly && !allowed) {
    return (
      <div className="mx-auto max-w-lg p-8 text-center">
        <p className="rounded-xl bg-amber-50 p-4 text-sm text-amber-800 ring-1 ring-amber-200">
          حسابك مش مفعّل عليه بوابة الطالب — كلّم الإدارة عشان تفعّلها.
        </p>
      </div>
    );
  }

  const student = overview.data?.student ?? user?.student ?? null;

  return (
    <div className="mx-auto max-w-4xl p-4 sm:p-6">
      {/* ===== الترويسة ===== */}
      <header className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">
            {student?.full_name ?? "بوابة الطالب"}
          </h1>
          <p className="text-xs text-slate-500">
            {student?.student_code ? `كود الطالب: ${student.student_code}` : ""}
          </p>
        </div>

        <button
          type="button"
          onClick={handleLogout}
          className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200"
        >
          🚪 تسجيل الخروج
        </button>
      </header>

      {/* ===== التبويبات ===== */}
      <nav
        aria-label="أقسام بوابة الطالب"
        className="mb-5 flex gap-1 overflow-x-auto rounded-xl bg-slate-100 p-1"
      >
        {STUDENT_TABS.map((item) => (
          <button
            key={item.key}
            type="button"
            onClick={() => setTab(item.key)}
            aria-current={tab === item.key ? "page" : undefined}
            className={`flex-1 whitespace-nowrap rounded-lg px-3 py-2 text-sm font-medium transition ${
              tab === item.key
                ? "bg-white text-slate-800 shadow-sm"
                : "text-slate-500 hover:text-slate-700"
            }`}
          >
            {item.icon} {item.label}
          </button>
        ))}
      </nav>

      {/* ===== المحتوى ===== */}
      {tab === "overview" && (
        <TabBody loading={overview.loading} error={overview.error}>
          {overview.data && <StudentOverviewPanel overview={overview.data} />}
        </TabBody>
      )}

      {tab === "schedule" && (
        <TabBody loading={schedule.loading} error={schedule.error}>
          {schedule.data && (
            <StudentSchedulePanel
              upcoming={schedule.data.upcoming}
              past={schedule.data.past}
            />
          )}
        </TabBody>
      )}

      {tab === "progress" && (
        <TabBody loading={progress.loading} error={progress.error}>
          {progress.data && <StudentProgressPanel progress={progress.data} />}
        </TabBody>
      )}

      <p className="mt-6 text-center text-[11px] text-slate-400">
        {num(STUDENT_TABS.length, 0)} أقسام — بياناتك أكاديمية فقط، ومفيش أي بيانات مالية هنا.
      </p>
    </div>
  );
}

function TabBody({
  loading,
  error,
  children,
}: {
  loading: boolean;
  error: string | null;
  children: React.ReactNode;
}) {
  if (loading) {
    return <p className="py-10 text-center text-sm text-slate-500">جاري التحميل…</p>;
  }

  if (error) {
    return (
      <div className="rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200">
        {error}
      </div>
    );
  }

  return <>{children}</>;
}
