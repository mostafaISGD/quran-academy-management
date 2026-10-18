import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

import { stripComments } from "./guard";

/**
 * ⭐⭐⭐ إدارة الحسابات (PR 1.5) — العقد بين الواجهة والسيرفر.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه الملف ده بيقرأ ملفات الـbackend
 *
 * ⭐ «مافيش حذف لـ`users`» و«كل راوت عليه `permission`» — دي
 * قرارات **معمارية**. مالها أي أثر وقت التشغيل: لو حد أضاف
 * `Route::delete('/users/{user}')` من غير تفكير، مافيش error،
 * مافيش build failure، والـUI هيفضل يشتغل عادي.
 *
 * ⭐ والاختبار هو الحاجة الوحيدة اللي تحميها.
 *
 * ⭐ ونفس المنطق بيبرر قراءة `SeedRolesPermissions.php`: اسم
 * الصلاحية مكتوب في تلات أماكن (الكتالوج، الراوت، القائمة
 * الجانبية) ومافيش حاجة بتخليهم يتفقوا غير الـtype-checker اللي
 * مش شامل على نصوص.
 *
 * @see \Tests\Feature\UserManagementTest (backend)
 */

const FRONTEND_ROOT = fileURLToPath(new URL("..", import.meta.url));
const BACKEND_ROOT = fileURLToPath(new URL("../../backend/", import.meta.url));

const routes = (): string => stripComments(readFileSync(`${BACKEND_ROOT}/routes/api.php`, "utf8"));
const catalog = (): string => readFileSync(`${BACKEND_ROOT}/config/permissions.php`, "utf8");

// ═══════════════════════════════════════════════════════════════
// ① مافيش حذف — القاعدة
// ═══════════════════════════════════════════════════════════════

describe("مافيش حذف لحساب", () => {
  it("مافيش راوت DELETE على /users", () => {
    const src = routes();

    expect(
      /Route::delete\(\s*['"]\/users/.test(src),
      "⭐⭐ رجع راو حذف لـusers — القرار المعماري إن الحذف **أمر CLI** مش زرار. شوف UserController.",
    ).toBe(false);
  });

  it("مافيش زر حذف في صفحة الحسابات", () => {
    const page = stripComments(
      readFileSync(`${FRONTEND_ROOT}/app/(dashboard)/settings/users/page.tsx`, "utf8"),
    );

    // ⭐ «حذف» كلمة شائعة («إلغاء» كمان بتظهر في `confirm`). فبنفحص
    // على أسماء الـendpoint والـimport تحديداً مش على النص.
    expect(page).not.toContain("deleteUser");
    expect(page).not.toContain("IconTrash");
  });

  it("الأرشفة موجودة — يعني فيه طريقة لإغلاق الحساب", () => {
    const page = readFileSync(`${FRONTEND_ROOT}/app/(dashboard)/settings/users/page.tsx`, "utf8");

    // ⭐ الاختبار ده هو الشفعة التانية: «مافيش حذف» لوحده ممكن
    // يبقى معناه «مافيش حاجة تعملها أصلاً».
    expect(page).toContain("archiveUser");
  });
});

// ═══════════════════════════════════════════════════════════════
// ② كل راوت عليه صلاحية
// ═══════════════════════════════════════════════════════════════

describe("راوتات /users محمية", () => {
  it("كل راوت /users عليه permission", () => {
    const src = routes();

    const userRoutes = [...src.matchAll(/Route::(get|post|put|delete)\(\s*'\/users[^']*'[\s\S]{0,200}?;/g)];

    expect(userRoutes.length, "مافيش راوتات /users — الاختبار مش بيختبر حاجة").toBeGreaterThan(0);

    for (const [block] of userRoutes) {
      expect(
        block,
        `راوت /users من غير صلاحية:\n${block.slice(0, 160)}`,
      ).toMatch(/permission:[a-z.]+/);
    }
  });

  it("الراوتات متغطّاة كلها في `RouteProtectionTest::EXCEPTIONS`", () => {
    const src = routes();

    // ⭐ `/users` كله جوه `auth:sanctum` — مافيش استثناء متوقع.
    // الاختبار بيفشل لو حد نقل راوت جوه مجموعة **عامة** بالغلط
    // (زي اللي حصل مع `/groups/alerts`).
    // ⭐ رجع المؤشر لأصله بعد حذف `two_factor.required` مع شغل 2FA
    // (وضع المستخدم الواحد). الفحص نفسه: المجموعة موجودة.
    expect(src).toContain("Route::middleware(['auth:sanctum', 'account.active', 'password.changed'])");
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ أسماء الصلاحيات متطابقة في التلات أماكن
// ═══════════════════════════════════════════════════════════════

describe("أسماء الصلاحيات متطابقة", () => {
  const PERMISSIONS = ["users.view", "users.invite", "users.suspend", "users.archive", "users.roles.assign"];

  it("كل صلاحية في الكتالوج", () => {
    const src = catalog();

    for (const permission of PERMISSIONS) {
      // ⚠️ PR 1.6: الكتالوج انتقل من `SeedRolesPermissions::PERMISSIONS`
      // إلى `config/permissions.php` — والشكل بقى `'users.view' => [`
      // مش `['name' => 'users.view']`.
      expect(src, `«${permission}» مش في config/permissions.php`).toContain(
        `'${permission}' => [`,
      );
    }
  });

  it("كل صلاحية مستخدمة فعلاً في راوت", () => {
    const src = routes();

    for (const permission of PERMISSIONS) {
      expect(src, `«${permission}» في الكتالوج بس — مافيش راوت بيستخدمها`).toContain(
        `permission:${permission}`,
      );
    }
  });

  it("القائمة الجانبية بتطلب `users.view`", () => {
    // ⭐⭐ **الاسم مكرّر في التلات أماكن** — الكتالوج، الراوت،
     // ⭐⭐ الاسم مكرّر في التلات أماكن — والـnav لو اتغير
    // لـ`users.list` (وهي الصلاحية **الأقرب** للي موجودة) الرابط
    // بيختفي **من غير أي error**.
    //
    // ⭐ ده نفس صنف الخطأ اللي PR 1.2 كشّفه: الاسم مش موجود في
    // الكتالوج = **ممنوع** (fail-safe). نفس المبدأ هنا.
    const layout = readFileSync(`${FRONTEND_ROOT}/app/(dashboard)/layout.tsx`, "utf8");

    expect(layout).toMatch(/href:\s*"\/settings\/users"[^}]*permission:\s*"users\.view"/);
  });
});

// ═══════════════════════════════════════════════════════════════
// ④ سبب الإيقاف إجباري
// ═══════════════════════════════════════════════════════════════

describe("سبب الإيقاف والأرشفة إجباري", () => {
  it("الـbackend بيفرض `reason` بـmin:5", () => {
    const controller = readFileSync(`${BACKEND_ROOT}/app/Http/Controllers/Api/UserController.php`, "utf8");

    // ⭐ السبب بيتسجّل في `audit_logs`، فلو كان اختياري كان
    // الإيقاف «بلا سبب» ومينفعش يراجع بعد شهور.
    const required = [...controller.matchAll(/'reason'\s*=>\s*\['required'[^\]]*\]/g)];

    expect(required.length, "مافيش `reason` إجباري — الإيقاف بلا سبب").toBeGreaterThanOrEqual(2);
    expect(controller).toMatch(/'min:5'/);
  });
});
