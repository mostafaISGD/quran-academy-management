import { NextResponse, type NextRequest } from "next/server";

import { decideRoute, type AccessUser } from "@/lib/route-access";

/**
 * ⭐⭐⭐ `proxy.ts` — **مش** `middleware.ts`.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️⚠️⚠️ **Next 16 غيّر الاسم**
 *
 * من Next 16، اللي كان `middleware.ts` بقى **`proxy.ts`** والدالة
 * بتبقى `proxy`. الملف القديم **مش** بيتقرا — فحماية كاملة بتتسرّب
 * من غير أي تحذير ولا error.
 *
 * ⭐ الملف ده في **جذر المشروع**، جنب `app/`. ومسموح ملف واحد بس
 * في المشروع كله، فأي proxy تاني لازم يتعمل `import` من هنا.
 *
 * @see node_modules/next/dist/docs/01-app/01-getting-started/16-proxy.md
 * @see node_modules/next/dist/docs/01-app/02-guides/authentication.md
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ إيه اللي بيعمله، وإيه اللي **مش** بيعمله
 *
 * ⚠️ **ده مش طبقة الأمان.** ده فحص **تفاؤلي** (optimistic) بس:
 *
 *   • بيعملها: «فيه كوكي جلسة ولا لأ» + «الدور الخاص بيتوجه لبيته»
 *   • **مش** بيعملها: قراءة محتوى الكوكي، أو أي طلب للداتابيز
 *
 * ⭐ ليش؟ كوكي Laravel **مشفّر** بمفتاح تطبيقه، والـNext مالوش
 * المفتاح ده. فالـNext server يقدر يشوف إن في `laravel_session`،
 * بس **مش** يقدر يقرأ مين جواها.
 *
 * ⭐ والأمان الحقيقي في مكانين:
 *
 *   ① `CheckPermission` على كل راو في `routes/api.php` (البند 7)
 *   ② `lib/dal.ts` — بيسأل الـAPI، والـAPI هو اللي بيتحقق
 *
 * ⭐ ومحدش يقدر يتخطّى الـproxy بتمرير `laravel_session=حاجة` —
 * الطلب اللي هيتبعت للـAPI هيرفضه. الـproxy بيوفّر تجربة، مش أمان.
 *
 * ⭐⭐ وثائق Next بتقولها بالحرف: Proxy «is _not_ intended for slow
 * data fetching … it should not be used as a full session management
 * or authorization solution».
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ ليه نعمله أصلاً وهو مش أمان؟
 *
 * ① **تجربة**: حد ماعداش بيفتح `/students` من غير جلسة ويشوف
 *    صفحة فاضية ويقرأ «403». ده بيوديه لـ`/login`.
 *
 * ② **-performance**: من غيره، كل صفحة بتعمل request بترجع 401،
 *    والصفحات بتحمّل غلط.
 *
 * ③ ⭐ **منع حلقة إعادة التوجيه**: لو رجّعنا `/login` كل ما
 *    `user = null`، الـlayout هيوديه `/dashboard`… واللي بيحصل
 *    **loop لا نهائي** بين الراوتين. عشان كده `decideRoute` بتسمح
 *    لما المستخدم مجهول، والتحقق الحقيقي في `lib/dal.ts`.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه المنطق في `lib/route-access.ts` مش هنا
 *
 * ⭐ Vitest هنا بيشتغل في بيئة `node` — مافيش Next runtime
 * ومافيش React renderer. فلو المنطق كان جوه الـproxy، مش هيقدر
 * حد يختبره. فصلناه عشان يكون **قابل للاختبار**،
 * والـproxy بقى غلاف رقيق.
 */

/**
 * ⭐⭐⭐⭐ **اسم كوكي الجلسة — من `NEXT_PUBLIC_SESSION_COOKIE`،
 * مش ثابت مكتوب هنا.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه ده بقى متغير مش hardcoded
 *
 * ⭐ `SessionAuthenticationTest` (الـbackend) كان بيدوّر على
 * `laravel_session`. والاسم الفعلي على السيرفر كان
 * `quran_academy_session` — لأن `config/session.php` كان بيحسبه
 * من `APP_NAME`:
 *
 *     Str::slug(env('APP_NAME')).'_session'
 *
 * ⭐⭐⭐ النتيجة على **`NEXT_PUBLIC_SESSION_COOKIE` ناقصة**: كل
 * صفحة محمية بتعمل redirect لـ`/login` — **حتى لمستخدم داخل
 * فعلاً**. الـlogin شغّال والـproxy **ميت بالكامل**، ومافيش
 * error ولا build failure.
 *
 * ⭐ الـfallback لسه `laravel_session` عشان الشغل من غير `.env`
 * ميوقعّرش — بس **الاسم في مكان واحد**، والـbackend بيقرا نفس
 * المتغير.
 *
 * ⚠️ **`NEXT_PUBLIC_` مقصود**: الاسم بيتقرا في الـproxy اللي
 * بيشتغل على الـedge/server runtime. من غير البادئة دي Next
 * بيرفض يقرا المتغير أصلاً في `proxy.ts`.
 *
 * @see backend/config/session.php
 * @see tests/session-cookie.test.ts
 */
const SESSION_COOKIE =
  process.env.NEXT_PUBLIC_SESSION_COOKIE ?? "laravel_session";

/**
 * ⭐⭐ الكوكي موجود؟
 *
 * ⚠️ **ده كل اللي بنقدر نعمله.** الكوكي مشفّر، فمافيش أي حاجة
 * نقدر نتحقق منها جواه. فالإجابة «أيوه» معناها «المتصفح عنده
 * كوكي باسم ده» مش «المستخدم داخل».
 *
 * ⭐ والمستخدم اللي بيعدّي بـكوكي مزوّر بيوصل للـAPI، والـAPI
 * بيرفضه بـ**401** — وده هو الرفض الحقيقي.
 */
function hasSessionCookie(request: NextRequest): boolean {
  return Boolean(request.cookies.get(SESSION_COOKIE)?.value);
}

/**
 * ⭐⭐ المستخدم **غير معروف** في الـproxy — عن قصد.
 *
 * ⚠️ الـproxy بيشتغل على **كل** راوت، حتى الـprefetched ones.
 * فلو جببنا المستخدم بـ`fetch` هنا، كل مستخدم على كل راوت
 * هيعمل request إضافي — وده بالظبط اللي وثائق Next بتحذّر منه.
 *
 * ⭐ والتحقق الحقيقي في `lib/dal.ts`.
 */
function readUser(request: NextRequest): AccessUser {
  void request;
  return null;
}

/**
 * ⭐⭐⭐ **وضع المستخدم الواحد**: مافيش تحويل لدخول.
 *
 * ⭐ قرار المشروع: مستخدم واحد هو الأدمن، والدخول تلقائي من
 * `AutoLoginAdmin` في الـbackend. فمافيش صفحة `/login` أصلا.
 *
 * ⭐ الملف لسه موجود لأن Next محتاج غلاف واحد على الأقل، ولأن
 * `decideRoute` تحت毯 لسه له اختبارات (سلوك مرجعي لو رجعنا
 * لنموذج متعدد المستخدمين يوم). بس هو **مش** بيحوّل حد.
 */
export function proxy(request: NextRequest): NextResponse {
  void request;
  void readUser;
  void hasSessionCookie;
  void decideRoute;

  return NextResponse.next();
}

/**
 * ⭐⭐⭐ الـmatcher — بيحدد فين الـproxy **مش** هيشتغل.
 *
 * ⚠️ **مش** كل حاجة. الـproxy بيتنفّذ قبل كل راو (حتى اللي
 * prefetched)، فكل ملف ثابت بيمرّي فيه = ضرر بلا فايدة:
 *
 *   • `api` — مافيش route handlers في المشروع دلوقتي، بس لو
 *     ضفت واحدة لازم ما تتحوّلش لـredirect.
 *   • `_next/static` و `_next/image` — ملفات البناء.
 *   • الملفات الثابتة (`.png` …) — الصور والملفات.
 *   • `.well-known` — شهادات TLS وملفات verification.
 *
 * ⭐⭐ `.*\\..*` في الآخر بيغطي **أي** ملف بنسخة، مش بس الصور.
 * الـ`(\\..*|\\/.*)` في الأول بتغطي المجلدات المخفية زي `.next`.
 */
export const config = {
  matcher: [
    /*
     * ⭐ بلا شرط `|` قبل الخيارات دي.
     *
     * ⭐ regex واحد = مسار واحد. لو حطينا
     * `"/((?!api).*)"` كـstring منفصل كان هيبقى matcher تاني،
     * وNext بيقول: **مفيش أكثر من matcher مدعوم**.
     */
    "/((?!api|_next/static|_next/image|_next/webpack-hmr|\\.well-known|.*\\.).*)",
  ],
};

export default proxy;
