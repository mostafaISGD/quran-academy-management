"use client";

/**Preview لجدول الحصص الأسبوعية خلال مدة الاشتراك.*/
export type SchedulePreviewSlot = {
  /** yyyy-mm-dd */
  date: string;
  /** 0=الأحد … 6=السبت */
  weekday: number;
  /** فيه حصة في اليوم ده */
  hasLesson: boolean;
  /** التعارض موجود (معلم مشغول) */
  conflict: boolean;
  /** التاريخ خارج المدة */
  outOfRange: boolean;
  /** أول يوم في الشهر */
  isMonthStart?: boolean;
  inMonth: boolean;
};

const DAY_HEADERS = ["س", "ح", "ن", "ر", "خ", "ج", "س"];

/** ISO week start: الأحد */
function startOfWeek(d: Date): Date {
  const x = new Date(d);
  const diff = (x.getDay() + 1) % 7; // getDay: 0=Sun → 0
  x.setDate(x.getDate() - diff);
  return x;
}

const toISO = (d: Date) => {
  const m = `${d.getMonth() + 1}`.padStart(2, "0");
  const day = `${d.getDate()}`.padStart(2, "0");
  return `${d.getFullYear()}-${m}-${day}`;
};

type Props = {
  /** تاريخ بداية الاشتراك */
  from: string;
  /** تاريخ نهاية الاشتراك (فارغ = غير محدد) */
  to: string;
  /** الأيام المختارة (0=الأحد) */
  weekdays: number[];
  /** عدد الحصة بالدقائق */
  durationMinutes: number;
  /** أقل عدد أسطر يعرضه قبل ما يصير scroll */
  maxWeeks?: number;
};

/** أسماء الأيام للمفاتيح الرقمية (0=الأحد) */
export const WEEKDAY_NAMES: Record<number, string> = {
  0: "الأحد",
  1: "الإثنين",
  2: "الثلاثاء",
  3: "الأربعاء",
  4: "الخميس",
  5: "الجمعة",
  6: "السبت",
};

/**
 * تقويم مصغّر بيوضح أيام الحصص المتوقعة خلال المدة.
 *
 * بيتحسب في المتصفح (فوري) — والحقيقة النهائية بتتأكد عند الحفظ
 * من الـ backend اللي بيعرف تعارضات المعلم.
 */
export default function LessonCalendarPreview({
  from,
  to,
  weekdays,
  durationMinutes,
  maxWeeks = 8,
}: Props) {
  if (!from) {
    return (
      <p className="rounded-lg bg-slate-50 px-3 py-4 text-center text-xs text-slate-400">
        اختر تاريخ البداية عشان تشوف المعاينة
      </p>
    );
  }

  const start = new Date(`${from}T00:00:00`);
  if (isNaN(start.getTime())) return null;

  const end = to ? new Date(`${to}T00:00:00`) : null;
  const hasEnd = end !== null && !isNaN(end.getTime()) && end >= start;

  // حدود العرض: من أول الشهر اللي فيه البداية لآخر الشهر اللي فيه النهاية
  const gridStart = startOfWeek(new Date(start.getFullYear(), start.getMonth(), 1));
  const gridEnd = hasEnd
    ? new Date(end.getFullYear(), end.getMonth() + 1, 0)
    : new Date(start.getFullYear(), start.getMonth() + 1, 0);

  // نبني كل الأيام بين gridStart و gridEnd
  const days: (SchedulePreviewSlot | null)[] = [];
  const cursor = new Date(gridStart);
  while (cursor <= gridEnd) {
    const iso = toISO(cursor);
    const inRange = cursor >= start && (!hasEnd || cursor <= end!);
    const selected = weekdays.includes(cursor.getDay());

    days.push({
      date: iso,
      weekday: cursor.getDay(),
      hasLesson: inRange && selected,
      conflict: false,
      outOfRange: !inRange,
      inMonth: cursor.getMonth() === start.getMonth(),
      isMonthStart: cursor.getDate() === 1,
    });

    cursor.setDate(cursor.getDate() + 1);
  }

  // نديل الأسبوع الأول خانات فاضية عشان يبدأ بالأحد
  const firstDay = days[0];
  const leadingBlanks =
    firstDay && typeof firstDay !== "number" ? new Date(`${firstDay.date}T00:00:00`).getDay() : 0;
  const cells: (SchedulePreviewSlot | null)[] = [
    ...Array.from({ length: leadingBlanks }, () => null),
    ...days,
  ];

  const lessonCount = days.filter((d) => d?.hasLesson).length;
  const weeksTotal = Math.ceil((hasEnd ? Math.round((end!.getTime() - start.getTime()) / 86400000) : 29) / 7);
  const maxMinutes = weekdays.length * durationMinutes;

  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3">
      {/* الملخص */}
      <div className="mb-2.5 flex flex-wrap items-center justify-between gap-2">
        <div className="text-xs text-slate-600">
          <span className="font-semibold text-slate-800">
            {lessonCount.toLocaleString("ar-EG")} حصة
          </span>
          {hasEnd && (
            <>
              {" "}· {weeksTotal} أسبوع ·{" "}
              <span className="tabular-nums">
                {Math.round((end!.getTime() - start.getTime()) / 86400000) + 1} يوم
              </span>
            </>
          )}
          {!hasEnd && " · المدة غير محددة"}
        </div>

        {weekdays.length > 0 && (
          <span className="text-xs text-slate-500">
            {durationMinutes} دقيقة × {weekdays.length} أيام
          </span>
        )}
      </div>

      {/* رأس الأيام */}
      <div className="mb-1 grid grid-cols-7 gap-1">
        {DAY_HEADERS.map((d, i) => (
          <div key={i} className="text-center text-[11px] font-medium text-slate-400">
            {d}
          </div>
        ))}
      </div>

      {/* الشبكة */}
      <div className="grid grid-cols-7 gap-1">
        {cells.map((cell, i) => {
          if (!cell) return <div key={`b${i}`} />;

          const dayNum = Number(cell.date.slice(8, 10));

          return (
            <div
              key={cell.date}
              title={
                cell.hasLesson
                  ? `${cell.date} — فيه حصة`
                  : cell.outOfRange
                    ? `${cell.date} — خارج المدة`
                    : cell.date
              }
              className={[
                "relative flex aspect-square items-center justify-center rounded-md text-[11px] tabular-nums transition",
                cell.hasLesson
                  ? "bg-emerald-600 font-semibold text-white shadow-sm"
                  : cell.outOfRange
                    ? "bg-slate-50 text-slate-300"
                    : "bg-slate-100 text-slate-600",
                cell.inMonth ? "" : "opacity-45",
              ].join(" ")}
            >
              {dayNum}
              {cell.isMonthStart && (
                <span className="absolute -top-1 start-0 rounded bg-slate-700 px-1 text-[8px] text-white">
                  {new Date(`${cell.date}T00:00:00`).toLocaleDateString("ar-EG", {
                    month: "short",
                  })}
                </span>
              )}
            </div>
          );
        })}
      </div>

      {/* الدلالة */}
      <div className="mt-2.5 flex flex-wrap items-center gap-3 text-[11px] text-slate-500">
        <span className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-sm bg-emerald-600" /> فيه حصة
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-sm bg-slate-100 ring-1 ring-slate-200" /> يوم بدون حصة
        </span>
        <span className="inline-flex items-center gap-1.5">
          <span className="size-2.5 rounded-sm bg-slate-50 ring-1 ring-slate-200" /> خارج المدة
        </span>
        <span className="ms-auto">
          لو المدة طويلة، المعاينة بتعرض أول {maxWeeks} أسابيع
        </span>
      </div>
    </div>
  );
}
