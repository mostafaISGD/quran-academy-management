"use client";

import { useEffect, useState } from "react";
import {
  getGroups,
  joinWaitingList,
  type GroupListResponse,
  type GroupRow,
} from "@/lib/api";
import { num, scheduleLine } from "@/lib/format";
import { useUI } from "@/components/ui";

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
function GroupCard({ group }: { group: GroupRow }) {
  const { toast } = useUI();
  const [open, setOpen] = useState(false);
  const [name, setName] = useState("");
  const [phone, setPhone] = useState("");
  const [notes, setNotes] = useState("");
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState<{ message: string; position: number } | null>(null);

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
    <article className="flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white">
      {/* ===== الترويسة ===== */}
      <div className="border-b border-slate-100 px-4 py-3">
        <div className="flex items-start justify-between gap-2">
          <h2 className="text-sm font-semibold text-slate-800">{group.name}</h2>
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
    </article>
  );
}

export default function GroupsPage() {
  const [data, setData] = useState<GroupListResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    getGroups()
      .then(setData)
      .catch((e) => setError(e instanceof Error ? e.message : "تعذر تحميل المجموعات"))
      .finally(() => setLoading(false));
  }, []);

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

      {data && data.data.length === 0 ? (
        <p className="text-sm text-slate-400">مفيش مجموعات مفتوحة دلوقتي</p>
      ) : (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {data?.data.map((g) => (
            <GroupCard key={g.id} group={g} />
          ))}
        </div>
      )}
    </div>
  );
}
