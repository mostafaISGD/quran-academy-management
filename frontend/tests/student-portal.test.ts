import { describe, expect, it } from "vitest";

import {
  attendanceRate,
  canJoinLesson,
  canOpenStudentPortal,
  homeworkNotice,
  joinPreviewState,
  lessonStatusLabel,
  memorizationMap,
  STUDENT_TABS,
  type AttendanceCounts,
  type MemorizationSegment,
} from "@/lib/student-portal";
import type { AccessUser } from "@/lib/route-access";

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

const student = () =>
  user({
    is_student: true,
    student: { id: 7 } as NonNullable<AccessUser>["student"],
    roles: ["student"],
    permissions: ["student.portal", "programs.view"],
  });

// ═══════════════════════════════════════════════════════════════
// ① مين يقدر يفتح بوابة الطالب
// ═══════════════════════════════════════════════════════════════

describe("canOpenStudentPortal", () => {
  it("a student with a profile and the portal permission can open it", () => {
    expect(canOpenStudentPortal(student())).toBe(true);
  });

  it("⭐ the permission alone is not enough without a student profile", () => {
    // ⭐ نفس شرط السيرفر: `StudentPortalView::studentFor` بيرد
    // 403 لو مفيش بروفايل — الواجهة ما تعرضش صفحة هتفشل.
    const noProfile = user({
      roles: ["student"],
      permissions: ["student.portal"],
    });

    expect(canOpenStudentPortal(noProfile)).toBe(false);
  });

  it("⭐ a student profile without the permission is not enough either", () => {
    const noPermission = user({
      is_student: true,
      student: { id: 7 } as NonNullable<AccessUser>["student"],
      roles: ["student"],
      permissions: ["programs.view"],
    });

    expect(canOpenStudentPortal(noPermission)).toBe(false);
  });

  it("a teacher or a parent never opens the student portal", () => {
    expect(
      canOpenStudentPortal(
        user({
          is_teacher: true,
          teacher: { id: 1 } as NonNullable<AccessUser>["teacher"],
          roles: ["teacher"],
          permissions: ["teacher.portal", "student.portal"],
        }),
      ),
    ).toBe(false);

    expect(
      canOpenStudentPortal(
        user({
          is_parent: true,
          parent: { id: 1 } as NonNullable<AccessUser>["parent"],
          roles: ["parent"],
          permissions: ["programs.view", "student.portal"],
        }),
      ),
    ).toBe(false);
  });

  it("⭐ an admin linked to a student record is not a student", () => {
    // ⭐ نفس استثناء `AuthController::profile()` — المدير مربوط
    // بسجل طالب ما يتوجّهش لبوابة الطالب.
    const linkedAdmin = user({
      roles: ["admin"],
      is_student: false,
      student: { id: 7 } as NonNullable<AccessUser>["student"],
      permissions: ["student.portal"],
    });

    expect(canOpenStudentPortal(linkedAdmin)).toBe(false);
  });

  it("null user cannot open it", () => {
    expect(canOpenStudentPortal(null)).toBe(false);
  });
});

// ═══════════════════════════════════════════════════════════════
// ② التبويبات
// ═══════════════════════════════════════════════════════════════

describe("STUDENT_TABS", () => {
  it("⭐ has exactly the three tabs of the roadmap, with unique keys", () => {
    expect(STUDENT_TABS.map((tab) => tab.key)).toEqual([
      "overview",
      "schedule",
      "progress",
    ]);

    expect(new Set(STUDENT_TABS.map((tab) => tab.key)).size).toBe(STUDENT_TABS.length);
  });

  it("every tab has an Arabic label", () => {
    for (const tab of STUDENT_TABS) {
      expect(tab.label).toMatch(/[\u0600-\u06FF]/);
    }
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ نسبة الحضور
// ═══════════════════════════════════════════════════════════════

describe("attendanceRate", () => {
  it("⭐ counts present and late as attended, excused as neither", () => {
    // ⭐ نفس قاعدة `LessonService`: حاضر/متأخر = الحصة اتحسبت،
    // وبعذر مش حضور ولا غياب في النسبة.
    const counts: AttendanceCounts = {
      present: 3,
      late: 1,
      absent: 1,
      excused: 1,
    };

    expect(attendanceRate(counts)).toBe(67);
  });

  it("is zero when nothing was recorded", () => {
    expect(attendanceRate({ present: 0, late: 0, absent: 0, excused: 0 })).toBe(0);
  });

  it("⭐ an all-excused history is not 100% attendance", () => {
    expect(attendanceRate({ present: 0, late: 0, absent: 0, excused: 5 })).toBe(0);
  });

  it("rounds to the nearest integer", () => {
    expect(attendanceRate({ present: 2, late: 0, absent: 1, excused: 0 })).toBe(67);
    expect(attendanceRate({ present: 1, late: 0, absent: 2, excused: 0 })).toBe(33);
  });
});

// ═══════════════════════════════════════════════════════════════
// ④ خريطة الحفظ
// ═══════════════════════════════════════════════════════════════

function segment(overrides: Partial<MemorizationSegment> = {}): MemorizationSegment {
  return {
    surah_id: 1,
    surah_name: "الفاتحة",
    surah_number: 1,
    from_ayah: 1,
    to_ayah: 7,
    quality: 5,
    ...overrides,
  };
}

describe("memorizationMap", () => {
  it("⭐ merges the segments of one surah into one map row", () => {
    const map = memorizationMap([
      segment({ from_ayah: 1, to_ayah: 3 }),
      segment({ from_ayah: 5, to_ayah: 7 }),
    ]);

    expect(map).toHaveLength(1);
    expect(map[0]).toMatchObject({
      surah_id: 1,
      surah_name: "الفاتحة",
      surah_number: 1,
      from_ayah: 1,
      to_ayah: 7,
    });
    expect(map[0].ayahs).toHaveLength(2);
  });

  it("⭐ keeps the segments, not a fake continuous range", () => {
    // ⭐ لو دمجنا 1-3 و5-7 في «1-7» هنكدب على الواجهة: الآية 4
    // مش محفوظة. المقاطع هي الحقيقة.
    const map = memorizationMap([
      segment({ from_ayah: 5, to_ayah: 7 }),
      segment({ from_ayah: 1, to_ayah: 3 }),
    ]);

    expect(map[0].ayahs).toEqual([
      expect.objectContaining({ from_ayah: 1, to_ayah: 3 }),
      expect.objectContaining({ from_ayah: 5, to_ayah: 7 }),
    ]);
  });

  it("sorts surahs by their number and segments by their start", () => {
    const map = memorizationMap([
      segment({
        surah_id: 2,
        surah_name: "البقرة",
        surah_number: 2,
        from_ayah: 1,
        to_ayah: 5,
      }),
      segment({ surah_id: 1, surah_name: "الفاتحة", surah_number: 1 }),
    ]);

    expect(map.map((row) => row.surah_number)).toEqual([1, 2]);
  });

  it("returns an empty map when nothing was memorized", () => {
    expect(memorizationMap([])).toEqual([]);
  });
});

// ═══════════════════════════════════════════════════════════════
// ⑤ زر الانضمام
// ═══════════════════════════════════════════════════════════════

describe("canJoinLesson", () => {
  it("a scheduled lesson with a link is joinable", () => {
    expect(canJoinLesson({ status: "scheduled", can_join: true })).toBe(true);
  });

  it("a confirmed or in-progress lesson with a link is joinable", () => {
    expect(canJoinLesson({ status: "confirmed", can_join: true })).toBe(true);
    expect(canJoinLesson({ status: "in_progress", can_join: true })).toBe(true);
  });

  it("⭐ a cancelled lesson is not joinable even if the server says can_join", () => {
    // ⭐ الحكم المزدوج مقصود: السيرفر بيمنع، والواجهة ما تعرضش
    // زرار هيرجع 422.
    expect(canJoinLesson({ status: "cancelled", can_join: true })).toBe(false);
    expect(canJoinLesson({ status: "completed", can_join: true })).toBe(false);
  });

  it("⭐ no link means no join button", () => {
    expect(canJoinLesson({ status: "scheduled", can_join: false })).toBe(false);
  });

  it("a missing lesson is not joinable", () => {
    expect(canJoinLesson(null)).toBe(false);
  });
});

// ═══════════════════════════════════════════════════════════════
// ⑥ معاينة رابط الطفل (< 13)
// ═══════════════════════════════════════════════════════════════

describe("joinPreviewState", () => {
  const ready = {
    status: "scheduled",
    has_meeting: true,
    expired: false,
    used: false,
  };

  it("a live link is ready", () => {
    expect(joinPreviewState(ready)).toBe("ready");
  });

  it("⭐ an expired link wins over every other flag", () => {
    expect(joinPreviewState({ ...ready, expired: true })).toBe("expired");
  });

  it("a used link is reported as used", () => {
    expect(joinPreviewState({ ...ready, used: true })).toBe("used");
  });

  it("a cancelled lesson is closed", () => {
    expect(joinPreviewState({ ...ready, status: "cancelled" })).toBe("closed");
  });

  it("a lesson without a meeting link has no link", () => {
    expect(joinPreviewState({ ...ready, has_meeting: false })).toBe("no_link");
  });
});

// ═══════════════════════════════════════════════════════════════
// ⑦ الواجبات
// ═══════════════════════════════════════════════════════════════

describe("lessonStatusLabel", () => {
  it("⭐ translates the statuses a child can meet on the join page", () => {
    expect(lessonStatusLabel("scheduled")).toBe("مجدولة");
    expect(lessonStatusLabel("confirmed")).toBe("مؤكدة");
    expect(lessonStatusLabel("in_progress")).toBe("جارية");
    expect(lessonStatusLabel("cancelled")).toBe("ملغاة");
  });

  it("falls back to the raw status instead of an empty label", () => {
    expect(lessonStatusLabel("something_new")).toBe("something_new");
  });
});

describe("homeworkNotice", () => {
  it("⭐ explains the absence instead of showing an empty list", () => {
    const notice = homeworkNotice({ available: false, items: [] });

    expect(notice).not.toBeNull();
    expect(notice).toMatch(/[\u0600-\u06FF]/);
  });

  it("says nothing when homework is available", () => {
    expect(homeworkNotice({ available: true, items: [] })).toBeNull();
  });
});
