"use client";


import { date } from "@/lib/format";
/**
 * عناصر صغيرة مشتركة بين شاشة البرامج ومُلَف البرنامج.
 *
 * نفس الأنماط المستخدمة في employees/teachers عشان الـ UX يطلع
 * متسق عبر الشاشات.
 */

export function Section({ title, icon, count, children, action }: {
  title: string; icon: string; count?: number; children: React.ReactNode; action?: React.ReactNode;
}) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white">
      <header className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5">
        <h3 className="text-sm font-semibold text-slate-800">
          <span className="ml-1.5">{icon}</span>{title}
          {count !== undefined && (
            <span className="mr-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
              {count}
            </span>
          )}
        </h3>
        {action}
      </header>
      <div className="p-4">{children}</div>
    </section>
  );
}

export function InfoGrid({ rows }: {
  rows: { label: string; value: string; tone?: "warn" | "muted" | "good" }[];
}) {
  return (
    <dl className="grid grid-cols-1 gap-x-6 gap-y-2.5 sm:grid-cols-2">
      {rows.map((r) => (
        <div
          key={r.label}
          className="flex items-baseline justify-between gap-3 border-b border-dashed border-slate-100 pb-2"
        >
          <dt className="shrink-0 text-xs text-slate-500">{r.label}</dt>
          <dd
            className={`truncate text-sm font-medium ${
              r.tone === "warn"
                ? "text-amber-600"
                : r.tone === "muted"
                  ? "text-slate-400"
                  : r.tone === "good"
                    ? "text-emerald-700"
                    : "text-slate-800"
            }`}
          >
            {r.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}

export function StatCard({ label, value, hint, tone = "slate", icon }: {
  label: string; value: string | number; hint?: string;
  tone?: "slate" | "green" | "blue" | "purple" | "amber";
  icon?: string;
}) {
  const tones = {
    slate: "bg-slate-800 text-white",
    green: "bg-emerald-50 text-emerald-800",
    blue: "bg-blue-50 text-blue-800",
    purple: "bg-purple-50 text-purple-800",
    amber: "bg-amber-50 text-amber-800",
  } as const;

  return (
    <div className={`rounded-xl p-3.5 ${tones[tone]}`}>
      <p className={`text-xs ${tone === "slate" ? "text-slate-300" : "opacity-70"}`}>
        {icon && <span className="ml-1">{icon}</span>}
        {label}
      </p>
      <p className="mt-1 text-xl font-bold">{value}</p>
      {hint && (
        <p className={`mt-0.5 text-[11px] ${tone === "slate" ? "text-slate-400" : "opacity-60"}`}>
          {hint}
        </p>
      )}
    </div>
  );
}

export function Empty({ text, hint }: { text: string; hint?: string }) {
  return (
    <div className="rounded-lg border border-dashed border-slate-200 py-6 text-center">
      <p className="text-sm text-slate-400">{text}</p>
      {hint && <p className="mt-1 text-xs text-slate-300">{hint}</p>}
    </div>
  );
}

export function Pill({ children, tone = "slate" }: {
  children: React.ReactNode;
  tone?: "slate" | "green" | "red" | "amber" | "blue" | "purple";
}) {
  const tones = {
    slate: "bg-slate-100 text-slate-600",
    green: "bg-emerald-100 text-emerald-700",
    red: "bg-red-100 text-red-700",
    amber: "bg-amber-100 text-amber-700",
    blue: "bg-blue-100 text-blue-700",
    purple: "bg-purple-100 text-purple-700",
  } as const;
  return (
    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${tones[tone]}`}>
      {children}
    </span>
  );
}

export function Skeleton({ rows = 3 }: { rows?: number }) {
  return (
    <div className="space-y-3">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className="h-24 animate-pulse rounded-xl bg-slate-100" />
      ))}
    </div>
  );
}

export const fmtDate = (v?: string | null): string => {
  if (!v) return "—";
  const d = new Date(v);
  return Number.isNaN(d.getTime())
    ? "—"
    : date(d);
};

export const fmtMoney = (amount?: number | string | null, currency = "EGP"): string => {
  if (amount === null || amount === undefined || amount === "") return "—";
  const n = typeof amount === "string" ? parseFloat(amount) : amount;
  if (Number.isNaN(n)) return "—";
  return `${new Intl.NumberFormat("ar-EG", { maximumFractionDigits: 0 }).format(n)} ${currency}`;
};

/** لون شفاف من لون hex — للخلفيات الفاتحة */
export const alpha = (hex: string, a: number): string => {
  const clean = hex.replace("#", "");
  const full = clean.length === 3 ? clean.split("").map((c) => c + c).join("") : clean;
  const r = parseInt(full.slice(0, 2), 16);
  const g = parseInt(full.slice(2, 4), 16);
  const b = parseInt(full.slice(4, 6), 16);
  return `rgba(${r}, ${g}, ${b}, ${a})`;
};