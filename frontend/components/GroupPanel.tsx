"use client";

import { useEffect, useState } from "react";
import {
  admitFromWaitingList,
  declineFromWaitingList,
  getGroupMembers,
  getGroupWaiting,
  removeGroupMember,
  type GroupMemberRow,
  type GroupOccupancy,
  type GroupRow,
  type WaitingEntry,
} from "@/lib/api";
import { date, num, time } from "@/lib/format";
import { useUI } from "@/components/ui";
import MoveMemberDialog from "@/components/MoveMemberDialog";

/**
 * تفاصيل مجموعة واحدة — الأعضاء والطابور.
 *
 * ⭐ الشاشة فيها **تنبيه في الأول** لو فيه مقعد فاضي وفي حد مستني.
 * ده كل الفكرة: تفتح الشاشة، تشوف «فيه شغل»، تشتغل.
 *
 * ⭐ «ادخل» بيعلّم السطر وخلاص — بيقولك بعدها «لسه مش داخل
 * المجموعة فعليًا». مش بيعمل اشتراك ولا حساب.
 */
export default function GroupPanel({
  group,
  onChanged,
}: {
  group: GroupRow;
  /** ⭐ الصفحة الأم تحدّث الكارت (الأرقام) بعد أي تغيير */
  onChanged: (occupancy: GroupOccupancy) => void;
}) {
  const { toast, confirm, prompt } = useUI();
  const [members, setMembers] = useState<GroupMemberRow[]>([]);
  const [waiting, setWaiting] = useState<WaitingEntry[]>([]);
  const [occupancy, setOccupancy] = useState<GroupOccupancy>(group.occupancy);
  const [busyId, setBusyId] = useState<number | null>(null);

  /** ⭐ الطالب اللي عميل يفتح شاشة نقله — `null` = الشاشة مقفولة */
  const [moving, setMoving] = useState<GroupMemberRow | null>(null);

  /**
   * ⭐ `loading` بيتحسب بمقارنة — مش `useState(true)`.
   *
   * السبب: لو حطينا `setLoading(true)` جوه الـ effect، بنعمل
   * `setState` متزامن جوّه effect — وده بيعمل رندر تلغيم.
   * الأوضح: «هل البيانات المحمّلة بتاعة المجموعة دي؟»
   */
  const [loadedGroupId, setLoadedGroupId] = useState<number | null>(null);
  const loading = loadedGroupId !== group.id;

  /** ⭐ إعادة تحميل بعد أي تغيير — نفس مصدر الأرقام */
  async function load() {
    const [m, w] = await Promise.all([
      getGroupMembers(group.id),
      getGroupWaiting(group.id),
    ]);
    setMembers(m.data);
    setWaiting(w.data);
    setOccupancy(w.occupancy);
    setLoadedGroupId(group.id);
    onChanged(w.occupancy);
  }

  useEffect(() => {
    // ⭐ الإلغاء: لو المستخدم فتح مجموعة تانية بسرعة، الرد المتأخر
    // بتاع الأولى كان هيكتب فوق بيانات التانية. فبنAlive flag.
    let alive = true;

    Promise.all([getGroupMembers(group.id), getGroupWaiting(group.id)])
      .then(([m, w]) => {
        if (!alive) return;
        setMembers(m.data);
        setWaiting(w.data);
        // ⭐ الأرقام من السيرفر — نفس المصدر دايمًا
        setOccupancy(w.occupancy);
        setLoadedGroupId(group.id);
        onChanged(w.occupancy);
      })
      .catch((e) => {
        if (alive) toast.apiError("تعذر تحميل بيانات المجموعة", e);
      });

    return () => {
      alive = false;
    };
    // ⭐ `group.id` بس — `toast` و `onChanged` بيتغيروا كل رندر،
    // ولو حطيناهم في الـ deps الـ effect هيعمل load بلا فايدة.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [group.id]);

  /**
   * ⭐ «ادخل» — الخطوة الأولى بس.
   *
   * ⚠️ السيرفر **مش** بيعمل طالب ولا اشتراك. عشان كده بنعرض
   * `next_step` — عشان الأدمن يفتكر إنه خلص وهو لأ.
   */
  async function admit(entry: WaitingEntry) {
    if (busyId) return;

    setBusyId(entry.id);
    try {
      const r = await admitFromWaitingList(group.id, entry.id);
      toast.success(r.message);

      // ⭐ «ادخل» بقي بيعمل الشغل كله — الطالب بقى عضو + اشتراكه شهري
      toast.info("الخطوة الجاية", "تقدر تشوف الطالب في جدول الطلاب وحصص المجموعة هتتحسب عليه", 9000);

      await load();
    } catch (e) {
      toast.apiError("فشل الدخول", e);
    } finally {
      setBusyId(null);
    }
  }

  async function decline(entry: WaitingEntry) {
    if (busyId) return;

    const reason = await prompt({
      title: "ليه رافض؟",
      label: "السبب",
      placeholder: "مثلاً: مش عايز أونلاين",
      multiline: true,
      rows: 2,
    });
    if (reason === null) return;

    setBusyId(entry.id);
    try {
      const r = await declineFromWaitingList(group.id, entry.id, reason || undefined);
      toast.success(r.message);
      await load();
    } catch (e) {
      toast.apiError("فشل الرفض", e);
    } finally {
      setBusyId(null);
    }
  }

  async function removeMember(member: GroupMemberRow) {
    if (busyId || !member.student) return;

    const ok = await confirm({
      title: "تشيل الطالب؟",
      message: `تشيل ${member.student.name} من المجموعة؟\n\nالسطر مش هيتمسح — هيبقى مسجّل إنه كان هنا.`,
      confirmLabel: "أيوه، اشيله",
      cancelLabel: "لأ",
      tone: "danger",
    });
    if (!ok) return;

    setBusyId(member.id);
    try {
      const r = await removeGroupMember(group.id, member.id);
      toast.success(r.message);
      await load();
    } catch (e) {
      toast.apiError("فشل الشيل", e);
    } finally {
      setBusyId(null);
    }
  }

  const activeMembers = members.filter((m) => m.status === "active");
  const formerMembers = members.filter((m) => m.status === "left");
  const stillWaiting = waiting.filter((e) => e.status === "waiting");

  if (loading) {
    return <div className="h-32 animate-pulse rounded-xl bg-slate-100" />;
  }

  return (
    <div className="space-y-4">
      {/* ============================================================
          ⭐ التنبيه — أهم حاجة في الشاشة
          ============================================================ */}
      {occupancy.waiting > 0 && occupancy.has_space && (
        <div className="flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
          <span className="text-lg leading-none">🔔</span>
          <div className="flex-1">
            <p className="text-sm font-medium text-amber-900">
              فيه {num(occupancy.waiting, 0)} ناس مستنيين
              {occupancy.seats_left !== null && (
                <> و {num(occupancy.seats_left, 0)} مقعد فاضي</>
              )}
            </p>
            <p className="mt-0.5 text-xs text-amber-800">
              ابدأ بأول واحد في الطابور — أو زوّد العدد الأقصى لو فيه
              مكان فاضي.
            </p>
          </div>
        </div>
      )}

      {/* ===== الأرقام ===== */}
      <div className="grid grid-cols-4 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 text-center">
        {[
          { label: "الداخلين", value: num(occupancy.members, 0) },
          {
            label: "العدد الأقصى",
            // ⭐ `null` = مفيش حد — مش صفر
            value: occupancy.capacity === null ? "مفيش حد" : num(occupancy.capacity, 0),
          },
          {
            label: "مقاعد فاضية",
            value: occupancy.seats_left === null ? "مفيش حد" : num(occupancy.seats_left, 0),
          },
          { label: "مستنيين", value: num(occupancy.waiting, 0) },
        ].map((s) => (
          <div key={s.label} className="bg-white px-2 py-3">
            <p className="text-base font-bold text-slate-800">{s.value}</p>
            <p className="mt-0.5 text-[11px] text-slate-400">{s.label}</p>
          </div>
        ))}
      </div>

      {/* ============================================================
          الطابور
          ============================================================ */}
      <section className="rounded-xl border border-slate-200 bg-white">
        <header className="flex items-center justify-between border-b border-slate-100 px-4 py-2.5">
          <h3 className="text-sm font-semibold text-slate-800">
            ⏳ قائمة الانتظار
            {stillWaiting.length > 0 && (
              <span className="mr-2 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">
                {num(stillWaiting.length, 0)}
              </span>
            )}
          </h3>
        </header>

        {waiting.length === 0 ? (
          <p className="px-4 py-6 text-center text-xs text-slate-400">
            مفيش حد مستني
          </p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-slate-50">
              <tr>
                <th className="w-12 px-3 py-2 text-center text-[11px] font-medium text-slate-500">
                  #
                </th>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  الاسم
                </th>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  الموبايل
                </th>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  وقت التسجيل
                </th>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  الحالة
                </th>
                <th className="px-3 py-2 text-end text-[11px] font-medium text-slate-500">
                  إجراء
                </th>
              </tr>
            </thead>
            <tbody>
              {waiting.map((e) => {
                // ⭐ «مش مربوط بحساب» — ده اللي بيحدد الشغل بعدها
                const notLinked = !e.linked_student;

                return (
                  <tr key={e.id} className="border-b border-slate-100 last:border-0">
                    {/* ⭐ الرقم — لو مستني بس. اللي دخل رقمه ٠ */}
                    <td className="px-3 py-2 text-center">
                      {e.position > 0 ? (
                        <span
                          className={`inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-bold ${
                            e.position === 1
                              ? "bg-slate-800 text-white"
                              : "bg-slate-100 text-slate-600"
                          }`}
                        >
                          {num(e.position, 0)}
                        </span>
                      ) : (
                        <span className="text-xs text-slate-300">—</span>
                      )}
                    </td>

                    <td className="px-3 py-2">
                      <span className="font-medium text-slate-800">{e.name}</span>
                      {notLinked && (
                        <span className="mr-1.5 rounded bg-amber-50 px-1 py-0.5 text-[10px] text-amber-700">
                          مفيش حساب
                        </span>
                      )}
                      {e.notes && (
                        <span className="block text-[11px] text-slate-400">{e.notes}</span>
                      )}
                    </td>

                    <td className="px-3 py-2 text-slate-600" dir="ltr">
                      <span className="inline-block text-start">{e.phone}</span>
                    </td>

                    <td className="px-3 py-2 text-xs text-slate-500">
                      {e.entered_at ? (
                        // ⭐ نقطة فاصلة — من غيرها، في RTL، التاريخ والوقت
                        // ملزومين في بعض: «٦ أكتوبر ٢٠٢٦٠٥:٠٥ م»
                        `${date(e.entered_at)} · ${time(e.entered_at)}`
                      ) : (
                        "—"
                      )}
                    </td>

                    <td className="px-3 py-2">
                      <EntryStatusPill status={e.status} />
                    </td>

                    <td className="px-3 py-2">
                      {e.status === "waiting" ? (
                        <div className="flex items-center gap-1">
                          <button
                            onClick={() => admit(e)}
                            disabled={busyId !== null}
                            className="rounded-lg bg-emerald-600 px-2.5 py-1 text-[11px] font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                          >
                            {busyId === e.id ? "..." : "ادخل"}
                          </button>
                          <button
                            onClick={() => decline(e)}
                            disabled={busyId !== null}
                            className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                          >
                            ارفض
                          </button>
                        </div>
                      ) : (
                        <span className="text-[11px] text-slate-300">—</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}
      </section>

      {/* ============================================================
          الأعضاء
          ============================================================ */}
      <section className="rounded-xl border border-slate-200 bg-white">
        <header className="border-b border-slate-100 px-4 py-2.5">
          <h3 className="text-sm font-semibold text-slate-800">
            👥 الأعضاء
            {activeMembers.length > 0 && (
              <span className="mr-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-800">
                {num(activeMembers.length, 0)}
              </span>
            )}
          </h3>
        </header>

        {activeMembers.length === 0 ? (
          <p className="px-4 py-6 text-center text-xs text-slate-400">
            مفيش طلاب داخلين المجموعة
          </p>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-slate-50">
              <tr>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  الطالب
                </th>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  الكود
                </th>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  دخل من
                </th>
                <th className="px-3 py-2 text-start text-[11px] font-medium text-slate-500">
                  وقت الدخول
                </th>
                <th className="px-3 py-2 text-end text-[11px] font-medium text-slate-500">
                  إجراء
                </th>
              </tr>
            </thead>
            <tbody>
              {activeMembers.map((m) => (
                <tr key={m.id} className="border-b border-slate-100 last:border-0">
                  <td className="px-3 py-2 font-medium text-slate-800">
                    {m.student?.name ?? "—"}
                  </td>
                  <td className="px-3 py-2 text-xs text-slate-500" dir="ltr">
                    <span className="inline-block text-start">{m.student?.code ?? "—"}</span>
                  </td>
                  <td className="px-3 py-2">
                    <span
                      className={`rounded px-1.5 py-0.5 text-[10px] ${
                        m.source === "waitlist"
                          ? "bg-emerald-50 text-emerald-700"
                          : "bg-slate-50 text-slate-600"
                      }`}
                    >
                      {m.source === "waitlist" ? "من الانتظار" : "إضافة يدوية"}
                    </span>
                  </td>
                  <td className="px-3 py-2 text-xs text-slate-500">
                    {m.joined_at ? date(m.joined_at) : "—"}
                  </td>
                  <td className="px-3 py-2">
                    <div className="flex items-center justify-end gap-1.5">
                      {/* ⭐ النقل قبل «شيل» — لأن «شيل» نهائي.
                          لو الأدمن عايز ينقله، «شيل» غلط. */}
                      <button
                        onClick={() => setMoving(m)}
                        disabled={busyId !== null || !m.student}
                        className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] text-slate-600 transition hover:bg-slate-50 disabled:opacity-50"
                      >
                        نقل
                      </button>
                      <button
                        onClick={() => removeMember(m)}
                        disabled={busyId !== null}
                        className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] text-slate-600 transition hover:bg-rose-50 hover:text-rose-700 disabled:opacity-50"
                      >
                        شيل
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}

        {/* ⭐ اللي سابوا — السطر بيفصل عشان ماتفرقش «مين كان هنا» */}
        {formerMembers.length > 0 && (
          <div className="border-t border-slate-100 px-4 py-2.5">
            <p className="mb-1.5 text-[11px] font-medium text-slate-500">
              خرجوا ({num(formerMembers.length, 0)})
            </p>
            <div className="flex flex-wrap gap-1.5">
              {formerMembers.map((m) => (
                <span
                  key={m.id}
                  className="rounded-md bg-slate-50 px-2 py-0.5 text-[11px] text-slate-500"
                >
                  {m.student?.name ?? "—"}
                  {m.left_at && (
                    <span className="mr-1 text-slate-400">{date(m.left_at)}</span>
                  )}
                </span>
              ))}
            </div>
          </div>
        )}
      </section>

      {/* ============================================================
          ⭐ شاشة النقل — مودال فوق كل حاجة
          ============================================================ */}
      {moving && (
        <MoveMemberDialog
          groupId={group.id}
          groupName={group.name}
          member={moving}
          onClose={() => setMoving(null)}
          onMoved={async () => {
            await load();
            onChanged(group.occupancy);
          }}
        />
      )}
    </div>
  );
}

/** حالة سطر الطابور */
function EntryStatusPill({ status }: { status: WaitingEntry["status"] }) {
  if (status === "joined") {
    return (
      <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-medium text-emerald-800">
        داخل
      </span>
    );
  }

  if (status === "declined") {
    return (
      <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-medium text-slate-600">
        رافض
      </span>
    );
  }

  return (
    <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">
      مستني
    </span>
  );
}
