"use client";

import type { ParentChildView } from "@/lib/api";
import { nextChildId } from "@/lib/parent-portal";

/**
 * ⭐⭐⭐ **PR 4.5 — التبديل السريع بين الأبناء.**
 *
 * ⭐ ولي الأمر عنده أكتر من ابن؟ شريط أسماء فوق كل تبويب: نقرة
 * واحدة بتغيّر الابن — ومن غير رجوع لصفحة أو تحميل كامل.
 *
 * ⭐ `nextChildId` (مختبَرة) بتلفّ من الآخر للأول، والزراير
 * بتظهر بس لما يكون فيه أكتر من ابن.
 */
export default function ParentChildHeader({
  items,
  selectedId,
  parentName,
  parentPhone,
  onSelect,
}: {
  items: ParentChildView[];
  selectedId: number | null;
  parentName: string;
  parentPhone: string | null;
  onSelect: (id: number) => void;
}) {
  const current =
    items.find((child) => child.student.id === selectedId) ?? items[0] ?? null;

  if (!current) return null;

  const previous = nextChildId(items, current.student.id, -1);
  const next = nextChildId(items, current.student.id, 1);
  const many = items.length > 1;

  return (
    <header className="space-y-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <div>
          <h1 className="text-lg font-bold text-slate-900">أبنائي</h1>
          <p className="text-xs text-slate-500">
            {parentName}
            {parentPhone ? ` — ${parentPhone}` : ""}
          </p>
        </div>
        <p className="text-xs text-slate-500">
          {items.length === 1 ? "طالب واحد" : `${items.length} طلاب`}
        </p>
      </div>

      {/* ⭐ الابن الحالي + أسهم التبديل — للموبايل */}
      <div className="flex items-center gap-2 rounded-xl bg-white p-2 shadow-sm ring-1 ring-slate-200">
        <button
          type="button"
          onClick={() => previous !== null && onSelect(previous)}
          disabled={!many}
          aria-label="الابن السابق"
          className="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-lg text-slate-600 transition hover:bg-slate-50 disabled:opacity-30"
        >
          ›
        </button>

        <div className="min-w-0 flex-1 text-center">
          <p className="truncate text-sm font-semibold text-slate-800">
            {current.student.full_name}
          </p>
          <p className="text-[11px] text-slate-500">
            {current.student.student_code}
            {current.relationship ? ` · ${current.relationship}` : ""}
            {current.is_primary ? " · ولي الأمر الأساسي" : ""}
          </p>
        </div>

        <button
          type="button"
          onClick={() => next !== null && onSelect(next)}
          disabled={!many}
          aria-label="الابن التالي"
          className="flex min-h-11 min-w-11 items-center justify-center rounded-lg text-lg text-slate-600 transition hover:bg-slate-50 disabled:opacity-30"
        >
          ‹
        </button>
      </div>

      {/* ⭐ اختيار مباشر — أسرع من الضغط على «التالي» كذا مرة */}
      {many && (
        <div className="-mx-1 flex gap-2 overflow-x-auto px-1 pb-1">
          {items.map((child) => {
            const active = child.student.id === current.student.id;

            return (
              <button
                key={child.student.id}
                type="button"
                onClick={() => onSelect(child.student.id)}
                aria-pressed={active}
                className={`min-h-9 shrink-0 rounded-full px-3 text-xs font-medium ring-1 transition ${
                  active
                    ? "bg-emerald-700 text-white ring-emerald-700"
                    : "bg-white text-slate-600 ring-slate-200 hover:bg-slate-50"
                }`}
              >
                {child.student.full_name}
              </button>
            );
          })}
        </div>
      )}
    </header>
  );
}
