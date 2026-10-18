"use client";

import { useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";

import { acceptInvitation, ApiError } from "@/lib/api";

/**
 * ⭐⭐⭐ قبول الدعوة — اختيار كلمة المرور الأولى.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ الصفحة دي **مفتوحة لغير المسجّلين** — وده مقصود
 *
 * ⭐ المدعو **مالوش حساب**. هو أكتر حد محتاج يسجّل دخول في
 * النظام، وده بالظبط معنى الدعوة.
 *
 * ⭐ sided لازم `/accept-invitation` في `PUBLIC_PATHS`
 * (`lib/route-access.ts`) — من غير كده الـ`proxy` يعمل redirect لـ
 * `/login`، ومدعو الأكاديمية يبقى عايز يدخل لحاجة مش عنده أصلاً.
 * الواجهة والـbackend بيقولوا نفس الكلام: `InvitationController::
 * accept` **مافيش** `auth:sanctum`.
 *
 * @see app/accept-invitation/page.tsx
 * @see lib/route-access.ts
 */

/** ⭐ أقل طول بتقبله `StrongPassword` في الـbackend */
const MIN_PASSWORD_LENGTH = 12;

export default function AcceptInvitationForm() {
  const router = useRouter();
  const search = useSearchParams();

  // ⭐⭐ التوكن **ديكود** هنا ولا كما هو؟
  //
  // ⭐ `InvitationService` بيولّد **base64url** (`-` و `_` بدل `+`
  // و `/`، من غير padding). فالتوكن **آمن في الـURL زي ما هو**
  // و`URLSearchParams.get()` بيرجّعه زي ما اتبعت.
  //
  // ⭐⚠️ لو استخدمنا base64 العادي كان التوكن هينزل `%2B` و `%2F`
  // في الرابط، والـbackend كان بيرجّع رسالة «الدعوة مش صالحة» على
  // دعوة سليمة. الاختبار `test_the_token_is_512_bits...` بيحرس
  // `rawurlencode(token) === token` عشان，获取 ده مايفصلش.
  const token = search.get("token") ?? "";

  const [password, setPassword] = useState("");
  const [confirmation, setConfirmation] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);

  /** ⭐⭐ التوكن **ناقص** — مش «السجل» في حالة تحميل */
  if (!token) {
    return (
      <main dir="rtl" className="flex min-h-screen items-center justify-center bg-slate-50">
        <div className="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
          <h1 className="mb-3 text-xl font-semibold text-slate-800">رابط الدعوة ناقص</h1>
          <p className="mb-6 text-sm text-slate-600">
           الرابط اللي فتحته مافيهوش رمز. اطلب من إدارة الأكاديمية تبعتلك دعوة جديدة.
          </p>
          <a
            href="/login"
            className="block w-full rounded-lg bg-slate-800 px-3 py-2 text-center text-sm font-medium text-white hover:bg-slate-700"
          >
            صفحة الدخول
          </a>
        </div>
      </main>
    );
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);

    // ⭐⭐ التحقق في المتصفح **مختصر** — مش بديل عن الـbackend.
    // الغرض منع طلبnetwork واضح، والقرار الحقيقي في
    // `StrongPassword` على السيرفر.
    if (password.length < MIN_PASSWORD_LENGTH) {
      setError(`كلمة المرور لازم ${MIN_PASSWORD_LENGTH} حرف على الأقل`);
      return;
    }

    if (password !== confirmation) {
      setError("كلمتا المرور مش متطابقتين");
      return;
    }

    setLoading(true);

    try {
      await acceptInvitation(token, password, confirmation);

      // ⭐ بنوديه `/login` مش `/dashboard`: قبول الدعوة **مش**
      // تسجيل دخول — الحساب اتفعّل بس الجلسة لسه مفيش.
      // والـbackend بيرجّع `message` عربي فيه «سجّل دخولك».
      router.push("/login");
    } catch (err) {
      // ⭐⭐ الـ`410` (منتهي/ملغي/مستخدم مرتين) بييجي برسالة
      // عربية من `InvitationGoneException` — و`ApiError` بيمررها
      // في `message`. فالرسالة دي **مفيدة للمستخدم فعلاً**:
      // بتقوله «اطلب واحدة جديدة» بدل رقم غامض.
      if (err instanceof ApiError && err.status === 410) {
        setError(err.message);
      } else if (err instanceof ApiError) {
        setError(err.details ?? err.message);
      } else {
        setError("حصل خطأ غير متوقع");
      }
    } finally {
      setLoading(false);
    }
  }

  return (
    <main dir="rtl" className="flex min-h-screen items-center justify-center bg-slate-50">
      <form
        onSubmit={handleSubmit}
        className="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-8 shadow-sm"
      >
        <h1 className="mb-2 text-xl font-semibold text-slate-800">
          أهلاً بك في أكاديمية القرآن
        </h1>
        <p className="mb-6 text-sm text-slate-600">
          اختار كلمة مرور لحسابك. مفيش حد تاني بيعرفها.
        </p>

        <label className="mb-1 block text-sm text-slate-600">كلمة المرور</label>
        <input
          type="password"
          required
          minLength={MIN_PASSWORD_LENGTH}
          autoComplete="new-password"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          className="mb-4 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
        />

        <label className="mb-1 block text-sm text-slate-600">تأكيد كلمة المرور</label>
        <input
          type="password"
          required
          minLength={MIN_PASSWORD_LENGTH}
          autoComplete="new-password"
          value={confirmation}
          onChange={(e) => setConfirmation(e.target.value)}
          className="mb-4 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none"
        />

        {error && <p className="mb-4 text-sm text-red-600">{error}</p>}

        <button
          type="submit"
          disabled={loading}
          className="w-full rounded-lg bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50"
        >
          {loading ? "جارٍ التفعيل..." : "فعّل حسابي"}
        </button>
      </form>
    </main>
  );
}