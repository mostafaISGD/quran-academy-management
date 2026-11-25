"use client";

import Link from "next/link";
import { useState } from "react";
import { getMyEmployeeAttendance, getMyEmployeeSalaries, type AttendanceStatus } from "@/lib/api";
import { date } from "@/lib/format";
import { usePage, usePaginated } from "@/lib/use-page";

const statusLabels: Record<AttendanceStatus, string> = {
  present: "حاضر",
  absent: "غائب",
  late: "متأخر",
  on_leave: "إجازة",
  half_day: "نصف يوم",
};

export default function EmployeePage() {
  const [month, setMonth] = useState(() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
  });
  const [page, setPage] = useState(1);
  const attendance = usePage(`employee-attendance:${month}`, () => getMyEmployeeAttendance(month));
  const salaries = usePaginated(`employee-salaries:${page}`, () => getMyEmployeeSalaries(page));

  return (
    <main dir="rtl" className="mx-auto max-w-5xl space-y-6 p-4 sm:p-8">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold">بوابة الموظف</h1>
          {attendance.data && <p className="text-slate-600">{attendance.data.employee.name}</p>}
        </div>
        <Link href="/notifications" className="text-sky-700 underline">إشعاراتي</Link>
      </header>

      <section className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h2 className="text-lg font-semibold">الحضور الشهري</h2>
          <label className="flex items-center gap-2">
            <span>الشهر</span>
            <input type="month" value={month} className="rounded border border-slate-300 px-2 py-1"
              onChange={(event) => { if (event.target.value) setMonth(event.target.value); }} />
          </label>
        </div>
        {attendance.loading && <p role="status">جارٍ تحميل الحضور…</p>}
        {attendance.error && <p role="alert">{attendance.error}</p>}
        {attendance.data && !attendance.loading && !attendance.error && (
          <>
            <p>الحضور {attendance.data.stats.present} يوم · الغياب {attendance.data.stats.absent} يوم · الإجازات {attendance.data.stats.on_leave} يوم</p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {attendance.data.days.map(({ date: day, day: number, record }) => (
                <div key={day} className="rounded border border-slate-200 p-2 text-sm">
                  <strong>{number}</strong> · {record ? statusLabels[record.status] : "بلا تسجيل"}
                </div>
              ))}
            </div>
          </>
        )}
      </section>

      <section className="space-y-4 rounded-lg border border-slate-200 bg-white p-4">
        <h2 className="text-lg font-semibold">الرواتب المعتمدة</h2>
        {salaries.loading && <p role="status">جارٍ تحميل الرواتب…</p>}
        {salaries.error && <p role="alert">{salaries.error}</p>}
        {!salaries.loading && !salaries.error && (
          salaries.items.length ? (
            <ul className="divide-y divide-slate-200">
              {salaries.items.map((salary) => (
                <li key={salary.id} className="flex flex-wrap justify-between gap-2 py-3">
                  <span>{date(salary.period_start)} – {date(salary.period_end)}</span>
                  <strong><bdi>{salary.amount} {salary.currency}</bdi></strong>
                  <span>{salary.status === "paid" ? "تم الصرف" : "معتمد"}</span>
                </li>
              ))}
            </ul>
          ) : <p>لا توجد رواتب معتمدة.</p>
        )}
        {salaries.lastPage > 1 && (
          <nav aria-label="صفحات الرواتب" className="flex items-center gap-3">
            <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)}>السابق</button>
            <span>{page} / {salaries.lastPage}</span>
            <button type="button" disabled={page >= salaries.lastPage} onClick={() => setPage(page + 1)}>التالي</button>
          </nav>
        )}
      </section>
    </main>
  );
}
