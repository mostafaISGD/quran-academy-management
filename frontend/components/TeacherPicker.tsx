"use client";

import { useEffect, useMemo, useState } from "react";
import {
  getAvailableTeachers,
  WEEKDAY_LABELS,
  type AvailableTeacher,
} from "@/lib/api";
import { IconAlert } from "@/components/ui";

type Props = {
  /** الأيام المختارة (0=الأحد) */
  weekdays: number[];
  /** وقت البداية HH:MM */
  startTime: string;
  /** مدة الحصة بالدقائق */
  durationMinutes: number;
  /** المعلم المختار */
  value: string;
  onChange: (teacherId: string) => void;
  disabled?: boolean;
};

/**
 * قائمة المعلمين مفلترة على المواعيد المختارة.
 *
 * المعلم اللي مالوش جدول مسجّل بيظهر مع علامة تحذير — مش مخفي، لأن
 * الجدول إجباري بس مش متنفّذ عند الاختيار. لو اختاره المستخدم، يطلع تحذير.
 */
export default function TeacherPicker({
  weekdays,
  startTime,
  durationMinutes,
  value,
  onChange,
  disabled,
}: Props) {
  const [teachers, setTeachers] = useState<AvailableTeacher[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const hasSlot = weekdays.length > 0 && /^\d{2}:\d{2}$/.test(startTime) && durationMinutes > 0;

  /** بنطلب بعد ما المستخدم يختار المواعيد، مع debounce بسيط */
  useEffect(() => {
    if (!hasSlot) {
      setTeachers([]);
      return;
    }

    let cancelled = false;
    setLoading(true);
    setError(null);

    const timer = window.setTimeout(() => {
      getAvailableTeachers({ weekdays, start_time: startTime, duration: durationMinutes })
        .then((r) => {
          if (!cancelled) setTeachers(r.teachers);
        })
        .catch((e) => {
          if (!cancelled) {
            setError(e instanceof Error ? e.message : "تعذر تحميل المعلمين");
            setTeachers([]);
          }
        })
        .finally(() => {
          if (!cancelled) setLoading(false);
        });
    }, 250);

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [hasSlot, weekdays.join(","), startTime, durationMinutes]);

  const available = useMemo(() => teachers.filter((t) => t.available), [teachers]);
  const busy = useMemo(() => teachers.filter((t) => !t.available), [teachers]);

  if (!hasSlot) {
    return (
      <div className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-3">
        <p className="flex items-center gap-1.5 text-xs font-medium text-amber-900">
          <IconAlert size={13} />
          اختار أيام الحصة والوقت الأول
        </p>
        <p className="mt-0.5 text-xs text-amber-800">
          القائمة هتعرض المعلمين المتاحين في المواعيد دي بس.
        </p>
      </div>
    );
  }

  const selected = teachers.find((t) => String(t.id) === value);
  const selectedHasNoSchedule = selected && !selected.has_schedule;

  return (
    <div>
      <div className="mb-1.5 flex items-center justify-between">
        <label className="text-xs font-medium text-slate-700">
          المعلم <span className="text-red-500">*</span>
        </label>
        {!loading && teachers.length > 0 && (
          <span className="text-[11px] text-slate-500">
            {available.length} متاح من {teachers.length}
          </span>
        )}
      </div>

      {error && (
        <p className="mb-2 rounded-lg bg-red-50 px-3 py-2 text-xs text-red-700">{error}</p>
      )}

      {loading && teachers.length === 0 ? (
        <div className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-3 text-xs text-slate-500">
          <span className="size-3.5 animate-spin rounded-full border-2 border-slate-400 border-t-transparent" />
          بنشوف مين فاضي…
        </div>
      ) : (
        <div className="max-h-72 space-y-2 overflow-y-auto rounded-lg border border-slate-200 p-2">
          {/* المتاحون */}
          {available.length > 0 && (
            <div>
              <p className="mb-1.5 px-1 text-[11px] font-semibold text-emerald-700">
                متاح ({available.length})
              </p>
              <div className="space-y-1">
                {available.map((t) => {
                  const active = String(t.id) === value;
                  return (
                    <button
                      key={t.id}
                      type="button"
                      disabled={disabled}
                      onClick={() => onChange(String(t.id))}
                      className={`flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-right text-sm transition disabled:opacity-50 ${
                        active
                          ? "bg-slate-800 text-white"
                          : "bg-white text-slate-700 ring-1 ring-slate-200 hover:bg-slate-50"
                      }`}
                    >
                      <span
                        className={`size-2 shrink-0 rounded-full ${
                          active ? "bg-emerald-400" : "bg-emerald-500"
                        }`}
                      />
                      <span className="flex-1 truncate font-medium">{t.name}</span>

                      {/* معلم من غير جدول — علامة تحذير */}
                      {!t.has_schedule && (
                        <span
                          title="مالوش جدول مسجّل — أي وقت بيتحسب فاضي"
                          className={`inline-flex shrink-0 items-center gap-1 rounded px-1.5 py-0.5 text-[10px] font-medium ${
                            active ? "bg-amber-400/25 text-amber-200" : "bg-amber-50 text-amber-700"
                          }`}
                        >
                          <IconAlert size={10} />
                          مالهوش جدول
                        </span>
                      )}
                    </button>
                  );
                })}
              </div>
            </div>
          )}

          {/* المشغولون — ظاهرين للعلم بس مش قابلين للاختيار */}
          {busy.length > 0 && (
            <div className="border-t border-slate-100 pt-2">
              <p className="mb-1.5 px-1 text-[11px] font-semibold text-slate-400">
                مشغول في المواعيد دي ({busy.length})
              </p>
              <div className="space-y-1">
                {busy.map((t) => (
                  <div
                    key={t.id}
                    title={t.reason ?? undefined}
                    className="flex cursor-not-allowed items-center gap-2 rounded-lg bg-slate-50 px-2.5 py-2 text-sm text-slate-400"
                  >
                    <span className="size-2 shrink-0 rounded-full bg-slate-300" />
                    <span className="flex-1 truncate">{t.name}</span>
                    <span className="shrink-0 text-[10px]">{t.reason}</span>
                  </div>
                ))}
              </div>
            </div>
          )}

          {!loading && teachers.length === 0 && (
            <p className="px-2 py-6 text-center text-xs text-slate-400">
              مفيش معلمين مطابقين — جرّب أيام أو وقت تاني
            </p>
          )}
        </div>
      )}

      {/* تحذير المعلم اللي مالوش جدول */}
      {selectedHasNoSchedule && (
        <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs leading-relaxed text-amber-800">
          <IconAlert size={13} className="mt-0.5 shrink-0" />
          <span>
            <strong>{selected!.name}</strong> مالهوش جدول مسجّل. لازم يسجّل مواعيده
            الأول، وإلا أي وقت في الأسبوع هيبقى متاح للحجز.
          </span>
        </p>
      )}

      {available.length === 0 && !loading && teachers.length > 0 && (
        <p className="mt-2 flex items-start gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-xs leading-relaxed text-red-700">
          <IconAlert size={13} className="mt-0.5 shrink-0" />
          مفيش أي معلم متاح في المواعيد دي — جرّب أيام أو وقت تاني.
        </p>
      )}
    </div>
  );
}
