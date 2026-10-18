"use client";

import Link from "next/link";
import { useRouter, usePathname } from "next/navigation";
import { useEffect, useState } from "react";
import { useCurrentUser } from "@/lib/use-current-user";
import { useGroupAlerts } from "@/lib/use-group-alerts";
import { num } from "@/lib/format";
import { UIProvider } from "@/components/ui/UIProvider";
import { filterNavGroups, type NavGroup } from "@/lib/nav-filter";
import { homeFor, isParentOnly, isTeacherOnly } from "@/lib/route-access";

/**
 * ⭐⭐⭐ القائمة — **كل رابط معاه صلاحيته**.
 *
 * ⭐⭐ `permission` لازم تطابق **بالظبط** اسم الصلاحية في
 * `SeedRolesPermissions::ROLES`، **و** اسم الصلاحية في
 * `routes/api.php` للراوت المقابل.
 *
 * ⚠️ لو الصلاحية غلط، الرابط **بيختفي** مش بيظهر — وده الاتجاه
 * الآمن. لو غيّرناه للإظهار عند الخطأ، أي typo في اسم صلاحية =
 * رابط مفتوح لكل حاجة. (في اختبار لي ده.)
 *
 * ⭐ `undefined` = «الكل». ده لـ`/dashboard` بس — صفحة بتجمّع
 * ملخص، مش بيانات حسّاسة.
 */
const NAV_GROUPS: NavGroup[] = [
  {
    label: "الرئيسية",
    icon: "🏠",
    items: [{ href: "/dashboard", label: "لوحة التحكم", icon: "🏠" }],
  },
  {
    label: "الأشخاص",
    icon: "👥",
    items: [
      { href: "/students", label: "الطلاب", icon: "👨‍🎓", permission: "students.view" },
      { href: "/teachers", label: "المعلمين", icon: "👨‍🏫", permission: "teachers.view" },
      { href: "/employees", label: "الموظفين", icon: "👤", permission: "students.view" },
      { href: "/parents", label: "أولياء الأمور", icon: "👨‍👩‍👧", permission: "parents.view" },
      { href: "/waitlist", label: "قائمة الانتظار", icon: "⏳", permission: "groups.manage" },
      { href: "/groups", label: "إدارة المجموعات", icon: "👥", permission: "groups.manage" },
    ],
  },
  {
    label: "الأكاديمية",
    icon: "📚",
    items: [
      { href: "/programs", label: "البرامج", icon: "📖", permission: "programs.view" },
      { href: "/assessments", label: "التقييمات", icon: "📋", permission: "leads.view" },
    ],
  },
  {
    label: "التشغيل",
    icon: "📅",
    items: [
      { href: "/schedule", label: "جدول الحصص", icon: "📅", permission: "lessons.view" },
      { href: "/attendance", label: "تسجيل الحضور", icon: "🕐", permission: "attendance.view" },
      { href: "/pricing", label: "جدول الأسعار", icon: "💲" },
    ],
  },
  {
    label: "المالية",
    icon: "💰",
    items: [
      { href: "/finance", label: "لوحة المالية", icon: "💰", permission: "reports.view" },
      { href: "/accounts", label: "الخزائن", icon: "🏦", permission: "finance.accounts" },
      { href: "/subscriptions", label: "اشتراكات الطلاب", icon: "📋", permission: "students.view" },
      { href: "/invoices", label: "فواتير الطلاب", icon: "🧾", permission: "payments.view" },
      { href: "/payments", label: "مدفوعات الطلاب", icon: "💳", permission: "payments.view" },
      { href: "/teacher-earnings", label: "مستحقات المعلمين", icon: "👨‍🏫", permission: "payroll.view" },
      { href: "/teacher-payments", label: "مدفوعات المعلمين", icon: "💸", permission: "payroll.view" },
      { href: "/teacher-payroll", label: "مرتبات المعلمين", icon: "🧮", permission: "payroll.manage" },
      { href: "/payroll", label: "مرتبات الموظفين", icon: "💼", permission: "payroll.manage" },
      { href: "/expenses", label: "المصروفات", icon: "🏢", permission: "expenses.view" },
    ],
  },
  {
    label: "CRM",
    icon: "📈",
    items: [
      { href: "/leads", label: "العملاء المحتملون", icon: "🎯", permission: "leads.view" },
    ],
  },
  {
    label: "التقارير",
    icon: "📊",
    items: [
      { href: "/reports", label: "التقارير", icon: "📊", permission: "reports.view" },
    ],
  },
  {
    label: "الإدارة",
    icon: "🛠️",
    items: [
      { href: "/pricing-admin", label: "إدارة الباقات", icon: "🏷️", permission: "pricing.manage" },
      { href: "/student-credits", label: "الرصيد الدائن", icon: "💳", permission: "payments.view" },
      { href: "/lesson-policy", label: "سياسات الحصة", icon: "📋", permission: "settings.manage" },
      { href: "/promo-codes", label: "الأكواد الترويجية", icon: "🎟️", permission: "pricing.view" },
      { href: "/reminder-settings", label: "إعدادات التذكيرات", icon: "⏰", permission: "settings.manage" },
      // ⭐ الإشعارات مالهاش `permission` عمداً — كل دور بياخد
      // إشعاراته، والحماية بالملكية جوه الـcontroller.
      { href: "/notifications", label: "الإشعارات", icon: "🔔" },
      { href: "/audit-logs", label: "سجل العمليات", icon: "📝", permission: "settings.manage" },
      // ⭐⭐ الحسابات بـ`users.view` مش `settings.manage` — دي
      // صلاحيات مختلفة. تقدر تشوف الحسابات من غير ما تلمسها.
      { href: "/settings/users", label: "الحسابات", icon: "👥", permission: "users.view" },
      // ⚡ PR 1.9: شاشة 2FA مالهاش `permission` — خدمة ذاتية
      // لحماية الحساب نفسه، زي الإشعارات بالظبط.
      { href: "/security/two-factor", label: "حماية الحساب (2FA)", icon: "🛡️" },
      { href: "/settings", label: "الإعدادات", icon: "⚙️", permission: "settings.manage" },
    ],
  },
];

export default function DashboardLayout({ children }: { children: React.ReactNode }) {
  const router = useRouter();
  const pathname = usePathname();
  const [expandedGroups, setExpandedGroups] = useState<Record<string, boolean>>({
    "الرئيسية": true,
    "الأشخاص": true,
    "المالية": true,
  });

  /**
   * ⭐⭐⭐ المستخدم الحالي — **طلب واحد للتطبيق كله**.
   *
   * ⭐ `usePage` بيشارك الطلب عبر نفس الـ`key` (`auth-me`)، فمهما
   * كان عدد الـcomponents اللي بتطلب المستخدم، **طلب واحد** بس.
   *
   * ⭐⭐ `loading` هو اللي بيخلّي الـSkeleton يظهر. من غيره، أول
   * رسم بيحصل بـ`user = null` فيرسم **القائمة كاملة** لحظتها،
   * وبعد ثانية تختفي لمعلم — ده الـflash اللي الخارطة بتتكلم عنه.
   *
   * ⭐ ومعاه مافيش `localStorage` (خلّصنا منه في PR 1.2)، فمافيش
   * نسخة قديمة تتعرض قبل الطلب يخلص.
   */
  const { user, loading } = useCurrentUser();

  const teacherOnly = isTeacherOnly(user);
  const parentOnly = isParentOnly(user);

  // ⭐⭐ بيت المستخدم — **من نفس الوحدة** اللي بتستخدمها الـproxy
  //
  // ⭐ قبل كده الشرط كان مكتوب مرتين بمحتلفين: شرط «teacherOnly»
  // هنا، وشرط `isDashboardPath` هناك. النتيجة إن **المعلم اللي
  // فتح `/students` بيشوف `homeFor` محسوب غلط** لثانية.
  const home = homeFor(user);

  /**
   * ⭐⭐⭐ القائمة بعد الفلترة.
   *
   * ⭐ `null` = لسه بنجيب المستخدم. و`[]` = مستخدم مالهوش قائمة
   * إدارية (معلم / ولي أمر) — وده **مقصود**، مش بنقص items.
   */
  const groups = filterNavGroups(NAV_GROUPS, user);

  /**
   * ⭐ رقم الجرس: «فيه ناس مستنية وفيه مقعد فاضي».
   *
   * بيتحسب **جوه** `GroupController::alerts()` — مش هنا. لو حسبناه
   * في الواجهة كمان، هنشوف رقمين مختلفين أول ما حد يدخل.
   *
   * ⚠️ الخطأ بيتجاهل بصمت: الرقم ده مش مهم، و`/groups/alerts`
   * بيرجّع 403 لموظف الاستقبال أصلاً. ما ينفعش طلب بيفشل يبقى
   * سبب إنه يفضل يطلب كل ثانية.
   */
  const canManageGroups = Boolean(user?.permissions?.includes("groups.manage"));

  /**
   * ⭐ إعادة الحساب أول ما تفتح «المجموعات».
   *
   * ⭐ الـ key فيه المسار عشان أول ما تفتح الصفحة يتحدّث
   * الرقم — لو دخّلت حد، الجرس هيفضل بالقديم غير كده.
   *
   * @see useGroupAlerts
   */
  const alertScope = pathname === "/groups" ? "groups" : "main";

  const { count: groupAlerts } = useGroupAlerts(canManageGroups, alertScope);

  // ⭐⭐ اللي فتح رابط مش ليه — نرجّعه لبيته
  //
  // ⭐ الشرط بقى `home !== "/dashboard"` بدل `teacherOnly || parentOnly`.
  // الفرق: أي حساب تاني بيتوجه لنفس الحسبة من مصدر **واحد** —
  // فلو ضفنا دور «طالب» بكرة، الشرط ده بيشتغل لوحده.
  useEffect(() => {
    if (!user) return;
    if (home !== "/dashboard" && pathname !== home) router.replace(home);
  }, [user, home, pathname, router]);

  /**
 * ⭐⭐⭐ مافيش `handleLogout` — مافيش خروج في وضع المستخدم الواحد.
 *
 * ⭐ الزرار كان بيودّي `/login` (الصفحة بقت مش موجودة)، والخروج
 * نفسه مالوش معنى: الطلب اللي بعده بيرجّع الأدمن تلقائياً.
 */

function toggleGroup(label: string) {
    setExpandedGroups((prev) => ({ ...prev, [label]: !prev[label] }));
  }

  // ===== واجهة المعلّم وولي الأمر: صفحة واحدة بس =====
  // من غير الـ sidebar — ما فيش أي رابط إداري يوصله
  if (teacherOnly || parentOnly) {
    return (
      <UIProvider>
        <div dir="rtl" className="min-h-screen bg-slate-50">{children}</div>
      </UIProvider>
    );
  }

  return (
    <UIProvider>
      <div dir="rtl" className="flex min-h-screen bg-slate-50">
      <aside className="w-60 shrink-0 border-l border-slate-200 bg-white p-3">
        <p className="mb-4 px-2 text-sm font-bold text-slate-800">أكاديمية القرآن</p>
        <nav className="space-y-1">
          {/**
           * ⭐⭐⭐ **الـSkeleton جوه الـ`nav` نفسه.**
           *
           * ⭐ لو رجعنا بيها للأرض (`animate-pulse` في الصفحة كلها)، المستخدم
           * هيفتكر إن في تأخير في **المحتوى** — واللي بيحمّل فعلاً هو
           * **القائمة**. فبنحط الهيكل في مكانه بالظبط، بنفس الأبعاد، عشان
           * لما تظهر ما يحصلش قفزة (layout shift).
           *
           * ⭐⭐ ودي ليست **قائمة من غير روابط**. الفرق مهم: الأولى بتوعد
           * المستخدم بحاجة مش موجودة، والتانية صادقة — «أنا لسه بعرف
           * مين أنت».
           */}
          {loading || groups === null
            ? Array.from({ length: 6 }).map((_, i) => (
                <div key={`skeleton-${i}`} className="space-y-2 py-1">
                  <div className="mx-3 h-4 w-40 animate-pulse rounded bg-slate-200" />
                  <div className="mx-3 h-4 w-32 animate-pulse rounded bg-slate-100" />
                  <div className="mx-3 h-4 w-36 animate-pulse rounded bg-slate-100" />
                </div>
              ))
            : groups.map((group) => {
            /* ⭐ القسم اللي فيه رابط المجموعات — عشان الجرس يفضل معاه */
            const hasGroupsLink = group.items.some((i) => i.href === "/groups");
            return (
            <div key={group.label}>
              <button
                onClick={() => toggleGroup(group.label)}
                className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              >
                <span>{group.icon} {group.label}</span>
                <span className="flex items-center gap-1.5">
                  {/* ⭐ الجرس كمان على **القسم المطوي**.
                      غير كده الجرس مخفي لحد ما تفتح القسم —
                      يعني هو مش بيلفّت نظرك زي ما المفروض.

                      ⭐ وبيتبني على «فيه رابط المجموعات» مش على اسم القسم،
                      عشان لو نقلنا رابط المجموعات لقسم تاني الجرس يروح معاه. */}
                  {hasGroupsLink && groupAlerts > 0 && (
                    <span className="inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">
                      {num(groupAlerts, 0)}
                    </span>
                  )}
                  <span className="text-xs">{expandedGroups[group.label] ? "▼" : "▶"}</span>
                </span>
              </button>
              {expandedGroups[group.label] && (
                <div className="mr-4 space-y-0.5 border-r border-slate-200 pr-2">
                  {group.items.map((item) => {
                    const isActive = pathname === item.href || (item.href !== "/dashboard" && pathname.startsWith(item.href));
                    return (
                      <Link
                        key={item.href}
                        href={item.href}
                        className={`block rounded-lg px-3 py-1.5 text-sm transition-colors ${
                          isActive
                            ? "bg-slate-800 text-white"
                            : "text-slate-600 hover:bg-slate-100 hover:text-slate-900"
                        }`}
                      >
                        {/* ⭐ الجرس: «فيه ناس مستنية وفيه مقعد فاضي» */}
                        {item.href === "/groups" && groupAlerts > 0 && (
                          <span className="mr-1 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-amber-500 px-1 text-[10px] font-bold text-white">
                            {/* ⭐ `num` مش الرقم الخام — التطبيق كله عربي */}
                            {num(groupAlerts, 0)}
                          </span>
                        )}
                        {item.icon} {item.label}
                      </Link>
                    );
                  })}
                </div>
              )}
            </div>
            );
          })}
        </nav>
        {/* ⭐⭐⭐ مافيش زرار خروج — وضع المستخدم الواحد.

            ⭐ كان بيودّي `/login`، والصفحة دي بقت مش موجودة.
            وحتى لو وجودها، الطلب اللي بعده بيرجّع الأدمن تاني
            تلقائياً (`AutoLoginAdmin`)، فـ«تسجيل الخروج» معناه
            صفر. الزرار كان وعد كاذب.
        */}
      </aside>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </UIProvider>
  );
}
