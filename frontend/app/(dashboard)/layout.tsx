"use client";

import Link from "next/link";
import { useRouter, usePathname } from "next/navigation";
import { useState } from "react";
import { logout } from "@/lib/api";
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
    ],
  },
  {
    label: "المالية",
    icon: "💰",
    items: [
      { href: "/finance", label: "لوحة المالية", icon: "💰" },
      { href: "/subscriptions", label: "اشتراكات الطلاب", icon: "📋" },
      { href: "/invoices", label: "فواتير الطلاب", icon: "🧾" },
      { href: "/payments", label: "مدفوعات الطلاب", icon: "💳" },
      { href: "/teacher-earnings", label: "مستحقات المعلمين", icon: "👨‍🏫" },
      { href: "/teacher-payments", label: "مدفوعات المعلمين", icon: "💸" },
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
    icon: "⚙️",
    items: [
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

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  function toggleGroup(label: string) {
    setExpandedGroups((prev) => ({ ...prev, [label]: !prev[label] }));
  }

  return (
    <UIProvider>
      <div dir="rtl" className="flex min-h-screen bg-slate-50">
      <aside className="w-60 shrink-0 border-l border-slate-200 bg-white p-3">
        <p className="mb-4 px-2 text-sm font-bold text-slate-800">أكاديمية القرآن</p>
        <nav className="space-y-1">
          {NAV_GROUPS.map((group) => (
            <div key={group.label}>
              <button
                onClick={() => toggleGroup(group.label)}
                className="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              >
                <span>{group.icon} {group.label}</span>
                <span className="text-xs">{expandedGroups[group.label] ? "▼" : "▶"}</span>
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
                        {item.icon} {item.label}
                      </Link>
                    );
                  })}
                </div>
              )}
            </div>
          ))}
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
