"use client";

import type { Program } from "@/lib/api";
import { Pill, alpha } from "./ProgramPrimitives";

/**
 * كارت البرنامج.
 *
 * البرنامج كيان ليه وصف وتصنيفات ولون — الجدول بيضيّع ده، فالكارت
 * هو الشكل الأساسي، والجدول بييجي كخيار تاني.
 *
 * الأرقام هنا ملخّص فقط — مش إدارة. الضغط على «عرض البرنامج» هو
 * اللي بيفتح الملف.
 */
export default function ProgramCard({
  program, onOpen,
}: {
  program: Program;
  onOpen: (p: Program) => void;
}) {
  const color = program.color ?? "#64748b";
  const active = program.status === "active";
  const cats = program.categories ?? [];
  // نعرض ٣ تصنيفات بس على الكارت — الباقي في الملف
  const shown = cats.slice(0, 3);
  const rest = cats.length - shown.length;

  return (
    <article
      className="group flex flex-col overflow-hidden rounded-xl border border-slate-200 bg-white transition hover:shadow-md"
      style={{ borderTopColor: color, borderTopWidth: 3 }}
    >
      {/* ===== الترويسة: الصورة/الأيقونة + الاسم ===== */}
      <div className="flex items-start gap-3 p-4 pb-3">
        {program.image_url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            src={program.image_url}
            alt={program.name}
            className="h-12 w-12 shrink-0 rounded-lg object-cover"
          />
        ) : (
          <div
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-lg text-lg font-bold"
            style={{ backgroundColor: alpha(color, 0.12), color }}
          >
            {program.name.trim().charAt(0)}
          </div>
        )}

        <div className="min-w-0 flex-1">
          <h3 className="truncate font-semibold text-slate-800" title={program.name}>
            {program.name}
          </h3>
          {program.description ? (
            <p className="mt-0.5 line-clamp-2 text-xs leading-relaxed text-slate-500">
              {program.description}
            </p>
          ) : (
            <p className="mt-0.5 text-xs text-slate-300">مفيش وصف</p>
          )}
        </div>

        <Pill tone={active ? "green" : "red"}>{active ? "نشط" : "غير نشط"}</Pill>
      </div>

      {/* ===== التصنيفات ===== */}
      {cats.length > 0 && (
        <div className="flex flex-wrap gap-1 px-4 pb-3">
          {shown.map((c) => (
            <span
              key={c.id}
              className="inline-flex items-center gap-1 rounded-md bg-slate-50 px-1.5 py-0.5 text-[11px] text-slate-600"
            >
              {c.icon && <span>{c.icon}</span>}
              {c.name}
            </span>
          ))}
          {rest > 0 && (
            <span className="inline-flex items-center rounded-md bg-slate-50 px-1.5 py-0.5 text-[11px] text-slate-400">
              +{rest}
            </span>
          )}
        </div>
      )}

      {/* ===== الأرقام: ملخّص، مش إدارة ===== */}
      <dl className="grid grid-cols-4 gap-px border-y border-slate-100 bg-slate-100 text-center">
        {[
          { label: "الطلاب", value: program.students_count ?? 0 },
          { label: "المعلمون", value: program.teachers_count ?? 0 },
          { label: "الباقات", value: program.plans_count ?? 0 },
          { label: "المستويات", value: program.levels_count ?? 0 },
        ].map((s) => (
          <div key={s.label} className="bg-white px-1 py-2.5">
            <dd className="text-base font-bold text-slate-800">{s.value}</dd>
            <dt className="mt-0.5 text-[11px] text-slate-400">{s.label}</dt>
          </div>
        ))}
      </dl>

      {/* ===== الإجراء ===== */}
      <button
        onClick={() => onOpen(program)}
        className="mt-auto px-4 py-2.5 text-sm font-medium text-slate-600 transition hover:bg-slate-50 hover:text-slate-900"
      >
        عرض البرنامج ←
      </button>
    </article>
  );
}