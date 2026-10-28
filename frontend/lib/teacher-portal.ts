/**
 * ⭐⭐⭐ **PR 4.4 — بوابة المعلم: منطق الشاشة (بلا React، بلا Next).**
 *
 * ⚠️ المشروع ما فيهوش Playwright (ولا `playwright.config.ts`)،
 * وقبول الـroadmap («معلم يسجّل حضور ويكمّل حصة من 390px») مش
 * قابل للتنفيذ الآلي هنا. فكل قرار في الشاشة اتطلّع لدوال خالصة
 * قابلة للاختبار:
 *
 *   • أي زر يظهر لحصة في حالة معيّنة (`lessonActions`).
 *   • الحضور **بنقرتين** مش نقرة (`tapLesson` / `tapAttendanceStatus`).
 *   • المستحقات **كل عملة لوحدها** (`earningsRows`).
 *   • تجميع الأسبوع بمنطقة المعلم الزمنية (`groupLessonsByDay`).
 *   • تحقق نموذج الحفظ قبل أي طلب (`validateMemorization`).
 *
 * ⭐ الصفحة نفسها بتستهلك الدوال دي — فمفيش قرار في JSX بيتاخد
 * من غير اختبار.
 */

import { dayKey } from "@/lib/format";

// ⭐ إعادة تصدير للاختبارات وللصفحة — التنفيذ في `lib/format.ts`
// عشان حارس التنسيق يسمح بـ`Intl` في ملف واحد بس.
export { dayKey };

// ═══════════════════════════════════════════════════════════════
// التبويبات
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐ التبويبات الخمسة — أزرار في شاشة واحدة، **مش روابط**.
 *
 * ⚠️ ليه أزرار؟ لأن المعلم في البوابة مالوش أي مسار إداري:
 * الـlayout بيرجّع صفر مجموعات (`filterNavGroups`). لو التبويبات
 * روابط، أي `href` جديد ممكن يفتح باب لصفحة مش ليه.
 */
export const TEACHER_TABS = [
  { id: "today", label: "اليوم", icon: "☀" },
  { id: "week", label: "الأسبوع", icon: "🗓" },
  { id: "lessons", label: "كل الحصص", icon: "📚" },
  { id: "earnings", label: "مستحقاتي", icon: "💰" },
  { id: "availability", label: "مواعيدي", icon: "⏰" },
] as const;

export type TeacherTab = (typeof TEACHER_TABS)[number]["id"];

/**
 * ⭐⭐ **مين يحق له يفتح البوابة؟**
 *
 * ⭐ شرطين: بروفايل معلم **و**صلاحية `teacher.portal`.
 *
 * ⚠️ ليه الاتنين؟ البروفايل لوحده مش كفاية: أي `user_id` مربوط
 * بسجل `teachers` (زي حساب المدير في وضع المستخدم الواحد) كان
 * هيفتح بوابة كل نداءاتها بترجع 403 لو دوره مش معاه الصلاحية.
 * والصلاحية لوحدها من غير بروفايل = كل المسارات `403` برضه
 * (`ownTeacherId()`).
 *
 * ⭐ ده حارس **واجهة** مش أمان — الحماية الحقيقية `permission:teacher.portal`
 * على المسارات. لكنه بيمنع شاشة مكسورة مليانة أخطاء.
 */
export function canOpenTeacherPortal(user: {
  teacher: unknown;
  permissions: readonly string[];
}): boolean {
  return Boolean(user?.teacher) && user.permissions.includes("teacher.portal");
}

// ═══════════════════════════════════════════════════════════════
// حالات الحصة
// ═══════════════════════════════════════════════════════════════

export type PortalStatus =
  | "scheduled"
  | "confirmed"
  | "in_progress"
  | "completed"
  | "student_absent"
  | "teacher_absent"
  | "cancelled_by_academy"
  | "cancelled_late"
  | "rescheduled";

export type PortalTone = "emerald" | "sky" | "amber" | "red" | "slate";

/**
 * ⭐ نفس مفردات `LessonStatus::label()` في السيرفر عمداً —
 * معلم يشوف «جارية» في الموبايل و«جارية» في التقارير.
 */
export const PORTAL_STATUS_LABEL: Record<PortalStatus, string> = {
  scheduled: "مجدولة",
  confirmed: "مؤكدة",
  in_progress: "جارية",
  completed: "مكتملة",
  student_absent: "الطالب غائب",
  teacher_absent: "المعلم غائب",
  cancelled_by_academy: "ملغاة من الأكاديمية",
  cancelled_late: "ملغاة متأخرة",
  rescheduled: "مُعاد جدولتها",
};

export function portalStatusTone(status: PortalStatus): PortalTone {
  switch (status) {
    case "completed":
      return "emerald";
    case "in_progress":
      return "sky";
    case "scheduled":
    case "confirmed":
    case "rescheduled":
      return "amber";
    case "student_absent":
    case "teacher_absent":
      return "red";
    default:
      return "slate";
  }
}

// ═══════════════════════════════════════════════════════════════
// أي زر يظهر؟
// ═══════════════════════════════════════════════════════════════

export type LessonAction =
  | "join"
  | "start"
  | "attendance"
  | "memorization"
  | "complete";

/**
 * ⭐ الأزرار بتتبع **آلة حالات السيرفر** (`LessonStatus::allowedTransitions`).
 *
 * ⚠️ مفيش «إتمام» للحصة المجدولة حتى لو السيرفر بيسمح بيه —
 * المعلم لازم يبدأها الأول (نقرتين مقصودتين: بدء ثم إتمام).
 * الحضور «حاضر» بيكمّل الحصة أصلاً، فمسار الاستخدام اليومي
 * أقصر.
 *
 * ⚠️ **حصة المجموعة** (`hasStudent = false`): الحضور والحفظ
 * محتاجين `student_id` واحد، والبوابة ما بترجّعش قائمة طلاب
 * المجموعة — فالأزرار بتختفي بدل ما تفتح لوحة كل ضغطاتها
 * بتفشل. البدء/الإتمام/الانضمام شغالين عادي.
 */
export function lessonActions(
  status: PortalStatus,
  hasMeetingUrl: boolean,
  hasStudent = true,
): LessonAction[] {
  const join: LessonAction[] = hasMeetingUrl ? ["join"] : [];
  const progress: LessonAction[] = hasStudent ? ["attendance", "memorization"] : [];

  switch (status) {
    case "scheduled":
    case "confirmed":
      return [...join, "start"];

    case "in_progress":
      return [...join, ...progress, "complete"];

    // ⭐ الحصة المكتملة بتقبل تسجيل حفظ/مراجعة بعدها — لكن مش
    // إعادة إتمام (الحالة نهائية في السيرفر).
    case "completed":
      return hasStudent ? ["memorization"] : [];

    default:
      return [];
  }
}

// ═══════════════════════════════════════════════════════════════
// الحضور بنقرتين
// ═══════════════════════════════════════════════════════════════

export type AttendanceStatus = "present" | "absent" | "late" | "excused";

/**
 * ⭐⭐⭐ آلة حالة الحضور.
 *
 * ① نقرة على الحصة = `choose` (فتح «حاضر / غائب / متأخر»).
 * ② نقرة على الحالة = `confirm` — **وساعتها بس** تتكوّن حمولة
 *    الطلب.
 *
 * ⚠️ ده مش تفصيل UI: `POST /lessons/{id}/attendance` بيكمّل
 * الحصة ويخصم رصيد ويولّد مستحق. نقرة غلط واحدة من غير تأكيد
 * = أثر مالي. فالنقرتين حماية، مش رفاهية.
 */
export type AttendanceStep =
  | { step: "idle" }
  | { step: "choose"; lessonId: number; studentId: number }
  | {
      step: "confirm";
      lessonId: number;
      studentId: number;
      status: AttendanceStatus;
    };

export const IDLE_ATTENDANCE: AttendanceStep = { step: "idle" };

/** ⭐ النقرة الأولى — اختيار الحصة. نفس الحصة تاني = إلغاء. */
export function tapLesson(
  current: AttendanceStep,
  lesson: { id: number; studentId: number },
): AttendanceStep {
  if (current.step === "choose" && current.lessonId === lesson.id) {
    return IDLE_ATTENDANCE;
  }

  return { step: "choose", lessonId: lesson.id, studentId: lesson.studentId };
}

/** ⭐ النقرة التانية — اختيار الحالة. من غير حصة مختارة = مفيش حاجة. */
export function tapAttendanceStatus(
  current: AttendanceStep,
  status: AttendanceStatus,
): AttendanceStep {
  if (current.step !== "choose") return current;

  return {
    step: "confirm",
    lessonId: current.lessonId,
    studentId: current.studentId,
    status,
  };
}

/** ⭐ الحمولة النهائية — `null` قبل النقرة التانية. */
export function attendancePayload(
  state: AttendanceStep,
): { entries: Array<{ student_id: number; status: AttendanceStatus }> } | null {
  if (state.step !== "confirm") return null;

  return {
    entries: [{ student_id: state.studentId, status: state.status }],
  };
}

// ═══════════════════════════════════════════════════════════════
// المستحقات
// ═══════════════════════════════════════════════════════════════

export type CurrencyTotals = {
  balance: number;
  total_debit: number;
  total_credit: number;
};

export type EarningsRow = { currency: string } & CurrencyTotals;

/**
 * ⭐⭐⭐ **كل عملة سطر — ممنوع الجمع بينها.**
 *
 * ⚠️ `currencies` في رد `/teacher/earnings` كائن بمفتاح العملة
 * (من `TeacherLedgerService`). تحويله لرقم واحد = خلط وحدات
 * (AGENTS.md قاعدة 11: multi-currency حقيقي).
 */
export function earningsRows(
  currencies: Record<string, CurrencyTotals> | null | undefined,
): EarningsRow[] {
  return Object.entries(currencies ?? {})
    .map(([currency, totals]) => ({
      currency,
      balance: Number(totals.balance ?? 0),
      total_debit: Number(totals.total_debit ?? 0),
      total_credit: Number(totals.total_credit ?? 0),
    }))
    .sort((a, b) => a.currency.localeCompare(b.currency));
}

// ═══════════════════════════════════════════════════════════════
// الأسبوع — تجميع بمنطقة المعلم الزمنية
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐ تجميع حصص الأسبوع يوم بيوم — **بالترتيب الواصل** (السيرفر
 * بيرتّب بـ`scheduled_start_at` أصلاً).
 */
export function groupLessonsByDay<T extends { scheduled_start_at: string }>(
  lessons: readonly T[],
  timeZone: string,
): Array<{ key: string; lessons: T[] }> {
  const groups: Array<{ key: string; lessons: T[] }> = [];
  const index = new Map<string, number>();

  for (const lesson of lessons) {
    const key = dayKey(lesson.scheduled_start_at, timeZone);
    let at = index.get(key);

    if (at === undefined) {
      at = groups.length;
      index.set(key, at);
      groups.push({ key, lessons: [] });
    }

    groups[at].lessons.push(lesson);
  }

  return groups;
}

// ═══════════════════════════════════════════════════════════════
// الحفظ والمراجعة
// ═══════════════════════════════════════════════════════════════

/** ⭐ الفئات الست اللي بيقبلها `POST /lessons/{lesson}/memorization`. */
export const PROGRESS_CATEGORIES = [
  { id: "memorization", label: "حفظ جديد" },
  { id: "revision", label: "مراجعة" },
  { id: "tajweed", label: "تجويد" },
  { id: "reading", label: "قراءة" },
  { id: "fluency", label: "طلاقة" },
  { id: "pronunciation", label: "نطق" },
] as const;

export type ProgressCategory = (typeof PROGRESS_CATEGORIES)[number]["id"];

/** ⭐ السورة ونطاق الآيات مطلوبين للحفظ الجديد بس. */
export function needsAyahRange(category: ProgressCategory): boolean {
  return category === "memorization";
}

export type MemorizationInput = {
  category: ProgressCategory;
  surahId?: number | null;
  fromAyah?: number | null;
  toAyah?: number | null;
  quality?: number | null;
  notes?: string;
};

/**
 * ⭐⭐⭐ تحقق **قبل** الطلب — رسائل عربية للمعلم.
 *
 * ⚠️ السيرفر عنده نفس القواعد (`required_if` + نطاق الآيات +
 * عدد آيات السورة). ده مش بديل عنها: ده بيمنع رحلة شبكة فاشلة
 * على الموبايل ويعرض الخطأ جنب الحقل.
 */
export function validateMemorization(input: MemorizationInput): string[] {
  const errors: string[] = [];

  if (needsAyahRange(input.category)) {
    if (input.surahId == null || input.surahId <= 0) {
      errors.push("اختر السورة");
    }

    const from = input.fromAyah;
    const to = input.toAyah;

    if (from == null || to == null) {
      errors.push("حدد من آية إلى آية");
    } else if (from < 1 || to < 1) {
      errors.push("رقم الآية لازم يكون 1 على الأقل");
    } else if (to < from) {
      errors.push("«إلى آية» لازم تكون بعد «من آية»");
    }
  }

  if (input.quality != null && (input.quality < 1 || input.quality > 5)) {
    errors.push("التقييم من 1 إلى 5");
  }

  return errors;
}
