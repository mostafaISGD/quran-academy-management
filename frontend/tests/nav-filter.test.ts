import { describe, expect, it } from "vitest";

import { filterNavGroups, type NavGroup } from "@/lib/nav-filter";
import type { AccessUser } from "@/lib/route-access";

function user(overrides: Partial<NonNullable<AccessUser>> = {}): NonNullable<AccessUser> {
  return {
    is_teacher: false,
    teacher: null,
    is_parent: false,
    parent: null,
    roles: [],
    permissions: [],
    ...overrides,
  };
}

const admin = () =>
  user({ roles: ["admin"], permissions: ["students.view", "finance.accounts"] });

const teacher = () =>
  user({
    is_teacher: true,
    teacher: { id: 1 } as NonNullable<AccessUser>["teacher"],
    roles: ["teacher"],
    permissions: ["programs.view"],
  });

const receptionist = () =>
  user({ roles: ["receptionist"], permissions: ["students.view", "attendance.manage"] });

const parent = () =>
  user({
    is_parent: true,
    parent: { id: 1 } as NonNullable<AccessUser>["parent"],
    roles: ["parent"],
    permissions: ["programs.view"],
  });

// ═══════════════════════════════════════════════════════════════
// القائمة الحقيقية — نفس الأرقام اللي في (dashboard)/layout.tsx
// ═══════════════════════════════════════════════════════════════

const GROUPS: NavGroup[] = [
  {
    label: "الرئيسية",
    icon: "🏠",
    items: [{ href: "/dashboard", label: "لوحة التحكم", icon: "🏠" }],
  },
  {
    label: "الأشخاص",
    icon: "👥",
    items: [
      { href: "/students", label: "الطلاب", icon: "A", permission: "students.view" },
      { href: "/teachers", label: "المعلمين", icon: "B", permission: "teachers.view" },
      { href: "/employees", label: "الموظفين", icon: "C", permission: "students.view" },
      { href: "/groups", label: "المجموعات", icon: "D", permission: "groups.manage" },
    ],
  },
  {
    label: "المالية",
    icon: "💰",
    items: [
      { href: "/finance", label: "المالية", icon: "E", permission: "reports.view" },
      { href: "/accounts", label: "الخزائن", icon: "F", permission: "finance.accounts" },
    ],
  },
  {
    label: "الإدارة",
    icon: "🛠️",
    items: [
      { href: "/settings", label: "الإعدادات", icon: "G", permission: "settings.manage" },
      { href: "/audit-logs", label: "السجل", icon: "H", permission: "settings.manage" },
    ],
  },
];

const hrefs = (groups: NavGroup[] | null) =>
  groups === null ? [] : groups.flatMap((g) => g.items.map((i) => i.href));

// ═══════════════════════════════════════════════════════════════
// ① التحميل
// ═══════════════════════════════════════════════════════════════

describe("filterNavGroups — while loading", () => {
  it("⭐⭐ returns null, not an empty list", () => {
    // ⭐⭐⭐ دي نقطة_flash الـadmin UI كلها.
    //
    // لو رجعنا `[]`، المستخدم هيشوف **قائمة من غير روابط**
    // لثانية، وبعدين فلجأة تظهر links. ده أسوأ من الـflash:
    // المستخدم بيضغط على مكان فاضي.
    //
    // ⭐ `null` معناها «لسه بنحمّل» والـlayout بيعرض Skeleton.
    expect(filterNavGroups(GROUPS, null)).toBeNull();
  });
});

// ═══════════════════════════════════════════════════════════════
// ② الأدوار الخاصة
// ═══════════════════════════════════════════════════════════════

describe("filterNavGroups — restricted roles", () => {
  it("⭐⭐ a teacher sees no admin menu at all", () => {
    // ⭐ ده الـacceptance التالت في الخارطة بالحرف.
    const result = filterNavGroups(GROUPS, teacher());

    expect(result).toEqual([]);
    expect(hrefs(result)).not.toContain("/students");
    expect(hrefs(result)).not.toContain("/settings");
  });

  it("a parent sees no admin menu at all", () => {
    expect(filterNavGroups(GROUPS, parent())).toEqual([]);
  });

  it("⭐ an admin linked to a teacher record still sees the menu", () => {
    // ⭐ نفس حادثة قاعدة البيانات: المدير مربوط بمعلم #1.
    const linkedAdmin = user({
      roles: ["admin"],
      permissions: ["students.view"],
      is_teacher: true,
      teacher: { id: 1 } as NonNullable<AccessUser>["teacher"],
    });

    expect(hrefs(filterNavGroups(GROUPS, linkedAdmin))).toContain("/students");
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ الفلترة بالصلاحية
// ═══════════════════════════════════════════════════════════════

describe("filterNavGroups — permission filtering", () => {
  it("⭐ a receptionist sees students but no finance and no settings", () => {
    const result = filterNavGroups(GROUPS, receptionist()) ?? [];

    expect(hrefs(result)).toContain("/students");
    expect(hrefs(result)).not.toContain("/accounts");
    expect(hrefs(result)).not.toContain("/settings");
  });

  it("⭐ a group with nothing left is dropped entirely", () => {
    // ⭐ قسم فاضي = واجهة مكسورة. موظف الاستقبال مالوش
    // `settings.manage`، فقسم «الإدارة» كله اختفى.
    const result = filterNavGroups(GROUPS, receptionist()) ?? [];

    expect(result.map((g) => g.label)).not.toContain("الإدارة");
    expect(result.map((g) => g.label)).not.toContain("المالية");
  });

  it("⭐ a user with no permissions at all still gets the unguarded pages", () => {
    // ⭐ `/dashboard` مالهاش صلاحية — دي مش accidental. لازم
    // حد مالهوش أي صلاحية يفضل يقدر يدخل ويشوف «ماعندكش
    // صلاحية» بدل صفحة بيضاء.
    const result = filterNavGroups(GROUPS, user({ roles: ["teacher"], permissions: [] }));

    expect(hrefs(result)).toEqual(["/dashboard"]);
  });

  it("never returns a group with zero items", () => {
    const users = [admin(), teacher(), receptionist(), user()];

    for (const u of users) {
      for (const group of filterNavGroups(GROUPS, u) ?? []) {
        expect(
          group.items.length,
          `القسم «${group.label}» طلع فاضي — لازم يتشال`,
        ).toBeGreaterThan(0);
      }
    }
  });
});

// ═══════════════════════════════════════════════════════════════
// ④ Cada على أسماء الصلاحيات
// ═══════════════════════════════════════════════════════════════

describe("filterNavGroups — a permission that does not exist", () => {
  it("⭐ a typo silently hides the link instead of showing it", () => {
    // ⭐⭐ وده **سلوك مقصود**: لو كتبنا `students.views` (في ن)،
    // الرابط بيختفي — مش بيظهر لغير المصرّح.
    //
    // ⭐ الاتجاه الآمن هو الإخفاء مش الإظهار. لو غيّرناه، أي
    // typo في اسم الصلاحية = رابط مفتوح لكل حاجة.
    const groups: NavGroup[] = [
      {
        label: "الأشخاص",
        icon: "👥",
        items: [{ href: "/students", label: "الطلاب", icon: "A", permission: "students.view" }],
      },
    ];

    // ⭐ الاسم الصح
    expect(hrefs(filterNavGroups(groups, user({ permissions: ["students.view"] })))).toEqual([
      "/students",
    ]);

    // ⭐ خطأ إملائي — الرابط بيختفي، وده هو الآمن
    expect(hrefs(filterNavGroups(groups, user({ permissions: ["students.views"] })))).toEqual([]);
  });
});