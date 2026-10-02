"use client";

import { useEffect, useState } from "react";
import Modal, { TONE_BUTTON, type UiTone } from "./Modal";
import { IconPencil } from "./icons";
import type { PromptOptions } from "./UIProvider";

type Props = {
  state: (PromptOptions & { uid: number }) | null;
  onClose: (result: string | null) => void;
};

const CUSTOM_VALUE = "__custom__";

/**
 * بديل `prompt()` — مودال فيه حقل إدخال حقيقي.
 *
 * خانتين:
 *  - حقل نص (سطر واحد / متعدد)
 *  - قائمة اختيار (`options`) مع إمكانية «أخرى»
 *
 * const reason = await prompt({ title: "سبب الإلغاء", multiline: true, required: true });
 * const teacher = await prompt({ title: "اختر المعلم", options: [...] });
 */
export default function PromptDialog({ state, onClose }: Props) {
  const [value, setValue] = useState("");
  const [custom, setCustom] = useState("");
  const [isCustom, setIsCustom] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const uid = state?.uid;

  // تهيئة القيمة الافتراضية كل ما المودال ينفتح من جديد
  useEffect(() => {
    if (uid === undefined) return;
    setValue(state?.defaultValue ?? "");
    setCustom("");
    setIsCustom(false);
    setError(null);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [uid]);

  if (!state) return null;

  // نسخة ثابتة من الـ state — عشان الـ closures تشوفها non-null
  const s: PromptOptions & { uid: number } = state;

  const tone: UiTone = s.tone ?? "primary";
  const hasOptions = Boolean(s.options?.length);

  function submit() {
    const raw = hasOptions ? (isCustom ? custom.trim() : value) : value.trim();

    if (s.required && !raw) {
      setError("هذا الحقل مطلوب");
      return;
    }
    if (s.validate) {
      const msg = s.validate(raw);
      if (msg) {
        setError(msg);
        return;
      }
    }
    onClose(raw);
  }

  return (
    <Modal
      key={s.uid}
      open
      onClose={() => onClose(null)}
      title={s.title}
      tone={tone}
      icon={s.icon ?? <IconPencil size={16} />}
      footer={
        <>
          <button
            type="button"
            onClick={() => onClose(null)}
            className="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:bg-slate-50"
          >
            {s.cancelLabel ?? "إلغاء"}
          </button>
          <button
            type="button"
            data-autofocus
            onClick={submit}
            className={`rounded-lg px-4 py-2 text-sm font-medium shadow-sm transition focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 ${TONE_BUTTON[tone]}`}
          >
            {s.confirmLabel ?? "حفظ"}
          </button>
        </>
      }
    >
      {s.message && (
        <p className="mb-3 text-sm leading-relaxed text-slate-600">{s.message}</p>
      )}

      {s.label && (
        <label className="mb-1.5 block text-xs font-medium text-slate-700">{s.label}</label>
      )}

      {hasOptions ? (
        <div className="space-y-2">
          <select
            value={isCustom ? CUSTOM_VALUE : value}
            onChange={(e) => {
              const v = e.target.value;
              if (v === CUSTOM_VALUE) {
                setIsCustom(true);
                setCustom("");
              } else {
                setIsCustom(false);
                setValue(v);
              }
              setError(null);
            }}
            className="w-full rounded-lg border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
          >
            <option value="" disabled>
              اختر…
            </option>
            {s.options!.map((opt) => (
              <option key={opt.value} value={opt.value}>
                {opt.label}
                {opt.description ? ` — ${opt.description}` : ""}
              </option>
            ))}
            {s.allowCustom && <option value={CUSTOM_VALUE}>أخرى — كتابة يدوية</option>}
          </select>

          {isCustom && (
            <input
              autoFocus
              value={custom}
              onChange={(e) => {
                setCustom(e.target.value);
                setError(null);
              }}
              onKeyDown={(e) => {
                if (e.key === "Enter") submit();
              }}
              placeholder={s.placeholder ?? "اكتب هنا…"}
              className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-800 outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
            />
          )}
        </div>
      ) : s.multiline ? (
        <textarea
          autoFocus
          rows={s.rows ?? 3}
          value={value}
          onChange={(e) => {
            setValue(e.target.value);
            setError(null);
          }}
          onKeyDown={(e) => {
            if (e.key === "Enter" && (e.ctrlKey || e.metaKey)) submit();
          }}
          placeholder={s.placeholder ?? "اكتب هنا…"}
          className="w-full resize-y rounded-lg border border-slate-300 px-3 py-2.5 text-sm leading-relaxed text-slate-800 outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
        />
      ) : (
        <input
          autoFocus
          type="text"
          value={value}
          onChange={(e) => {
            setValue(e.target.value);
            setError(null);
          }}
          onKeyDown={(e) => {
            if (e.key === "Enter") submit();
          }}
          placeholder={s.placeholder ?? "اكتب هنا…"}
          className="w-full rounded-lg border border-slate-300 px-3 py-2.5 text-sm text-slate-800 outline-none focus:border-slate-500 focus:ring-2 focus:ring-slate-200"
        />
      )}

      {error && (
        <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-xs font-medium text-red-700">
          {error}
        </p>
      )}
    </Modal>
  );
}
