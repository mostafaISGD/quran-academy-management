"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import {
  getTeacherAvailability,
  createScheduleBlock,
  updateScheduleBlock,
  deleteScheduleBlock,
  WEEKDAY_LABELS,
  SCHEDULE_KIND_LABEL,
  type TeacherScheduleBlock,
  type ScheduleKind,
  type Teacher,
  type Lesson,
} from "@/lib/api";
import { getTeacher, getTeacherLessons } from "@/lib/api";
import { Modal, useUI, IconTrash, IconPencil, IconCheck, IconAlert } from "@/components/ui";

const DAY_ORDER = [0, 1, 2, 3, 4, 5, 6];

/** ألوان كل نوع التزام */
const KIND_STYLE: Record<ScheduleKind, { chip: string; dot: string; bar: string }> = {
  academy: {
    chip: "bg-emerald-50 text-emerald-800 ring-emerald-200",
    dot: "bg-emerald-500",
    bar: "bg-emerald-500",
  },
  external: {
    chip: "bg-violet-50 text-violet-800 ring-violet-200",
    dot: "bg-violet-500",
    bar: "bg-violet-500",
  },
  leave: {
    chip: "bg-amber-50 text-amber-800 ring-amber-200",
    dot: "bg-amber-500",
    bar: "bg-amber-500",
  },
  personal: {
    chip: "bg-slate-100 text-slate-700 ring-slate-200",
    dot: "bg-slate-400",
    bar: "bg-slate-400",
  },
};

const KIND_OPTIONS: ScheduleKind[] = ["academy", "external", "leave", "personal"];

type Draft = {
  id: number | null;
  weekday: number;
  starts_at: string;
  ends_at: string;
  kind: ScheduleKind;
  title: string;
  is_recurring: boolean;
  specific_date: string;
};

const emptyDraft = (weekday = 0): Draft => ({
  id: null,
  weekday,
  starts_at: "16:00",
  ends_at: "18:00",
  kind: "academy",
  title: "",
  is_recurring: true,
  specific_date: "",
});

/** دقائق من منتصف الليل — عشان نجمّع الإجماليات */
const toMin = (t: string) => {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + m;
};

const fmtDur = (mins: number) => {
  if (mins < 60) return `${mins} دقيقة`;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return m === 0 ? `${h} ساعة` : `${h} ساعة و${m} دقيقة`;
};

export default function TeacherSchedulePage({ params }: { params: Promise<{ id: string }> }) {
  const [teacherId, setTeacherId] = useState<number | null>(null);
  const [teacher, setTeacher] = useState<Teacher | null>(null);
  const [blocks, setBlocks] = useState<TeacherScheduleBlock[]>([]);
  const [lessons, setLessons] = useState<Lesson[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [draft, setDraft] = useState<Draft>(emptyDraft());
  const [saving, setSaving] = useState(false);

  const { toast, confirm } = useUI();

  useEffect(() => {
    params.then((p) => setTeacherId(Number(p.id)));
  }, [params]);

  const load = useCallback(async () => {
    if (!teacherId) return;
    setLoading(true);
    setError(null);
    try {
      const [t, av, ls] = await Promise.all([
        getTeacher(teacherId),
        getTeacherAvailability(teacherId),
        getTeacherLessons(teacherId),
      ]);
      setTeacher(t);
      setBlocks(av.blocks);
      setLessons(ls.data ?? []);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل الجدول");
    } finally {
      setLoading(false);
    }
  }, [teacherId]);

  useEffect(() => {
    load();
  }, [load]);

  /** الـ blocks مجمّعة حسب اليوم + الإجماليات */
  const byDay = useMemo(() => {
    const map = new Map<number, TeacherScheduleBlock[]>();
    for (const d of DAY_ORDER) map.set(d, []);
    for (const b of blocks) {
      if (!map.has(b.weekday)) map.set(b.weekday, []);
      map.get(b.weekday)!.push(b);
    }
    for (const [, list] of map) list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));

    let busyMinutes = 0;
    for (const [, list] of map) {
      for (const b of list) {
        if (b.kind === "leave") continue; // الإجازة مش وقت شغل
        const end = toMin(b.ends_at);
        const start = toMin(b.starts_at);
        busyMinutes += end > start ? end - start : end + 1440 - start;
      }
    }

    return { map, busyMinutes };
  }, [blocks]);

  /** إحصاءً: كام يوم عليه التزامات */
  const activeDays = useMemo(
    () => DAY_ORDER.filter((d) => (byDay.map.get(d)?.length ?? 0) > 0),
    [byDay],
  );

  const upcomingLessons = useMemo(
    () =>
      lessons
        .filter((l) => new Date(l.scheduled_start_at) >= new Date())
        .filter((l) => l.status !== "cancelled")
        .sort(
          (a, b) =>
            new Date(a.scheduled_start_at).getTime() -
            new Date(b.scheduled_start_at).getTime(),
        ),
    [lessons],
  );

  function openAdd(weekday = 0) {
    setDraft(emptyDraft(weekday));
    setShowForm(true);
  }

  function openEdit(b: TeacherScheduleBlock) {
    setDraft({
      id: b.id,
      weekday: b.weekday,
      starts_at: b.starts_at.slice(0, 5),
      ends_at: b.ends_at.slice(0, 5),
      kind: b.kind,
      title: b.title ?? "",
      is_recurring: b.is_recurring,
      specific_date: b.specific_date ?? "",
    });
    setShowForm(true);
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!teacherId) return;

    setSaving(true);
    try {
      const payload = {
        weekday: draft.weekday,
        starts_at: draft.starts_at,
        ends_at: draft.ends_at,
        kind: draft.kind,
        title: draft.title.trim() || undefined,
        is_recurring: draft.is_recurring,
        specific_date: draft.is_recurring ? undefined : draft.specific_date || undefined,
      };

      if (draft.id) {
        await updateScheduleBlock(teacherId, draft.id, payload);
        toast.success("تم تعديل الفترة", `${WEEKDAY_LABELS[draft.weekday]} ${payload.starts_at} - ${payload.ends_at}`);
      } else {
        await createScheduleBlock(teacherId, payload);
        toast.success("تمت إضافة الفترة", `${WEEKDAY_LABELS[draft.weekday]} ${payload.starts_at} - ${payload.ends_at}`);
      }

      setShowForm(false);
      load();
    } catch (err) {
      toast.error("فشل الحفظ", err instanceof Error ? err.message : undefined);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(b: TeacherScheduleBlock) {
    if (!teacherId) return;

    const ok = await confirm({
      title: "حذف الفترة",
      message: `متأكد إنك عايز تحذف «${WEEKDAY_LABELS[b.weekday]} من ${b.starts_at} إلى ${b.ends_at}»؟\n\n${b.kind === "leave" ? "الإجازة هتتشال من الجدول." : "الوقت ده هيبقى متاح تاني في الحجز."}`,
      confirmLabel: "احذف",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });
    if (!ok) return;

    try {
      await deleteScheduleBlock(teacherId, b.id);
      toast.success("تم حذف الفترة");
      load();
    } catch (err) {
      toast.error("فشل الحذف", err instanceof Error ? err.message : undefined);
    }
  }

  if (!teacherId) return null;

  return (
    <div>
      {/* الهيدر */}
      <div className="mb-5 flex flex-wrap items-start justify-between gap-3">
        <div>
          <Link
            href="/teachers"
            className="mb-1 inline-block text-xs text-slate-500 hover:text-slate-700"
          >
            ← رجوع للمعلمين
          </Link>
          <h1 className="text-lg font-semibold text-slate-800">
            جدول {teacher?.display_name ?? "المعلم"}
          </h1>
          <p className="text-sm text-slate-500">
            {teacher?.specialization ?? "—"} · سجّل كل مواعيدك — الأكاديمية والشغل الخارجي
          </p>
        </div>
        <button
          onClick={() => openAdd()}
          className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700"
        >
          + إضافة وقت مشغول
        </button>
      </div>

      {error && (
        <div className="mb-4 rounded-lg bg-red-50 px-4 py-2.5 text-sm text-red-700">
          {error}
        </div>
      )}

      {/* تنبيه الجدول الفاضي */}
      {!loading && blocks.length === 0 && (
        <div className="mb-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
          <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700">
            <IconAlert size={16} />
          </span>
          <div>
            <p className="text-sm font-semibold text-amber-900">جدولك فاضي</p>
            <p className="mt-0.5 text-sm leading-relaxed text-amber-800">
              لازم تسجّل مواعيدك قبل ما يتبعت لك طلاب جدد. أي وقت مش مسجّل بيتحسب
              فاضي — بما فيه اللي مش هتكون فيه.
            </p>
          </div>
        </div>
      )}

      {/* إحصائيات */}
      {!loading && blocks.length > 0 && (
        <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
          <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
            <p className="text-xs text-slate-300">إجمالي المواعيد</p>
            <p className="mt-1 text-xl font-bold">{blocks.length.toLocaleString("ar-EG")}</p>
          </div>
          <div className="rounded-xl bg-slate-50 p-4 text-center ring-1 ring-slate-200">
            <p className="text-xs text-slate-500">أيام مشغولة</p>
            <p className="mt-1 text-xl font-bold text-slate-800">
              {activeDays.length.toLocaleString("ar-EG")}
            </p>
          </div>
          <div className="rounded-xl bg-slate-50 p-4 text-center ring-1 ring-slate-200">
            <p className="text-xs text-slate-500">ساعات مشغولة / الأسبوع</p>
            <p className="mt-1 text-xl font-bold text-slate-800">
              {(byDay.busyMinutes / 60).toLocaleString("ar-EG", { maximumFractionDigits: 1 })}
            </p>
          </div>
          <div className="rounded-xl bg-emerald-50 p-4 text-center">
            <p className="text-xs text-emerald-600">حصص قادمة في الأكاديمية</p>
            <p className="mt-1 text-xl font-bold text-emerald-800">
              {upcomingLessons.length.toLocaleString("ar-EG")}
            </p>
          </div>
        </div>
      )}

      {/* الجدول الأسبوعي */}
      {!loading && (
        <div className="mb-5 overflow-x-auto rounded-xl border border-slate-200 bg-white">
          <div className="grid min-w-[820px] grid-cols-7 divide-x divide-x-reverse divide-slate-100">
            {DAY_ORDER.map((day) => {
              const list = byDay.map.get(day) ?? [];
              const dayLessons = upcomingLessons.filter(
                (l) => new Date(l.scheduled_start_at).getDay() === day,
              );

              return (
                <div key={day} className="flex min-h-[240px] flex-col">
                  {/* رأس اليوم */}
                  <div className="border-b border-slate-100 bg-slate-50 px-2 py-2.5 text-center">
                    <p
                      className={`text-xs font-semibold ${list.length ? "text-slate-800" : "text-slate-400"}`}
                    >
                      {WEEKDAY_LABELS[day]}
                    </p>
                    <button
                      onClick={() => openAdd(day)}
                      className="mt-1 rounded-md px-1.5 py-0.5 text-[11px] text-slate-400 transition hover:bg-slate-200 hover:text-slate-700"
                    >
                      + إضافة
                    </button>
                  </div>

                  {/* المواعيد */}
                  <div className="flex-1 space-y-1.5 p-2">
                    {list.length === 0 ? (
                      <p className="py-6 text-center text-[11px] text-slate-300">فاضي</p>
                    ) : (
                      list.map((b) => (
                        <div
                          key={b.id}
                          className={`group rounded-lg px-2 py-1.5 ring-1 transition ${KIND_STYLE[b.kind].chip}`}
                        >
                          <div className="flex items-start justify-between gap-1">
                            <span
                              dir="ltr"
                              className="text-[11px] font-semibold tabular-nums"
                            >
                              {b.starts_at.slice(0, 5)} – {b.ends_at.slice(0, 5)}
                            </span>
                            <div className="flex shrink-0 gap-0.5 opacity-0 transition group-hover:opacity-100">
                              <button
                                onClick={() => openEdit(b)}
                                aria-label="تعديل"
                                className="rounded p-0.5 hover:bg-white/60"
                              >
                                <IconPencil size={11} />
                              </button>
                              <button
                                onClick={() => handleDelete(b)}
                                aria-label="حذف"
                                className="rounded p-0.5 text-red-600 hover:bg-white/60"
                              >
                                <IconTrash size={11} />
                              </button>
                            </div>
                          </div>

                          <p className="mt-0.5 truncate text-[11px] font-medium">
                            {SCHEDULE_KIND_LABEL[b.kind]}
                            {b.title ? ` · ${b.title}` : ""}
                          </p>

                          {!b.is_recurring && (
                            <p className="mt-0.5 text-[10px] opacity-70">
                              مرة واحدة · {b.specific_date}
                            </p>
                          )}
                        </div>
                      ))
                    )}

                    {/* الحصص المحجوزة في الأكاديمية */}
                    {dayLessons.length > 0 && (
                      <p className="pt-1 text-center text-[10px] text-emerald-600">
                        + {dayLessons.length} حصة محجوزة
                      </p>
                    )}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* ملخص الفترات */}
      {!loading && blocks.length > 0 && (
        <div className="mb-5 rounded-xl border border-slate-200 bg-white p-4">
          <h2 className="mb-3 text-sm font-semibold text-slate-800">
            الساعات المتاحة أسبوعياً
          </h2>
          <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {activeDays.map((day) => {
              const list = byDay.map.get(day) ?? [];
              const working = list.filter((b) => b.kind !== "leave");
              const leaves = list.length - working.length;

              // ناقص: كل الفترات اللي عليها شغل (مش الإجازات)
              const sorted = [...working].sort(
                (a, b) => toMin(a.starts_at) - toMin(b.starts_at),
              );
              const first = sorted[0];
              const last = sorted[sorted.length - 1];

              return (
                <div
                  key={day}
                  className="rounded-lg bg-slate-50 px-3 py-2 ring-1 ring-slate-200"
                >
                  <p className="mb-1 text-xs font-semibold text-slate-700">
                    {WEEKDAY_LABELS[day]}
                  </p>

                  {working.length === 0 ? (
                    <p className="text-xs text-amber-700">
                      إجازة — مفيش شغل مسجّل
                    </p>
                  ) : (
                    <>
                      <p
                        dir="ltr"
                        className="text-left text-xs font-medium tabular-nums text-slate-700"
                      >
                        {first.starts_at.slice(0, 5)} → {last.ends_at.slice(0, 5)}
                      </p>
                      <p className="mt-1 text-[11px] text-slate-500">
                        {working.length} موعد · مشغول{" "}
                        {fmtDur(
                          working.reduce((sum, b) => {
                            const e = toMin(b.ends_at);
                            const s = toMin(b.starts_at);
                            return sum + (e > s ? e - s : e + 1440 - s);
                          }, 0),
                        )}
                        {leaves > 0 && ` · إجازة ${leaves}`}
                      </p>
                    </>
                  )}
                </div>
              );
            })}
          </div>
        </div>
      )}

      {/* الحصص القادمة */}
      {!loading && upcomingLessons.length > 0 && (
        <div className="mb-5 rounded-xl border border-slate-200 bg-white">
          <h2 className="border-b border-slate-100 px-4 py-3 text-sm font-semibold text-slate-800">
            الحصص القادمة في الأكاديمية ({upcomingLessons.length})
          </h2>
          <ul className="divide-y divide-slate-100">
            {upcomingLessons.slice(0, 8).map((l) => (
              <li key={l.id} className="flex items-center gap-3 px-4 py-2.5">
                <span className="size-2 shrink-0 rounded-full bg-emerald-500" />
                <span className="text-sm text-slate-700">{l.student?.full_name ?? "—"}</span>
                <span className="text-xs text-slate-500">{l.program?.name ?? "—"}</span>
                <span className="ms-auto text-xs tabular-nums text-slate-500">
                  {new Date(l.scheduled_start_at).toLocaleString("ar-EG", {
                    weekday: "short",
                    day: "numeric",
                    month: "short",
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}

      {/* نموذج إضافة/تعديل */}
      {showForm && (
        <Modal
          open
          onClose={() => setShowForm(false)}
          title={draft.id ? "تعديل الفترة" : "إضافة وقت مشغول"}
          icon={<IconPencil size={16} />}
          footer={
            <>
              <button
                type="button"
                onClick={() => setShowForm(false)}
                className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
              >
                إلغاء
              </button>
              <button
                type="submit"
                form="schedule-block-form"
                disabled={saving}
                className="inline-flex items-center gap-1.5 rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-60"
              >
                <IconCheck size={14} />
                {saving ? "جارٍ الحفظ..." : "حفظ"}
              </button>
            </>
          }
        >
          <form id="schedule-block-form" onSubmit={handleSubmit} className="space-y-3">
            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">اليوم</label>
              <select
                value={draft.weekday}
                onChange={(e) => setDraft({ ...draft, weekday: Number(e.target.value) })}
                className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
              >
                {DAY_ORDER.map((d) => (
                  <option key={d} value={d}>
                    {WEEKDAY_LABELS[d]}
                  </option>
                ))}
              </select>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">من</label>
                <input
                  type="time"
                  required
                  value={draft.starts_at}
                  onChange={(e) => setDraft({ ...draft, starts_at: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
              </div>
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">إلى</label>
                <input
                  type="time"
                  required
                  value={draft.ends_at}
                  onChange={(e) => setDraft({ ...draft, ends_at: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">النوع</label>
              <div className="grid grid-cols-2 gap-2">
                {KIND_OPTIONS.map((k) => (
                  <button
                    key={k}
                    type="button"
                    onClick={() => setDraft({ ...draft, kind: k })}
                    className={`rounded-lg px-3 py-2 text-sm transition ${
                      draft.kind === k
                        ? `${KIND_STYLE[k].chip} ring-2`
                        : "bg-slate-50 text-slate-600 ring-1 ring-slate-200 hover:bg-slate-100"
                    }`}
                  >
                    {SCHEDULE_KIND_LABEL[k]}
                  </button>
                ))}
              </div>
            </div>

            <div>
              <label className="mb-1.5 block text-xs font-medium text-slate-700">
                العنوان (اختياري)
              </label>
              <input
                value={draft.title}
                onChange={(e) => setDraft({ ...draft, title: e.target.value })}
                placeholder={draft.kind === "external" ? "مثال: أكاديمية النور" : "مثال: حصة تحفيظ"}
                className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
              />
            </div>

            <label className="flex items-center gap-2 rounded-lg bg-slate-50 px-3 py-2.5 text-sm text-slate-700">
              <input
                type="checkbox"
                checked={draft.is_recurring}
                onChange={(e) => setDraft({ ...draft, is_recurring: e.target.checked })}
                className="rounded"
              />
              يتكرر كل أسبوع
            </label>

            {!draft.is_recurring && (
              <div>
                <label className="mb-1.5 block text-xs font-medium text-slate-700">
                  التاريخ المحدّد *
                </label>
                <input
                  type="date"
                  required
                  value={draft.specific_date}
                  onChange={(e) => setDraft({ ...draft, specific_date: e.target.value })}
                  className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
                />
              </div>
            )}
          </form>
        </Modal>
      )}
    </div>
  );
}
