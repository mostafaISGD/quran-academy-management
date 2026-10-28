"use client";

import type { ParentAttendance } from "@/lib/api";
import { dateTime, num } from "@/lib/format";
import {
  ATTENDANCE_LABEL,
  ATTENDANCE_TONE,
  attendanceRate,
} from "@/lib/parent-portal";
import { Badge, Card, Empty, ErrorBox, LoadingRows } from "./parent-ui";

/**
 * ⭐⭐⭐ **PR 4.5 — الحضور.**
 *
 * ⭐ ملخّص عددي + نسبة، وتحته آخر 100 حصة. «متأخر» بيتحسب حضور،
 * و«بعذر» مش حضور ولا غياب — نفس منطق `attendanceRate` المختبَر.
 */
export default function ChildAttendance({
  data,
  loading,
  error,
  timeZone,
}: {
  data: ParentAttendance | null;
  loading: boolean;
  error: string | null;
  timeZone?: string;
}) {
  if (loading && !data) return <LoadingRows count={4} />;
  if (error) return <ErrorBox message={error} />;

  const summary = data?.summary ?? { present: 0, absent: 0, late: 0, excused: 0, total: 0 };
  const rate = attendanceRate(summary);
  const rows = data?.attendance ?? [];

  return (
    <div className="space-y-4">
      <Card
        title="ملخّص الحضور"
        hint={rate === null ? "مفيش حصص اتسجلت لسه" : `نسبة الحضور ${num(rate, 0)}%`}
      >
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
          {(["present", "absent", "late", "excused"] as const).map((status) => (
            <div
              key={status}
              className="rounded-lg bg-slate-50 p-3 text-center ring-1 ring-slate-100"
            >
              <p className="text-xl font-bold text-slate-900">{num(summary[status], 0)}</p>
              <p className="mt-0.5 text-[11px] text-slate-500">{ATTENDANCE_LABEL[status]}</p>
            </div>
          ))}
        </div>
      </Card>

      <Card title="سجل الحضور" hint="الأحدث الأول — آخر 100 حصة">
        {rows.length === 0 ? (
          <Empty title="مفيش سجل حضور" hint="هيظهر هنا بعد أول حصة يتم تسجيلها." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {rows.map((row) => (
              <li key={`${row.lesson_id}-${row.date ?? ""}`} className="py-2.5">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="text-sm text-slate-800">
                    {dateTime(row.date, timeZone)}
                  </p>
                  <Badge tone={ATTENDANCE_TONE[row.status]}>
                    {ATTENDANCE_LABEL[row.status]}
                    {row.late_minutes ? ` · ${num(row.late_minutes, 0)} د` : ""}
                  </Badge>
                </div>

                <p className="mt-0.5 text-xs text-slate-500">
                  {[row.program_name, row.teacher_name].filter(Boolean).join(" · ") || "—"}
                </p>

                {row.notes && (
                  <p className="mt-1 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600">
                    {row.notes}
                  </p>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
