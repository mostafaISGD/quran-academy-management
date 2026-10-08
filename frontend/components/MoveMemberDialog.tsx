"use client";

import { useEffect, useState } from "react";
import {
  getGroupMoveOptions,
  moveGroupMember,
  type Blocker,
  type MoveTarget,
  type GroupMemberRow,
} from "@/lib/api";
import { date, num } from "@/lib/format";

/**
 * ⭐ شاشة **نقل طالب** لمجموعة تانية.
 *
 * ⭐ ليه مش dropdown بسيط؟
 *
 * النقل **مسدود** في حالتين، والأدمن لازم يشوف السبب قبل ما
 * يختار. فلو عملنا list عادي، الأدمن هيختار مجموعة حلوة
 * ويضغط «نقل» ويطلعله رسالة مفهومةش. هنا:
 *
 *  ① القفل على **الطالب نفسه** بيظهر مرة واحدة في الأول —
 *     مش متكرر في كل سطر.
 *  ② كل مجموعة بتقول **ليه** مش مسموحة (مليانة / الطالب مش
 *     قابل للنقل).
 *  ③ المسموحة بتظهر **فوق** الممنوعة.
 *
 * ⭐ الفكرة: الشاشة تقود المستخدم صح، مش تسيبه يغلط.
 */
export default function MoveMemberDialog({
  groupId,
  groupName,
  member,
  onClose,
  onMoved,
}: {
  groupId: number;
  groupName: string;
  member: GroupMemberRow;
  onClose: () => void;
  onMoved: () => void | Promise<void>;
}) {
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [targets, setTargets] = useState<MoveTarget[]>([]);
  const [studentBlockers, setStudentBlockers] = useState<Blocker[]>([]);
  const [picked, setPicked] = useState<number | null>(null);
  const [reason, setReason] = useState("");

  useEffect(() => {
    let alive = true;

    (async () => {
      try {
        const r = await getGroupMoveOptions(groupId, member.id);
        if (!alive) return;
        setTargets(r.data);
        setStudentBlockers(r.blockers);
        setError(null);
      } catch (e) {
        if (alive) setError(e instanceof Error ? e.message : "تعذر تحميل المجموعات");
      } finally {
        if (alive) setLoading(false);
      }
    })();

    return () => {
      alive = false;
    };
  }, [groupId, member.id]);

  /** ⭐ المسموحة فوق والممنوعة تحت — الترتيب بيقول القرار قبل ما تقراه */
  const allowed = targets.filter((t) => t.can_move);
  const denied = targets.filter((t) => !t.can_move);

  async function submit() {
    if (!picked) return;

    setSubmitting(true);
    setError(null);
    try {
      await moveGroupMember(groupId, member.id, picked, reason.trim() || undefined);
      await onMoved();
      onClose();
    } catch (e) {
      setError(e instanceof Error ? e.message : "فشل النقل");
    } finally {
      setSubmitting(false);
    }
  }

  const studentName = member.student?.name ?? "الطالب";

  return (
    <div
      className="fixed inset-0 z-[110] flex items-center justify-center bg-slate-900/50 p-4"
      onClick={onClose}
    >
      <div
        onClick={(e) => e.stopPropagation()}
        className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white shadow-2xl"
      >
        {/* ===== الترويسة ===== */}
        <div className="flex items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
          <div>
            <h2 className="text-base font-semibold text-slate-800">نقل طالب لمجموعة تانية</h2>
            <p className="mt-0.5 text-xs text-slate-500">
              {studentName} · من «{groupName}»
            </p>
          </div>
          <button
            onClick={onClose}
            aria-label="اقفل"
            className="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-100"
          >
            ✕
          </button>
        </div>

        <div className="space-y-4 p-5">
          {loading && (
            <div className="space-y-2">
              {[0, 1, 2].map((i) => (
                <div key={i} className="h-12 animate-pulse rounded-lg bg-slate-100" />
              ))}
            </div>
          )}

          {!loading && error && !targets.length && (
            <p className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{error}</p>
          )}

          {/* ============================================================
              ⭐ القفل على الطالب نفسه — مرة واحدة، فوق
              ============================================================ */}
          {!loading && studentBlockers.length > 0 && (
            <div className="rounded-xl border border-amber-300 bg-amber-50 p-3.5">
              <p className="flex items-center gap-2 text-sm font-medium text-amber-900">
                <span>🔒</span>
                مش هينفع ينقل دلوقتي
              </p>
              <ul className="mt-2 space-y-1.5">
                {studentBlockers.map((b, i) => (
                  <li key={i} className="flex gap-2 text-xs leading-relaxed text-amber-800">
                    <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-amber-500" />
                    {b.message}
                  </li>
                ))}
              </ul>
              <p className="mt-2.5 text-[11px] text-amber-700">
                سيب الاشتراك يخلص والفواتير تتسدّد، وبعدين ارجع نقله.
              </p>
            </div>
          )}

          {/* ============================================================
              القوائم
              ============================================================ */}
          {!loading && targets.length === 0 && studentBlockers.length === 0 && (
            <p className="py-6 text-center text-xs text-slate-400">
              مفيش مجموعات تانية تنفع
            </p>
          )}

          {!loading && allowed.length > 0 && (
            <div>
              <h3 className="mb-2 text-[11px] font-medium text-emerald-700">
                متاحة ({num(allowed.length, 0)})
              </h3>
              <div className="space-y-1.5">
                {allowed.map((t) => (
                  <TargetRow
                    key={t.id}
                    target={t}
                    picked={picked === t.id}
                    onPick={() => setPicked(t.id)}
                  />
                ))}
              </div>
            </div>
          )}

          {!loading && denied.length > 0 && (
            <div>
              <h3 className="mb-2 text-[11px] font-medium text-slate-400">
                مش متاحة ({num(denied.length, 0)})
              </h3>
              <div className="space-y-1.5">
                {denied.map((t) => (
                  <TargetRow key={t.id} target={t} picked={false} onPick={() => {}} />
                ))}
              </div>
            </div>
          )}

          {/* ⭐ سبب النقل — بيتبعت كـ notes على العضوية الجديدة */}
          {!loading && picked !== null && (
            <div>
              <label className="mb-1 block text-[11px] font-medium text-slate-600">
                سبب النقل
                <span className="block text-[11px] font-normal text-slate-400">
                  عشان يبان في سجل العضوية بعدين
                </span>
              </label>
              <textarea
                value={reason}
                onChange={(e) => setReason(e.target.value)}
                rows={2}
                placeholder="مثال: ماشي معLevel التاني"
                className="w-full resize-none rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-800"
              />
            </div>
          )}

          {/* ============================================================
              ⭐ التوضيح: السطر القديم بيفضل
              ============================================================ */}
          {!loading && picked !== null && (
            <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] leading-relaxed text-slate-500">
              السطر القديم في «{groupName}» بيفضل مسجّل بحالة «خرج» — عشان يبان إنه
              كان هنا من {member.joined_at ? date(member.joined_at) : "فتره"}، ومفيش
              تاريخ يضيع.
            </p>
          )}

          {error && targets.length > 0 && (
            <p className="rounded-lg bg-rose-50 px-3 py-2 text-xs text-rose-700">{error}</p>
          )}
        </div>

        {/* ===== الأزرار ===== */}
        <div className="flex justify-end gap-2 border-t border-slate-100 px-5 py-3.5">
          <button
            onClick={onClose}
            className="rounded-lg border border-slate-200 px-4 py-2 text-sm text-slate-600 transition hover:bg-slate-50"
          >
            إلغاء
          </button>
          <button
            onClick={submit}
            disabled={picked === null || submitting || studentBlockers.length > 0}
            className="rounded-lg bg-slate-800 px-4 py-2 text-sm font-medium text-white transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-40"
          >
            {submitting ? "جاري النقل..." : picked === null ? "اختار مجموعة" : "انقل"}
          </button>
        </div>
      </div>
    </div>
  );
}

/** ⭐ سطر مجموعة واحدة — متاحة أو مش متاحة مع سببها */
function TargetRow({
  target,
  picked,
  onPick,
}: {
  target: MoveTarget;
  picked: boolean;
  onPick: () => void;
}) {
  const { occupancy } = target;
  const enabled = target.can_move;

  return (
    <button
      onClick={onPick}
      disabled={!enabled}
      className={`w-full rounded-xl border px-3.5 py-2.5 text-start transition ${
        picked
          ? "border-slate-800 bg-slate-50 ring-1 ring-slate-800"
          : enabled
            ? "border-slate-200 bg-white hover:border-slate-400 hover:bg-slate-50"
            : "cursor-not-allowed border-slate-100 bg-slate-50/60"
      }`}
    >
      <div className="flex items-center justify-between gap-3">
        <span
          className={`text-sm ${enabled ? "font-medium text-slate-800" : "text-slate-400"}`}
        >
          {target.name}
        </span>
        <span className="shrink-0 text-[11px] text-slate-500">
          {/* ⭐ `null` سعة = «مفيش حد» — مش «٠ مقعد» */}
          {occupancy.capacity === null
            ? `${num(occupancy.members, 0)} طالب · مفيش حد أقصى`
            : `${num(occupancy.members, 0)} من ${num(occupancy.capacity, 0)}`}
        </span>
      </div>

      {target.blockers.length > 0 && (
        <ul className="mt-1 space-y-0.5">
          {target.blockers.map((b, i) => (
            <li key={i} className="text-[11px] leading-relaxed text-slate-400">
              {b.message}
            </li>
          ))}
        </ul>
      )}
    </button>
  );
}