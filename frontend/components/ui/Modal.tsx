"use client";

import { useEffect, useRef, type ReactNode } from "react";

export type UiTone = "danger" | "warning" | "info" | "success" | "primary";

/** ألوان الأيقونة حسب نوع العملية */
export const TONE_ICON_WRAP: Record<UiTone, string> = {
  danger: "bg-red-50 text-red-600 ring-red-100",
  warning: "bg-amber-50 text-amber-600 ring-amber-100",
  info: "bg-slate-100 text-slate-600 ring-slate-200",
  success: "bg-green-50 text-green-600 ring-green-100",
  primary: "bg-slate-800 text-white ring-slate-800",
};

/** ألوان زر التأكيد حسب نوع العملية */
export const TONE_BUTTON: Record<UiTone, string> = {
  danger: "bg-red-600 text-white hover:bg-red-700 focus-visible:outline-red-600",
  warning: "bg-amber-500 text-white hover:bg-amber-600 focus-visible:outline-amber-500",
  info: "bg-slate-800 text-white hover:bg-slate-700 focus-visible:outline-slate-800",
  success: "bg-green-600 text-white hover:bg-green-700 focus-visible:outline-green-600",
  primary: "bg-slate-800 text-white hover:bg-slate-700 focus-visible:outline-slate-800",
};

type ModalProps = {
  open: boolean;
  onClose: () => void;
  title: string;
  /** أيقونة دائرية فوق/جنب العنوان */
  icon?: ReactNode;
  tone?: UiTone;
  /** المحتوى الداخلي (وصف أو حقل إدخال) */
  children?: ReactNode;
  /** أزرار الأسفل */
  footer?: ReactNode;
  /** عرض البانر — Tailwind width class */
  width?: string;
  /** إيقاف الإغلاق بالضغط على الخلفية */
  staticBackdrop?: boolean;
};

/** يمنع تمرير الصفحة ورا المودال + رجوع التركيز بعد الإغلاق */
let scrollLockCount = 0;
function lockScroll() {
  scrollLockCount += 1;
  if (scrollLockCount === 1) document.body.style.overflow = "hidden";
}
function unlockScroll() {
  scrollLockCount = Math.max(0, scrollLockCount - 1);
  if (scrollLockCount === 0) document.body.style.overflow = "";
}

/**
 * مودال مشترك — الأساس اللي بتبني عليه ConfirmDialog و PromptDialog.
 * بيدعم: إغلاق بـ ESC، الضغط على الخلفية، قفل تمرير الصفحة، RTL.
 */
export default function Modal({
  open,
  onClose,
  title,
  icon,
  tone = "primary",
  children,
  footer,
  width = "max-w-md",
  staticBackdrop = false,
}: ModalProps) {
  const panelRef = useRef<HTMLDivElement>(null);

  // ESC للإغلاق
  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === "Escape") onClose();
    };
    document.addEventListener("keydown", onKey);
    return () => document.removeEventListener("keydown", onKey);
  }, [open, onClose]);

  // قفل تمرير الصفحة
  useEffect(() => {
    if (!open) return;
    lockScroll();
    return unlockScroll;
  }, [open]);

  // التركيز على أول حقل إدخال (أو البانل نفسه)
  useEffect(() => {
    if (!open) return;
    const t = window.setTimeout(() => {
      const panel = panelRef.current;
      if (!panel) return;
      const field = panel.querySelector<HTMLElement>(
        "input:not([type=hidden]), textarea, select, button[data-autofocus]",
      );
      (field ?? panel).focus();
    }, 40);
    return () => window.clearTimeout(t);
  }, [open]);

  if (!open) return null;

  return (
    <div
      dir="rtl"
      className="fixed inset-0 z-[90] flex items-center justify-center p-4"
      role="presentation"
    >
      {/* الخلفية */}
      <div
        onClick={staticBackdrop ? undefined : onClose}
        className="ui-anim-backdrop absolute inset-0 bg-slate-900/45 backdrop-blur-[2px]"
        aria-hidden="true"
      />

      {/* البانر */}
      <div
        ref={panelRef}
        role="dialog"
        aria-modal="true"
        aria-label={title}
        tabIndex={-1}
        className={`ui-anim-dialog relative w-full ${width} rounded-2xl bg-white shadow-2xl shadow-slate-900/25 ring-1 ring-slate-900/5 outline-none`}
      >
        <div className="flex items-start gap-3 px-5 pt-5">
          {icon && (
            <span
              className={`flex size-9 shrink-0 items-center justify-center rounded-full ring-4 ${TONE_ICON_WRAP[tone]}`}
              aria-hidden="true"
            >
              {icon}
            </span>
          )}
          <h2 className="flex-1 pt-1.5 text-base font-semibold text-slate-900">{title}</h2>
        </div>

        {children && <div className="px-5 pb-1 pt-3">{children}</div>}

        {footer && (
          <div className="flex items-center justify-end gap-2 px-5 py-4">{footer}</div>
        )}
      </div>
    </div>
  );
}
