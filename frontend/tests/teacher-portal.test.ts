import { describe, expect, it } from "vitest";

import {
  IDLE_ATTENDANCE,
  TEACHER_TABS,
  attendancePayload,
  canOpenTeacherPortal,
  dayKey,
  earningsRows,
  groupLessonsByDay,
  lessonActions,
  needsAyahRange,
  portalStatusTone,
  PORTAL_STATUS_LABEL,
  tapAttendanceStatus,
  tapLesson,
  validateMemorization,
  type AttendanceStep,
  type PortalStatus,
} from "@/lib/teacher-portal";
import { filterNavGroups, type NavGroup } from "@/lib/nav-filter";
import type { AccessUser } from "@/lib/route-access";

/**
 * ⭐⭐⭐ **PR 4.4 — بوابة المعلم (موبايل أولاً): منطق الشاشة.**
 *
 * ⚠️ Playwright مش مثبّت في المشروع (ولا `playwright.config.ts`)،
 * فقبول الـroadmap «معلم يسجّل حضور ويكمّل حصة من 390px» مش
 * قابل للتنفيذ الآلي هنا. البديل: كل قرار في الشاشة (أي زر يظهر،
 * الحضور بنقرتين، ملخص المستحقات، تجميع الأسبوع) بقى **دالة
 * خالصة** ليها اختبار — والباقي فحص يدوي موثّق في الـPR.
 */

// ═══════════════════════════════════════════════════════════════
// ① التبويبات — كلها داخل البوابة، مفيش رابط إداري
// ═══════════════════════════════════════════════════════════════

describe("teacher portal tabs", () => {
  it("⭐ التبويبات الخمسة المطلوبة بالترتيب وبمعرّفات فريدة", () => {
    expect(TEACHER_TABS.map((t) => t.id)).toEqual([
      "today",
      "week",
      "lessons",
      "earnings",
      "availability",
    ]);

    expect(new Set(TEACHER_TABS.map((t) => t.id)).size).toBe(TEACHER_TABS.length);
    expect(TEACHER_TABS.every((t) => t.label.trim().length > 0)).toBe(true);
  });

  it("⭐⭐ مفيش رابط إداري في البوابة — ولا حتى في تعريف التبويبات", () => {
    // ⭐ القبول في الـroadmap: «no admin link appears for them».
    // البوابة شاشة واحدة بتبويبات (أزرار، مش روابط) — فأي `href`
    // هنا لازم يكون داخل `/teacher` أو رابط حصة خارجي.
    for (const tab of TEACHER_TABS) {
      const href = (tab as { href?: string }).href;
      if (href !== undefined) {
        expect(href.startsWith("/teacher"), `${tab.id}: ${href}`).toBe(true);
      }
    }
  });

  it("⭐⭐ حتى لو القائمة الإدارية اتبعتت للمعلم، الفلتر بيرجّع صفر", () => {
    // ⭐ ده الضمان اللي الـlayout بيعتمد عليه: مش «إخفاء» — صفر
    // مجموعات. الاختبار هنا بيثبت القبول مش مجرد السلوك.
    const adminNav: NavGroup[] = [
      {
        label: "الإدارة",
        icon: "⚙",
        items: [
          { href: "/students", label: "الطلاب", icon: "A" },
          { href: "/finance", label: "المالية", icon: "B" },
          { href: "/settings", label: "الإعدادات", icon: "C" },
        ],
      },
    ];

    const teacher: AccessUser = {
      is_teacher: true,
      teacher: {
        id: 1,
        teacher_code: "TCH-001",
        display_name: "معلم",
        specialization: null,
        avatar_url: null,
      },
      is_parent: false,
      parent: null,
      roles: ["teacher"],
      permissions: ["teacher.portal"],
    };

    expect(filterNavGroups(adminNav, teacher)).toEqual([]);
  });
});

// ═══════════════════════════════════════════════════════════════
// ①-ب مين يفتح البوابة — بروفايل + صلاحية
// ═══════════════════════════════════════════════════════════════

describe("canOpenTeacherPortal", () => {
  const profile = {
    id: 1,
    teacher_code: "TCH-001",
    display_name: "معلم",
    specialization: null,
    avatar_url: null,
  };

  it("⭐ بروفايل معلم + `teacher.portal` = يفتح", () => {
    expect(
      canOpenTeacherPortal({ teacher: profile, permissions: ["teacher.portal"] }),
    ).toBe(true);
  });

  it("⭐⭐ بروفايل بلا صلاحية = ما يفتحش (كان هياخد 403 على كل تبويب)", () => {
    // ⭐ أي `user_id` مربوط بسجل teacher — زي حساب المدير — كان
    // هيدخل شاشة كل طلباتها بترجع 403 لو دوره مش معاه الصلاحية.
    expect(
      canOpenTeacherPortal({ teacher: profile, permissions: ["programs.view"] }),
    ).toBe(false);
  });

  it("صلاحية بلا بروفايل = ما يفتحش (السيرفر بيرد 403 في `ownTeacherId`)", () => {
    expect(canOpenTeacherPortal({ teacher: null, permissions: ["teacher.portal"] })).toBe(
      false,
    );
  });

  it("مستخدم لسه ما وصلش (null) = ما يفتحش", () => {
    expect(canOpenTeacherPortal({ teacher: null, permissions: [] })).toBe(false);
  });
});

// ═══════════════════════════════════════════════════════════════
// ② أزرار الحصة — حسب الحالة
// ═══════════════════════════════════════════════════════════════

describe("lessonActions", () => {
  it("الحصة المجدولة: بدء + انضمام لو فيه رابط", () => {
    expect(lessonActions("scheduled", false)).toEqual(["start"]);
    expect(lessonActions("confirmed", true)).toEqual(["join", "start"]);
  });

  it("الحصة الجارية: حضور وحفظ وإتمام + انضمام", () => {
    expect(lessonActions("in_progress", true)).toEqual([
      "join",
      "attendance",
      "memorization",
      "complete",
    ]);
    expect(lessonActions("in_progress", false)).toEqual([
      "attendance",
      "memorization",
      "complete",
    ]);
  });

  it("الحصة المكتملة: مراجعة/حفظ بس — مفيش إعادة إتمام", () => {
    expect(lessonActions("completed", false)).toEqual(["memorization"]);
  });

  it("⭐ الملغية والغياب: مفيش أي إجراء", () => {
    const closed: PortalStatus[] = [
      "cancelled_by_academy",
      "cancelled_late",
      "teacher_absent",
      "student_absent",
    ];

    for (const status of closed) {
      expect(lessonActions(status, true), status).toEqual([]);
    }
  });

  it("⭐⭐ حصة مجموعة (بلا طالب): مفيش حضور ولا حفظ — بس بدء/إتمام/انضمام", () => {
    // ⭐ البوابة بترجّع `student: null` لحصص المجموعات، ومسارات
    // الحضور/الحفظ محتاجة `student_id` واحد. الأزرار بتختفي بدل
    // ما تفتح لوحة كل ضغطاتها بتفشل.
    expect(lessonActions("scheduled", false, false)).toEqual(["start"]);
    expect(lessonActions("in_progress", false, false)).toEqual(["complete"]);
    expect(lessonActions("in_progress", true, false)).toEqual(["join", "complete"]);
    expect(lessonActions("completed", false, false)).toEqual([]);
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ الحضور بنقرتين — مش نقرة واحدة
// ═══════════════════════════════════════════════════════════════

describe("two-tap attendance", () => {
  const lesson = { id: 7, studentId: 3 };

  it("⭐ نقرة واحدة على الحصة بتفتح الاختيار — ومفيش أي حمولة لسه", () => {
    const afterFirstTap = tapLesson(IDLE_ATTENDANCE, lesson);

    expect(afterFirstTap.step).toBe("choose");
    expect(attendancePayload(afterFirstTap)).toBeNull();
  });

  it("⭐ النقرة التانية (حاضر) بتكوّن الحمولة الصح", () => {
    const chosen = tapLesson(IDLE_ATTENDANCE, lesson);
    const confirmed = tapAttendanceStatus(chosen, "present");

    expect(confirmed.step).toBe("confirm");
    expect(attendancePayload(confirmed)).toEqual({
      entries: [{ student_id: 3, status: "present" }],
    });
  });

  it("⭐ «غائب» و«متأخر» بيوصلوا بنفس الطريقة", () => {
    const chosen = tapLesson(IDLE_ATTENDANCE, lesson);

    expect(attendancePayload(tapAttendanceStatus(chosen, "absent"))).toEqual({
      entries: [{ student_id: 3, status: "absent" }],
    });
    expect(attendancePayload(tapAttendanceStatus(chosen, "late"))).toEqual({
      entries: [{ student_id: 3, status: "late" }],
    });
  });

  it("⭐ نقرة الحالة من غير اختيار حصة = مفيش حمولة (زر مش مفعّل)", () => {
    const state = tapAttendanceStatus(IDLE_ATTENDANCE, "present");

    expect(state.step).toBe("idle");
    expect(attendancePayload(state)).toBeNull();
  });

  it("⭐ النقر على نفس الحصة تاني بيلغي الاختيار، وحصة تانية بتنقل الاختيار", () => {
    const chosen = tapLesson(IDLE_ATTENDANCE, lesson);

    expect(tapLesson(chosen, lesson).step).toBe("idle");

    const other = tapLesson(chosen, { id: 9, studentId: 4 });
    expect(other).toEqual({ step: "choose", lessonId: 9, studentId: 4 });
  });

  it("⭐ بعد التسجيل (idle) مفيش حمولة قديمة بتتعاد", () => {
    const confirmed: AttendanceStep = {
      step: "confirm",
      lessonId: 7,
      studentId: 3,
      status: "present",
    };

    expect(attendancePayload(confirmed)).not.toBeNull();
    expect(attendancePayload(IDLE_ATTENDANCE)).toBeNull();
  });
});

// ═══════════════════════════════════════════════════════════════
// ④ المستحقات — كل عملة لوحدها (بلا خلط وحدات)
// ═══════════════════════════════════════════════════════════════

describe("earningsRows", () => {
  it("⭐⭐ عملتين = سطرين منفصلين — ممنوع جمع EGP مع USD", () => {
    const rows = earningsRows({
      USD: { balance: 40, total_debit: 0, total_credit: 40 },
      EGP: { balance: 1200, total_debit: 200, total_credit: 1400 },
    });

    expect(rows).toEqual([
      { currency: "EGP", balance: 1200, total_debit: 200, total_credit: 1400 },
      { currency: "USD", balance: 40, total_debit: 0, total_credit: 40 },
    ]);
  });

  it("كشف فاضي = صفر سطور (مش سطر بأصفار)", () => {
    expect(earningsRows({})).toEqual([]);
  });
});

// ═══════════════════════════════════════════════════════════════
// ⑤ الأسبوع — التجميع بمنطقة المعلم الزمنية
// ═══════════════════════════════════════════════════════════════

describe("groupLessonsByDay", () => {
  it("⭐⭐ حصة 22:30 UTC يوم 27 = يوم 28 في القاهرة", () => {
    // ⭐ من غير تجميع بالمنطقة الزمنية، حصة آخر الليل بتنزل في
    // اليوم الغلط — وبوابة معلم في مصر بتورّي «امبارح».
    expect(dayKey("2026-10-27T22:30:00+00:00", "Africa/Cairo")).toBe("2026-10-28");
    expect(dayKey("2026-10-27T20:30:00+00:00", "Africa/Cairo")).toBe("2026-10-27");
  });

  it("بيجمّع بنفس الترتيب وبيحافظ على حصص اليوم", () => {
    const lessons = [
      { id: 1, scheduled_start_at: "2026-10-28T06:00:00+00:00" },
      { id: 2, scheduled_start_at: "2026-10-28T08:00:00+00:00" },
      { id: 3, scheduled_start_at: "2026-10-29T06:00:00+00:00" },
    ];

    const days = groupLessonsByDay(lessons, "Africa/Cairo");

    expect(days.map((d) => d.key)).toEqual(["2026-10-28", "2026-10-29"]);
    expect(days[0].lessons.map((l) => l.id)).toEqual([1, 2]);
    expect(days[1].lessons.map((l) => l.id)).toEqual([3]);
  });
});

// ═══════════════════════════════════════════════════════════════
// ⑥ الحفظ/المراجعة — تحقق قبل الطلب
// ═══════════════════════════════════════════════════════════════

describe("memorization form", () => {
  it("⭐ الحفظ الجديد محتاج سورة ونطاق آيات", () => {
    expect(needsAyahRange("memorization")).toBe(true);
    expect(needsAyahRange("revision")).toBe(false);

    const errors = validateMemorization({ category: "memorization" });

    expect(errors).toContain("اختر السورة");
    expect(errors).toContain("حدد من آية إلى آية");
  });

  it("⭐ المراجعة مش محتاجة نطاق آيات", () => {
    expect(validateMemorization({ category: "revision", quality: 4 })).toEqual([]);
  });

  it("⭐ نطاق مقلوب أو صفر = خطأ بالعربي", () => {
    expect(
      validateMemorization({
        category: "memorization",
        surahId: 1,
        fromAyah: 0,
        toAyah: 5,
      }),
    ).toContain("رقم الآية لازم يكون 1 على الأقل");

    expect(
      validateMemorization({
        category: "memorization",
        surahId: 1,
        fromAyah: 5,
        toAyah: 3,
      }),
    ).toContain("«إلى آية» لازم تكون بعد «من آية»");
  });

  it("⭐ نطاق سليم من غير أخطاء", () => {
    expect(
      validateMemorization({
        category: "memorization",
        surahId: 1,
        fromAyah: 1,
        toAyah: 7,
        quality: 5,
      }),
    ).toEqual([]);
  });
});

// ═══════════════════════════════════════════════════════════════
// ⑦ الحالة — كل حالة ليها لون واسم عربي
// ═══════════════════════════════════════════════════════════════

describe("portal status presentation", () => {
  const all: PortalStatus[] = [
    "scheduled",
    "confirmed",
    "in_progress",
    "completed",
    "student_absent",
    "teacher_absent",
    "cancelled_by_academy",
    "cancelled_late",
    "rescheduled",
  ];

  it("كل حالة ليها اسم عربي غير فاضي", () => {
    for (const status of all) {
      expect(PORTAL_STATUS_LABEL[status]?.trim().length, status).toBeGreaterThan(0);
    }
  });

  it("الألوان محدودة ومتسقة", () => {
    const tones = new Set(all.map(portalStatusTone));

    expect([...tones].every((t) => ["emerald", "sky", "amber", "red", "slate"].includes(t))).toBe(
      true,
    );
    expect(portalStatusTone("completed")).toBe("emerald");
    expect(portalStatusTone("in_progress")).toBe("sky");
    expect(portalStatusTone("cancelled_by_academy")).toBe("slate");
  });
});
