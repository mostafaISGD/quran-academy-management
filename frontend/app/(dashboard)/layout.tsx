"use client";

import Link from "next/link";
import { useRouter, usePathname } from "next/navigation";
import { useCallback, useEffect, useState } from "react";
import { getGroupAlerts, logout, fetchMe, type AuthUser } from "@/lib/api";
import { usePage } from "@/lib/use-page";
import { num } from "@/lib/format";
import { UIProvider } from "@/components/ui/UIProvider";

interface NavItem {
  href: string;
  label: string;
  icon: string;
}

interface NavGroup {
  label: string;
  icon: string;
  items: NavItem[];
}

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
      { href: "/students", label: "الطلاب", icon: "👨‍🎓" },
      { href: "/teachers", label: "المعلمين", icon: "👨‍🏫" },
      { href: "/employees", label: "الموظفين", icon: "👤" },
      { href: "/parents", label: "أولياء الأمور", icon: "👨‍👩‍👧" },
      { href: "/waitlist", label: "قائمة الانتظار", icon: "⏳" },
      { href: "/groups", label: "إدارة المجموعات", icon: "👥" },
    ],
  },
  {
    label: "الأكاديمية",
    icon: "📚",
    items: [
      { href: "/programs", label: "البرامج", icon: "📖" },
      { href: "/assessments", label: "التقييمات", icon: "📋" },
    ],
  },
  {
    label: "التشغيل",
    icon: "📅",
    items: [
      { href: "/schedule", label: "جدول الحصص", icon: "📅" },
      { href: "/attendance", label: "تسجيل الحضور", icon: "🕐" },
      { href: "/pricing", label: "جدول الأسعار", icon: "💲" },
    ],
  },
  {
    label: "المالية",
    icon: "💰",
    items: [
      { href: "/finance", label: "لوحة المالية", icon: "💰" },
      { href: "/accounts", label: "الخزائن", icon: "🏦" },
      { href: "/subscriptions", label: "اشتراكات الطلاب", icon: "📋" },
      { href: "/invoices", label: "فواتير الطلاب", icon: "🧾" },
      { href: "/payments", label: "مدفوعات الطلاب", icon: "💳" },
      { href: "/teacher-earnings", label: "مستحقات المعلمين", icon: "👨‍🏫" },
      { href: "/teacher-payments", label: "مدفوعات المعلمين", icon: "💸" },
      { href: "/teacher-payroll", label: "مرتبات المعلمين", icon: "🧮" },
      { href: "/payroll", label: "مرتبات الموظفين", icon: "💼" },
      { href: "/expenses", label: "المصروفات", icon: "🏢" },
    ],
  },
  {
    label: "CRM",
    icon: "📈",
    items: [
      { href: "/leads", label: "العملاء المحتملون", icon: "🎯" },
    ],
  },
  {
    label: "التقارير",
    icon: "📊",
    items: [
      { href: "/reports", label: "التقارير", icon: "📊" },
    ],
  },
  {
    label: "الإدارة",
    icon: "🛠️",
    items: [
      { href: "/pricing-admin", label: "إدارة الباقات", icon: "🏷️" },
      { href: "/student-credits", label: "الرصيد الدائن", icon: "💳" },
      { href: "/lesson-policy", label: "سياسات الحصة", icon: "📋" },
      { href: "/promo-codes", label: "الأكواد الترويجية", icon: "🎟️" },
      { href: "/reminder-settings", label: "إعدادات التذكيرات", icon: "⏰" },
      { href: "/notifications", label: "الإشعارات", icon: "🔔" },
      { href: "/audit-logs", label: "سجل العمليات", icon: "📝" },
      { href: "/settings", label: "الإعدادات", icon: "⚙️" },
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
   * ⭐ مين داخل؟ المعلّم بياخد واجهة مختصرة بجدوله، وولي الأمر بصفحة أبناؤه.
   *
   * ⭐⭐⭐ **كان فيه طلبين لنفس الحاجة.**
   *
   * الـ layout كان فيه **تنين** `useEffect` بيعملوا `fetchMe()`
   * — واحد بيجيب الجرس معاه وواحد لوحده. يعني **كل فتح صفحة
   * بيعمل طلبين لـ `/auth/me`** والاثنين نفس النتيجة.
   *
   * اتدمجوا في `usePage` واحد: طلب واحد، ومفيش طلب تاني.
   *
   * @see usePage
   */
  const fetchMeOnce = useCallback(() => fetchMe(), []);

  const { data: user } = usePage<AuthUser | null>("auth-me", fetchMeOnce, {
    initial: null,
  });

  const isTeacherOnly = Boolean(user?.is_teacher && user?.teacher);
  const isParentOnly = Boolean(user?.is_parent && user?.parent);

  // صفحة ولي الأمر — مختلفة عن صفحة المعلّم
  const homeFor = isParentOnly ? "/my-children" : "/my-schedule";

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

  const fetchAlerts = useCallback(async () => {
    const r = await getGroupAlerts();
    // ⭐ حماية: لو الـtoken اتبطل، الـcount بيرجع undefined
    return typeof r?.count === "number" ? r.count : 0;
  }, []);

  /**
   * ⭐ إعادة الحساب أول ما تفتح «المجموعات».
   *
   * السبب: لولا كده، لو دخّلت حد، الجرس هيفضل بالرقم القديم لحد
   * ما تعمل تحديث للصفحة كلها.
   *
   * ⭐ والـ `key` بيغطي الحالة: من غير صلاحية مفيش طلب خالص
   * (`enabled`) — عشان ما نعملش 403 لكل موظف استقبال.
   */
  const { data: groupAlerts } = usePage<number>(
    `group-alerts:${canManageGroups ? (pathname === "/groups" ? "groups" : "main") : "off"}`,
    fetchAlerts,
    { initial: 0, enabled: canManageGroups },
  );

  // اللي فتح رابط مش ليه — نرجّعه لصفحته
  useEffect(() => {
    if (!user) return;
    if (isTeacherOnly && pathname !== homeFor) router.replace(homeFor);
    if (isParentOnly && pathname !== homeFor) router.replace(homeFor);
  }, [user, isTeacherOnly, isParentOnly, pathname, router, homeFor]);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  function toggleGroup(label: string) {
    setExpandedGroups((prev) => ({ ...prev, [label]: !prev[label] }));
  }

  // ===== واجهة المعلّم وولي الأمر: صفحة واحدة بس =====
  // من غير الـ sidebar — ما فيش أي رابط إداري يوصله
  if (isTeacherOnly || isParentOnly) {
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
          {NAV_GROUPS.map((group) => {
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
        <button
          onClick={handleLogout}
          className="mt-6 w-full rounded-lg bg-red-50 px-3 py-2 text-sm text-red-600 hover:bg-red-100"
        >
          🚪 تسجيل الخروج
        </button>
      </aside>
        <main className="flex-1 p-6">{children}</main>
      </div>
    </UIProvider>
  );
}
