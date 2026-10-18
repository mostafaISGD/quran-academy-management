/**
 * ⭐⭐⭐ قواعد الوصول للراوتات — منفصلة عن الـproxy عن قصد.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه المنطق في ملف لوحده
 *
 * `proxy.ts` بيستورد من هنا. فلو المنطق كان مكتوب جوه الـproxy
 * مباشرة، مش هيعرف حد يختبره — وVitest هنا بيشغّل في بيئة `node`
 * من غير Next، فمقدرناش نعمل render للـproxy نفسه.
 *
 * ⭐ فبنطلع الدوال **خالية من React ومن Next**، فتبقى قابلة
 * للاختبار من غير أي setup. وده مش افتراضي — ده **اللي** بيخلّي
 * الـproxy قابل للاختبار أصلاً.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة الأهم: ده **مش** طبقة الأمان
 *
 * ⭐⭐ الـcookie بتاع Laravel **مشفّر** بمفتاح التطبيق بتاعه،
 * والـNext مالوش المفتاح ده. فالـNext server يقدر يشوف إن في
 * `laravel_session`، بس **مش** يقدر يقرأ اللي جواها.
 *
 * يعني أي فحص هنا **تفاؤمي** (optimistic): بيتصدّق إن في جلسة.
 * ومحدش يقدر يتخطّاه بتمرير `laravel_session=anything` — لأن
 * الطلب اللي هيتبعت للـAPI سيرفضه.
 *
 * ⭐ ده بالظبط اللي بتقوله وثائق Next:
 * «Proxy … should not be used as a full session management or
 * authorization solution».
 *
 * ⭐⭐ والأمان الحقيقي في مكانين تانيين:
 *   ① `CheckPermission` على كل راو في `routes/api.php` (البند 7)
 *   ② `dal.ts` — فحص حقيقي للسيرفر (تحت)
 *
 * @see proxy.ts
 * @see lib/dal.ts
 */

import type { AuthUser } from "@/lib/api";

/** ⭐ مستخدم بشكل مبسّط — اللي الـproxy بيحتاجه بس */
export type AccessUser = Pick<
  AuthUser,
  "is_teacher" | "teacher" | "is_parent" | "parent" | "roles" | "permissions"
> | null;

// ═══════════════════════════════════════════════════════════════
// الصفحات العامة
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐ راوات عامة — حد يدخلها من غير جلسة.
 *
 * ⭐ `/pricing` و`/groups` عامّة على السيرفر (قرار موثّق في
 * `routes/api.php`): الأهالي بتسأل عن الأسعار من غير حساب.
 * فلو حجبناها، سؤال صحيح هيتقابل بـ«لازم تسجّل».
 */
export const PUBLIC_PATHS: readonly string[] = [
  "/login",
  "/",
  "/pricing",
  "/groups",
  "/accept-invitation",
];

/**
 * ⭐⭐⭐ `/accept-invitation` **لازم** تكون عامة — مش اختياري.
 *
 * ⭐ المدعو عنده رابط دعوة بس. **مالوش حساب، ومالهوش دخول** —
 * وده بالظبط معنى «دعوة».
 *
 * ⭐ لو الصفحة دي مش في `PUBLIC_PATHS`، الـ`decideRoute` هيشوف
 * `hasSession === false` → إعادة توجيه لـ`/login`، ومدعو الأكاديمية
 * هيفتح الإيميل ويقابل «سجّل دخول»… وهو مش فاكر كلمة مرور،
 * ومش عنده حساب أصلاً. **الصفحة ميتة والنظام شغّال.**
 *
 * ⭐ والـbackend بيقول نفس الكلام: `InvitationController::accept`
 * **مافيش** `auth:sanctum` — الراوت عام بالتصميم. لو الواجهة
 * حجبته والbackend مش، ده تعارض مش حماية.
 *
 * ⭐ `@see lib/__tests__` — فيه اختبار بيربط الراوت بالـbackend.
 */

/** ⭐ راوات الأدوار الخاصة — كل واحد ليه بيت واحد */
export const ROLE_HOME: Record<string, string> = {
  teacher: "/my-schedule",
  parent: "/my-children",
};

/** ⭐ افتراضي أي حد تاني */
export const DEFAULT_HOME = "/dashboard";

// ═══════════════════════════════════════════════════════════════
// مين أنا
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐⭐ معلم بيشتغل معلم **ومش** إداري.
 *
 * ⭐ نفس شرط `AuthController::profile()`:
 *
 *     'is_teacher' => $user->teacher !== null && !$user->hasRole('admin'),
 *
 * ⭐ ليش الشرط مزدوج؟ لأن أي `user_id` مربوط بسجل `teachers`
 * كان بيحسب معلم — حتى لو الحساب أصلاً **إداري**. والنتيجة في
 * القاعدة: حساب المدير نفسه مربوط بمعلم #1، والواجهة كانت
 * بتوديه `/my-schedule` بدل لوحة التحكم.
 */
export function isTeacherOnly(user: AccessUser): boolean {
  return Boolean(user?.is_teacher && user.teacher && !user.roles.includes("admin"));
}

/** ⭐ ولي أمر — الحساب مالوش دور إداري وله ملف ولي أمر */
export function isParentOnly(user: AccessUser): boolean {
  return Boolean(user?.is_parent && user.parent && !user.roles.includes("admin"));
}

/** ⭐ بيت المستخدم — حسب دوره */
export function homeFor(user: AccessUser): string {
  if (isParentOnly(user)) return ROLE_HOME.parent;
  if (isTeacherOnly(user)) return ROLE_HOME.teacher;
  return DEFAULT_HOME;
}

// ═══════════════════════════════════════════════════════════════
// تصنيف المسار
// ═══════════════════════════════════════════════════════════════

/** ⭐ كل حاجة جوه `(dashboard)` محمية */
export function isDashboardPath(pathname: string): boolean {
  return pathname !== "/" && !PUBLIC_PATHS.includes(pathname);
}

export function isPublicPath(pathname: string): boolean {
  return PUBLIC_PATHS.includes(pathname);
}

// ═══════════════════════════════════════════════════════════════
// القرار
// ═══════════════════════════════════════════════════════════════

export type RouteDecision =
  | { kind: "allow" }
  /** ⭐ إعادة توجيه إلى `to` — المسار نسبي، مش URL كامل */
  | { kind: "redirect"; to: string };

/**
 * ⭐⭐⭐ القرار الوحيد بتاع الـproxy.
 *
 * ═══════════════════════════════════════════════════════════════
 * ترتيب الفحوص — ليه بهذا الترتيب بالذات
 *
 * ① **الراوت العام بيعدّي الأول.** `/pricing` و`/groups` عامّة
 *    حتى لو في جلسة. ولو رجعنا بالشرط العكس، مستخدم داخل هيتبعت
 *    لـ`/dashboard` وهو مش عايز.
 *
 * ② **من غير جلسة → `/login`.**
 *
 * ③ ⭐ **`user === null` مع وجود جلسة → `allow`.**
 *
 *    ⚠️⚠️ **دي النقطة اللي بتعمل loop لو غلطت فيها.**
 *
 *    الـproxy **مش** بيجيب المستخدم (docs: متعملش data fetching
 *    هناك). فلو المستخدم لسه مش معروف ورجّعنا `/login`، الـlayout
 *    هيبعته `/dashboard`… واللي بيحصل هو **loop** بين الراوتين.
 *
 *    فبنسمح وبنخلي الـDAL والـlayout يتحققوا. الـproxy مجرد
 *    presfilter للزيارات الغريبة.
 *
 * ④ **معلم/ولي أمر على راو مش بيته** → إعادة توجيه لبيته.
 *
 * @param pathname المسار من `NextRequest`
 * @param hasSession هل في كوكي جلسة — **تفاؤمي**، مش قراءة محتوى
 * @param user المستخدم — `null` لو الـproxy لسه مش عارفه
 */
export function decideRoute(
  pathname: string,
  opts: { hasSession: boolean; user?: AccessUser },
): RouteDecision {
  // ① عام
  if (!isDashboardPath(pathname)) return { kind: "allow" };

  // ② من غير جلسة
  if (!opts.hasSession) return { kind: "redirect", to: "/login" };

  // ③ ما نعرفش مين — سيب الـDAL
  if (!opts.user) return { kind: "allow" };

  // ④ الدور الخاص
  const home = homeFor(opts.user);

  if (home !== DEFAULT_HOME && pathname !== home) {
    return { kind: "redirect", to: home };
  }

  return { kind: "allow" };
}

// ═══════════════════════════════════════════════════════════════
// طبقة الأمان الحقيقية
// ═══════════════════════════════════════════════════════════════

/**
 * ⭐⭐⭐ التحقق الحقيقي — على السيرفر، مش تفاؤمي.
 *
 * ⚠️⚠️ **الفرق بين ده وبين الـproxy:**
 *
 *   • الـproxy بيشوف «في كوكي» — ومحدش بيقدر يتخطّاه فعلاً،
 *     بس هو كمان **مش** بيكشف حالة حقيقية.
 *
 *   • `dal.ts` بيعمل `fetch` للـAPI. الـAPI هو اللي بيتحقق
 *     (الـcookie + `auth:sanctum` + `CheckPermission`). فلو الجلسة
 *     مش صالحة، بيرجّع **401** — وده جواب **حقيقي**.
 *
 * ⭐ ⭐ **وده اللي بيمنع الـloop.** الـproxy بيسمح لما ما
 * يعرفش، والـDAL بيرجّع `/login` بس لما الـAPI يقول **401 فعلاً**.
 *
 * ⭐ لو المستخدم داخل وجاه مش ليه صلاحية → الـAPI بيرجّع **403**
 * مش 401، فالـDAL **مش** بيرجّعه لـ`/login` — بيخليه يشوف الصفحة
 * الفاضية ويعرف إنه مش مصرّح. الفصل مقصود.
 *
 * ⭐ وكلا الـredirect بيحتفظ بالمسار اللي طلبه المستخدم، عشان
 * بعد الدخول يرجع لنفس الصفحة مش `/dashboard`.
 */
export function loginRedirect(pathname: string): string {
  if (!pathname || pathname === "/") return "/login";

  return `/login?next=${encodeURIComponent(pathname)}`;
}
