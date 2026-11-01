"use client";

import { useState, type FormEvent } from "react";

import { redeemLessonJoinToken } from "@/lib/api";

/**
 * ⭐⭐ **PR 4.6 — دخول الطفل (< 13) بالـPIN.**
 *
 * ⭐ الرمز بيجي من ولي الأمر في رسالة واتساب/تليفون، ومربوط
 * بحصة واحدة وبيتستخدم مرة واحدة (PR 1.12). الصفحة عامة —
 * الطفل مالوش حساب أصلاً.
 *
 * ⚠️ الصفحة **ما بتعدّلش أي بيانات**: الاستعمال بيسجّل دخول
 * (سطر `lesson_participants`) ويرجّع رابط الحصة. مفيش أي حقل
 * شخصي هنا.
 */
export default function JoinWithPinPage() {
  const [lessonId, setLessonId] = useState("");
  const [pin, setPin] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();

    setBusy(true);
    setError(null);
    setNotice(null);

    try {
      const result = await redeemLessonJoinToken(pin.trim(), Number(lessonId));

      if (result.data.meeting_url) {
        window.location.assign(result.data.meeting_url);
        return;
      }

      setNotice(
        "تم تسجيل دخولك للحصة، بس لسه مفيش رابط اجتماع — كلّم المعلم.",
      );
    } catch (cause) {
      setError(
        cause instanceof Error
          ? cause.message
          : "الرمز مش صحيح — راجع ولي أمرك",
      );
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-slate-50 p-4">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-md rounded-2xl bg-white p-6 shadow-sm ring-1 ring-slate-200"
      >
        <div className="mb-5 text-center">
          <p className="text-3xl">🔑</p>
          <h1 className="mt-2 text-lg font-semibold text-slate-800">
            دخول الحصة بالـPIN
          </h1>
          <p className="mt-1 text-xs leading-5 text-slate-500">
            اكتب رقم الحصة والـPIN اللي ولي أمرك بعتهملك في الرسالة.
          </p>
        </div>

        <label className="mb-3 block text-sm">
          <span className="mb-1 block text-slate-600">رقم الحصة</span>
          <input
            type="number"
            inputMode="numeric"
            min={1}
            required
            value={lessonId}
            onChange={(event) => setLessonId(event.target.value)}
            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100"
          />
        </label>

        <label className="mb-4 block text-sm">
          <span className="mb-1 block text-slate-600">PIN</span>
          <input
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            required
            maxLength={6}
            value={pin}
            onChange={(event) => setPin(event.target.value)}
            placeholder="6 أرقام"
            className="w-full rounded-lg border border-slate-300 px-3 py-2 text-center text-lg tracking-[0.4em] text-slate-800 outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-100"
          />
        </label>

        {error && (
          <p
            role="alert"
            className="mb-3 rounded-lg bg-red-50 p-3 text-center text-sm text-red-700 ring-1 ring-red-200"
          >
            {error}
          </p>
        )}

        {notice && (
          <p
            role="status"
            className="mb-3 rounded-lg bg-amber-50 p-3 text-center text-sm leading-6 text-amber-800 ring-1 ring-amber-200"
          >
            {notice}
          </p>
        )}

        <button
          type="submit"
          disabled={busy}
          className="w-full rounded-xl bg-emerald-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {busy ? "بيتحقق…" : "ادخل الحصة"}
        </button>

        <p className="mt-4 text-center text-[11px] leading-5 text-slate-400">
          الرمز بيشتغل مرة واحدة، ومربوط بحصة واحدة بس.
        </p>
      </form>
    </main>
  );
}
