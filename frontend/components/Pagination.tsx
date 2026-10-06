"use client";


import { num } from "@/lib/format";
type PaginationProps = {
  /** الصفحة الحالية (1-indexed) */
  page: number;
  /** إجمالي عدد الصفحات */
  lastPage: number;
  /** إجمالي عدد السجلات */
  total: number;
  /** حجم الصفحة */
  perPage: number;
  /** دالة التنقل — تستقبل رقم الصفحة الجديدة */
  onChange: (page: number) => void;
  /** هل فيه تحميل جاري؟ */
  loading?: boolean;
  /** اسم العنصر (مثال: "طالب") */
  itemLabel?: string;
  /** تسمية مخصصة للسجلات المجمّعة (مثال: "دفعة") */
  totalLabel?: string;
};

/** سهم يمين (السابق في واجهة RTL) */
function ChevronRight({ size = 15 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M9 18l6-6-6-6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/** سهم شمال (التالي في واجهة RTL) */
function ChevronLeft({ size = 15 }: { size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" fill="none" aria-hidden="true">
      <path d="M15 18l-6-6 6-6" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
    </svg>
  );
}

/**
 * Pagination مشترك لكل صفحات النظام.
 *
 * بيعرض: "عرض 1 — 100 من 150 طالب" + أزرار التنقل.
 * بيستخدم تنسيق الأرقام العربية للأرقام الكبيرة (1,234).
 */
export default function Pagination({
  page,
  lastPage,
  total,
  perPage,
  onChange,
  loading = false,
  itemLabel = "سجل",
  totalLabel,
}: PaginationProps) {
  // مفيش صفحات تانية = متعملش حاجة
  if (lastPage <= 1) return null;

  const from = (page - 1) * perPage + 1;
  const to = Math.min(page * perPage, total);

  // لو الأرقام كبيرة، نعرض نطاق مختصر (1 … 4 5 6 … 20)
  const pages: (number | "gap")[] = [];
  const window = 1;

  for (let i = 1; i <= lastPage; i++) {
    const isEdge = i === 1 || i === lastPage;
    const isNear = Math.abs(i - page) <= window;

    if (isEdge || isNear) {
      pages.push(i);
    } else if (pages[pages.length - 1] !== "gap") {
      pages.push("gap");
    }
  }

  const label = totalLabel ?? itemLabel;

  return (
    <div className="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3">
      <p className="text-sm text-slate-500">
        عرض{" "}
        <span className="font-medium text-slate-700">{num(from)}</span>
        {" — "}
        <span className="font-medium text-slate-700">{num(to)}</span>
        {" من "}
        <span className="font-medium text-slate-700">{num(total)}</span>{" "}
        {label}
      </p>

      <div className="flex items-center gap-1">
        {/* السابق */}
        <button
          type="button"
          onClick={() => onChange(page - 1)}
          disabled={page === 1 || loading}
          aria-label="الصفحة السابقة"
          className="flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          <ChevronRight size={15} />
          <span className="hidden sm:inline">السابق</span>
        </button>

        {/* أرقام الصفحات */}
        {pages.map((p, idx) =>
          p === "gap" ? (
            <span key={`gap-${idx}`} className="px-1 text-sm text-slate-400">
              …
            </span>
          ) : (
            <button
              key={p}
              type="button"
              onClick={() => onChange(p)}
              disabled={loading}
              aria-current={p === page ? "page" : undefined}
              className={`min-w-[34px] rounded-lg px-2.5 py-1.5 text-sm transition ${
                p === page
                  ? "bg-slate-800 font-medium text-white"
                  : "border border-slate-300 text-slate-600 hover:bg-slate-50"
              }`}
            >
              {num(p)}
            </button>
          ),
        )}

        {/* التالي */}
        <button
          type="button"
          onClick={() => onChange(page + 1)}
          disabled={page === lastPage || loading}
          aria-label="الصفحة التالية"
          className="flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-40"
        >
          <span className="hidden sm:inline">التالي</span>
          <ChevronLeft size={15} />
        </button>
      </div>
    </div>
  );
}