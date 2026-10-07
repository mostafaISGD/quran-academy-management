"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  fetchMe,
  getMyChildren,
  getStoredUser,
  logout,
  type ParentChildView,
} from "@/lib/api";
import { date, money, num, time, weekday } from "@/lib/format";

/**
 * صفحة ولي الأمر.
 *
 * مفيش sidebar ولا روابط تانية — كل اللي هنا عن أبناؤه بس.
 * السيرفر نفسه بيفلتر: `/parent/children` بيرجّع الأبناء المسجّلين
 * عند ولي الأمر ده بالظبط.
 */

/** ألوان الحالة حسب status */
const STATUS_STYLE: Record<string, string> = {
  active: "bg-emerald-50 text-emerald-700 ring-emerald-200",
  paused: "bg-amber-50 text-amber-700 ring-amber-200",
  expired: "bg-red-50 text-red-600 ring-red-200",
  cancelled: "bg-slate-100 text-slate-500 ring-slate-200",
};

const STATUS_LABEL: Record<string, string> = {
  active: "نشط",
  paused: "متوقف",
  expired: "منتهي",
  cancelled: "ملغي",
  lead: "مبدئي",
  graduated: "متخرج",
  archived: "مؤرشف",
  inactive: "غير نشط",
};

export default function MyChildrenPage() {
  const router = useRouter();
  const [parent, setParent] = useState(getStoredUser());
  const [children, setChildren] = useState<ParentChildView[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [ready, setReady] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const r = await getMyChildren();
      setChildren(r.children ?? []);
    } catch (e) {
      setError(e instanceof Error ? e.message : "تعذر تحميل البيانات");
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    // السيرفر هو مصدر الحقيقة — هو اللي بيعرف إن الحساب ده ولي أمر
    fetchMe()
      .then((user) => {
        setParent(user);
        if (!user.is_parent || !user.parent) {
          router.replace("/dashboard");
          return;
        }
        return load();
      })
      .catch(() => router.replace("/login"))
      .finally(() => setReady(true));
  }, [router, load]);

  async function handleLogout() {
    await logout();
    router.push("/login");
  }

  if (!ready || !parent?.is_parent) return null;

  return (
    <div className="mx-auto max-w-4xl p-4 sm:p-6">
      {/* ===== الترويسة ===== */}
      <div className="mb-6 flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-lg font-semibold text-slate-800">
            {parent.parent?.name ?? parent.name}
          </h1>
          <p className="text-sm text-slate-500">
            {children.length === 0
              ? "لا يوجد طلاب مرتبطون بحسابك"
              : children.length === 1
                ? "طالب واحد"
                : `${children.length} طلاب`}
            {parent.parent?.phone ? ` — ${parent.parent.phone}` : ""}
          </p>
        </div>
        <button
          onClick={handleLogout}
          className="rounded-lg bg-slate-100 px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-200"
        >
          🚪 تسجيل الخروج
        </button>
      </div>

      {loading && (
        <p className="py-10 text-center text-sm text-slate-500">جاري التحميل…</p>
      )}

      {error && (
        <div className="rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200">
          {error}
        </div>
      )}

      {!loading && !error && children.length === 0 && (
        <div className="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center">
          <p className="text-sm text-slate-600">
            مفيش طلاب مرتبطين بحسابك لسه.
          </p>
          <p className="mt-1 text-xs text-slate-400">
            لو اتسجلت في أكاديمية قبل كده، كلّم الإدارة عشان تربط الحساب.
          </p>
        </div>
      )}

      {/* ===== كارت لكل ابن ===== */}
      <div className="space-y-5">
        {children.map((child) => (
          <ChildCard key={child.student.id} child={child} />
        ))}
      </div>
    </div>
  );
}

/** كارت ابن واحد */
function ChildCard({ child }: { child: ParentChildView }) {
  const { student, subscription, lesson_credit, upcoming_lessons, outstanding } = child;
  const daysLeft = subscription?.days_left ?? null;

  return (
    <section className="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200">
      {/* الترويسة */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
        <div>
          <h2 className="font-semibold text-slate-800">{student.full_name}</h2>
          <p className="text-xs text-slate-500">
            {child.relationship ? `${child.relationship} · ` : ""}
            {student.student_code}
          </p>
        </div>
        <span
          className={`rounded-full px-2.5 py-1 text-xs font-medium ring-1 ${
            STATUS_STYLE[student.status] ?? "bg-slate-100 text-slate-600 ring-slate-200"
          }`}
        >
          {STATUS_LABEL[student.status] ?? student.status}
        </span>
      </div>

      <div className="space-y-5 px-5 py-4">
        {/* ===== الاشتراك ===== */}
        {subscription ? (
          <div>
            <div className="mb-2 flex items-center justify-between gap-2">
              <h3 className="text-sm font-medium text-slate-700">الاشتراك</h3>
              <span
                className={`rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ${
                  STATUS_STYLE[subscription.status] ?? "bg-slate-100 text-slate-600 ring-slate-200"
                }`}
              >
                {STATUS_LABEL[subscription.status] ?? subscription.status}
              </span>
            </div>

            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
              <Field label="البرنامج" value={subscription.program_name} />
              <Field label="المعلّم" value={subscription.teacher_name ?? "—"} />
              <Field
                label="الموعد"
                value={
                  subscription.weekday_names
                    ? `${subscription.weekday_names} · ${subscription.start_time ?? ""}`
                    : "—"
                }
              />
              <Field
                label="ينتهي"
                value={subscription.end_date ?? "غير محدود"}
                tone={
                  daysLeft !== null && daysLeft <= 3 ? "warn" : "normal"
                }
              />
            </dl>

            {/* شريط التقدّم في الحصص */}
            {lesson_credit && lesson_credit.included > 0 && (
              <div className="mt-4">
                <div className="mb-1 flex items-baseline justify-between text-xs text-slate-600">
                  <span>الحصص المستهلكة</span>
                  <span className="tabular-nums">
                    {lesson_credit.included - lesson_credit.balance} /{" "}
                    {lesson_credit.included}
                  </span>
                </div>
                <div className="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                  <div
                    className="h-full rounded-full bg-emerald-500 transition-all"
                    style={{
                      width: `${Math.min(100, Math.round((subscription.progress ?? 0) * 100))}%`,
                    }}
                  />
                </div>
                <p className="mt-1 text-xs text-slate-500">
                  فاضل {lesson_credit.balance} حصة
                  {lesson_credit.expires_at
                    ? ` — تنتهي ${lesson_credit.expires_at.slice(0, 10)}`
                    : ""}
                </p>
              </div>
            )}
          </div>
        ) : (
          <div className="rounded-lg bg-slate-50 p-3 text-sm text-slate-600">
            مفيش اشتراك نشط حالياً.
          </div>
        )}

        {/* ===== المستحقات ===== */}
        {outstanding.invoices > 0 && (
          <div className="rounded-lg bg-amber-50 p-3 ring-1 ring-amber-200">
            <p className="text-sm text-amber-900">
              {/* ⭐ `money` بتعمل التحويل لـ«ج.م» جواها */}
              <strong className="tabular-nums">
                {money(outstanding.total, outstanding.currency)}
              </strong>{" "}
              مستحق على {num(outstanding.invoices, 0)}{" "}
              {outstanding.invoices === 1 ? "فاتورة" : "فواتير"}.
            </p>
          </div>
        )}

        {/* ===== الحصص القادمة ===== */}
        <div>
          <h3 className="mb-2 text-sm font-medium text-slate-700">
            الحصص القادمة
          </h3>
          {upcoming_lessons.length === 0 ? (
            <p className="text-sm text-slate-500">
              مفيش حصص مجدولة حالياً.
            </p>
          ) : (
            <ul className="divide-y divide-slate-100 rounded-lg ring-1 ring-slate-200">
              {upcoming_lessons.map((lesson) => (
                <li
                  key={lesson.id}
                  className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm"
                >
                  {/* ⭐ اليوم والوقت **في سطرين مختلفين** — قبل كده
                      كانوا `toLocaleDateString` + `toLocaleTimeString`
                      بخيارات مختلفة عن باقي البرنامج */}
                  <span className="text-slate-700">
                    {weekday(lesson.scheduled_start_at)}، {date(lesson.scheduled_start_at)}
                  </span>
                  <span className="tabular-nums text-slate-500">
                    {/* ⭐ مافيش `scheduled_end_at` في رد ولي الأمر — وقت بس */}
                    {time(lesson.scheduled_start_at)}
                  </span>
                  <span className="text-xs text-slate-500">
                    {lesson.teacher_name ?? "—"}
                  </span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </div>
    </section>
  );
}

/** حقل صغير: عنوان + قيمة */
function Field({
  label,
  value,
  tone = "normal",
}: {
  label: string;
  value: string;
  tone?: "normal" | "warn";
}) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd
        className={`font-medium ${tone === "warn" ? "text-red-600" : "text-slate-800"}`}
      >
        {value}
      </dd>
    </div>
  );
}
