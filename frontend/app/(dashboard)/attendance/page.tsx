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

  /**
   * هل في تعديلات غير محفوظة؟
   *
   * لازم نقارن **الساعات** كمان مش الحالة بس — لأن الده بقى الحقل
   * الأساسي. لو قارنّا الحالة بس، الأدمن يقدر يعدّل ٤ ساعات لـ ٦
   * ويжет زرار الحفظ معروض «محفوظ».
   */
  const isDirty = useMemo(() => {
    if (!day) return false;

    return day.rows.some((row) => {
      const saved = row.record;
      const now = draft[row.employee.id] ?? null;

      if ((saved?.status ?? null) !== (now?.status ?? null)) return true;
      if (!now) return false;

      // نقارن الأرقام بعد التوحيد عشان 4 و 4.0 يبقوا واحد
      return (
        Number(saved?.worked_hours ?? 0) !== Number(now.worked_hours) ||
        (saved?.check_in ?? null) !== (now.check_in ?? null) ||
        (saved?.check_out ?? null) !== (now.check_out ?? null) ||
        (saved?.notes ?? null) !== (now.notes ?? null)
      );
    });
  }, [day, draft]);

  const markedCount = useMemo(
    () => Object.values(draft).filter((r) => r !== null).length,
    [draft],
  );

  /**
   * ساعات افتراضية بتتحط مع الحالة.
   *
   * دي **معاينة للمستخدم** بس — مش قيمة بتتخزّن. الـ backend هو اللي
   * بيقرر الساعات النهائية (`resolveHours`). يعني لو الأدمن غيّر
   * الحالة ساعات من غير ما يعدّل الخانة، السيرفر بيفرض ٠ للغائب
   * و٤ لنص اليوم على أي حال.
   *
   * مهم: الحط هنا مفيش `09:00` / `17:00`. النظام فريلانس بالساعات،
   * فمفيش يوم افتراضي نتخيّله.
   */
  function defaultHoursFor(status: AttendanceStatus, current: number): number {
    if (status === "absent" || status === "on_leave") return 0;
    if (status === "half_day") return 4;
    // حاضر / متأخر: لو فيه رقم متسجل قبل كده نحتفظ بيه
    return current > 0 ? current : 0;
  }

  function makeDraftRow(
    current: AttendanceDayRow["record"],
    status: AttendanceStatus,
  ): NonNullable<AttendanceDayRow["record"]> {
    return {
      id: current?.id ?? 0,
      status,
      check_in: current?.check_in ?? null,
      check_out: current?.check_out ?? null,
      worked_hours: defaultHoursFor(status, current?.worked_hours ?? 0),
      notes: current?.notes ?? null,
    };
  }

  function markAll(status: AttendanceStatus) {
    setDraft((prev) => {
      const next = { ...prev };
      for (const row of day?.rows ?? []) {
        next[row.employee.id] = makeDraftRow(prev[row.employee.id], status);
      }
      return next;
    });
  }

  function setStatus(employeeId: number, status: AttendanceStatus) {
    setDraft((prev) => ({
      ...prev,
      [employeeId]: makeDraftRow(prev[employeeId], status),
    }));
  }

  function setField(
    employeeId: number,
    field: "worked_hours" | "check_in" | "check_out" | "notes",
    value: string,
  ) {
    setDraft((prev) => {
      const current = prev[employeeId];
      if (!current) return prev;

      const next = { ...current };

      if (field === "worked_hours") {
        // حد أقصى ٢٤ ساعة — من غير قيد، حد يكتب ٩٠ ويفوتّده
        const n = parseFloat(value);
        next.worked_hours = Number.isNaN(n) ? 0 : Math.min(Math.max(n, 0), 24);
      } else if (field === "notes") {
        next.notes = value || null;
      } else {
        next[field] = value || null;
      }

      return { ...prev, [employeeId]: next };
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
        worked_hours: r!.worked_hours,
        check_in: r!.check_in,
        check_out: r!.check_out,
        notes: r!.notes,
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
                <th className="px-3 py-3 text-center font-medium">الحالة</th>
                <th className="px-3 py-3 text-center font-medium">
                  الساعات
                  <span className="block text-[10px] font-normal text-slate-400">الأجر بيحسب منها</span>
                </th>
                <th className="px-3 py-3 text-center font-medium">الأجر المتوقع</th>
                <th className="px-3 py-3 text-center font-medium">ملاحظات</th>
              </tr>
            </thead>
            <tbody>
              {day.rows.map((row) => {
                const record = draft[row.employee.id] ?? null;
                const worksToday =
                  record !== null &&
                  record.status !== "absent" &&
                  record.status !== "on_leave";
                // الأجر المتوقع = الساعات × سعر الساعة. معروض كمساعدة
                // للعين، مش محفوظ.
                const payout =
                  row.employee.hourly_rate !== null
                    ? record?.worked_hours
                      ? record.worked_hours * row.employee.hourly_rate
                      : null
                    : null;

                return (
                  <tr key={row.employee.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50">
                    <td className="px-4 py-2.5">
                      <p className="font-medium text-slate-800">{row.employee.name}</p>
                      <p className="text-[11px] text-slate-400">
                        {row.employee.job_title ?? "—"}
                        {row.employee.hourly_rate !== null && (
                          <span className="mr-1.5">· {row.employee.hourly_rate} ج/ساعة</span>
                        )}
                      </p>
                    </td>

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

                    {/* ⭐ الساعات — الحقل الأساسي */}
                    <td className="px-3 py-2.5 text-center">
                      {worksToday ? (
                        <input
                          type="number"
                          inputMode="decimal"
                          step="0.25"
                          min="0"
                          max="24"
                          value={record!.worked_hours || ""}
                          placeholder="0"
                          onChange={(e) => setField(row.employee.id, "worked_hours", e.target.value)}
                          className="w-20 rounded-lg border border-slate-200 px-2 py-1.5 text-center text-sm font-semibold tabular-nums text-slate-800 focus:border-slate-400 focus:outline-none"
                        />
                      ) : (
                        <span className="text-xs text-slate-300">
                          {record ? "٠" : "—"}
                        </span>
                      )}
                    </td>

                    {/* الأجر المتوقع */}
                    <td className="px-3 py-2.5 text-center text-xs tabular-nums">
                      {payout !== null && payout > 0 ? (
                        <span className="font-medium text-slate-700">
                          {new Intl.NumberFormat("ar-EG", { maximumFractionDigits: 0 }).format(payout)}
                        </span>
                      ) : row.employee.hourly_rate === null ? (
                        <span className="text-slate-300" title="مش متسجّل سعر ساعة لهذا الموظف">
                          بدون سعر
                        </span>
                      ) : (
                        <span className="text-slate-300">—</span>
                      )}
                    </td>

                    {/* ملاحظات */}
                    <td className="px-3 py-2.5">
                      <input
                        value={record?.notes ?? ""}
                        onChange={(e) => setField(row.employee.id, "notes", e.target.value)}
                        placeholder="—"
                        maxLength={200}
                        className="w-full rounded border border-slate-200 px-2 py-1 text-xs text-slate-700 focus:border-slate-400 focus:outline-none"
                      />
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {/* ===== دليل الألوان + ملاحظة الأجر ===== */}
      <div className="mt-4 flex flex-wrap items-center gap-4 text-xs text-slate-500">
        {STATUS_OPTIONS.map((s) => (
          <span key={s.value} className="flex items-center gap-1.5">
            <span className={`h-2.5 w-2.5 rounded-full ${s.dot}`} />
            {s.label}
          </span>
        ))}
        <span className="mr-auto text-slate-400">
          الساعات هي مصدر الأجر · غائب وإجازة = ٠ أياً ما كتبت
        </span>
      </div>
    </div>
  );
}
