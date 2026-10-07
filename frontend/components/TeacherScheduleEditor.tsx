"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import {
  getTeacherAvailability,
  createScheduleBlock,
  updateScheduleBlock,
  deleteScheduleBlock,
  WEEKDAY_LABELS,
  SCHEDULE_KIND_LABEL,
  type TeacherScheduleBlock,
  type ScheduleKind,
  type Lesson,
} from "@/lib/api";
import { dateTime, num } from "@/lib/format";
import { Modal, useUI, IconTrash, IconPencil, IconCheck, IconAlert } from "@/components/ui";

export const DAY_ORDER = [0, 1, 2, 3, 4, 5, 6];

/** ألوان كل نوع التزام */
const KIND_STYLE: Record<ScheduleKind, { chip: string }> = {
  academy: { chip: "bg-emerald-50 text-emerald-800 ring-emerald-200" },
  external: { chip: "bg-violet-50 text-violet-800 ring-violet-200" },
  leave: { chip: "bg-amber-50 text-amber-800 ring-amber-200" },
  personal: { chip: "bg-slate-100 text-slate-700 ring-slate-200" },
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

/** دقائق من منتصف الليل */
const toMin = (t: string) => {
  const [h, m] = t.split(":").map(Number);
  return h * 60 + m;
};

const spanOf = (b: TeacherScheduleBlock) => {
  const e = toMin(b.ends_at);
  const s = toMin(b.starts_at);
  return e > s ? e - s : e + 1440 - s;
};

/** ⭐ مدة بالساعات: `١٢ ساعة` / `ساعة و٣٠ دقيقة` */
const fmtDur = (mins: number) => {
  if (mins < 60) return `${num(mins, 0)} دقيقة`;
  const h = Math.floor(mins / 60);
  const m = mins % 60;
  return m === 0 ? `${num(h, 0)} ساعة` : `${num(h, 0)} ساعة و${num(m, 0)} دقيقة`;
};

type Props = {
  teacherId: number;
  /** نص التنبيه لما يكون الجدول فاضي */
  emptyTitle?: string;
  emptyHint?: string;
  /** الحصص القادمة في الأكاديمية */
  lessons?: Lesson[];
  loadingLessons?: boolean;
  onAddClick?: () => void;
  headerRight?: React.ReactNode;
};

/**
 * محرّر جدول commitments المعلم — مشترك بين:
 *  - /teachers/[id]/schedule (الأدمن بيعدّل نيابة عن المعلم)
 *  - /my-schedule (المعلم بيعدّل جدوله بنفسه)
 */
export default function TeacherScheduleEditor({
  teacherId,
  emptyTitle = "جدولك فاضي",
  emptyHint = "لازم تسجّل مواعيدك قبل ما يتبعت لك طلاب جدد. أي وقت مش مسجّل بيتحسب فاضي — بما فيه اللي مش هتكون فيه.",
  lessons = [],
  loadingLessons = false,
  onAddClick,
  headerRight,
}: Props) {
  const [blocks, setBlocks] = useState<TeacherScheduleBlock[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [showForm, setShowForm] = useState(false);
  const [draft, setDraft] = useState<Draft>(emptyDraft());
  const [saving, setSaving] = useState(false);

  const { toast, confirm } = useUI();

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const r = await getTeacherAvailability(teacherId);
      setBlocks(r.blocks);
    } catch (err) {
      setError(err instanceof Error ? err.message : "تعذر تحميل الجدول");
    } finally {
      setLoading(false);
    }
  }, [teacherId]);

  useEffect(() => {
    load();
  }, [load]);

  /** الـ blocks مجمّعة حسب اليوم + إجمالي الساعات المشغولة */
  const byDay = useMemo(() => {
    const map = new Map<number, TeacherScheduleBlock[]>();
    for (const d of DAY_ORDER) map.set(d, []);
    for (const b of blocks) {
      if (!map.has(b.weekday)) map.set(b.weekday, []);
      map.get(b.weekday)!.push(b);
    }
    for (const [, list] of map) list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));

    const busy = blocks.filter((b) => b.kind !== "leave").reduce((s, b) => s + spanOf(b), 0);
    return { map, busy };
  }, [blocks]);

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
    onAddClick?.();
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
        toast.success("تم تعديل الفترة", `${WEEKDAY_LABELS[draft.weekday]} ${draft.starts_at} - ${draft.ends_at}`);
      } else {
        await createScheduleBlock(teacherId, payload);
        toast.success("تمت إضافة الفترة", `${WEEKDAY_LABELS[draft.weekday]} ${draft.starts_at} - ${draft.ends_at}`);
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

  return (
    <div>
      {headerRight}

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
            <p className="text-sm font-semibold text-amber-900">{emptyTitle}</p>
            <p className="mt-0.5 text-sm leading-relaxed text-amber-800">{emptyHint}</p>
          </div>
        </div>
      )}

      {/* إحصائيات */}
      {!loading && blocks.length > 0 && (
        <div className="mb-5 grid grid-cols-2 gap-3 md:grid-cols-4">
          <div className="rounded-xl bg-slate-800 p-4 text-center text-white">
            <p className="text-xs text-slate-300">إجمالي المواعيد</p>
            <p className="mt-1 text-xl font-bold">{num(blocks.length, 0)}</p>
          </div>
          <div className="rounded-xl bg-slate-50 p-4 text-center ring-1 ring-slate-200">
            <p className="text-xs text-slate-500">أيام مشغولة</p>
            <p className="mt-1 text-xl font-bold text-slate-800">
              {num(activeDays.length, 0)}
            </p>
          </div>
          <div className="rounded-xl bg-slate-50 p-4 text-center ring-1 ring-slate-200">
            <p className="text-xs text-slate-500">ساعات مشغولة / الأسبوع</p>
            {/* ⭐ رقم عشري واحد — الساعات ممكن تبقى ٢٫٥ */}
            <p className="mt-1 text-xl font-bold text-slate-800">
              {num(byDay.busy / 60, 1)}
            </p>
          </div>
          <div className="rounded-xl bg-emerald-50 p-4 text-center">
            <p className="text-xs text-emerald-600">حصص قادمة في الأكاديمية</p>
            <p className="mt-1 text-xl font-bold text-emerald-800">
              {loadingLessons ? "…" : num(upcomingLessons.length, 0)}
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
              const sorted = [...working].sort((a, b) => toMin(a.starts_at) - toMin(b.starts_at));
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
                    <p className="text-xs text-amber-700">إجازة — مفيش شغل مسجّل</p>
                  ) : (
                    <>
                      <p
                        dir="ltr"
                        className="text-end text-xs font-medium tabular-nums text-slate-700"
                      >
                        {first.starts_at.slice(0, 5)} → {last.ends_at.slice(0, 5)}
                      </p>
                      <p className="mt-1 text-[11px] text-slate-500">
                        {working.length} موعد · مشغول {fmtDur(working.reduce((s, b) => s + spanOf(b), 0))}
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
                {/* ⭐ `dateTime` — «7 أكتوبر، الأربعاء - 7:05 م».
                    القديم كان بيطلع ترتيب الحقول بتاعة الـ locale
                    («الأحد، 7 أكتوبر، 07:05 م») وفيه صفر بادي. */}
                <span className="ms-auto text-xs tabular-nums text-slate-500">
                  {dateTime(l.scheduled_start_at)}
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
