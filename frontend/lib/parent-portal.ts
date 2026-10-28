/**
 * ⭐⭐⭐ **PR 4.5 — بوابة ولي الأمر v2: منطق الشاشة (بلا React، بلا Next).**
 *
 * ⚠️ المشروع ما فيهوش Playwright، فقبول الـroadmap
 * («الولي ما يشوفش مال ابنه لو `can_view_finance=false`») اتغطّى
 * في الباكإند (`ParentPortalV2Test`) وفي الدوال الخالصة دي:
 *
 *   • أي تبويب يظهر لأي علم (`visibleParentTabs` / `resolveParentTab`).
 *   • حارس فتح البوابة (`canOpenParentPortal`).
 *   • التبديل السريع بين الأبناء (`childOptions` / `nextChildId`).
 *   • نسبة الحضور (`attendanceRate`).
 *   • تحقق طلب الولي قبل أي طلب شبكة (`validateStudentRequest`).
 *
 * ⭐ الصفحة بتستهلك الدوال دي — فمفيش قرار في JSX بيتاخد من غير
 * اختبار، ونفس قواعد السيرفر مكتوبة مرة واحدة.
 */

// ═══════════════════════════════════════════════════════════════
// التبويبات
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐ أعلام الرابط (`student_parents`) اللي بترجع مع ملف الابن.
 *
 * ⭐ نفس الأسماء اللي في `ParentChildScope` — الحارس في السيرفر
 * بيفلتر، والواجهة بتخفي عشان ما تعرضش زر بيرجّع 403.
 */
export type ParentFlags = {
  can_view_finance: boolean;
  can_view_progress: boolean;
  can_request_changes: boolean;
};

export type ParentPortalTabId =
  | "overview"
  | "attendance"
  | "progress"
  | "lessons"
  | "invoices"
  | "requests";

type TabGate = "progress" | "finance" | "requests" | null;

/**
 * ⭐ التبويبات — أزرار في شاشة واحدة، **مش روابط**.
 *
 * ⚠️ ليه أزرار؟ لأن ولي الأمر مالوش أي مسار إداري: الـlayout
 * بيرجّع صفر مجموعات (`filterNavGroups`). لو التبويبات روابط، أي
 * `href` جديد ممكن يفتح باب لصفحة مش ليه.
 */
export const PARENT_TABS: ReadonlyArray<{
  id: ParentPortalTabId;
  label: string;
  icon: string;
  gate: TabGate;
}> = [
  { id: "overview", label: "نظرة عامة", icon: "🏠", gate: null },
  { id: "attendance", label: "الحضور", icon: "🕐", gate: "progress" },
  { id: "progress", label: "التقدم", icon: "📈", gate: "progress" },
  { id: "lessons", label: "الحصص", icon: "📚", gate: "progress" },
  { id: "invoices", label: "الفواتير", icon: "🧾", gate: "finance" },
  { id: "requests", label: "طلباتي", icon: "📨", gate: "requests" },
];

/**
 * ⭐⭐ **التبويبات المسموحة بالأعلام.**
 *
 * ⭐ قبل ما ملف الابن يوصل (`flags = null`): النظرة العامة بس —
 * مش بنعرض تبويب وبعدين نكتشف إنه 403.
 */
export function visibleParentTabs(
  flags: ParentFlags | null | undefined,
): ParentPortalTabId[] {
  return PARENT_TABS.filter((tab) => {
    switch (tab.gate) {
      case "progress":
        return flags?.can_view_progress === true;
      case "finance":
        return flags?.can_view_finance === true;
      case "requests":
        return flags?.can_request_changes === true;
      default:
        return true;
    }
  }).map((tab) => tab.id);
}

/**
 * ⭐⭐ **التبويب المفتوح بعد تبديل الابن.**
 *
 * ⚠️ الأعلام بتختلف من ابن لابن (`can_view_finance` لكل رابط).
 * لو التبويب المفتوح بقى ممنوع، بنرجع للنظرة العامة بدل شاشة
 * كلها أخطاء 403.
 */
export function resolveParentTab(
  tab: ParentPortalTabId,
  flags: ParentFlags | null | undefined,
): ParentPortalTabId {
  return visibleParentTabs(flags).includes(tab) ? tab : "overview";
}

// ═══════════════════════════════════════════════════════════════
// حارس فتح البوابة
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐⭐ **مين يحق له يفتح البوابة؟**
 *
 * ⭐ شرطين: `is_parent` (مشتق من وجود بروفايل — PR 1.3) **و**
 * وجود `parent` نفسه في `/auth/me`.
 *
 * ⚠️ ده حارس **واجهة** مش أمان — الحماية الحقيقية `parent.only` +
 * `ParentChildScope` على السيرفر. لكنه بيمنع شاشة مكسورة.
 */
export function canOpenParentPortal(
  user: { is_parent?: boolean; parent?: unknown } | null | undefined,
): boolean {
  return Boolean(user?.is_parent) && Boolean(user?.parent);
}

// ═══════════════════════════════════════════════════════════════
// التبديل السريع بين الأبناء
// ═══════════════════════════════════════════════════════════════

export type ChildOption = { id: number; name: string; code: string };

/** ⭐ قائمة التبديل — الاسم والكود بس. */
export function childOptions(
  children: ReadonlyArray<{
    student: { id: number; full_name: string; student_code: string };
  }>,
): ChildOption[] {
  return children.map((child) => ({
    id: child.student.id,
    name: child.student.full_name,
    code: child.student.student_code,
  }));
}

/**
 * ⭐⭐ **الابن التالي/السابق — بيلفّ.**
 *
 * ⭐ ليه لفّ بدل توقف عند الآخر؟ لأن ولي الأمر بيفضّل يضغط
 * «التالي» كذا مرة بدل ما يرجع لأول القائمة. وابن واحد = `null`
 * (مفيش تبديل أصلاً).
 */
export function nextChildId(
  children: ReadonlyArray<{ student: { id: number } }>,
  currentId: number,
  direction: 1 | -1 = 1,
): number | null {
  if (children.length < 2) return null;

  const ids = children.map((child) => child.student.id);
  const at = ids.indexOf(currentId);

  if (at === -1) return ids[0];

  return ids[(at + direction + ids.length) % ids.length];
}

// ═══════════════════════════════════════════════════════════════
// الحضور
// ═══════════════════════════════════════════════════════════════

export type AttendanceStatus = "present" | "absent" | "late" | "excused";

export type AttendanceSummary = {
  present: number;
  absent: number;
  late: number;
  excused: number;
  total: number;
};

/** ⭐ نفس مفردات `lesson_attendance.status` في السيرفر. */
export const ATTENDANCE_LABEL: Record<AttendanceStatus, string> = {
  present: "حاضر",
  absent: "غائب",
  late: "متأخر",
  excused: "بعذر",
};

export type ParentTone = "emerald" | "amber" | "red" | "slate";

export const ATTENDANCE_TONE: Record<AttendanceStatus, ParentTone> = {
  present: "emerald",
  late: "amber",
  excused: "slate",
  absent: "red",
};

/**
 * ⭐⭐⭐ **نسبة الحضور.**
 *
 * ⚠️ «متأخر» بيتحسب حضور — الطالب حضر، بس اتأخر. «بعذر» مش
 * حضور ولا غياب، فمش بيدخل في البسط (بس في المقام، لأنه حصة
 * اتسجلت).
 *
 * ⭐ صفر حصص = `null` مش `0%` — «مفيش بيانات» غير «حضر صفر».
 */
export function attendanceRate(
  summary: AttendanceSummary | null | undefined,
): number | null {
  if (!summary) return null;

  const total =
    summary.total ??
    summary.present + summary.absent + summary.late + summary.excused;

  if (total <= 0) return null;

  return Math.round(((summary.present + summary.late) / total) * 100);
}

// ═══════════════════════════════════════════════════════════════
// طلبات ولي الأمر
// ═══════════════════════════════════════════════════════════════

export type StudentRequestKind = "cancel" | "postpone" | "suspend";

export const REQUEST_TYPES: ReadonlyArray<{
  id: StudentRequestKind;
  label: string;
}> = [
  { id: "cancel", label: "إلغاء حصة" },
  { id: "postpone", label: "تأجيل حصة" },
  { id: "suspend", label: "تعليق الاشتراك" },
];

export type RequestNeeds = {
  lesson: boolean;
  subscription: boolean;
  date: boolean;
};

/** ⭐ متطلبات كل نوع — نفس منطق `StudentRequestType` في السيرفر. */
export function requestNeeds(type: StudentRequestKind): RequestNeeds {
  return {
    lesson: type === "cancel" || type === "postpone",
    subscription: type === "suspend",
    date: type === "postpone",
  };
}

export type StudentRequestDraft = {
  type: StudentRequestKind;
  lessonId?: number | null;
  subscriptionId?: number | null;
  requestedDate?: string | null;
  reason: string;
};

/**
 * ⭐⭐⭐ **تحقق قبل الطلب — رسائل عربية للولي.**
 *
 * ⚠️ السيرفر عنده نفس القواعد (`StudentRequestService::create`).
 * ده مش بديل عنها: ده بيمنع رحلة شبكة فاشلة ويعرض الخطأ جنب
 * الحقل.
 */
export function validateStudentRequest(draft: StudentRequestDraft): string[] {
  const errors: string[] = [];
  const needs = requestNeeds(draft.type);

  if (needs.lesson && !draft.lessonId) {
    errors.push(
      draft.type === "cancel"
        ? "اختر الحصة المطلوب إلغاؤها"
        : "اختر الحصة المطلوب تأجيلها",
    );
  }

  if (needs.subscription && !draft.subscriptionId) {
    errors.push("مفيش اشتراك نشط لتعليقه");
  }

  if (needs.date && !draft.requestedDate) {
    errors.push("حدد التاريخ الجديد المطلوب");
  }

  if (draft.reason.trim().length < 5) {
    errors.push("اكتب سبباً واضحاً (5 حروف على الأقل)");
  }

  return errors;
}

/**
 * ⭐ الحمولة النهائية — **الحقول اللازمة للنوع بس**.
 *
 * ⚠️ ما بنبعتش `lesson_id` مع «تعليق» ولا `subscription_id` مع
 * «إلغاء»: السيرفر بيتجاهلهم، بس الحمولة الضيقة بتوضّح النية
 * وتمنع خلط الحقول في اللوج.
 */
export function requestPayload(draft: StudentRequestDraft): {
  type: StudentRequestKind;
  lesson_id?: number;
  subscription_id?: number;
  requested_date?: string;
  reason: string;
} {
  const needs = requestNeeds(draft.type);

  return {
    type: draft.type,
    ...(needs.lesson && draft.lessonId ? { lesson_id: draft.lessonId } : {}),
    ...(needs.subscription && draft.subscriptionId
      ? { subscription_id: draft.subscriptionId }
      : {}),
    ...(needs.date && draft.requestedDate
      ? { requested_date: draft.requestedDate }
      : {}),
    reason: draft.reason.trim(),
  };
}

export type StudentRequestStatus =
  | "pending"
  | "approved"
  | "rejected"
  | "withdrawn";

export const REQUEST_STATUS_LABEL: Record<StudentRequestStatus, string> = {
  pending: "قيد المراجعة",
  approved: "موافق عليه",
  rejected: "مرفوض",
  withdrawn: "مسحوب",
};

export const REQUEST_STATUS_TONE: Record<StudentRequestStatus, ParentTone> = {
  pending: "amber",
  approved: "emerald",
  rejected: "red",
  withdrawn: "slate",
};
