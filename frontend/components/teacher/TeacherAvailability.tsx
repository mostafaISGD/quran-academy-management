"use client";

import { useCallback, useState } from "react";

import {
  createMyAvailability,
  deleteMyAvailability,
  getMyAvailability,
  SCHEDULE_KIND_LABEL,
  WEEKDAY_LABELS,
  type ScheduleKind,
  type TeacherAvailability as TeacherAvailabilityResponse,
  type TeacherScheduleBlock,
} from "@/lib/api";
import { num } from "@/lib/format";
import { usePage } from "@/lib/use-page";
import { IconTrash, useUI } from "@/components/ui";

const KINDS: ScheduleKind[] = ["academy", "external", "leave", "personal"];

const KIND_CLASS: Record<ScheduleKind, string> = {
  academy: "bg-emerald-50 text-emerald-800 ring-emerald-200",
  external: "bg-violet-50 text-violet-800 ring-violet-200",
  leave: "bg-amber-50 text-amber-800 ring-amber-200",
  personal: "bg-slate-100 text-slate-700 ring-slate-200",
};

/**
 * ⭐⭐⭐ **تبويب «مواعيدي» — توفر المعلم.**
 *
 * ⭐ بيستهلك `/teacher/availability` (PR 3.10) — الهوية من
 * الجلسة، مفيش `teacherId` في المسار، فالمعلم مش قادر يعدّل
 * جدول غيره (السيرفر بيرد 403).
 */
export default function TeacherAvailability({ onChanged }: { onChanged: () => void }) {
  const { toast, confirm } = useUI();

  const fetcher = useCallback(() => getMyAvailability(), []);
  const { data, loading, error, reload } = usePage<TeacherAvailabilityResponse | null>(
    "teacher-availability",
    fetcher,
    { initial: null },
  );

  const [weekday, setWeekday] = useState(1);
  const [startsAt, setStartsAt] = useState("16:00");
  const [endsAt, setEndsAt] = useState("18:00");
  const [kind, setKind] = useState<ScheduleKind>("academy");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  async function handleAdd(e: React.FormEvent) {
    e.preventDefault();

    if (startsAt === endsAt) {
      setFormError("وقت النهاية لازم يكون مختلف عن البداية");
      return;
    }

    setFormError(null);
    setSaving(true);

    try {
      await createMyAvailability({
        weekday,
        starts_at: startsAt,
        ends_at: endsAt,
        kind,
      });
      toast.success("تمت إضافة الفترة", `${WEEKDAY_LABELS[weekday]} ${startsAt} – ${endsAt}`);
      reload();
      onChanged();
    } catch (err) {
      toast.apiError("فشل حفظ الفترة", err);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(block: TeacherScheduleBlock) {
    const ok = await confirm({
      title: "حذف الفترة",
      message: `متأكد إنك عايز تحذف «${WEEKDAY_LABELS[block.weekday]} ${block.starts_at.slice(0, 5)} – ${block.ends_at.slice(0, 5)}»؟`,
      confirmLabel: "احذف",
      tone: "danger",
      icon: <IconTrash size={16} />,
    });

    if (!ok) return;

    try {
      await deleteMyAvailability(block.id);
      toast.success("تم حذف الفترة");
      reload();
      onChanged();
    } catch (err) {
      toast.apiError("فشل الحذف", err);
    }
  }

  const blocks = data?.blocks ?? [];

  const byDay = new Map<number, TeacherScheduleBlock[]>();
  for (const block of blocks) {
    const list = byDay.get(block.weekday) ?? [];
    list.push(block);
    byDay.set(block.weekday, list);
  }
  for (const list of byDay.values()) {
    list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
  }

  return (
    <div className="space-y-5">
      {/* ===== إضافة فترة ===== */}
      <form
        onSubmit={handleAdd}
        className="rounded-xl bg-white p-4 shadow-sm ring-1 ring-slate-200"
      >
        <h2 className="mb-3 text-sm font-semibold text-slate-800">أضف فترة</h2>

        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <div className="col-span-2 sm:col-span-1">
            <label htmlFor="av-weekday" className="mb-1 block text-xs text-slate-600">
              اليوم
            </label>
            <select
              id="av-weekday"
              value={weekday}
              onChange={(e) => setWeekday(Number(e.target.value))}
              className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm"
            >
              {Object.entries(WEEKDAY_LABELS).map(([value, label]) => (
                <option key={value} value={value}>
                  {label}
                </option>
              ))}
            </select>
          </div>

          <div>
            <label htmlFor="av-from" className="mb-1 block text-xs text-slate-600">
              من
            </label>
            <input
              id="av-from"
              type="time"
              value={startsAt}
              onChange={(e) => setStartsAt(e.target.value)}
              className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm tabular-nums"
            />
          </div>

          <div>
            <label htmlFor="av-to" className="mb-1 block text-xs text-slate-600">
              إلى
            </label>
            <input
              id="av-to"
              type="time"
              value={endsAt}
              onChange={(e) => setEndsAt(e.target.value)}
              className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm tabular-nums"
            />
          </div>

          <div>
            <label htmlFor="av-kind" className="mb-1 block text-xs text-slate-600">
              النوع
            </label>
            <select
              id="av-kind"
              value={kind}
              onChange={(e) => setKind(e.target.value as ScheduleKind)}
              className="min-h-11 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm"
            >
              {KINDS.map((k) => (
                <option key={k} value={k}>
                  {SCHEDULE_KIND_LABEL[k]}
                </option>
              ))}
            </select>
          </div>
        </div>

        {formError && (
          <p role="alert" className="mt-2 text-xs text-red-600">
            {formError}
          </p>
        )}

        <button
          type="submit"
          disabled={saving}
          className="mt-3 min-h-11 w-full rounded-lg bg-slate-800 px-4 text-sm font-medium text-white transition hover:bg-slate-700 disabled:opacity-60 sm:w-auto"
        >
          {saving ? "…" : "أضف الفترة"}
        </button>
      </form>

      {/* ===== الفترات ===== */}
      {loading && <p className="py-6 text-center text-sm text-slate-500">جاري التحميل…</p>}

      {error && (
        <div role="alert" className="rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200">
          {error}
        </div>
      )}

      {!loading && !error && blocks.length === 0 && (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center">
          <p className="text-sm font-medium text-slate-700">جدولك فاضي</p>
          <p className="mt-1 text-xs leading-relaxed text-slate-500">
            سجّل مواعيدك عشان الإدارة تقدر تجدول لك طلاب. أي وقت مش مسجّل بيتحسب فاضي.
          </p>
        </div>
      )}

      {!loading && blocks.length > 0 && (
        <div className="space-y-4">
          <p className="px-1 text-xs text-slate-500">
            {num(blocks.length, 0)} فترة في الأسبوع
          </p>

          {[...byDay.entries()].map(([day, list]) => (
            <section key={day}>
              <h3 className="mb-2 px-1 text-sm font-semibold text-slate-800">
                {WEEKDAY_LABELS[day]}
              </h3>
              <ul className="space-y-2">
                {list.map((block) => (
                  <li
                    key={block.id}
                    className={`flex items-center justify-between gap-2 rounded-xl px-3 py-2.5 ring-1 ${KIND_CLASS[block.kind]}`}
                  >
                    <div className="min-w-0">
                      <p className="text-sm font-semibold tabular-nums" dir="ltr">
                        {block.starts_at.slice(0, 5)} – {block.ends_at.slice(0, 5)}
                      </p>
                      <p className="truncate text-[11px]">
                        {SCHEDULE_KIND_LABEL[block.kind]}
                        {block.title ? ` · ${block.title}` : ""}
                      </p>
                    </div>

                    <button
                      type="button"
                      onClick={() => void handleDelete(block)}
                      aria-label={`حذف ${WEEKDAY_LABELS[block.weekday]} ${block.starts_at.slice(0, 5)}`}
                      className="flex size-9 shrink-0 items-center justify-center rounded-lg text-red-600 transition hover:bg-white/60 focus-visible:outline-2 focus-visible:outline-offset-2"
                    >
                      <IconTrash size={16} />
                    </button>
                  </li>
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}
    </div>
  );
}
