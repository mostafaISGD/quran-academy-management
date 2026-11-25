import { describe, expect, it } from "vitest";

import {
  ATTENDANCE_LABEL,
  ATTENDANCE_TONE,
  PARENT_TABS,
  REQUEST_STATUS_LABEL,
  REQUEST_TYPES,
  attendanceRate,
  canOpenParentPortal,
  childOptions,
  nextChildId,
  requestNeeds,
  requestPayload,
  resolveParentTab,
  validateStudentRequest,
  visibleParentTabs,
  type ParentFlags,
} from "@/lib/parent-portal";

/**
 * ⭐⭐⭐ **PR 4.5 — بوابة ولي الأمر v2: منطق الشاشة.**
 *
 * ⚠️ زي PR 4.4: Playwright مش مثبّت في المشروع، فقبول الـroadmap
 * («الولي ما يشوفش مال ابنه لو can_view_finance=false») اتغطّى من
 * ناحيتين: الباكإند (ParentPortalV2Test) و**دوال الشاشة الخالصة**
 * هنا. الصفحة بتستهلك الدوال دي — فمفيش قرار في JSX من غير اختبار.
 */

const ALL: ParentFlags = {
  can_view_finance: true,
  can_view_progress: true,
  can_request_changes: true,
};

const NO_FINANCE: ParentFlags = { ...ALL, can_view_finance: false };
const NO_PROGRESS: ParentFlags = { ...ALL, can_view_progress: false };
const NO_REQUESTS: ParentFlags = { ...ALL, can_request_changes: false };

// ═══════════════════════════════════════════════════════════════
// ① التبويبات والأعلام
// ═══════════════════════════════════════════════════════════════

describe("parent portal tabs", () => {
  it("⭐ التبويبات الستة بمعرّفات فريدة وتسميات عربية", () => {
    expect(PARENT_TABS.map((t) => t.id)).toEqual([
      "overview",
      "attendance",
      "progress",
      "lessons",
      "invoices",
      "requests",
    ]);

    expect(new Set(PARENT_TABS.map((t) => t.id)).size).toBe(PARENT_TABS.length);
    expect(PARENT_TABS.every((t) => t.label.trim().length > 0)).toBe(true);
  });

  it("⭐⭐ can_view_finance=false يخفي الفواتير — قبول الـroadmap", () => {
    const tabs = visibleParentTabs(NO_FINANCE);

    expect(tabs).not.toContain("invoices");
    expect(tabs).toContain("overview");
  });

  it("⭐ can_view_progress=false يخفي الحضور والتقدم والحصص", () => {
    const tabs = visibleParentTabs(NO_PROGRESS);

    expect(tabs).not.toContain("attendance");
    expect(tabs).not.toContain("progress");
    expect(tabs).not.toContain("lessons");
    expect(tabs).toContain("overview");
  });

  it("⭐ can_request_changes=false يخفي تبويب الطلبات", () => {
    expect(visibleParentTabs(NO_REQUESTS)).not.toContain("requests");
    expect(visibleParentTabs(ALL)).toContain("requests");
  });

  it("⭐⭐ قبل وصول الأعلام — النظرة العامة بس", () => {
    expect(visibleParentTabs(null)).toEqual(["overview"]);
    expect(visibleParentTabs(undefined)).toEqual(["overview"]);
  });

  it("⭐⭐ التبديل لابن تاني قد يخفي التبويب المفتوح — نرجع للنظرة العامة", () => {
    expect(resolveParentTab("invoices", NO_FINANCE)).toBe("overview");
    expect(resolveParentTab("progress", NO_PROGRESS)).toBe("overview");
    expect(resolveParentTab("requests", NO_REQUESTS)).toBe("overview");
    expect(resolveParentTab("invoices", ALL)).toBe("invoices");
  });
});

// ═══════════════════════════════════════════════════════════════
// ② حارس فتح البوابة والتبديل بين الأبناء
// ═══════════════════════════════════════════════════════════════

describe("canOpenParentPortal", () => {
  it("⭐ لازم بروفايل ولي أمر — مش بس الدور", () => {
    expect(canOpenParentPortal({ is_parent: true, parent: { id: 1 } })).toBe(true);
    expect(canOpenParentPortal({ is_parent: false, parent: { id: 1 } })).toBe(false);
    expect(canOpenParentPortal({ is_parent: true, parent: null })).toBe(false);
    expect(canOpenParentPortal(null)).toBe(false);
  });
});

describe("child switching", () => {
  const children = [
    { student: { id: 11, full_name: "أحمد", student_code: "ST-11" } },
    { student: { id: 22, full_name: "مريم", student_code: "ST-22" } },
    { student: { id: 33, full_name: "يوسف", student_code: "ST-33" } },
  ];

  it("⭐ قائمة التبديل بتحمل الاسم والكود", () => {
    expect(childOptions(children)).toEqual([
      { id: 11, name: "أحمد", code: "ST-11" },
      { id: 22, name: "مريم", code: "ST-22" },
      { id: 33, name: "يوسف", code: "ST-33" },
    ]);
  });

  it("⭐⭐ التبديل بيلفّ — من الآخر للأول والعكس", () => {
    expect(nextChildId(children, 11)).toBe(22);
    expect(nextChildId(children, 33)).toBe(11);
    expect(nextChildId(children, 11, -1)).toBe(33);
    expect(nextChildId(children, 22, -1)).toBe(11);
  });

  it("⭐ ابن واحد = مفيش تبديل، ومعرّف مش موجود = أول ابن", () => {
    expect(nextChildId([children[0]], 11)).toBeNull();
    expect(nextChildId([], 1)).toBeNull();
    expect(nextChildId(children, 999)).toBe(11);
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ الحضور
// ═══════════════════════════════════════════════════════════════

describe("attendance", () => {
  it("⭐ كل حالة ليها تسمية عربية ولون", () => {
    for (const status of ["present", "absent", "late", "excused"] as const) {
      expect(ATTENDANCE_LABEL[status].length).toBeGreaterThan(0);
      expect(ATTENDANCE_TONE[status].length).toBeGreaterThan(0);
    }
  });

  it("⭐⭐ نسبة الحضور = (حاضر + متأخر) / الإجمالي", () => {
    expect(
      attendanceRate({ present: 6, absent: 2, late: 2, excused: 0, total: 10 }),
    ).toBe(80);

    expect(attendanceRate({ present: 3, absent: 0, late: 0, excused: 0, total: 3 })).toBe(100);
  });

  it("⭐ مفيش حصص = مفيش نسبة (null مش 0%)", () => {
    expect(attendanceRate({ present: 0, absent: 0, late: 0, excused: 0, total: 0 })).toBeNull();
    expect(attendanceRate(null)).toBeNull();
  });
});

// ═══════════════════════════════════════════════════════════════
// ④ طلبات ولي الأمر
// ═══════════════════════════════════════════════════════════════

describe("student requests", () => {
  it("⭐ الأنواع الثلاثة ومتطلبات كل نوع", () => {
    expect(REQUEST_TYPES.map((t) => t.id)).toEqual(["cancel", "postpone", "suspend"]);

    expect(requestNeeds("cancel")).toEqual({
      lesson: true,
      subscription: false,
      date: false,
    });
    expect(requestNeeds("postpone")).toEqual({
      lesson: true,
      subscription: false,
      date: true,
    });
    expect(requestNeeds("suspend")).toEqual({
      lesson: false,
      subscription: true,
      date: false,
    });
  });

  it("⭐⭐ الإلغاء بدون حصة مرفوض", () => {
    const errors = validateStudentRequest({
      type: "cancel",
      reason: "ظرف عائلي طارئ",
    });

    expect(errors).toContain("اختر الحصة المطلوب إلغاؤها");
  });

  it("⭐⭐ التأجيل لازم تاريخ جديد", () => {
    const errors = validateStudentRequest({
      type: "postpone",
      lessonId: 5,
      reason: "ظرف عائلي طارئ",
    });

    expect(errors).toContain("حدد التاريخ الجديد المطلوب");
  });

  it("⭐⭐ التعليق لازم اشتراك", () => {
    const errors = validateStudentRequest({
      type: "suspend",
      reason: "سفر لمدة شهر",
    });

    expect(errors).toContain("مفيش اشتراك نشط لتعليقه");
  });

  it("⭐ السبب أقل من ٥ حروف مرفوض", () => {
    const errors = validateStudentRequest({
      type: "cancel",
      lessonId: 5,
      reason: "سفر",
    });

    expect(errors).toContain("اكتب سبباً واضحاً (5 حروف على الأقل)");
  });

  it("⭐⭐ طلب صحيح = صفر أخطاء، والحمولة فيها الحقول اللازمة بس", () => {
    const draft = {
      type: "postpone" as const,
      lessonId: 5,
      subscriptionId: 9,
      requestedDate: "2026-11-05",
      reason: "ظرف عائلي طارئ يمنع الحضور",
    };

    expect(validateStudentRequest(draft)).toEqual([]);
    expect(requestPayload(draft)).toEqual({
      type: "postpone",
      lesson_id: 5,
      requested_date: "2026-11-05",
      reason: "ظرف عائلي طارئ يمنع الحضور",
    });
  });

  it("⭐ التعليق بيبعت الاشتراك وبلا حصة أو تاريخ", () => {
    expect(
      requestPayload({
        type: "suspend",
        lessonId: 5,
        subscriptionId: 9,
        requestedDate: "2026-11-05",
        reason: "سفر لمدة شهر",
      }),
    ).toEqual({
      type: "suspend",
      subscription_id: 9,
      reason: "سفر لمدة شهر",
    });
  });

  it("⭐ كل حالة طلب ليها تسمية عربية", () => {
    for (const status of ["pending", "approved", "rejected", "withdrawn"] as const) {
      expect(REQUEST_STATUS_LABEL[status].length).toBeGreaterThan(0);
    }
  });
});
