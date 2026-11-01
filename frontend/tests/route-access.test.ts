import { describe, expect, it } from "vitest";

import {
  DEFAULT_HOME,
  decideRoute,
  homeFor,
  isDashboardPath,
  isParentOnly,
  isPublicPath,
  isStudentOnly,
  isTeacherOnly,
  loginRedirect,
  ROLE_HOME,
  type AccessUser,
} from "@/lib/route-access";

// ═══════════════════════════════════════════════════════════════
// بناء المستخدمين
// ═══════════════════════════════════════════════════════════════

function user(overrides: Partial<NonNullable<AccessUser>> = {}): NonNullable<AccessUser> {
  return {
    is_teacher: false,
    teacher: null,
    is_parent: false,
    parent: null,
    is_student: false,
    student: null,
    roles: [],
    permissions: [],
    ...overrides,
  };
}

const admin = () => user({ roles: ["admin"], permissions: ["students.view"] });
const teacher = () =>
  user({
    is_teacher: true,
    teacher: { id: 1 } as NonNullable<AccessUser>["teacher"],
    roles: ["teacher"],
    permissions: ["programs.view"],
  });
const parent = () =>
  user({
    is_parent: true,
    parent: { id: 1 } as NonNullable<AccessUser>["parent"],
    roles: ["parent"],
    permissions: ["programs.view"],
  });
const student = () =>
  user({
    is_student: true,
    student: { id: 7 } as NonNullable<AccessUser>["student"],
    roles: ["student"],
    permissions: ["student.portal", "programs.view"],
  });

// ═══════════════════════════════════════════════════════════════
// ① مين أنا
// ═══════════════════════════════════════════════════════════════

describe("isTeacherOnly / isParentOnly / isStudentOnly", () => {
  it("a teacher with a teacher record is teacher-only", () => {
    expect(isTeacherOnly(teacher())).toBe(true);
    expect(isParentOnly(teacher())).toBe(false);
    expect(isStudentOnly(teacher())).toBe(false);
  });

  it("⭐ PR 4.6 — a student with a student record is student-only", () => {
    expect(isStudentOnly(student())).toBe(true);
    expect(isTeacherOnly(student())).toBe(false);
    expect(isParentOnly(student())).toBe(false);
  });

  it("⭐ an admin linked to a student record is NOT student-only", () => {
    // ⭐ نفس حادثة المعلم: المدير مربوط بسجل طالب في القاعدة.
    const linkedAdmin = user({
      roles: ["admin"],
      is_student: true,
      student: { id: 7 } as NonNullable<AccessUser>["student"],
    });

    expect(isStudentOnly(linkedAdmin)).toBe(false);
  });

  it("a student record without the link is not enough", () => {
    expect(
      isStudentOnly(user({ is_student: true, roles: ["student"] })),
    ).toBe(false);
  });

  it("a parent with a parent profile is parent-only", () => {
    expect(isParentOnly(parent())).toBe(true);
  });

  it("⭐ an admin who happens to be linked to a teacher record is NOT teacher-only", () => {
    // ⭐⭐ دي الحادثة اللي كانت موجودة في القاعدة فعلاً:
    // حساب المدير نفسه مربوط بمعلم #1، والواجهة كانت بتوديه
    // /my-schedule بدل لوحة التحكم.
    const linkedAdmin = user({
      roles: ["admin"],
      is_teacher: true,
      teacher: { id: 1 } as NonNullable<AccessUser>["teacher"],
    });

    expect(isTeacherOnly(linkedAdmin)).toBe(false);
    expect(isParentOnly(linkedAdmin)).toBe(false);
  });

  it("a teacher record without the link is not enough", () => {
    // ⭐ `is_teacher: true` بس من غير `teacher` = بيانات ناقصة،
    // مش دليل. لازم الاتنين.
    expect(isTeacherOnly(user({ is_teacher: true, roles: ["teacher"] }))).toBe(false);
  });

  it("null user is none of them", () => {
    expect(isTeacherOnly(null)).toBe(false);
    expect(isParentOnly(null)).toBe(false);
    expect(isStudentOnly(null)).toBe(false);
  });
});

describe("homeFor", () => {
  it("an admin lands on the dashboard", () => {
    expect(homeFor(admin())).toBe(DEFAULT_HOME);
  });

  it("a teacher lands on their schedule", () => {
    expect(homeFor(teacher())).toBe(ROLE_HOME.teacher);
  });

  it("a parent lands on their children", () => {
    expect(homeFor(parent())).toBe(ROLE_HOME.parent);
  });

  it("⭐ PR 4.6 — a student lands on the student portal", () => {
    expect(homeFor(student())).toBe(ROLE_HOME.student);
    expect(ROLE_HOME.student).toBe("/student");
  });

  it("⭐ the role homes are distinct", () => {
    // ⭐ لو اتكرروا، اختبار آخر بيبقى بلا معنى: كل اختبار
    // redirect هيعدّي لاCause السبب.
    expect(new Set(Object.values(ROLE_HOME)).size).toBe(Object.keys(ROLE_HOME).length);
    expect(DEFAULT_HOME).not.toBe(ROLE_HOME.teacher);
    expect(DEFAULT_HOME).not.toBe(ROLE_HOME.parent);
    expect(DEFAULT_HOME).not.toBe(ROLE_HOME.student);
  });
});

// ═══════════════════════════════════════════════════════════════
// ② تصنيف المسار
// ═══════════════════════════════════════════════════════════════

describe("path classification", () => {
  it("public paths are exactly the declared ones", () => {
    for (const path of ["/login", "/", "/pricing", "/groups", "/join"]) {
      expect(isPublicPath(path)).toBe(true);
      expect(isDashboardPath(path)).toBe(false);
    }
  });

  it("⭐ PR 4.6 — a join link with a token is public too", () => {
    // ⭐ `/join/{token}` مش في القائمة حرفياً — بس هو نفس الصفحة
    // العامة. من غير فحص البادئة، الطفل هيتحوّل لـ`/login`.
    for (const path of ["/join/abc123", "/join/abc123/", "/join/a/b"]) {
      expect(isPublicPath(path)).toBe(true);
      expect(isDashboardPath(path)).toBe(false);
      expect(decideRoute(path, { hasSession: false })).toEqual({ kind: "allow" });
    }
  });

  it("⭐ a path that merely starts with 'join' is not public", () => {
    // ⭐ بادئة `/join/` مش `/join` — `/joiner` صفحة تانية تماماً.
    expect(isPublicPath("/joiner")).toBe(false);
    expect(isDashboardPath("/joiner")).toBe(true);
  });

  it("everything else is a dashboard path", () => {
    for (const path of ["/students", "/finance", "/settings", "/audit-logs", "/teachers", "/student"]) {
      expect(isDashboardPath(path)).toBe(true);
    }
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ القرار — ده الـacceptance بتاع الـPR
// ═══════════════════════════════════════════════════════════════

describe("decideRoute — no session", () => {
  it("⭐⭐ visiting /students without a session redirects to /login", () => {
    // ⭐ ده الـacceptance الأول في الخارطة بالحرف.
    expect(decideRoute("/students", { hasSession: false })).toEqual({
      kind: "redirect",
      to: "/login",
    });
  });

  it("every dashboard path is guarded, not just the samples", () => {
    // ⭐ `/groups` و`/pricing` **مش** في القائمة دي عن قصد:
    // الاتنين عامّان على السيرفر (قرار موثّق في `routes/api.php`)
    // لأن الأهالي بتسأل من غير حساب. لو حجبناهم، سؤال صحيح
    // هيتقابل بـ«لازم تسجّل».
    const paths = [
      "/students",
      "/teachers",
      "/employees",
      "/parents",
      "/waitlist",
      "/programs",
      "/schedule",
      "/attendance",
      "/pricing-admin",
      "/finance",
      "/accounts",
      "/subscriptions",
      "/invoices",
      "/payments",
      "/teacher-earnings",
      "/teacher-payroll",
      "/payroll",
      "/expenses",
      "/leads",
      "/assessments",
      "/reports",
      "/promo-codes",
      "/student-credits",
      "/lesson-policy",
      "/reminder-settings",
      "/notifications",
      "/audit-logs",
      "/settings",
    ];

    for (const path of paths) {
      expect(decideRoute(path, { hasSession: false })).toEqual({
        kind: "redirect",
        to: "/login",
      });
    }
  });

  it("public paths stay open", () => {
    for (const path of ["/login", "/", "/pricing", "/groups"]) {
      expect(decideRoute(path, { hasSession: false })).toEqual({ kind: "allow" });
    }
  });
});

describe("decideRoute — with a session", () => {
  it("⭐⭐ a session cookie exists but the user is unknown → allow (no loop)", () => {
    // ⭐⭐⭐ دي أهم حالة في الملف كله.
    //
    // الـproxy **مش** بيجيب المستخدم، فأي وقت يكون `user = null`
    // لازم يعدّي. لو رجعنا `/login` هنا، الـlayout هيوديه
    // `/dashboard`، واللي بيحصل هو **loop** لا نهائي بين
    // الراوتين.
    //
    // الأمان الحقيقي في `dal.ts` — اللي بيعمل `fetch` للـAPI
    // ويعيد التوجيه بس على **401 فعلي**.
    expect(decideRoute("/students", { hasSession: true })).toEqual({ kind: "allow" });
    expect(decideRoute("/students", { hasSession: true, user: null })).toEqual({
      kind: "allow",
    });
  });

  it("an admin on any page is allowed", () => {
    for (const path of ["/students", "/finance", "/settings", "/audit-logs"]) {
      expect(decideRoute(path, { hasSession: true, user: admin() })).toEqual({
        kind: "allow",
      });
    }
  });

  it("a teacher away from their home is sent to it", () => {
    expect(decideRoute("/students", { hasSession: true, user: teacher() })).toEqual({
      kind: "redirect",
      to: "/my-schedule",
    });
  });

  it("a teacher on their own home is allowed", () => {
    expect(
      decideRoute(ROLE_HOME.teacher, { hasSession: true, user: teacher() }),
    ).toEqual({ kind: "allow" });
  });

  it("a parent away from their home is sent to it", () => {
    expect(decideRoute("/finance", { hasSession: true, user: parent() })).toEqual({
      kind: "redirect",
      to: "/my-children",
    });
  });

  it("a parent on their own home is allowed", () => {
    expect(
      decideRoute(ROLE_HOME.parent, { hasSession: true, user: parent() }),
    ).toEqual({ kind: "allow" });
  });

  it("⭐ PR 4.6 — a student away from their home is sent to it", () => {
    expect(decideRoute("/students", { hasSession: true, user: student() })).toEqual({
      kind: "redirect",
      to: "/student",
    });
    expect(decideRoute("/finance", { hasSession: true, user: student() })).toEqual({
      kind: "redirect",
      to: "/student",
    });
  });

  it("a student on their own home is allowed", () => {
    expect(
      decideRoute(ROLE_HOME.student, { hasSession: true, user: student() }),
    ).toEqual({ kind: "allow" });
  });

  it("⭐ a public page stays open even for a teacher", () => {
    // ⭐ ولي الأمر والمعلم لازم يوصلوا `/pricing` — دي عامة على
    // السيرفر وبيستخدموها فعلاً.
    expect(
      decideRoute("/pricing", { hasSession: true, user: teacher() }),
    ).toEqual({ kind: "allow" });
  });

  it("⭐ the redirect is never a loop", () => {
    // ⭐⭐ بتاخد كل النواتج الممكنة وتطلب إنTarget نفسه يعدّي.
    // ⭐ أي `redirect → redirect` معناها loop حقيقي في الإنتاج.
    const users = [admin(), teacher(), parent(), student()];
    const paths = [
      "/login",
      "/",
      "/pricing",
      "/groups",
      "/join/token-1",
      "/students",
      "/finance",
      "/settings",
      "/my-schedule",
      "/my-children",
      "/student",
      "/dashboard",
    ];

    for (const session of [false, true]) {
      for (const u of [null, ...users]) {
        for (const path of paths) {
          const first = decideRoute(path, { hasSession: session, user: u });

          if (first.kind === "allow") continue;

          const second = decideRoute(first.to, { hasSession: session, user: u });

          expect(
            second.kind,
            `loop: ${path} → ${first.to} → ${second.kind}`,
          ).toEqual("allow");
        }
      }
    }
  });
});

// ═══════════════════════════════════════════════════════════════
// ④ الـredirect الحقيقي
// ═══════════════════════════════════════════════════════════════

describe("loginRedirect", () => {
  it("keeps where the user was going", () => {
    expect(loginRedirect("/students")).toBe("/login?next=%2Fstudents");
  });

  it("does not encode the root as a next target", () => {
    expect(loginRedirect("/")).toBe("/login");
    expect(loginRedirect("")).toBe("/login");
  });

  it("⭐ the next target cannot smuggle a full URL", () => {
    // ⭐⭐ لو الـvalue بتاعة `next` رايحة لـ`redirect()` مباشرة،
    // ده **open redirect**: حد يبعت `/login?next=https://evil.com`
    // والمستخدم يطلع على موقع تاني.
    //
    // ⭐ `encodeURIComponent` بيمنع ده، والـDAL لازم يفحص إن
    // القيمة تبدأ بـ`/`.
    const next = loginRedirect("//evil.com").split("next=")[1];
    expect(next).toBe(encodeURIComponent("//evil.com"));
    expect(decodeURIComponent(next).startsWith("//")).toBe(true);
    expect(encodeURIComponent(next)).not.toBe(next);
  });
});