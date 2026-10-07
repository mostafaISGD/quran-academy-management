"use client";

import { useEffect, useState } from "react";
import {
  fetchMe,
  getGroupAlerts,
  getGroups,
  updateGroup,
  type AuthUser,
  type GroupAlert,
  type GroupListResponse,
  type GroupOccupancy,
  type GroupRow,
} from "@/lib/api";
import { num, scheduleLine } from "@/lib/format";
import { useUI } from "@/components/ui";
import GroupPanel from "@/components/GroupPanel";

/**
 * إدارة المجموعات — الشاشة الداخلية.
 *
 * ⭐ الفرق بين الصفحة دي وصفحة `/groups`:
 *  - `/groups` = **عامة** — الأولاد بيشوفوا ويحجزوا
 *  - `/groups/manage` = **الإدارة بس** — الأعضاء والطابور والقرار
 *
 * ⭐ «ادخل» و«ارفض» بيظهروا بس لللي عنده `groups.manage`. مش بنخفي
 * الشاشة كلها — لو عملنا كده، موظف الاستقبال مش هيعرف مين مستني.
 */

/** شريط صغير للسعة */
function MiniBar({ g }: { g: GroupRow }) {
  if (g.capacity === null) {
    return (
      <div className="text-[11px] text-slate-500">
        {num(g.members_count, 0)} طالب · مفيش حد
      </div>
    );
  }

  const pct =
    g.capacity === 0 ? 100 : Math.min(100, Math.round((g.members_count / g.capacity) * 100));

  return (
    <div>
      <div className="flex items-baseline justify-between text-[11px]">
        <span className={g.is_full ? "text-rose-600" : "text-emerald-700"}>
          {g.is_full ? "ممتلئة" : `${num(g.seats_left ?? 0, 0)} فاضي`}
        </span>
        <span className="text-slate-400">
          {num(g.members_count, 0)} / {num(g.capacity, 0)}
        </span>
      </div>
      <div className="mt-1 h-1 overflow-hidden rounded-full bg-slate-100">
        <div
          className={`h-full rounded-full ${g.is_full ? "bg-rose-400" : "bg-emerald-500"}`}
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  );
}

export default function GroupsManagePage() {
  const { toast, prompt } = useUI();

  const [data, setData] = useState<GroupListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [openId, setOpenId] = useState<number | null>(null);
  const [me, setMe] = useState<AuthUser | null>(null);

  /**
   * ⭐ «فيه شغل» — من `/groups/alerts` **المحمي**، مش من الرد العام.
   *
   * السبب: الرد العام (`/groups`) مافيش فيه `needs_attention` —
   * لأنها إشارة داخلية («المجموعة دي محتاجة قرار دلوقتي»). لو
   * حسبناها هنا من `waiting_count` و `has_space`، هنرجّعها لموظف
   * الاستقبال بالغلط.
   */
  const [alerts, setAlerts] = useState<GroupAlert[]>([]);

  // ⭐ «ادخل» محمي — فلازم نعرف مين يقدر يشوف الزرار
  const canManage = Boolean(me?.permissions?.includes("groups.manage"));

  /** ⭐ إعادة قراءة الجرس — نفس المصدر دايمًا */
  function refreshAlerts() {
    if (!canManage) return;
    getGroupAlerts()
      .then((r) => setAlerts(r.groups))
      .catch(() => setAlerts([]));
  }

  useEffect(() => {
    getGroups()
      .then(setData)
      .catch((e) => toast.apiError("تعذر تحميل المجموعات", e))
      .finally(() => setLoading(false));

    fetchMe()
      .then(setMe)
      .catch(() => setMe(null));
  }, [toast]);

  // ⭐ ما نطلبش الجرس قبل ما نعرف إن ليه صلاحية — عشان ما نعملش
  // طلب 403 لكل موظف استقبال بيفتح الصفحة
  useEffect(() => {
    if (canManage) refreshAlerts();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canManage]);

  /** ⭐ الأرقام بتتحدّث في الكارت بعد أي تغيير جوا الدرو */
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

    // ⭐ الجرس كمان — الطابور اتغيّر فعلاً
    refreshAlerts();
  }

  /**
   * ⭐ تعديل **العدد الأقصى** بس من هنا.
   *
   * المدة وعدد الحصص والفئة مش هنا — دي في الإعدادات العامة.
   * والظهور كله محمي بـ `canManage`.
   */
  async function editCapacity(group: GroupRow) {
    const next = await prompt({
      title: "العدد الأقصى",
      message: `«${group.name}»\n\nاكتب الرقم، أو سيبه فاضي لو مفيش حد.`,
      label: "كام طالب يدخل المجموعة",
      placeholder: "مفيش حد",
      defaultValue: group.capacity === null ? "" : String(group.capacity),
    });
    if (next === null) return;

    // ⭐ فاضي = مفيش حد. مش صفر — الصفر معناه «مفيش حد يدخل»
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
        {[0, 1].map((i) => (
          <div key={i} className="h-24 animate-pulse rounded-xl bg-slate-100" />
        ))}
      </div>
    );
  }

  /** ⭐ المجموعات اللي محتاجة شغل — من الـ alerts المحمي */
  const attentionIds = new Set(alerts.map((a) => a.id));

  return (
    <div>
      {/* ===== الترويسة ===== */}
      <div className="mb-5 flex items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">إدارة المجموعات</h1>
          <p className="text-xs text-slate-500">
            {data ? `${num(data.data.length, 0)} مجموعة · ` : ""}
            الطابور وقرار الدخول
          </p>
        </div>

        {/* ⭐ التنبيه على الصفحة نفسها — مش بس في الجرس */}
        {canManage && alerts.length > 0 && (
          <button
            onClick={() => setOpenId(alerts[0].id)}
            className="flex items-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-3 py-2 text-start transition hover:bg-amber-100"
          >
            <span className="text-lg leading-none">🔔</span>
            <span>
              <span className="block text-sm font-bold text-amber-900">
                {num(alerts.length, 0)}{" "}
                {alerts.length === 1 ? "مجموعة فيها شغل" : "مجموعات فيها شغل"}
              </span>
              <span className="block text-[11px] text-amber-800">
                فيه ناس مستنية وفيه مقعد فاضي
              </span>
            </span>
          </button>
        )}
      </div>

      {!canManage && (
        <div className="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-xs text-amber-800">
          ⭐ تقدر تشوف المجموعات، بس «ادخل» و«ارفض» محميين — محتاج صلاحية
          إدارة المجموعات.
        </div>
      )}

      {data && data.data.length === 0 ? (
        <p className="text-sm text-slate-400">مفيش مجموعات لسه</p>
      ) : (
        <div className="space-y-2">
          {data?.data.map((g) => {
            // ⭐ التنبيه من المصدر الداخلي بس
            const needsAttention = attentionIds.has(g.id);

            return (
              <div
                key={g.id}
                className={`rounded-xl border bg-white transition ${
                  needsAttention ? "border-amber-300" : "border-slate-200"
                }`}
              >
                <div className="flex items-center gap-4 px-4 py-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2">
                      <button
                        onClick={() => setOpenId(openId === g.id ? null : g.id)}
                        className="truncate text-sm font-medium text-slate-800 hover:text-slate-900"
                      >
                        {g.name}
                      </button>
                      {needsAttention && (
                        <span className="shrink-0 rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800">
                          🔔 {num(g.waiting_count, 0)} مستني
                        </span>
                      )}
                    </div>
                    <p className="mt-0.5 text-[11px] text-slate-500">
                      {[
                        g.program?.name,
                        // ⭐ الوقت بالعربي — السيرفر بيرجّع الشكل الخام
                        scheduleLine(g.weekday_label, g.start_time, g.end_time),
                        g.teacher?.name,
                      ]
                        .filter(Boolean)
                        .join(" · ") || "—"}
                    </p>
                  </div>

                  <div className="w-40 shrink-0">
                    <MiniBar g={g} />
                  </div>

                  <div className="flex shrink-0 items-center gap-1.5">
                    {canManage && (
                      <button
                        onClick={() => editCapacity(g)}
                        className="rounded-lg border border-slate-200 px-2.5 py-1 text-[11px] text-slate-600 transition hover:bg-slate-50"
                      >
                        السعة
                      </button>
                    )}
                    <button
                      onClick={() => setOpenId(openId === g.id ? null : g.id)}
                      className="rounded-lg bg-slate-800 px-2.5 py-1 text-[11px] font-medium text-white transition hover:bg-slate-700"
                    >
                      {openId === g.id ? "اقفل" : "افتح"}
                    </button>
                  </div>
                </div>

                {/* ⭐ الـ panel — الأعضاء والطابور */}
                {openId === g.id && (
                  <div className="border-t border-slate-100 px-4 py-4">
                    <GroupPanel group={g} onChanged={(o) => patchOccupancy(g.id, o)} />
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}
    </div>
  );
}
