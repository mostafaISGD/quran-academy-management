"use client";

import Modal, { TONE_BUTTON, type UiTone } from "./Modal";
import { IconAlert, IconCheck, IconInfo } from "./icons";
import type { ConfirmOptions } from "./UIProvider";

type Props = {
  state: (ConfirmOptions & { uid: number }) | null;
  onClose: (result: boolean) => void;
};

const DEFAULT_ICON: Record<NonNullable<ConfirmOptions["tone"]>, React.ReactNode> = {
  danger: <IconAlert size={18} />,
  warning: <IconAlert size={18} />,
  primary: <IconInfo size={18} />,
};

/**
 * بديل `confirm()` — مودال تأكيد أنيق.
 *
 * await confirm({ title: "حذف الفاتورة", message: "متأكد؟", tone: "danger" })
 */
export default function ConfirmDialog({ state, onClose }: Props) {
  if (!state) return null;

  const tone: UiTone = state.tone ?? "primary";

  return (
    <Modal
      key={state.uid}
      open
      onClose={() => onClose(false)}
      title={state.title}
      tone={tone}
      icon={state.icon ?? DEFAULT_ICON[tone]}
      footer={
        <>
          <button
            type="button"
            onClick={() => onClose(false)}
            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
          >
            {state.cancelLabel ?? "إلغاء"}
          </button>
          <button
            type="button"
            data-autofocus
            onClick={() => onClose(true)}
            className={`rounded-lg px-4 py-2 text-sm font-medium shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${
              TONE_BUTTON[tone]
            }`}
          >
            <span className="inline-flex items-center gap-1.5">
              {tone === "primary" && <IconCheck size={15} />}
              {state.confirmLabel ?? "تأكيد"}
            </span>
          </button>
        </>
      }
    >
      <p className="text-sm leading-relaxed whitespace-pre-line text-slate-600">{state.message}</p>
    </Modal>
  );
}
