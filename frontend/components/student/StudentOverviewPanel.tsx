import type { StudentOverview } from "@/lib/api";
import { num } from "@/lib/format";
import { attendanceRate, homeworkNotice } from "@/lib/student-portal";

import { StudentLessonCard } from "./StudentLessonCard";

/**
 * ⭐ تبويب «نظرة عامة»: الحصة القادمة، الحضور، الحفظ، والواجبات.
 *
 * ⭐ كله أكاديمي — مفيش فواتير ولا أرصدة ولا أي مسار مالي.
 */
export function StudentOverviewPanel({ overview }: { overview: StudentOverview }) {
  const rate = attendanceRate(overview.attendance_summary);
  const homework = homeworkNotice(overview.homework);

  return (
    <div className="space-y-5">
      {/* ===== الحصة القادمة ===== */}
      <section>
        <h2 className="mb-2 text-sm font-semibold text-slate-700">الحصة القادمة</h2>
        {overview.next_lesson ? (
          <StudentLessonCard lesson={overview.next_lesson} />
        ) : (
          <p className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-500">
            مفيش حصة قادمة دلوقتي — جدولك بيتحدّث أول ما الإدارة تحجز.
          </p>
        )}
      </section>

      {/* ===== الأرقام ===== */}
      <section className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Stat label="نسبة الحضور" value={`${num(rate, 0)}%`} tone="emerald" />
        <Stat label="حصص قادمة" value={num(overview.upcoming_count, 0)} />
        <Stat label="سور محفوظة" value={num(overview.memorization_summary.surahs, 0)} />
        <Stat label="آيات محفوظة" value={num(overview.memorization_summary.ayahs, 0)} />
      </section>

      {/* ===== ملخص الحضور ===== */}
      <section className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 className="mb-3 text-sm font-semibold text-slate-700">ملخص الحضور</h2>
        <div className="grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
          <MiniStat label="حاضر" value={overview.attendance_summary.present} tone="text-emerald-600" />
          <MiniStat label="متأخر" value={overview.attendance_summary.late} tone="text-amber-600" />
          <MiniStat label="غائب" value={overview.attendance_summary.absent} tone="text-red-600" />
          <MiniStat label="بعذر" value={overview.attendance_summary.excused} tone="text-slate-500" />
        </div>
      </section>

      {/* ===== الواجبات ===== */}
      <section className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 className="mb-2 text-sm font-semibold text-slate-700">الواجبات</h2>

        {homework ? (
          <p className="text-xs leading-5 text-slate-500">{homework}</p>
        ) : (
          <ul className="space-y-2">
            {overview.homework.items.map((item) => (
              <li
                key={item.id}
                className="flex items-center justify-between gap-2 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-700"
              >
                <span>{item.title}</span>
                <span className="text-[11px] text-slate-400">
                  {item.due_date ? `تسليم: ${item.due_date}` : "بلا موعد"}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}

function Stat({
  label,
  value,
  tone = "slate",
}: {
  label: string;
  value: string;
  tone?: "slate" | "emerald";
}) {
  const valueTone = tone === "emerald" ? "text-emerald-600" : "text-slate-800";

  return (
    <div className="rounded-xl bg-white p-4 text-center ring-1 ring-slate-200">
      <p className={`text-xl font-bold tabular-nums ${valueTone}`}>{value}</p>
      <p className="mt-1 text-[11px] text-slate-500">{label}</p>
    </div>
  );
}

function MiniStat({
  label,
  value,
  tone,
}: {
  label: string;
  value: number;
  tone: string;
}) {
  return (
    <div className="rounded-lg bg-slate-50 py-2">
      <p className={`text-base font-semibold tabular-nums ${tone}`}>{num(value, 0)}</p>
      <p className="text-[11px] text-slate-500">{label}</p>
    </div>
  );
}
