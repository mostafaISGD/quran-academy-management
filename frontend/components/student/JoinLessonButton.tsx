"use client";

import { useState } from "react";

import { joinStudentLesson, type StudentLessonView } from "@/lib/api";
import { canJoinLesson } from "@/lib/student-portal";

/**
 * ⭐⭐ زر «انضم للحصة» — البوابة الوحيدة اللي بتسجّل المشارك.
 *
 * ⭐ السيرفر بيسجّل سطر `lesson_participants` (مرة واحدة) ويرجّع
 * رابط الاجتماع. الواجهة بتنتقل للرابط مباشرة — مش `window.open`
 * بعد `await`، لأن المتصفح بيعتبره popup مش من تفاعل المستخدم
 * فيمنعه.
 *
 * ⚠️ الزر بيظهر بس لما `canJoinLesson` تقول إن الحصة قابلة
 * للدخول — نفس حكم `LessonJoinService` على السيرفر.
 */
export function JoinLessonButton({ lesson }: { lesson: StudentLessonView }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!canJoinLesson(lesson)) return null;

  async function handleJoin() {
    setBusy(true);
    setError(null);

    try {
      const result = await joinStudentLesson(lesson.id);
      window.location.assign(result.data.join_url);
    } catch (cause) {
      setError(
        cause instanceof Error
          ? cause.message
          : "مش قادر أفتح الحصة دلوقتي — حاول تاني",
      );
      setBusy(false);
    }
  }

  return (
    <div className="flex flex-col items-end gap-1">
      <button
        type="button"
        onClick={handleJoin}
        disabled={busy}
        className="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-medium text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
      >
        {busy ? "بيفتح الحصة…" : "🎥 انضم للحصة"}
      </button>

      {error && (
        <p role="alert" className="max-w-52 text-[11px] leading-4 text-red-600">
          {error}
        </p>
      )}
    </div>
  );
}
