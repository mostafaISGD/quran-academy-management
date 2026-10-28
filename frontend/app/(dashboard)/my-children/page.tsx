"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

import {
  getMyChildren,
  getParentChild,
  getParentChildAttendance,
  getParentChildInvoices,
  getParentChildLessons,
  getParentChildPayments,
  getParentChildProgress,
  getParentRequests,
  type ParentAttendance,
  type ParentChildDetail,
  type ParentChildView,
  type ParentInvoices,
  type ParentLessons,
  type ParentPayments,
  type ParentProgress,
  type StudentRequestRow,
} from "@/lib/api";
import { useCurrentUser } from "@/lib/use-current-user";
import { usePage } from "@/lib/use-page";
import {
  PARENT_TABS,
  canOpenParentPortal,
  resolveParentTab,
  visibleParentTabs,
  type ParentPortalTabId,
} from "@/lib/parent-portal";
import ParentChildHeader from "@/components/parent/ParentChildHeader";
import ChildOverview from "@/components/parent/ChildOverview";
import ChildAttendance from "@/components/parent/ChildAttendance";
import ChildProgress from "@/components/parent/ChildProgress";
import ChildLessons from "@/components/parent/ChildLessons";
import ChildFinance from "@/components/parent/ChildFinance";
import ChildRequests from "@/components/parent/ChildRequests";
import { Empty, ErrorBox, LoadingRows } from "@/components/parent/parent-ui";

/**
 * ⭐⭐⭐ **PR 4.5 — بوابة ولي الأمر v2.**
 *
 * ⭐ شاشة واحدة، ستة تبويبات (حسب أعلام الرابط)، وشريط تنقّل سفلي
 * ثابت — زي بوابة المعلم (PR 4.4). مفيش أي رابط إداري: الـlayout
 * بيرجّع صفر مجموعات لولي الأمر (`filterNavGroups`).
 *
 * ⭐⭐ **التبديل بين الأبناء فوري**: الابن المختار بيتحدد من قائمة
 * الأبناء، وكل تبويب بيجيب بيانات الابن ده بس من `/parent/children/{id}/*`
 * — والسيرفر بيفلتر بـ`ParentChildScope` (أبناء مؤكَّدون فقط).
 *
 * ⚠️ **قبول الـroadmap**: الولي ما يشوفش مال ابنه لو
 * `can_view_finance=false` — متحقّق في السيرفر (`requireFinance`)
 * وفي الواجهة (`visibleParentTabs`). وطلب الإلغاء بيظهر في صندوق
 * الإدارة (`/student-requests`).
 *
 * ⚠️ Playwright مش مثبّت، فالقرارات كلها في `lib/parent-portal.ts`
 * المختبَرة، والباقي فحص يدوي موثّق في وصف الـPR.
 */

type ChildrenResponse = {
  parent: { id: number; name: string; phone: string | null; email: string | null };
  children: ParentChildView[];
};

/**
 * ⭐⭐ **«لسه بيحمّل» الحقيقي.**
 *
 * ⚠️ `usePage` بيبدأ `loading` بقيمة `enabled` وقت **التركيب**. والتبويبات
 * هنا مقفولة في أول رسم (المستخدم على «نظرة عامة»)، فلما يفتح تبويب
 * بـ`enabled` بيقلب لـ`true` — لكن `loading` بيفضل `false` لحد ما
 * الطلب يخلص. من غير الدالة دي، الشاشة بتعرض «مفيش بيانات» لحظة
 * قبل ما البيانات توصل. `data === null && error === null` = مستني.
 */
function stillLoading<T>(state: {
  data: T | null;
  loading: boolean;
  error: string | null;
}): boolean {
  return state.loading || (state.data === null && state.error === null);
}

export default function MyChildrenPage() {
  const router = useRouter();
  const { user, loading: userLoading } = useCurrentUser();

  const allowed = canOpenParentPortal(user);

  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [tab, setTab] = useState<ParentPortalTabId>("overview");

  /** ⭐ اللي مش ولي أمر — يرجع لصفحته */
  useEffect(() => {
    if (!user) return;
    if (!allowed) router.replace("/dashboard");
  }, [user, allowed, router]);

  // ── قائمة الأبناء ──────────────────────────────────────────
  const fetchChildren = useCallback(() => getMyChildren(), []);
  const childrenPage = usePage<ChildrenResponse | null>("parent-children", fetchChildren, {
    enabled: allowed,
    initial: null,
  });

  const children = childrenPage.data?.children ?? [];

  /**
   * ⭐⭐ الابن المختار **مشتق** مش مخزّن — فمفيش `useEffect` يزامن
   * الحالة، وأول ابن بيظهر تلقائياً من غير أي كتابة.
   */
  const selected =
    children.find((child) => child.student.id === selectedId) ?? children[0] ?? null;
  const childId = selected?.student.id ?? null;

  // ── ملف الابن (ومنه الأعلام) ───────────────────────────────
  const fetchDetail = useCallback(
    () => (childId === null ? Promise.resolve(null) : getParentChild(childId)),
    [childId],
  );
  const detail = usePage<ParentChildDetail | null>(
    `parent-child:${childId ?? 0}`,
    fetchDetail,
    { enabled: allowed && childId !== null, initial: null },
  );

  const flags = detail.data?.flags ?? null;
  const visible = visibleParentTabs(flags);
  /** ⭐ منطقة الابن الزمنية — كل التواريخ بتتعرض بيها. */
  const timeZone = detail.data?.profile.timezone ?? undefined;

  /** ⭐⭐ التبويب الفعلي — لو الأعلام اتغيّرت (تبديل ابن) نرجع للنظرة العامة. */
  const activeTab = resolveParentTab(tab, flags);

  function selectChild(id: number) {
    setSelectedId(id);
    // ⭐ الأعلام بتختلف من ابن لابن — نبدأ من النظرة العامة.
    setTab("overview");
  }

  // ── بيانات التبويبات (كل واحدة بتتطلب عند فتحها بس) ───────
  const fetchAttendance = useCallback(
    () => (childId === null ? Promise.resolve(null) : getParentChildAttendance(childId)),
    [childId],
  );
  const attendance = usePage<ParentAttendance | null>(
    `parent-attendance:${childId ?? 0}`,
    fetchAttendance,
    { enabled: allowed && childId !== null && activeTab === "attendance", initial: null },
  );

  const fetchProgress = useCallback(
    () => (childId === null ? Promise.resolve(null) : getParentChildProgress(childId)),
    [childId],
  );
  const progress = usePage<ParentProgress | null>(
    `parent-progress:${childId ?? 0}`,
    fetchProgress,
    { enabled: allowed && childId !== null && activeTab === "progress", initial: null },
  );

  const fetchLessons = useCallback(
    () => (childId === null ? Promise.resolve(null) : getParentChildLessons(childId)),
    [childId],
  );
  const lessons = usePage<ParentLessons | null>(
    `parent-lessons:${childId ?? 0}`,
    fetchLessons,
    { enabled: allowed && childId !== null && activeTab === "lessons", initial: null },
  );

  const fetchInvoices = useCallback(
    () => (childId === null ? Promise.resolve(null) : getParentChildInvoices(childId)),
    [childId],
  );
  const invoices = usePage<ParentInvoices | null>(
    `parent-invoices:${childId ?? 0}`,
    fetchInvoices,
    { enabled: allowed && childId !== null && activeTab === "invoices", initial: null },
  );

  const fetchPayments = useCallback(
    () => (childId === null ? Promise.resolve(null) : getParentChildPayments(childId)),
    [childId],
  );
  const payments = usePage<ParentPayments | null>(
    `parent-payments:${childId ?? 0}`,
    fetchPayments,
    { enabled: allowed && childId !== null && activeTab === "invoices", initial: null },
  );

  const fetchRequests = useCallback(() => getParentRequests(), []);
  const requestsPage = usePage<{ requests: StudentRequestRow[] } | null>(
    `parent-requests:${childId ?? 0}`,
    fetchRequests,
    {
      enabled:
        allowed &&
        childId !== null &&
        activeTab === "requests" &&
        flags?.can_request_changes === true,
      initial: null,
    },
  );

  /** ⭐ طلبات الابن المختار بس — السيرفر بيرجّع طلبات الحساب كله. */
  const childRequests = (requestsPage.data?.requests ?? []).filter(
    (row) => row.student?.id === childId,
  );

  // ── حالات الشاشة ───────────────────────────────────────────
  if (userLoading) {
    return (
      <div className="mx-auto max-w-3xl p-6">
        <p className="py-20 text-center text-sm text-slate-500">جاري تحميل البوابة…</p>
      </div>
    );
  }

  if (!user || !allowed) return null;

  const tabs = PARENT_TABS.filter((item) => visible.includes(item.id));

  return (
    <div className="mx-auto min-h-screen max-w-3xl px-4 pb-24 pt-4 sm:px-6">
      <div className="mb-4">
        {stillLoading(childrenPage) && !childrenPage.data ? (
          <div className="space-y-2">
            <div className="h-6 w-40 animate-pulse rounded bg-slate-200" />
            <div className="h-14 animate-pulse rounded-xl bg-white ring-1 ring-slate-200" />
          </div>
        ) : childrenPage.error ? (
          <ErrorBox message={childrenPage.error} />
        ) : children.length === 0 ? (
          <div className="space-y-3">
            <h1 className="text-lg font-bold text-slate-900">
              {childrenPage.data?.parent.name ?? user.name}
            </h1>
            <Empty
              title="مفيش طلاب مرتبطين بحسابك لسه."
              hint="لو اتسجلت في أكاديمية قبل كده، كلّم الإدارة عشان تربط الحساب."
            />
          </div>
        ) : (
          <ParentChildHeader
            items={children}
            selectedId={childId}
            parentName={childrenPage.data?.parent.name ?? user.name}
            parentPhone={childrenPage.data?.parent.phone ?? null}
            onSelect={selectChild}
          />
        )}
      </div>

      {children.length > 0 && (
        <main>
          {stillLoading(detail) && !detail.data ? (
            <LoadingRows count={3} />
          ) : detail.error ? (
            <ErrorBox message={detail.error} />
          ) : detail.data ? (
            <>
              {activeTab === "overview" && <ChildOverview child={detail.data} />}

              {activeTab === "attendance" && (
                <ChildAttendance
                  data={attendance.data}
                  loading={stillLoading(attendance)}
                  error={attendance.error}
                  timeZone={timeZone}
                />
              )}

              {activeTab === "progress" && (
                <ChildProgress
                  data={progress.data}
                  loading={stillLoading(progress)}
                  error={progress.error}
                />
              )}

              {activeTab === "lessons" && (
                <ChildLessons
                  data={lessons.data}
                  loading={stillLoading(lessons)}
                  error={lessons.error}
                  timeZone={timeZone}
                />
              )}

              {activeTab === "invoices" && (
                <ChildFinance
                  invoices={invoices.data}
                  payments={payments.data}
                  invoicesLoading={stillLoading(invoices)}
                  paymentsLoading={stillLoading(payments)}
                  invoicesError={invoices.error}
                  paymentsError={payments.error}
                  timeZone={timeZone}
                />
              )}

              {activeTab === "requests" && (
                <ChildRequests
                  child={detail.data}
                  requests={childRequests}
                  loading={stillLoading(requestsPage)}
                  error={requestsPage.error}
                  onChanged={requestsPage.reload}
                />
              )}
            </>
          ) : null}
        </main>
      )}

      {/* ===== شريط التبويبات — ثابت في الآخر (موبايل أولاً) ===== */}
      {children.length > 0 && tabs.length > 0 && (
        <nav
          aria-label="أقسام بوابة ولي الأمر"
          className="fixed inset-x-0 bottom-0 z-20 border-t border-slate-200 bg-white shadow-[0_-4px_12px_rgba(15,23,42,0.06)]"
        >
          <div
            className="mx-auto grid max-w-3xl"
            style={{ gridTemplateColumns: `repeat(${tabs.length}, minmax(0, 1fr))` }}
          >
            {tabs.map((item) => {
              const active = activeTab === item.id;

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
      )}
    </div>
  );
}
