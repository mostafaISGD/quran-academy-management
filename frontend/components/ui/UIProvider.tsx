"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from "react";
import Toaster from "./Toaster";
import ConfirmDialog from "./ConfirmDialog";
import PromptDialog from "./PromptDialog";
import type { UiTone } from "./Modal";

/* ============================================================
 * الأنواع
 * ========================================================== */

export type ToastTone = "success" | "error" | "warning" | "info";

export type ToastItem = {
  id: number;
  tone: ToastTone;
  title: string;
  description?: string;
  duration: number;
};

export type ConfirmOptions = {
  title: string;
  /** نص السؤال — بيقبل \n */
  message: string;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: "danger" | "warning" | "primary";
  icon?: ReactNode;
};
export type PromptOption = {
  value: string;
  label: string;
  description?: string;
};

export type PromptOptions = {
  title: string;
  message?: string;
  /** نص الحقل فوق الـ input */
  label?: string;
  placeholder?: string;
  defaultValue?: string;
  required?: boolean;
  /** حقل نص متعدد الأسطر بدل input */
  multiline?: boolean;
  rows?: number;
  /** لو موجود: يعرض قائمة اختيار بدل الحقل النصي */
  options?: PromptOption[];
  /** يضيف خيار «أخرى» بيفتح حقل كتابة حر */
  allowCustom?: boolean;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: "primary" | "danger";
  icon?: ReactNode;
  /** راجب validators — يرجّع رسالة خطأ أو null */
  validate?: (value: string) => string | null;
};

type UIContextValue = {
  toast: {
    success: (title: string, description?: string, duration?: number) => void;
    error: (title: string, description?: string, duration?: number) => void;
    warning: (title: string, description?: string, duration?: number) => void;
    info: (title: string, description?: string, duration?: number) => void;
    show: (tone: ToastTone, title: string, description?: string, duration?: number) => void;
    dismiss: (id: number) => void;
  };
  /** Promise<boolean> — بديل confirm */
  confirm: (options: ConfirmOptions) => Promise<boolean>;
  /** Promise<string | null> — بديل prompt */
  prompt: (options: PromptOptions) => Promise<string | null>;
};

const UIContext = createContext<UIContextValue | null>(null);

/* ============================================================
 * الـ Provider
 * ========================================================== */

const MAX_VISIBLE_TOASTS = 4;

export function UIProvider({ children }: { children: ReactNode }) {
  /* ---------- التنبيهات ---------- */
  const [toasts, setToasts] = useState<ToastItem[]>([]);
  const toastId = useRef(0);
  const timers = useRef<Map<number, number>>(new Map());

  const dismiss = useCallback((id: number) => {
    setToasts((prev) => prev.filter((t) => t.id !== id));
    const handle = timers.current.get(id);
    if (handle) {
      window.clearTimeout(handle);
      timers.current.delete(id);
    }
  }, []);

  const show = useCallback(
    (tone: ToastTone, title: string, description?: string, duration?: number) => {
      const ms = duration ?? (tone === "error" ? 6000 : 4000);
      const id = ++toastId.current;
      setToasts((prev) => [...prev, { id, tone, title, description, duration: ms }].slice(-MAX_VISIBLE_TOASTS));
      timers.current.set(
        id,
        window.setTimeout(() => dismiss(id), ms),
      );
    },
    [dismiss],
  );

  // تنظيف كل المؤقتات لما الـ provider يتفكك
  useEffect(
    () => () => {
      timers.current.forEach((h) => window.clearTimeout(h));
      timers.current.clear();
    },
    [],
  );

  const toast = useMemo(
    () => ({
      success: (t: string, d?: string, ms?: number) => show("success", t, d, ms),
      error: (t: string, d?: string, ms?: number) => show("error", t, d, ms),
      warning: (t: string, d?: string, ms?: number) => show("warning", t, d, ms),
      info: (t: string, d?: string, ms?: number) => show("info", t, d, ms),
      show,
      dismiss,
    }),
    [show, dismiss],
  );

  /* ---------- نافذة التأكيد ---------- */
  const [confirmState, setConfirmState] = useState<(ConfirmOptions & { uid: number }) | null>(null);
  const confirmResolver = useRef<((v: boolean) => void) | null>(null);
  const confirmUid = useRef(0);

  const confirm = useCallback((options: ConfirmOptions) => {
    setConfirmState({ ...options, uid: ++confirmUid.current });
    return new Promise<boolean>((resolve) => {
      confirmResolver.current = resolve;
    });
  }, []);

  const closeConfirm = useCallback((result: boolean) => {
    setConfirmState(null);
    confirmResolver.current?.(result);
    confirmResolver.current = null;
  }, []);

  /* ---------- نافذة الإدخال ---------- */
  const [promptState, setPromptState] = useState<(PromptOptions & { uid: number }) | null>(null);
  const promptResolver = useRef<((v: string | null) => void) | null>(null);
  const promptUid = useRef(0);

  const prompt = useCallback((options: PromptOptions) => {
    setPromptState({ ...options, uid: ++promptUid.current });
    return new Promise<string | null>((resolve) => {
      promptResolver.current = resolve;
    });
  }, []);

  const closePrompt = useCallback((result: string | null) => {
    setPromptState(null);
    promptResolver.current?.(result);
    promptResolver.current = null;
  }, []);

  const value = useMemo<UIContextValue>(
    () => ({ toast, confirm, prompt }),
    [toast, confirm, prompt],
  );

  return (
    <UIContext.Provider value={value}>
      {children}

      <ConfirmDialog state={confirmState} onClose={closeConfirm} />
      <PromptDialog state={promptState} onClose={closePrompt} />
      <Toaster items={toasts} onDismiss={dismiss} />
    </UIContext.Provider>
  );
}

/* ============================================================
 * الـ Hooks
 * ========================================================== */

/** الوصول لكل أدوات الـ UI: toast / confirm / prompt */
export function useUI(): UIContextValue {
  const ctx = useContext(UIContext);
  if (!ctx) {
    throw new Error("useUI لازم يكون جوّه <UIProvider> — راجع app/(dashboard)/layout.tsx");
  }
  return ctx;
}

/** اختصار لو محتاج التنبيهات بس */
export function useToast() {
  return useUI().toast;
}

export type { UiTone };
