import type { StudentProgress } from "@/lib/api";
import { dateTime, num } from "@/lib/format";
import { attendanceRate } from "@/lib/student-portal";

import { MemorizationMap } from "./MemorizationMap";

/**
 * ⭐ تبويب «تقدمي»: الحضور، تقييمات المعلم، وخريطة الحفظ.
 *
 * ⭐ النسبة بتتحسب بنفس دالة العرض (`attendanceRate`) — مش
 * بمعادلة تانية مكتوبة في الـJSX.
 */
export function StudentProgressPanel({ progress }: { progress: StudentProgress }) {
  const rate = attendanceRate(progress.attendance.summary);
  const summary = progress.attendance.summary;

  return (
    <div className="space-y-6">
      {/* ===== الحضور ===== */}
      <section className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-700">الحضور</h2>
          <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-emerald-200">
            {num(rate, 0)}% حضور
          </span>
        </div>

        <div className="mb-4 grid grid-cols-2 gap-2 text-center sm:grid-cols-4">
          <Count label="حاضر" value={summary.present} tone="text-emerald-600" />
          <Count label="متأخر" value={summary.late} tone="text-amber-600" />
          <Count label="غائب" value={summary.absent} tone="text-red-600" />
          <Count label="بعذر" value={summary.excused} tone="text-slate-500" />
        </div>

        {progress.attendance.rows.length === 0 ? (
          <p className="py-4 text-center text-sm text-slate-500">
            لسه مفيش حضور مسجّل.
          </p>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-start text-xs">
              <thead className="text-slate-400">
                <tr>
                  <th className="pb-2 font-medium">الحصة</th>
                  <th className="pb-2 font-medium">الحالة</th>
                  <th className="pb-2 font-medium">المعلم</th>
                  <th className="pb-2 font-medium">تأخير</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {progress.attendance.rows.map((row) => (
                  <tr key={row.id} className="text-slate-600">
                    <td className="py-2">{dateTime(row.lesson_start_at)}</td>
                    <td className="py-2">{row.status_label}</td>
                    <td className="py-2">{row.teacher_name ?? "—"}</td>
                    <td className="py-2 tabular-nums">
                      {row.late_minutes > 0 ? `${num(row.late_minutes, 0)} د` : "—"}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      {/* ===== التقييمات ===== */}
      <section className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 className="mb-3 text-sm font-semibold text-slate-700">تقييمات المعلم</h2>

        {progress.progress.length === 0 ? (
          <p className="py-4 text-center text-sm text-slate-500">
            لسه مفيش تقييمات مسجّلة.
          </p>
        ) : (
          <ul className="space-y-2">
            {progress.progress.map((record) => (
              <li
                key={record.id}
                className="rounded-lg bg-slate-50 px-3 py-2 text-sm"
              >
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <span className="font-medium text-slate-700">
                    {record.category_label}
                  </span>
                  <span className="text-xs text-slate-500">
                    {record.score !== null ? `${record.score}/5` : "—"}
                  </span>
                </div>
                <p className="mt-1 text-[11px] text-slate-400">
                  {record.program_name ?? "—"}
                  {record.teacher_name ? ` · ${record.teacher_name}` : ""}
                  {record.recorded_at ? ` · ${dateTime(record.recorded_at)}` : ""}
                </p>
                {record.notes && (
                  <p className="mt-1 text-xs leading-5 text-slate-600">{record.notes}</p>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>

      {/* ===== خريطة الحفظ ===== */}
      <section>
        <h2 className="mb-2 text-sm font-semibold text-slate-700">خريطة الحفظ</h2>
        <MemorizationMap surahs={progress.memorization_map} />
      </section>
    </div>
  );
}

function Count({
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
