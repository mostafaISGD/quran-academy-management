"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import {
  getTeacherPortalEarnings,
  getTeacherPortalLessons,
  getTeacherToday,
  getTeacherWeek,
  type TeacherPortalDay,
  type TeacherPortalEarnings,
  type TeacherPortalLessonsResponse,
} from "@/lib/api";
import { dateNoYear, num, time, weekday } from "@/lib/format";
import { useCurrentUser } from "@/lib/use-current-user";
import { usePage } from "@/lib/use-page";
import {
  canOpenTeacherPortal,
  PORTAL_STATUS_LABEL,
  TEACHER_TABS,
  type PortalStatus,
  type TeacherTab,
} from "@/lib/teacher-portal";
import TeacherAvailability from "@/components/teacher/TeacherAvailability";
import TeacherEarnings from "@/components/teacher/TeacherEarnings";
import TeacherLessonList from "@/components/teacher/TeacherLessonList";
import TeacherWeek from "@/components/teacher/TeacherWeek";

/**
 * ⭐⭐⭐ **PR 4.4 — بوابة المعلم (موبايل أولاً).**
 *
 * ⭐ شاشة واحدة، خمسة تبويبات، ومن غير أي رابط إداري:
 * الـlayout بيرجّع صفر مجموعات للمعلم (`filterNavGroups`)، وهنا
 * كل التبويبات أزرار — مفيش `href` إداري.
 *
 * ⭐⭐ كل البيانات من `/teacher/*` (PR 3.10) — مفيش ولا طلب على
 * `/teacher-*` أو `/lessons` الإدارية.
 *
 * ⚠️ **Playwright مش مثبّت** في المشروع، فقبول الـroadmap (حضور
 * وإكمال حصة من 390px) اتغطّى بدوال منطق خالصة (`lib/teacher-portal.ts`)
 * + فحص يدوي موثّق — مش اختبار متصفح آلي.
 */

/** ⭐ الحالات اللي ليها معنى في فلتر «كل الحصص». */
const FILTERS: Array<PortalStatus | ""> = [
  "",
  "scheduled",
  "confirmed",
  "in_progress",
  "completed",
  "student_absent",
  "cancelled_by_academy",
];

function todayLabel(): string {
  return `${weekday(new Date())}، ${dateNoYear(new Date())}`;
}

export default function TeacherPortalPage() {
  const router = useRouter();
  const { user, loading: userLoading } = useCurrentUser();

  const [tab, setTab] = useState<TeacherTab>("today");
  const [statusFilter, setStatusFilter] = useState<PortalStatus | "">("");

  /**
   * ⭐⭐ مين يفتح البوابة: بروفايل معلم **و**`teacher.portal`.
   *
   * ⭐ مش `isTeacherOnly` عمداً: الأدمن اللي مربوط بمعلم (زي
   * حساب المدير في وضع المستخدم الواحد) يقدر يفتح البوابة
   * يشوف حصصه — لكن القائمة الإدارية لسه معاه.
   */
  const allowed = canOpenTeacherPortal(user ?? { teacher: null, permissions: [] });

  useEffect(() => {
    if (!user) return;
    if (!allowed) router.replace("/dashboard");
  }, [user, allowed, router]);

  /**
   * ⭐ الحصص بتتطلب أول ما الصفحة تفتح — والتبويبات بتضيف طلباتها.
   * كلها `enabled` بشرط الصلاحية عشان ما نبعتش طلبات هترجع 403
   * قبل ما التحويل يتم.
   */
  const fetchToday = useCallback(() => getTeacherToday(), []);
  const today = usePage<TeacherPortalDay | null>("teacher-today", fetchToday, {
    enabled: allowed && tab === "today",
    initial: null,
  });

  const fetchWeek = useCallback(() => getTeacherWeek(), []);
  const week = usePage<TeacherPortalDay | null>("teacher-week", fetchWeek, {
    enabled: allowed && tab === "week",
    initial: null,
  });

  const fetchLessons = useCallback(
    () => getTeacherPortalLessons(statusFilter ? { status: statusFilter } : undefined),
    [statusFilter],
  );
  const lessons = usePage<TeacherPortalLessonsResponse | null>(
    `teacher-lessons:${statusFilter}`,
    fetchLessons,
    { enabled: allowed && tab === "lessons", initial: null },
  );

  const fetchEarnings = useCallback(() => getTeacherPortalEarnings(), []);
  const earnings = usePage<TeacherPortalEarnings | null>(
    "teacher-earnings",
    fetchEarnings,
    { enabled: allowed && tab === "earnings", initial: null },
  );

  if (userLoading) {
    return (
      <div className="mx-auto max-w-3xl p-6">
        <p className="py-20 text-center text-sm text-slate-500">جاري تحميل البوابة…</p>
      </div>
    );
  }

  if (!user || !allowed || user.teacher === null) return null;

  const displayName = user.teacher.display_name || user.name;
  const day = today.data;
  const firstLesson = day?.lessons[0];

  return (
    <div className="mx-auto min-h-screen max-w-3xl px-4 pb-24 pt-4 sm:px-6">
      {/* ===== الترويسة ===== */}
      <header className="mb-4">
        <div className="flex flex-wrap items-baseline justify-between gap-2">
          <div>
            <h1 className="text-lg font-bold text-slate-900">بوابة المعلم</h1>
            <p className="text-xs text-slate-500">{displayName}</p>
          </div>
          <p className="text-xs text-slate-500">{todayLabel()}</p>
        </div>
      </header>

      {/* ===== المحتوى ===== */}
      <main>
        {tab === "today" && (
          <div className="space-y-4">
            <section className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
              <p className="text-xs text-slate-500">النهاردة عندك</p>
              <p className="mt-1 text-2xl font-bold text-slate-900">
                {today.loading ? "…" : `${num(day?.lessons.length ?? 0, 0)} حصة`}
              </p>
              {firstLesson && (
                <p className="mt-1 text-xs text-slate-500">
                  أول حصة {time(firstLesson.scheduled_start_at, firstLesson.timezone ?? undefined)}
                  {firstLesson.student ? ` — ${firstLesson.student.name}` : ""}
                </p>
              )}
            </section>

            <TeacherLessonList
              lessons={day?.lessons ?? []}
              loading={today.loading}
              error={today.error}
              emptyTitle="مفيش حصص النهاردة"
              emptyHint="استغل اليوم في مراجعة، أو راجع مواعيدك من تبويب «مواعيدي»."
              onChanged={today.reload}
            />
          </div>
        )}

        {tab === "week" && (
          <TeacherWeek
            day={week.data}
            loading={week.loading}
            error={week.error}
            onChanged={week.reload}
          />
        )}

        {tab === "lessons" && (
          <div className="space-y-4">
            {/* ⭐ فلتر الحالة — أزرار، مش روابط */}
            <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
              {FILTERS.map((status) => {
                const active = statusFilter === status;
                const key = status === "" ? "all" : status;

                return (
                  <button
                    key={key}
                    type="button"
                    onClick={() => setStatusFilter(status)}
                    aria-pressed={active}
                    className={`min-h-9 shrink-0 rounded-full px-3 text-xs font-medium ring-1 transition ${
                      active
                        ? "bg-slate-800 text-white ring-slate-800"
                        : "bg-white text-slate-600 ring-slate-200 hover:bg-slate-50"
                    }`}
                  >
                    {status === "" ? "الكل" : PORTAL_STATUS_LABEL[status]}
                  </button>
                );
              })}
            </div>

            <TeacherLessonList
              lessons={lessons.data?.lessons ?? []}
              loading={lessons.loading}
              error={lessons.error}
              emptyTitle="مفيش حصص بالفلتر ده"
              emptyHint="جرّب «الكل» أو غيّر الحالة."
              onChanged={lessons.reload}
            />
          </div>
        )}

        {tab === "earnings" && (
          <TeacherEarnings data={earnings.data} loading={earnings.loading} error={earnings.error} />
        )}

        {tab === "availability" && <TeacherAvailability onChanged={() => undefined} />}
      </main>

      {/* ===== شريط التبويبات — ثابت في الآخر (موبايل أولاً) ===== */}
      <nav
        aria-label="أقسام بوابة المعلم"
        className="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white shadow-[0_-4px_12px_rgba(15,23,42,0.06)]"
      >
        <div className="mx-auto grid max-w-3xl grid-cols-5">
          {TEACHER_TABS.map((item) => {
            const active = tab === item.id;

            return (
              <button
                key={item.id}
                type="button"
                onClick={() => setTab(item.id)}
                aria-current={active ? "page" : undefined}
                className={`flex min-h-14 flex-col items-center justify-center gap-0.5 text-[11px] transition focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-emerald-600 ${
                  active ? "font-semibold text-emerald-700" : "text-slate-500 hover:text-slate-800"
                }`}
              >
                <span aria-hidden className="text-base leading-none">
                  {item.icon}
                </span>
                {item.label}
              </button>
            );
          })}
        </div>
      </nav>
    </div>
  );
}
