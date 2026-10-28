"use client";

import type { ReactNode } from "react";
import { PROGRESS_CATEGORIES } from "@/lib/teacher-portal";
import type { ParentTone } from "@/lib/parent-portal";

/**
 * ⭐⭐⭐ **PR 4.5 — قطع واجهة بوابة ولي الأمر المشتركة.**
 *
 * ⭐ كل الألوان والتسميات في مكان واحد: لو اتغيّر لون حالة أو اسم
 * برنامج، بيتغيّر هنا مش في ستة مكوّنات.
 */

/** ⭐ ألوان الشارة — نفس مفردات `ParentTone` في `lib/parent-portal`. */
export const TONE_BADGE: Record<ParentTone, string> = {
  emerald: "bg-emerald-50 text-emerald-700 ring-emerald-200",
  amber: "bg-amber-50 text-amber-700 ring-amber-200",
  red: "bg-red-50 text-red-600 ring-red-200",
  slate: "bg-slate-100 text-slate-500 ring-slate-200",
};

export function Badge({
  tone = "slate",
  children,
}: {
  tone?: ParentTone;
  children: ReactNode;
}) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ${TONE_BADGE[tone]}`}
    >
      {children}
    </span>
  );
}

/** ⭐ كارت بقسم — كل أقسام البوابة بنفس الشكل. */
export function Card({
  title,
  hint,
  action,
  children,
}: {
  title: string;
  hint?: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200">
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
          <h2 className="text-sm font-semibold text-slate-800">{title}</h2>
          {hint && <p className="mt-0.5 text-xs text-slate-500">{hint}</p>}
        </div>
        {action}
      </div>
      {children}
    </section>
  );
}

/** ⭐ مفيش بيانات ≠ خطأ. الرسالة بتقول «إيه اللي ممكن تعمله». */
export function Empty({ title, hint }: { title: string; hint?: string }) {
  return (
    <div className="rounded-lg border border-dashed border-slate-300 p-6 text-center">
      <p className="text-sm text-slate-600">{title}</p>
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  );
}

export function ErrorBox({ message }: { message: string }) {
  return (
    <div
      role="alert"
      className="rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200"
    >
      {message}
    </div>
  );
}

/** ⭐ هياكل تحميل — نفس ارتفاع الكروت عشان ما يحصلش «قفزة». */
export function LoadingRows({ count = 3 }: { count?: number }) {
  return (
    <div className="space-y-3" aria-busy="true" aria-label="جاري التحميل">
      {Array.from({ length: count }, (_, i) => (
        <div
          key={i}
          className="h-16 animate-pulse rounded-lg bg-slate-100 ring-1 ring-slate-200"
        />
      ))}
    </div>
  );
}

// ═══════════════════════════════════════════════════════════════
// التسميات
// ═══════════════════════════════════════════════════════════════

export const STUDENT_STATUS_LABEL: Record<string, string> = {
  active: "نشط",
  paused: "متوقف",
  expired: "منتهي",
  cancelled: "ملغي",
  lead: "مبدئي",
  graduated: "متخرج",
  archived: "مؤرشف",
  inactive: "غير نشط",
};

export const STUDENT_STATUS_TONE: Record<string, ParentTone> = {
  active: "emerald",
  paused: "amber",
  expired: "red",
  cancelled: "slate",
};

/** ⭐ نفس مفردات آلة حالات الحصة (PR 3.1) — للعرض بس. */
export const LESSON_STATUS_LABEL: Record<string, string> = {
  scheduled: "مجدولة",
  confirmed: "مؤكدة",
  in_progress: "جارية",
  completed: "مكتملة",
  student_absent: "غياب الطالب",
  cancelled_by_academy: "ملغاة من الأكاديمية",
  cancelled_by_student: "ملغاة من الطالب",
  rescheduled: "معاد جدولتها",
  no_show: "لم يحضر",
};

/** ⭐ أنواع الحصة — نفس enum `lessons.lesson_type`. */
export const LESSON_TYPE_LABEL: Record<string, string> = {
  regular: "عادية",
  trial: "تجريبية",
  makeup: "تعويضية",
  extra: "إضافية",
  free: "مجانية",
  assessment: "تقييم",
  group: "جماعية",
};

export const LESSON_STATUS_TONE: Record<string, ParentTone> = {
  scheduled: "slate",
  confirmed: "emerald",
  in_progress: "amber",
  completed: "emerald",
  student_absent: "red",
  cancelled_by_academy: "red",
  cancelled_by_student: "amber",
  rescheduled: "amber",
  no_show: "red",
};

export const INVOICE_STATUS_LABEL: Record<string, string> = {
  draft: "مسودة",
  issued: "مصدرة",
  partially_paid: "مدفوعة جزئياً",
  paid: "مدفوعة",
  overdue: "متأخرة",
  void: "ملغاة",
};

export const INVOICE_STATUS_TONE: Record<string, ParentTone> = {
  draft: "slate",
  issued: "amber",
  partially_paid: "amber",
  paid: "emerald",
  overdue: "red",
  void: "slate",
};

export const PAYMENT_STATUS_LABEL: Record<string, string> = {
  pending: "معلق",
  completed: "مكتمل",
  failed: "فاشل",
  voided: "ملغي",
  reversed: "معكوس",
};

export const PAYMENT_STATUS_TONE: Record<string, ParentTone> = {
  pending: "amber",
  completed: "emerald",
  failed: "red",
  voided: "slate",
  reversed: "red",
};

export const PAYMENT_METHOD_LABEL: Record<string, string> = {
  cash: "نقدي",
  bank_transfer: "تحويل بنكي",
  wallet: "محفظة",
  online_payment: "دفع أونلاين",
  card: "بطاقة",
  other: "أخرى",
};

/** ⭐ فئات التقدم — من نفس مصدر بوابة المعلم (مصدر واحد). */
export const PROGRESS_CATEGORY_LABEL: Record<string, string> = Object.fromEntries(
  PROGRESS_CATEGORIES.map((category) => [category.id, category.label]),
);

/** ⭐ قراءة تسمية من خريطة — المفتاح الغريب بيرجع نفسه بدل ما يختفي. */
export function labelOf(
  map: Record<string, string>,
  key: string | null | undefined,
): string {
  if (!key) return "—";
  return map[key] ?? key;
}
