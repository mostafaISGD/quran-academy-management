"use client";

import type { ParentChildDetail } from "@/lib/api";
import { date, dateTime, money, num, timeOnly } from "@/lib/format";
import {
  Badge,
  Card,
  Empty,
  LESSON_STATUS_LABEL,
  LESSON_STATUS_TONE,
  STUDENT_STATUS_LABEL,
  STUDENT_STATUS_TONE,
  labelOf,
} from "./parent-ui";

/**
 * ⭐⭐⭐ **PR 4.5 — نظرة عامة على الابن.**
 *
 * ⭐ كل اللي الأب محتاجه في أول شاشة: حالة الطالب، اشتراكه، رصيد
 * الحصص، المستحق المالي (لو مسموح)، والحصص القادمة.
 *
 * ⚠️ `outstanding` و`lesson_credit` بيوصلوا `null` من السيرفر لما
 * `can_view_finance=false` — فمفيش أي فلوس بتتعرض.
 */

const GENDER_LABEL: Record<string, string> = {
  male: "ذكر",
  female: "أنثى",
};

export default function ChildOverview({ child }: { child: ParentChildDetail }) {
  const { student, subscription, lesson_credit, outstanding, upcoming_lessons, profile } = child;
  const progress = subscription ? Math.round(subscription.progress * 100) : 0;

  return (
    <div className="space-y-4">
      {/* ===== بيانات الطالب ===== */}
      <Card title="ملف الطالب" hint={`كود الطالب ${student.student_code}`}>
        <div className="flex flex-wrap items-center gap-2">
          <p className="text-base font-semibold text-slate-900">{student.full_name}</p>
          <Badge tone={STUDENT_STATUS_TONE[student.status] ?? "slate"}>
            {labelOf(STUDENT_STATUS_LABEL, student.status)}
          </Badge>
          {child.is_primary && <Badge tone="emerald">ولي الأمر الأساسي</Badge>}
        </div>

        <dl className="mt-3 grid grid-cols-2 gap-3 text-xs sm:grid-cols-4">
          <div>
            <dt className="text-slate-500">تاريخ الميلاد</dt>
            <dd className="mt-0.5 text-slate-800">{date(profile.date_of_birth)}</dd>
          </div>
          <div>
            <dt className="text-slate-500">النوع</dt>
            <dd className="mt-0.5 text-slate-800">
              {profile.gender ? (GENDER_LABEL[profile.gender] ?? profile.gender) : "—"}
            </dd>
          </div>
          <div>
            <dt className="text-slate-500">مسجّل من</dt>
            <dd className="mt-0.5 text-slate-800">{date(profile.joined_at)}</dd>
          </div>
          <div>
            <dt className="text-slate-500">المنطقة الزمنية</dt>
            <dd className="mt-0.5 text-slate-800">{profile.timezone ?? "—"}</dd>
          </div>
        </dl>
      </Card>

      {/* ===== الاشتراك ===== */}
      <Card title="الاشتراك الحالي" hint={subscription ? "البرنامج والمعلم والمواعيد" : undefined}>
        {!subscription ? (
          <Empty
            title="مفيش اشتراك نشط حالياً"
            hint="كلّم الإدارة لو تحب تجدد أو تسأل عن البرامج المتاحة."
          />
        ) : (
          <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-semibold text-slate-900">
                {subscription.program_name}
              </p>
              <Badge tone={subscription.status === "active" ? "emerald" : "amber"}>
                {subscription.status === "active" ? "نشط" : "متوقف"}
              </Badge>
            </div>

            <p className="text-xs text-slate-600">
              المعلم: {subscription.teacher_name ?? "—"}
              {subscription.weekday_names ? ` · ${subscription.weekday_names}` : ""}
              {subscription.start_time ? ` · ${timeOnly(subscription.start_time)}` : ""}
            </p>

            <div className="grid grid-cols-2 gap-3 text-xs sm:grid-cols-3">
              <div>
                <p className="text-slate-500">البداية</p>
                <p className="mt-0.5 text-slate-800">{date(subscription.start_date)}</p>
              </div>
              <div>
                <p className="text-slate-500">النهاية</p>
                <p className="mt-0.5 text-slate-800">{date(subscription.end_date)}</p>
              </div>
              <div>
                <p className="text-slate-500">المتبقي</p>
                <p className="mt-0.5 text-slate-800">
                  {subscription.days_left === null
                    ? "—"
                    : subscription.days_left < 0
                      ? `متأخر ${num(Math.abs(subscription.days_left), 0)} يوم`
                      : `${num(subscription.days_left, 0)} يوم`}
                </p>
              </div>
            </div>

            <div>
              <div className="flex items-center justify-between text-[11px] text-slate-500">
                <span>استهلاك الحصص</span>
                <span>{num(progress, 0)}%</span>
              </div>
              <div className="mt-1 h-2 overflow-hidden rounded-full bg-slate-100">
                <div
                  className="h-full rounded-full bg-emerald-600"
                  style={{ width: `${Math.min(100, Math.max(0, progress))}%` }}
                />
              </div>
            </div>
          </div>
        )}
      </Card>

      {/* ===== رصيد الحصص + المستحق — لو مسموح ===== */}
      {(lesson_credit || outstanding) && (
        <div className="grid gap-4 sm:grid-cols-2">
          {lesson_credit && (
            <Card title="رصيد الحصص">
              <p className="text-2xl font-bold text-slate-900">
                {num(lesson_credit.balance, 0)}
                {lesson_credit.included > 0 && (
                  <span className="text-sm font-normal text-slate-500">
                    {" "}
                    من {num(lesson_credit.included, 0)}
                  </span>
                )}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                {lesson_credit.expires_at
                  ? `ينتهي في ${date(lesson_credit.expires_at)}`
                  : "بدون تاريخ انتهاء"}
              </p>
            </Card>
          )}

          {outstanding && (
            <Card title="المستحق المالي">
              <p className="text-2xl font-bold text-slate-900">
                {money(outstanding.total, outstanding.currency)}
              </p>
              <p className="mt-1 text-xs text-slate-500">
                {outstanding.invoices === 0
                  ? "مفيش فواتير مستحقة"
                  : `${num(outstanding.invoices, 0)} فاتورة غير مسددة`}
              </p>
            </Card>
          )}
        </div>
      )}

      {/* ===== الحصص القادمة ===== */}
      <Card title="الحصص القادمة" hint="أقرب 12 حصة مجدولة">
        {upcoming_lessons.length === 0 ? (
          <Empty title="مفيش حصص قادمة" hint="هتظهر هنا أول ما يتم جدولة حصص جديدة." />
        ) : (
          <ul className="divide-y divide-slate-100">
            {upcoming_lessons.map((lesson) => (
              <li key={lesson.id} className="flex items-center justify-between gap-3 py-2">
                <div className="min-w-0">
                  <p className="text-sm text-slate-800">
                    {dateTime(lesson.scheduled_start_at, profile.timezone ?? undefined)}
                  </p>
                  <p className="text-xs text-slate-500">{lesson.teacher_name ?? "—"}</p>
                </div>
                <Badge tone={LESSON_STATUS_TONE[lesson.status] ?? "slate"}>
                  {labelOf(LESSON_STATUS_LABEL, lesson.status)}
                </Badge>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  );
}
