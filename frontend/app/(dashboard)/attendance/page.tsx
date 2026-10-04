"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  getAttendanceDay,
  saveAttendance,
  type AttendanceDay,
  type AttendanceDayRow,
  type AttendanceStatus,
} from "@/lib/api";
import { useUI } from "@/components/ui";

/**
 * تسجيل حضور الموظفين — كله على الأدمن.
 *
 * شاشة واحدة: تاريخ + قائمة الموظفين + زرار لكل حالة. الحفظ بياخد
 * اليوم كله مرة واحدة، والتسجيل مرتين بيحدّث مش بيضاعف.
 */

const STATUS_OPTIONS: { value: AttendanceStatus; label: string; dot: string; active: string }[] = [
  { value: "present", label: "حاضر", dot: "bg-emerald-500", active: "ring-emerald-500 bg-emerald-50 text-emerald-800" },
  { value: "late", label: "متأخر", dot: "bg-amber-500", active: "ring-amber-500 bg-amber-50 text-amber-800" },
  { value: "half_day", label: "نصف يوم", dot: "bg-purple-500", active: "ring-purple-500 bg-purple-50 text-purple-800" },
  { value: "absent", label: "غائب", dot: "bg-red-500", active: "ring-red-500 bg-red-50 text-red-800" },
  { value: "on_leave", label: "إجازة", dot: "bg-blue-500", active: "ring-blue-500 bg-blue-50 text-blue-800" },
];

const STATUS_LABEL: Record<AttendanceStatus, string> = {
  present: "حاضر",
  absent: "غائب",
  late: "متأخر",
  on_leave: "إجازة",
  half_day: "نصف يوم",
};

const WEEKDAYS = ["الأحد", "الإثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت"];

/** يحوّل تاريخ ISO لـ YYYY-MM-DD بدون انزلاق المنطقة الزمنية */
function toIso(d: Date): string {
  const off = d.getTimezoneOffset();
  return new Date(d.getTime() - off * 60000).toISOString().slice(0, 10);
}

function shiftDay(iso: string, delta: number): string {
  const [y, m, d] = iso.split("-").map(Number);
  const date = new Date(y, m - 1, d + delta);
  return toIso(date);
}

export default function AttendancePage() {
  const [date, setDate] = useState(() => toIso(new Date()));
  const [day, setDay] = useState<AttendanceDay | null>(null);
  /** المسودّة المحلية — اللي بيتعرض و بيتحفظ */
  const [draft, setDraft] = useState<Record<number, AttendanceDayRow["record"]>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [showTimes, setShowTimes] = useState<number | null>(null);

  const { toast, confirm } = useUI();

  const load = useCallback(async (targetDate: string) => {
    setLoading(true);
    setError(null);
    try {
      const r = await getAttendanceDay(targetDate);
      setDay(r);
      // المسودّة تبدأ من المحفوظ
      const next: Record<number, AttendanceDayRow["record"]> = {};
      for (const row of r.rows) next[row.employee.id] = row.record;
      setDraft(next);
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل اليوم");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => { load(date); }, [date, load]);

  const isDirty = useMemo(() => {
    if (!day) return false;
    return day.rows.some((row) => {
      const saved = row.record;
      const now = draft[row.employee.id] ?? null;
      return (saved?.status ?? null) !== (now?.status ?? null);
    });
  }, [day, draft]);

  const markedCount = useMemo(
    () => Object.values(draft).filter((r) => r !== null).length,
    [draft],
  );

  function markAll(status: AttendanceStatus) {
    setDraft((prev) => {
      const next = { ...prev };
      for (const row of day?.rows ?? []) {
        const current = prev[row.employee.id];
        // ما نمسحش الأوقات لو الأدمن غيّر الحالة بس
        next[row.employee.id] = {
          id: current?.id ?? 0,
          status,
          check_in: current?.check_in ?? (status === "present" || status === "late" ? "09:00" : null),
          check_out: current?.check_out ?? (status === "present" || status === "late" ? "17:00" : null),
          late_minutes: current?.late_minutes ?? 0,
          worked_hours: current?.worked_hours ?? 0,
          notes: current?.notes ?? null,
        };
      }
      return next;
    });
  }

  function setStatus(employeeId: number, status: AttendanceStatus) {
    setDraft((prev) => {
      const current = prev[employeeId];
      return {
        ...prev,
        [employeeId]: {
          id: current?.id ?? 0,
          status,
          check_in: current?.check_in ?? (status === "present" || status === "late" ? "09:00" : null),
          check_out: current?.check_out ?? (status === "present" || status === "late" ? "17:00" : null),
          late_minutes: status === "late" ? (current?.late_minutes || 15) : 0,
          worked_hours: current?.worked_hours ?? 0,
          notes: current?.notes ?? null,
        },
      };
    });
  }

  function setTime(employeeId: number, field: "check_in" | "check_out", value: string) {
    setDraft((prev) => {
      const current = prev[employeeId];
      if (!current) return prev;
      return { ...prev, [employeeId]: { ...current, [field]: value || null } };
    });
  }

  async function handleSave() {
    if (!day) return;

    // المسجّلين بس — اللي ما اتحددش حالته مش بيتحفظوا
    const records = Object.entries(draft)
      .filter(([, r]) => r !== null)
      .map(([employeeId, r]) => ({
        employee_id: Number(employeeId),
        status: r!.status,
        check_in: r!.check_in,
        check_out: r!.check_out,
      }));

    if (records.length === 0) {
      toast.warning("مفيش حاجة تتسجّل", "لم تحدّد حالة أي موظف.");
      return;
    }

    const ok = await confirm({
      title: "حفظ حضور اليوم",
      message: `هيتسجّل ${records.length} موظف ليوم ${date}؟\n\n• المسجّلين بس.\n• تقدر تعدّل في أي وقت تاني.`,
      confirmLabel: "احفظ",
    });
    if (!ok) return;

    setSaving(true);
    try {
      const r = await saveAttendance(date, records);
      toast.success(r.message, day.is_friday ? "الجمعة إجازة أسبوعية" : undefined);
      load(date);
    } catch (e) {
      toast.error("فشل الحفظ", e instanceof Error ? e.message : undefined);
      setError(e instanceof Error ? e.message : "فشل الحفظ");
    } finally {
      setSaving(false);
    }
  }

  const counts = useMemo(() => {
    const out: Record<string, number> = {};
    for (const r of Object.values(draft)) {
      if (r) out[r.status] = (out[r.status] ?? 0) + 1;
    }
    return out;
  }, [draft]);

  return (
    <div>
      {/* ===== الترويسة ===== */}
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">تسجيل الحضور</h1>
          <p className="text-xs text-slate-500">بسمّي الحالة لكل موظف واحفظ اليوم كله مرة واحدة</p>
        </div>
        <button
          onClick={handleSave}
          disabled={saving || !isDirty}
          className="rounded-lg bg-slate-800 px-5 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
        >
          {saving ? "جاري الحفظ…" : isDirty ? "حفظ اليوم" : "محفوظ"}
        </button>
      </div>

      {/* ===== اختيار اليوم ===== */}
      <div className="mb-5 flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-3">
        <button
          onClick={() => setDate(shiftDay(date, -1))}
          className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50"
        >
          → السابق
        </button>

        <input
          type="date"
          value={date}
          onChange={(e) => setDate(e.target.value || toIso(new Date()))}
          className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
        />

        <button
          onClick={() => setDate(shiftDay(date, 1))}
          className="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50"
        >
          التالي ←
        </button>

        <button
          onClick={() => setDate(toIso(new Date()))}
          className="rounded-lg bg-slate-100 px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-200"
        >
          النهاردة
        </button>

        {day && (
          <span className="text-sm font-medium text-slate-700">
            {WEEKDAYS[new Date(`${date}T00:00:00`).getDay()]}
            {day.is_friday && (
              <span className="mr-2 rounded-full bg-blue-50 px-2 py-0.5 text-[11px] text-blue-700">إجازة أسبوعية</span>
            )}
          </span>
        )}

        <span className="mr-auto text-xs text-slate-500">
          {markedCount} / {day?.summary.total ?? 0} مسجّل
        </span>
      </div>

      {/* ===== ملخص + تسجيل جماعي ===== */}
      <div className="mb-5 flex flex-wrap items-center gap-2">
        <span className="text-xs text-slate-500">تسجيل جماعي:</span>
        {STATUS_OPTIONS.map((s) => (
          <button
            key={s.value}
            onClick={() => markAll(s.value)}
            className="rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-600 transition hover:bg-slate-50"
          >
            الكل {s.label}
          </button>
        ))}
        <span className="mr-auto flex flex-wrap gap-2 text-xs text-slate-600">
          {STATUS_OPTIONS.map((s) =>
            counts[s.value] ? (
              <span key={s.value} className="flex items-center gap-1">
                <span className={`h-2 w-2 rounded-full ${s.dot}`} />
                {counts[s.value]} {s.label}
              </span>
            ) : null,
          )}
        </span>
      </div>

      {error && (
        <div className="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700">{error}</div>
      )}

      {/* ===== الجدول ===== */}
      {loading ? (
        <div className="space-y-2">
          {[0, 1, 2, 3, 4].map((i) => (
            <div key={i} className="h-14 animate-pulse rounded-xl bg-slate-100" />
          ))}
        </div>
      ) : !day || day.rows.length === 0 ? (
        <div className="rounded-xl border border-dashed border-slate-300 py-16 text-center">
          <p className="text-sm text-slate-500">مفيش موظفين ليهم دوام — أضف موظفين من شاشة الموظفين</p>
        </div>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="border-b border-slate-200 bg-slate-50 text-xs text-slate-500">
              <tr>
                <th className="px-4 py-3 text-right font-medium">الموظف</th>
                <th className="px-3 py-3 text-right font-medium">القسم</th>
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
                <th className="px-3 py-3 text-center font-medium">الحضور</th>
                <th className="px-3 py-3 text-center font-medium">الانصراف</th>
                <th className="px-3 py-3 text-center font-medium">الساعات</th>
              </tr>
            </thead>
            <tbody>
              {day.rows.map((row) => {
                const record = draft[row.employee.id] ?? null;
                const hasTimes = record && (record.status === "present" || record.status === "late" || record.status === "half_day");
                const hours = hoursBetween(record?.check_in ?? null, record?.check_out ?? null, record?.status ?? null);

                return (
                  <tr key={row.employee.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-2.5">
                      <p className="font-medium text-slate-800">{row.employee.name}</p>
                      <p className="text-[11px] text-slate-400">{row.employee.job_title ?? "—"}</p>
                    </td>
                    <td className="px-3 py-2.5 text-xs text-slate-600">{row.employee.department ?? "—"}</td>

                    {/* الحالة */}
                    <td className="px-3 py-2.5">
                      <div className="flex justify-center gap-1">
                        {STATUS_OPTIONS.map((s) => (
                          <button
                            key={s.value}
                            onClick={() => setStatus(row.employee.id, s.value)}
                            title={s.label}
                            className={`h-8 w-8 rounded-lg text-xs font-bold transition ring-2 ${
                              record?.status === s.value
                                ? s.active
                                : "bg-slate-50 text-slate-300 ring-transparent hover:bg-slate-100"
                            }`}
                          >
                            {s.label.charAt(0)}
                          </button>
                        ))}
                      </div>
                      {record ? (
                        <p className="mt-1 text-center text-[11px] text-slate-500">{STATUS_LABEL[record.status]}</p>
                      ) : (
                        <p className="mt-1 text-center text-[11px] text-slate-300">—</p>
                      )}
                    </td>

                    {/* الأوقات */}
                    <td className="px-3 py-2.5 text-center">
                      {hasTimes ? (
                        <input
                          type="time"
                          value={record!.check_in ?? ""}
                          onChange={(e) => setTime(row.employee.id, "check_in", e.target.value)}
                          className="rounded border border-slate-200 px-1.5 py-1 text-center text-xs tabular-nums"
                        />
                      ) : (
                        <span className="text-xs text-slate-300">—</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-center">
                      {hasTimes ? (
                        <input
                          type="time"
                          value={record!.check_out ?? ""}
                          onChange={(e) => setTime(row.employee.id, "check_out", e.target.value)}
                          className="rounded border border-slate-200 px-1.5 py-1 text-center text-xs tabular-nums"
                        />
                      ) : (
                        <span className="text-xs text-slate-300">—</span>
                      )}
                    </td>
                    <td className="px-3 py-2.5 text-center text-xs tabular-nums text-slate-600">
                      {hours !== null ? hours : "—"}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* ===== دليل الألوان ===== */}
      <div className="mt-4 flex flex-wrap gap-4 text-xs text-slate-500">
        {STATUS_OPTIONS.map((s) => (
          <span key={s.value} className="flex items-center gap-1.5">
            <span className={`h-2.5 w-2.5 rounded-full ${s.dot}`} />
            {s.label}
          </span>
        ))}
      </div>
    </div>
  );
}

/** الساعات بين وقتين — نفس منطق الـ backend */
function hoursBetween(inp: string | null, out: string | null, status: AttendanceStatus | null): number | null {
  if (!status) return null;
  if (status === "half_day") return 4;
  if (status !== "present" && status !== "late") return 0;
  if (!inp || !out) return null;

  const [ih, im] = inp.split(":").map(Number);
  const [oh, om] = out.split(":").map(Number);
  let start = ih * 60 + im;
  let end = oh * 60 + om;
  if (end < start) end += 24 * 60;

  return Math.round(((end - start) / 60) * 100) / 100;
}
