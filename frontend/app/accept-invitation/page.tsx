import { Suspense } from "react";

import AcceptInvitationForm from "./accept-form";

/**
 * ⭐⭐⭐ قبول الدعوة — الغلاف.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليها غلاف `Suspense` — زي `/login` بالظبط
 *
 * ⭐ `accept-form.tsx` بيستخدم `useSearchParams()` عشان يقرا
 * `?token=`. في Next 16 الـhook ده في **client component** بيخلي
 * الصفحة تخرج من الـstatic prerendering، والقاعدة إن أي component
 * بيستخدمه لازم يكون جوّه `<Suspense>`:
 *
 *     ⨯ useSearchParams() should be wrapped in a suspense boundary
 *       at page "/accept-invitation"
 *
 * ⭐ وده بيقفل **البناء كله**، مش تحذير.
 *
 * ⭐ الـ`fallback` لازم يكون **نفس الشكل** لا هيكل مختلف: المستخدم
 * بيفتح رابط الدعوة مرة واحدة، فلو شاف spinner هيحس إن الرابط مش
 * شغال. نفس الـskeleton بتاع `/login` بالظبط.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الصفحة **مش** جوه `app/(dashboard)` عن قصد
 *
 * ⭐ `app/(dashboard)` معناها «محمية بجلسة». والدعوة **قبل**
 * الجلسة بحكم طبيعةFeature. فلو حطينا الصفحة هناك كان لازم
 * نكسر حماية الـlayout عشان صفحة واحدة.
 *
 * ⭐ `(group)` في Next مالهوش أي تأثير على الـURL، فصفحة `/login`
 * كمان برّه الـgroup. نفس المنطق.
 *
 * @see accept-form.tsx
 */
export default function AcceptInvitationPage() {
  return (
    <Suspense fallback={<AcceptSkeleton />}>
      <AcceptInvitationForm />
    </Suspense>
  );
}

/**
 * ⭐ نفس تصميم الاستمارة بالظبط — عرض وارتفاع ومكان العناوين.
 *
 * ⭐ مقصود إنه **مطابق** للـform مش تقريبي: مافيش layout shift
 * لما التوكن يبقى موجود (وهيالحالة الطبيعية).
 */
function AcceptSkeleton() {
  return (
    <main dir="rtl" className="flex min-h-screen items-center justify-center bg-slate-50">
      <form className="w-full max-w-sm rounded-xl border border-slate-200 bg-white p-8 shadow-sm">
        <h1 className="mb-2 text-xl font-semibold text-slate-800">
          أهلاً بك في أكاديمية القرآن
        </h1>

        <div className="mb-6 h-4 w-48 animate-pulse rounded bg-slate-200" />

        <div className="mb-1 block h-4 w-32 animate-pulse rounded bg-slate-200" />
        <div className="mb-4 h-9 w-full animate-pulse rounded-lg bg-slate-100" />

        <div className="mb-1 block h-4 w-36 animate-pulse rounded bg-slate-200" />
        <div className="mb-4 h-9 w-full animate-pulse rounded-lg bg-slate-100" />

        <div className="h-9 w-full animate-pulse rounded-lg bg-slate-200" />
      </form>
    </main>
  );
}