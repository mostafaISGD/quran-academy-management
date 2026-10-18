import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

import {
  decideRoute,
  isDashboardPath,
  isPublicPath,
  PUBLIC_PATHS,
} from "@/lib/route-access";

import { stripComments } from "./guard";

/**
 * ⭐⭐⭐ قبول الدعوة — مربوط من الطرفين.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه الملف ده بيقرأ `backend/routes/api.php` من كود الواجهة
 *
 * ⭐ لأن **العقد** بين الطرفين ده مش مكتوب في أي مكان:
 *
 *   • `InvitationController::accept` **مافيش** `auth:sanctum`
 *     (الراوت عام — المدعو مالوش حساب).
 *   • `PUBLIC_PATHS` في `lib/route-access.ts` بتحوّل `/accept-invitation`
 *     لـ`allow`.
 *
 * ⭐⚠️ **الطرفين بيتغيروا مستقلين.** حد يضيف راوت عام جديد في
 * الـbackend ومش يلمس `PUBLIC_PATHS` → الصفحة هتتقابل بـ«سجّل
 * دخول» لمستخدم مالوش أصلاً حساب. **مفيش error، مفيش build
 * failure، والـfeature ميتة والنظام شغّال.**
 *
 * ⭐ والاختبار ده بيقرا الـbackend **من على القرص**. فلو حد شال
 * `auth:sanctum` من الراوت، الاختبار بيسقط — حتى لو مافيش حد
 * بيشغّل الـbackend في الـCI دلوقتي (لسه مفيش CI — PR 7.1).
 *
 * @see AccountInvitationTest (backend)
 */

// ═══════════════════════════════════════════════════════════════
// أدوات
// ═══════════════════════════════════════════════════════════════

const FRONTEND_ROOT = fileURLToPath(new URL("..", import.meta.url));

const BACKEND_ROOT = fileURLToPath(new URL("../../backend/", import.meta.url));

function readBackendRoutes(): string {
  return stripComments(readFileSync(`${BACKEND_ROOT}/routes/api.php`, "utf8"));
}

/**
 * ⭐⭐ الـmarker الحقيقي للمجموعة المحمية.
 *
 * ⚠️ **مش** `// ---- Authenticated ----` — ده **تعليق**،
 * و`stripComments()` بيشيله. فلو استعملناه، الاختبار كان هيقف
 * «مافيش علامة» حتى لو الملف سليم.
 *
 * ⭐ ده نفس سطر `bootstrap/app.php` اللي `RouteProtectionTest`
 * بيقرأه، فبنمشي على نفس المرجع.
 *
 * ⚡ PR 1.9: المجموعة بقت تحمل `two_factor.required` — بنحدّث
 * المؤشر؛ معناه الاختبار زي ما هو: «المجموعة المحمية موجودة».
 */
const AUTH_GROUP_MARKER = "Route::middleware(['auth:sanctum', 'account.active', 'password.changed'])";

// ═══════════════════════════════════════════════════════════════
// ① الجهة المعلنة: الراوت عام في الـbackend
// ═══════════════════════════════════════════════════════════════

describe("الراوت العام على السيرفر", () => {
  it("قبول الدعوة **برّه** مجموعة auth:sanctum", () => {
    const routes = readBackendRoutes();

    const start = routes.indexOf("Route::post('/invitations/accept'");

    expect(start, "مافيش راوت قبول الدعوة في routes/api.php").toBeGreaterThan(-1);

    const authStart = routes.indexOf(AUTH_GROUP_MARKER);

    expect(authStart, "مافيش المجموعة المحمية في routes/api.php").toBeGreaterThan(-1);

    // ⭐⭐ لازم يكون **قبل** المجموعة المحمية.
    //
    // ⭐ ده الفحص الحقيقي: لو حد نقل الراوت جوه المجموعة،
    // الـ`AcceptInvitationTest::test_the_accept_endpoint_is_public`
    // في الـbackend هيسقط. بس الاختبار ده بيحمي الواجهة لوحدها
    // حتى لو الـbackend اترجع.
    expect(
      start,
      "قبول الدعوة لازم يكون **قبل** المجموعة المحمية — المدعو مالوش حساب",
    ).toBeLessThan(authStart);
  });

  it("قبول الدعوة محدود بـ throttle", () => {
    const routes = readBackendRoutes();

    const start = routes.indexOf("Route::post('/invitations/accept'");

    expect(start).toBeGreaterThan(-1);

    // ⭐ الـ`->middleware()` على السطر اللي بعده، فمافيش
    // `[^\n]*` بينقذنا. بنقصّ نافذة قصيرة منDeclaration
    // ونفحص جواها.
    const window = routes.slice(start, start + 400);

    expect(
      window,
      "قبول الدعوة راو عام من غير throttle — حد يقدر يجرّب توكنات بلا حد",
    ).toMatch(/throttle:\d+,\d+/);
  });
});

// ═══════════════════════════════════════════════════════════════
// ② الواجهة لازم تسمح بالراوت
// ═══════════════════════════════════════════════════════════════

describe("الواجهة لازم تسمح للراوت العام", () => {
  it("‎/accept-invitation في PUBLIC_PATHS", () => {
    expect(
      PUBLIC_PATHS,
      "‎/accept-invitation لازم تكون عامة — المدعو مالوش حساب ولا جلسة",
    ).toContain("/accept-invitation");
  });

  it("مش محسوبة كـ dashboard", () => {
    expect(isDashboardPath("/accept-invitation")).toBe(false);
    expect(isPublicPath("/accept-invitation")).toBe(true);
  });

  it("decideRoute يسمح بيها من غير جلسة", () => {
    // ⭐⭐ هذا هو الفحص اللي بيكسر الـfeature لو اتغيّر.
    //
    // ⭐ `redirect` هنا معناه: الـproxy يودّي المدعو لـ`/login`،
    // وهو مالوش حساب وبيفتح الرابط لأول مرة. مية المات.
    expect(decideRoute("/accept-invitation", { hasSession: false })).toEqual({
      kind: "allow",
    });
  });

  it("صفحة موجودة فعلاً في app/", () => {
    // ⭐ `PUBLIC_PATHS` ممكن يتحدّث والصفحة متتكتبش — ساعتها
    // الرابط بيروح لـ404 والـbackend سليم والاختبارات بتمر.
    for (const file of ["app/accept-invitation/page.tsx", "app/accept-invitation/accept-form.tsx"]) {
      expect(
        () => readFileSync(`${FRONTEND_ROOT}/${file}`, "utf8"),
        `${file} مش موجود — PUBLIC_PATHS بيحكي على صفحة في الهواء`,
      ).not.toThrow();
    }
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ التوكن آمن في الـURL — بدون `decodeURIComponent`
// ═══════════════════════════════════════════════════════════════

describe("قراءة التوكن من `?token=`", () => {
  /**
   * ⭐⭐⭐ ليه ده اختبار في **الواجهة**؟
   *
   * ⭐ التوكن بيتولّد في الـbackend (base64url من 64 byte عشوائي).
   * وأول نسخة استخدمت base64 **العادي**، فطلع `%2B` و `%2F` في
   * الرابط. والصفحة كانت هتقارن التوكن اللي في `?token=` بالتوكن
   * اللي في الـ`accept_url` من غير decoding.
   *
   * ⭐ الـbackend عارف يقرا `%2B` (PHP بيـurldecode الـquery
   * تلقائياً)، فكان الـfeature **هيشتغل** — والخلل في الواجهة
   * بس، ومحدش كان هيكتشفه من الـbackend tests.
   *
   * ⭐ الاختبار ده بيقرا `accept-form.tsx` وبيتأكد إن مافيش
   * `decodeURIComponent` — أي لمسة decoding في الكود معناها إما
   * إن التوكن بقى base64 عادي (خطأ) أو إن في bug.
   */
  it("مافيش decodeURIComponent على التوكن", () => {
    const source = stripComments(readFileSync(`${FRONTEND_ROOT}/app/accept-invitation/accept-form.tsx`, "utf8"));

    expect(
      source,
      "مافيش decodeURIComponent على التوكن — التوكن base64url ومفروض يوصل زي ما هو",
    ).not.toContain("decodeURIComponent");
  });

  it("التوكن بيتقرا بـ searchParams.get", () => {
    const source = stripComments(readFileSync(`${FRONTEND_ROOT}/app/accept-invitation/accept-form.tsx`, "utf8"));

    expect(source).toMatch(/search\.get\(\s*["']token["']\s*\)/);
  });
});
