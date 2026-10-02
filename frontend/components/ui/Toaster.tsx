"use client";

import { IconAlert, IconCheck, IconInfo, IconX } from "./icons";
import type { ToastItem } from "./UIProvider";

/** ستايل كل نوع تنبيه: حافة ملوّنة + أيقونة + لون شريط التقدّم */
const TONES: Record<
  ToastItem["tone"],
  { bar: string; ring: string; iconWrap: string; icon: React.ReactNode }
> = {
  success: {
    bar: "bg-emerald-500",
    ring: "ring-emerald-100",
    iconWrap: "bg-emerald-50 text-emerald-600",
    icon: <IconCheck size={16} />,
  },
  error: {
    bar: "bg-red-500",
    ring: "ring-red-100",
    iconWrap: "bg-red-50 text-red-600",
    icon: <IconX size={16} />,
  },
  warning: {
    bar: "bg-amber-500",
    ring: "ring-amber-100",
    iconWrap: "bg-amber-50 text-amber-600",
    icon: <IconAlert size={16} />,
  },
  info: {
    bar: "bg-slate-700",
    ring: "ring-slate-200",
    iconWrap: "bg-slate-100 text-slate-600",
    icon: <IconInfo size={16} />,
  },
};

type ToasterProps = {
  items: ToastItem[];
  onDismiss: (id: number) => void;
};

/**
 * حاوية التنبيهات — أعلى الشاشة في النص.
 * بتعرض آخر 4 تنبيهات، كل واحد بيشيل نفسه بعد مدته.
 */
export default function Toaster({ items, onDismiss }: ToasterProps) {
  return (
    <div
      dir="rtl"
      aria-live="polite"
      aria-atomic="false"
      className="pointer-events-none fixed inset-x-0 top-4 z-[100] flex flex-col items-center gap-2 px-4"
    >
      {items.map((t) => {
        const tone = TONES[t.tone];
        return (
          <div
            key={t.id}
            role="status"
            className={`ui-anim-toast pointer-events-auto relative w-full max-w-md overflow-hidden rounded-xl bg-white shadow-lg shadow-slate-900/10 ring-4 ${tone.ring}`}
          >
            <div className="flex items-start gap-3 px-4 py-3">
              <span
                className={`mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-full ${tone.iconWrap}`}
                aria-hidden="true"
              >
                {tone.icon}
              </span>

              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-900">{t.title}</p>
                {t.description && (
                  <p className="mt-0.5 text-xs leading-relaxed text-slate-500">{t.description}</p>
                )}
              </div>

              <button
                type="button"
                onClick={() => onDismiss(t.id)}
                aria-label="إغلاق التنبيه"
                className="-me-1 -mt-1 shrink-0 rounded-lg p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
              >
                <IconX size={14} />
              </button>
            </div>

            {/* شريط التقدّم: الوقت المتبقي قبل الاختفاء التلقائي */}
            <div className="h-1 w-full bg-slate-100">
              <div
                className={`ui-anim-progress h-full ${tone.bar}`}
                style={{ animationDuration: `${t.duration}ms` }}
              />
            </div>
          </div>
        );
      })}
    </div>
  );
}
