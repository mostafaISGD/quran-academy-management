/**
 * ⭐⭐⭐ **PR 4.6 — منطق بوابة الطالب (خالي من React).**
 *
 * ⭐ نفس فلسفة `nav-filter` و`route-access`: القرارات القابلة
 * للاختبار بتطلع من الـJSX. أي شرط «هل ده طالب؟» أو «هل الحصة
 * دي ينفع يدخلها؟» بيتكتب هنا مرة واحدة، والصفحة بتستهلكه.
 *
 * ⭐ والقاعدة الحاكمة: **مفيش `student_id` من العميل**. الدوال
 * دي بتشتغل على بيانات السيرفر اللي أصلًا متفلترة على صاحب
 * الجلسة — مفيش معامل يقدر يطلب طالباً تانٍ.
 */

import { isStudentOnly, type AccessUser } from "@/lib/route-access";

// ═══════════════════════════════════════════════════════════════
// التبويبات
// ═══════════════════════════════════════════════════════════════

export type StudentTabKey = "overview" | "schedule" | "progress";

export type StudentTab = {
  key: StudentTabKey;
  label: string;
  icon: string;
};

/** ⭐ التبويبات الثلاثة — «الواجبات» قسم جوه «نظرة عامة» مش تبويب */
export const STUDENT_TABS: readonly StudentTab[] = [
  { key: "overview", label: "نظرة عامة", icon: "🏠" },
  { key: "schedule", label: "جدولي", icon: "📅" },
  { key: "progress", label: "تقدمي", icon: "📈" },
];

// ═══════════════════════════════════════════════════════════════
// مين يقدر يفتح البوابة
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐⭐ شرطان، مش شرط واحد — نفس شرط السيرفر:
 *
 *   ① `is_student && student` — فيه بروفايل طالب مربوط بالحساب.
 *   ② `permissions` فيها `student.portal` — الراو محمي بالصلاحية.
 *
 * ⭐ صلاحية بلا بروفايل = صفحة هترجع 403. وبروفايل بلا صلاحية =
 * صفحة هترجع 403 كمان. فالواجهة ما تعرضش الاثنين.
 */
export function canOpenStudentPortal(user: AccessUser): boolean {
  return (
    isStudentOnly(user) &&
    Boolean(user?.permissions?.includes("student.portal"))
  );
}

// ═══════════════════════════════════════════════════════════════
// الحضور
// ═══════════════════════════════════════════════════════════════

export type AttendanceCounts = {
  present: number;
  late: number;
  absent: number;
  excused: number;
};

/**
 * ⭐⭐ نسبة الحضور — نفس قاعدة `LessonService`:
 *
 *   • حاضر + متأخر = **حضور** (الحصة اتحسبت).
 *   • غائب = مش حضور.
 *   • بعذر = **مش حضور ولا غياب** — بيتشال من المقام خالص.
 *
 * ⭐ فلو كل السجل «بعذر»، النسبة **صفر** مش 100%. اعتبار
 * «بعذر» حضوراً هيكدب على الأكاديمية؛ واعتباره غياباً هيظلم
 * الطالب. فالتجاهل هو الأصح.
 */
export function attendanceRate(counts: AttendanceCounts): number {
  const total = counts.present + counts.late + counts.absent + counts.excused;

  if (total <= 0) return 0;

  return Math.round(((counts.present + counts.late) / total) * 100);
}

// ═══════════════════════════════════════════════════════════════
// خريطة الحفظ
// ═══════════════════════════════════════════════════════════════

/** ⭐ مقطع محفوظ — من السيرفر (`StudentPortalView::memorizationRows`) */
export type MemorizationSegment = {
  surah_id: number;
  surah_name: string | null;
  surah_number: number | null;
  from_ayah: number;
  to_ayah: number;
  quality?: number | null;
  recorded_at?: string | null;
};

export type MemorizationSurah = {
  surah_id: number;
  surah_name: string | null;
  surah_number: number | null;
  from_ayah: number;
  to_ayah: number;
  ayahs: {
    from_ayah: number;
    to_ayah: number;
    quality: number | null;
    recorded_at: string | null;
  }[];
};

/**
 * ⭐⭐ خريطة الحفظ: سطر لكل سورة، والمقاطع **زي ما اتسجلت**.
 *
 * ⚠️ مش بنحوّل المقاطع لمدى واحد متصل: لو الطالب حفظ 1-3 و5-7،
 * المدى «1-7» هيكدب ويقول إن الآية 4 محفوظة. الواجهة بترسم
 * `ayahs` — والمقاطع هي الحقيقة.
 */
export function memorizationMap(
  segments: readonly MemorizationSegment[],
): MemorizationSurah[] {
  const bySurah = new Map<number, MemorizationSurah>();

  for (const segment of segments) {
    const existing = bySurah.get(segment.surah_id);

    const range = {
      from_ayah: segment.from_ayah,
      to_ayah: segment.to_ayah,
      quality: segment.quality ?? null,
      recorded_at: segment.recorded_at ?? null,
    };

    if (existing) {
      existing.ayahs.push(range);
      existing.from_ayah = Math.min(existing.from_ayah, segment.from_ayah);
      existing.to_ayah = Math.max(existing.to_ayah, segment.to_ayah);
      continue;
    }

    bySurah.set(segment.surah_id, {
      surah_id: segment.surah_id,
      surah_name: segment.surah_name,
      surah_number: segment.surah_number,
      from_ayah: segment.from_ayah,
      to_ayah: segment.to_ayah,
      ayahs: [range],
    });
  }

  return [...bySurah.values()]
    .map((surah) => ({
      ...surah,
      ayahs: [...surah.ayahs].sort((a, b) => a.from_ayah - b.from_ayah),
    }))
    .sort((a, b) => (a.surah_number ?? 0) - (b.surah_number ?? 0));
}

// ═══════════════════════════════════════════════════════════════
// الدخول للحصة
// ═══════════════════════════════════════════════════════════════

/** ⭐ الحالات اللي لسه ينفع يدخل فيها — مطابقة لـ`LessonJoinService` */
const JOINABLE_STATUSES = new Set(["scheduled", "confirmed", "in_progress"]);

export type JoinableLesson = {
  status: string;
  can_join: boolean;
};

/**
 * ⭐⭐ حكم مزدوج مقصود:
 *
 *   ① `can_join` من السيرفر (حالة + رابط).
 *   ② نفس فحص الحالة في الواجهة.
 *
 * ⭐ لو السيرفر قال `can_join` لحصة اتلغت (خطأ أو نسخة قديمة من
 * الرد)، الواجهة ما تعرضش زرار بيرجع 422.
 */
export function canJoinLesson(
  lesson: JoinableLesson | null | undefined,
): boolean {
  if (!lesson) return false;

  return lesson.can_join === true && JOINABLE_STATUSES.has(lesson.status);
}

/**
 * ⭐ تسمية حالة الحصة بالعربي — لصفحة رابط الطفل.
 *
 * ⭐ الرد العام (`/api/join/{token}`) بيرجّع `status` الخام مش
 * `status_label` (اللي في بوابة الطالب). فبنترجم هنا بدل ما
 * نعرض `in_progress` لطفل عنده 10 سنين.
 */
const LESSON_STATUS_LABELS: Record<string, string> = {
  scheduled: "مجدولة",
  confirmed: "مؤكدة",
  in_progress: "جارية",
  completed: "مكتملة",
  cancelled: "ملغاة",
  postponed: "مؤجلة",
  student_absent: "غياب الطالب",
  technical_issue: "مشكلة فنية",
  cancelled_by_academy: "ملغاة من الأكاديمية",
};

export function lessonStatusLabel(status: string): string {
  return LESSON_STATUS_LABELS[status] ?? status;
}

// ═══════════════════════════════════════════════════════════════
// رابط الطفل (< 13)
// ═══════════════════════════════════════════════════════════════

export type JoinPreviewState =
  | "ready"
  | "expired"
  | "used"
  | "closed"
  | "no_link";

export type JoinPreviewFlags = {
  status: string;
  has_meeting: boolean;
  expired: boolean;
  used: boolean;
};

/**
 * ⭐⭐ حالة رابط الدخول — بترتيب أولوية صريح:
 *
 *   ① منتهي — حتى لو مستعمل، «انتهى» أدق.
 *   ② مستعمل — رمز الاستعمال الواحد.
 *   ③ حصة مش قابلة للدخول (ملغاة/مكتملة).
 *   ④ مفيش رابط اجتماع.
 *   ⑤ جاهز.
 *
 * ⭐ كل حالة ليها رسالة عربية في الصفحة — مفيش «خطأ غير معروف».
 */
export function joinPreviewState(preview: JoinPreviewFlags): JoinPreviewState {
  if (preview.expired) return "expired";
  if (preview.used) return "used";
  if (!JOINABLE_STATUSES.has(preview.status)) return "closed";
  if (!preview.has_meeting) return "no_link";

  return "ready";
}

// ═══════════════════════════════════════════════════════════════
// الواجبات
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐ مفيش جدول واجبات في المخطط (PR 4.6). بنقولها صريحة للواجهة
 * بدل ما نرسم قائمة فاضية من غير تفسير.
 */
export function homeworkNotice(homework: {
  available: boolean;
  items: readonly unknown[];
}): string | null {
  if (homework.available) return null;

  return "الواجبات لسه مش مفعّلة في النظام — هتظهر هنا أول ما تتاح.";
}
