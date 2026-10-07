"use client";

import { useEffect, useState } from "react";
import {
  fetchMe,
  getGroupAlerts,
  getGroups,
  joinWaitingList,
  updateGroup,
  type AuthUser,
  type GroupAlert,
  type GroupListResponse,
  type GroupOccupancy,
  type GroupRow,
} from "@/lib/api";
import { num, scheduleLine } from "@/lib/format";
import { useUI } from "@/components/ui";
import Modal from "@/components/ui/Modal";
import GroupPanel from "@/components/GroupPanel";

/**
 * المجموعات الأونلاين — الصفحة العامة.
 *
 * ⭐ **من غير تسجيل دخول**. الأولاد بيسألوا «فيه مجموعة للتحفيظ؟»
 * من غير ما يكونوا عملوا حساب. ولو ربطناها بحساب، اللي إحنا
 * عايزينه هو اللي مش هيقدر يسجّل.
 *
 * ⭐ التسجيل بيطلب **الاسم + رقم الموبايل بس**. مافيش حساب،
 * مافيش بريد، مافيش كلمة سر. ولو الشخص مسجّل عندنا من قبل،
 *Staff بيحطه في «العملاء المحتملين» أوшки يساعد.
 *
 * ⚠️ حدود دلوقتي: المجموعة = **قائمة طلاب + عدد أقصى + انتظار**.
 * الحصص نفسها بتتحجز عادي (طالب واحد في كل حصة).
 */

/** شريط السعة: «٨ من ١٠» أو «مفيش حد» */
function CapacityBar({ group }: { group: GroupRow }) {
  const { capacity, members, waiting } = group.occupancy;

  // ⭐ مافيش حد max — نكتبها صراحة بدل ما نعمل شريط مضلّل
  if (capacity === null) {
    return (
      <div className="text-xs">
        <span className="font-medium text-emerald-700">{num(members, 0)} طالب</span>
        <span className="text-slate-400"> · مفيش حد أقصى</span>
        {waiting > 0 && (
          <span className="text-amber-600"> · {num(waiting, 0)} مستني</span>
        )}
      </div>
    );
  }

  const filled = capacity === 0 ? 100 : Math.min(100, Math.round((members / capacity) * 100));
  const full = group.is_full;

  return (
    <div>
      {/* ⭐ الرقم كبير وواضح — «كام مقعد فاضي» هو السؤال الحقيقي */}
      <div className="flex items-baseline justify-between text-xs">
        <span className={full ? "font-medium text-rose-600" : "font-medium text-emerald-700"}>
          {full ? "المجموعة ممتلئة" : `${num(group.seats_left ?? 0, 0)} مقعد فاضي`}
        </span>
        <span className="text-slate-400">
          {num(members, 0)} من {num(capacity, 0)}
        </span>
      </div>

      <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100">
        <div
          className={`h-full rounded-full transition-all ${full ? "bg-rose-400" : "bg-emerald-500"}`}
          style={{ width: `${filled}%` }}
        />
      </div>

      {waiting > 0 && (
        <p className="mt-1 text-[11px] text-amber-600">
          {num(waiting, 0)} مستني — كل ما يفيق مكان بنتصل بيك
        </p>
      )}
    </div>
  );
}

/** كارت مجموعة واحدة */
function GroupCard({
  group,
  canManage,
  needsAttention,
  onChanged,
  onEditCapacity,
}: {
  group: GroupRow;
  canManage?: boolean;
  needsAttention?: boolean;
  onChanged?: (occupancy: GroupOccupancy) => void;
  onEditCapacity?: (group: GroupRow) => void;
}) {
  const { toast } = useUI();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState<{ message: string; position: number } | null>(null);
  // ⭐ الإدارة بقت داخل نفس الكارت — accordion من غير ما نروح صفحة تانية
  const [manageOpen, setManageOpen] = useState(false);

  /**
   * ⭐ **من غير حساب** — الـ API نفسها عامة.
   *
   * مافيش `token`، ومافيش `credentials`. لو حطينا حفظ الـ API
   * كانت هتبقى محمية والصفحة العامة مش هتشتغل خالص.
   */
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    if (saving) return;

    setSaving(true);
    try {
      const r = await joinWaitingList(group.id, { name, phone, notes: notes || undefined });
      setDone({ message: r.message, position: r.position });
      toast.success(r.message);
      // ⭐ تفريغ الحقول — عشان لو حد فتح تاني ما يلقاش كلام قديم
      setName("");
      setPhone("");
      setNotes("");
    } catch (e2) {
      toast.apiError("فشل التسجيل", e2);
    } finally {
      setSaving(false);
    }
  }

  const canJoin = group.accepts_waitlist;

  return (
    <article
      className={`flex flex-col overflow-hidden rounded-xl border bg-white transition ${
        needsAttention ? "border-amber-300 ring-1 ring-amber-200" : "border-slate-200"
      }`}
    >
      {/* ===== الترويسة ===== */}
      <div className="border-b border-slate-100 px-4 py-3">
        <div className="flex items-start justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-800">{group.name}</h2>
          {needsAttention && (
            <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">
              🔔 {num(group.waiting_count, 0)} مستني
            </span>
          )}
          {group.is_full ? (
            <span className="shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-[10px] font-medium text-rose-700">
              ممتلئة
            </span>
          ) : (
            <span className="shrink-0 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700">
              فيها مكان
            </span>
          )}
        </div>

        {group.program && (
          <p className="mt-0.5 text-xs text-slate-500">{group.program.name}</p>
        )}

        {scheduleLine(group.weekday_label, group.start_time, group.end_time) && (
          <p className="mt-1 text-xs text-slate-600">
            🕐 {scheduleLine(group.weekday_label, group.start_time, group.end_time)}
          </p>
        )}

        {group.teacher && (
          <p className="mt-0.5 text-xs text-slate-500">المعلم: {group.teacher.name}</p>
        )}
      </div>

      {/* ===== السعة ===== */}
      <div className="px-4 py-3">
        <CapacityBar group={group} />
        {group.description && (
          <p className="mt-2 text-xs leading-relaxed text-slate-500">{group.description}</p>
        )}
      </div>

      {/* ===== الإجراء ===== */}
      <div className="mt-auto border-t border-slate-100">
        {/* ⭐ للأدمن: زرار «إدارة» يفتح اللوحة جوّه نفس الكارت */}
        {canManage && (
          <div className="flex items-center gap-1.5 border-b border-slate-100 px-4 py-2">
            {onEditCapacity && (
              <button
                onClick={() => onEditCapacity(group)}
                className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] text-slate-600 transition hover:bg-slate-50"
              >
                السعة
              </button>
            )}
            <button
              onClick={() => setManageOpen((v) => !v)}
              className={`rounded-lg px-2.5 py-1 text-[11px] font-medium transition ${
                manageOpen
                  ? "bg-slate-800 text-white"
                  : "border border-slate-200 text-slate-600 hover:bg-slate-50"
              }`}
            >
              {manageOpen ? "اقفل الإدارة" : "إدارة"}
            </button>
          </div>
        )}
        {/* ⭐ نتيجة التسجيل — بتقول رقمه في الطابور */}
        {done ? (
          <div className="px-4 py-3">
            <p className="text-xs font-medium text-emerald-700">{done.message}</p>
            {done.position > 0 && (
              <p className="mt-0.5 text-[11px] text-slate-500">
                ترتيبك: {num(done.position, 0)} — إحنا هنتصل بيك في
                ترتيبك، من غير ما تدفع حاجة دلوقتي.
              </p>
            )}
          </div>
        ) : canJoin ? (
          open ? (
            <form onSubmit={submit} className="space-y-2 px-4 py-3">
              {/* ⭐ التسمية بتقول «مين» مش «إيه اسمك» — لأن اللي
                  بيكتب ممكن يكون وليّ الأمر مش الطالب نفسه */}
              <input
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="اسم الطالب (أو اسم وليّ الأمر)"
                className="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:border-slate-400 focus:outline-none"
                required
              />
              <input
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                placeholder="رقم الموبايل"
                dir="ltr"
                inputMode="tel"
                className="w-full rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:border-slate-400 focus:outline-none"
                required
              />
              <textarea
                value={notes}
                onChange={(e) => setNotes(e.target.value)}
                placeholder="ملاحظة (اختياري) — مثلاً: المستوى"
                rows={2}
                className="w-full resize-none rounded-lg border border-slate-200 px-2.5 py-1.5 text-xs focus:border-slate-400 focus:outline-none"
              />
              <div className="flex gap-2">
                <button
                  type="submit"
                  disabled={saving}
                  className="flex-1 rounded-lg bg-slate-800 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-slate-700 disabled:opacity-50"
                >
                  {saving ? "بنسجّل..." : "احجز مكاني"}
                </button>
                <button
                  type="button"
                  onClick={() => setOpen(false)}
                  className="rounded-lg border border-slate-200 px-3 py-1.5 text-xs text-slate-600 transition hover:bg-slate-50"
                >
                  إلغاء
                </button>
              </div>
              <p className="text-[10px] leading-relaxed text-slate-400">
                ⭐ مش محتاج حساب ولا تدفع حاجة دلوقتي. بسجّل اسمك ورقمك
                وإحنا هنتصل بيك أول ما يفيق مكان.
              </p>
            </form>
          ) : (
            <button
              onClick={() => setOpen(true)}
              className="w-full px-4 py-2.5 text-xs font-medium text-slate-700 transition hover:bg-slate-50"
            >
              {group.is_full ? "احجز في الانتظار" : "احجز مكاني"}
            </button>
          )
        ) : (
          <p className="px-4 py-2.5 text-[11px] text-slate-400">
            المجموعة موقفة مؤقتًا — مش بتاخد طلبات دلوقتي
          </p>
        )}
      </div>

      {/* ⭐ لوحة الإدارة في نافذة وسطية — مش داخل الكارت */}
      {canManage && (
        <Modal
          open={manageOpen}
          onClose={() => setManageOpen(false)}
          title={`إدارة ${group.name}`}
          width="max-w-2xl"
        >
          <div className="max-h-[70vh] overflow-y-auto">
            <GroupPanel group={group} onChanged={(o) => onChanged?.(o)} />
          </div>
        </Modal>
      )}
    </article>
  );
}

export default function GroupsPage() {
  const { toast, prompt } = useUI();

  const [data, setData] = useState<GroupListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // ⭐ صلاحية الإدارة — بتحدد إذا كنا نعرض زرار «إدارة»
  const [me, setMe] = useState<AuthUser | null>(null);
  const canManage = Boolean(me?.permissions?.includes("groups.manage"));

  // ⭐ التنبيه — من المصدر الداخلي المحمي
  const [alerts, setAlerts] = useState<GroupAlert[]>([]);

  useEffect(() => {
    getGroups()
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر تحميل المجموعات"))
      .finally(() => setLoading(false));

    fetchMe()
      .then(setMe)
      .catch(() => setMe(null));
  }, []);

  // ⭐ ما نطلبش التنبيهات قبل ما نعرف الصلاحية أختفي
  useEffect(() => {
    if (canManage) refreshAlerts();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canManage]);

  function refreshAlerts() {
    if (!canManage) return;
    getGroupAlerts()
      .then((r) => setAlerts(r.groups))
      .catch(() => setAlerts([]));
  }

  function patchOccupancy(groupId: number, occupancy: GroupOccupancy) {
    setData((prev) =>
      prev
        ? {
            ...prev,
            data: prev.data.map((g) =>
              g.id === groupId
                ? {
                    ...g,
                    occupancy,
                    capacity: occupancy.capacity,
                    members_count: occupancy.members,
                    waiting_count: occupancy.waiting,
                    seats_left: occupancy.seats_left,
                    is_full: occupancy.is_full,
                    has_space: occupancy.has_space,
                  }
                : g,
            ),
          }
        : prev,
    );
    refreshAlerts();
  }

  async function editCapacity(group: GroupRow) {
    const next = await prompt({
      title: "العدد الأقصى",
      message: `«${group.name}»\n\nاكتب الرقم، أو سيبه فاضي لو مفيش حد.`,
      label: "كام طالب يدخل المجموعة",
      placeholder: "مفيش حد",
      defaultValue: group.capacity === null ? "" : String(group.capacity),
    });
    if (next === null) return;

    const trimmed = next.trim();
    const n = trimmed === "" ? null : Number(trimmed);

    if (n !== null && (!Number.isFinite(n) || n < 1)) {
      toast.error("رقم مش صحيح", "اكتب رقم أكبر من صفر، أو سيبه فاضي");
      return;
    }

    try {
      const r = await updateGroup(group.id, {
        capacity: n,
        name: group.name,
        program_id: group.program?.id,
      });
      toast.success(r.message);
      setData((prev) =>
        prev ? { ...prev, data: prev.data.map((g) => (g.id === group.id ? r.data : g)) } : prev,
      );
      refreshAlerts();
    } catch (e) {
      toast.apiError("فشل التعديل", e);
    }
  }

  if (loading) {
    return (
      <div className="space-y-2">
        {[0, 1, 2].map((i) => (
          <div key={i} className="h-40 animate-pulse rounded-xl bg-slate-100" />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div className="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{error}</div>
    );
  }

  /** ⭐ المجموعات اللي محتاجة شغل فعلاً — من المصدر المحمي بس */
  const attentionIds = new Set(alerts.map((a) => a.id));

  return (
    <div>
      {/* ===== الترويسة ===== */}
      <div className="mb-6">
        <h1 className="text-lg font-semibold text-slate-800">المجموعات الأونلاين</h1>
        <p className="text-xs text-slate-500">
          {data ? `${num(data.meta.total, 0)} مجموعة · ` : ""}
          الحصص بتتم أونلاين. لو المجموعة ممتلئة، احجز مكانك في الانتظار
          وإحنا هنتصل بيك أول ما يفيق مكان.
        </p>
      </div>

      {/* ⭐ تنبيه للأدمن — مش لازم يفتح أي مجموعة عشان يلاحظ */}
      {canManage && alerts.length > 0 && (
        <div className="mb-4 flex items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
          <span className="text-lg leading-none">🔔</span>
          <div>
            <p className="text-sm font-bold text-amber-900">
              {num(alerts.length, 0)} {alerts.length === 1 ? "مجموعة فيها شغل" : "مجموعات فيها شغل"}
            </p>
            <p className="text-[11px] text-amber-800">
              فيه ناس مستنية وفيه مقعد فاضي — افتح المجموعة وادخلهم
            </p>
          </div>
        </div>
      )}

      {data && data.data.length === 0 ? (
        <p className="text-sm text-slate-400">مفيش مجموعات مفتوحة دلوقتي</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {data?.data.map((g) => (
            <GroupCard
              key={g.id}
              group={g}
              canManage={canManage}
              needsAttention={attentionIds.has(g.id)}
              onChanged={(o) => patchOccupancy(g.id, o)}
              onEditCapacity={editCapacity}
            />
          ))}
        </div>
      )}
    </div>
  );
}
