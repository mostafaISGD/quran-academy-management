"use client";

import type { TeacherPortalEarnings } from "@/lib/api";
import { date, money, num } from "@/lib/format";
import { earningsRows } from "@/lib/teacher-portal";

/**
 * ⭐⭐ **كشف مستحقاتي.**
 *
 * ⚠️ `currencies` كائن بمفتاح العملة — وكل عملة بتتعرض في كارت
 * لوحدها. جمعها في رقم واحد = خلط وحدات (AGENTS.md قاعدة 11).
 */
export default function TeacherEarnings({
  data,
  loading,
  error,
}: {
  data: TeacherPortalEarnings | null;
  loading: boolean;
  error: string | null;
}) {
  if (loading) {
    return (
      <div className="space-y-3" aria-busy="true">
        {[0, 1].map((i) => (
          <div key={i} className="h-28 animate-pulse rounded-xl bg-white ring-1 ring-slate-200" />
        ))}
      </div>
    );
  }

  if (error) {
    return (
      <div role="alert" className="rounded-xl bg-red-50 p-4 text-sm text-red-700 ring-1 ring-red-200">
        {error}
      </div>
    );
  }

  if (!data) return null;

  const rows = earningsRows(data.currencies);
  const entries = [...data.entries].reverse().slice(0, 30);

  return (
    <div className="space-y-5">
      {/* ===== الرصيد لكل عملة ===== */}
      <div className="grid gap-3 sm:grid-cols-2">
        {rows.length === 0 ? (
          <div className="rounded-xl border border-dashed border-slate-300 bg-white p-6 text-center text-sm text-slate-600 sm:col-span-2">
            لسه مفيش حركات على كشف حسابك.
          </div>
        ) : (
          rows.map((row) => (
            <div
              key={row.currency}
              className="rounded-xl bg-slate-800 p-4 text-white shadow-sm"
            >
              <p className="text-xs text-slate-300">الرصيد ({row.currency})</p>
              <p className="mt-1 text-2xl font-bold tabular-nums">
                {money(row.balance, row.currency)}
              </p>
              <dl className="mt-3 flex gap-4 text-[11px] text-slate-300">
                <div>
                  <dt>إجمالي المستحق</dt>
                  <dd className="tabular-nums text-white">
                    {money(row.total_credit, row.currency)}
                  </dd>
                </div>
                <div>
                  <dt>إجمالي المصروف</dt>
                  <dd className="tabular-nums text-white">
                    {money(row.total_debit, row.currency)}
                  </dd>
                </div>
              </dl>
            </div>
          ))
        )}
      </div>

      {/* ===== آخر الحركات ===== */}
      <section>
        <h2 className="mb-2 px-1 text-sm font-semibold text-slate-800">آخر الحركات</h2>

        {entries.length === 0 ? (
          <p className="rounded-xl bg-white p-4 text-sm text-slate-500 ring-1 ring-slate-200">
            مفيش حركات لسه.
          </p>
        ) : (
          <ul className="divide-y divide-slate-100 overflow-hidden rounded-xl bg-white ring-1 ring-slate-200">
            {entries.map((entry) => {
              const credit = Number(entry.credit ?? 0);
              const debit = Number(entry.debit ?? 0);

              return (
                <li key={entry.id} className="flex items-start justify-between gap-3 px-4 py-3">
                  <div className="min-w-0">
                    <p className="truncate text-sm text-slate-800">
                      {entry.description ?? "حركة على الحساب"}
                    </p>
                    <p className="mt-0.5 text-[11px] text-slate-500">
                      {date(entry.created_at)}
                      {" · "}
                      الرصيد بعدها: {money(entry.balance_after, entry.currency)}
                    </p>
                  </div>
                  <span
                    className={`shrink-0 text-sm font-semibold tabular-nums ${
                      credit > 0 ? "text-emerald-700" : "text-red-600"
                    }`}
                  >
                    {credit > 0 ? "+" : "−"}
                    {money(credit > 0 ? credit : debit, entry.currency)}
                  </span>
                </li>
              );
            })}
          </ul>
        )}

        <p className="mt-2 px-1 text-[11px] text-slate-400">
          {num(entries.length, 0)} حركة من إجمالي {num(data.entries.length, 0)}
        </p>
      </section>
    </div>
  );
}
