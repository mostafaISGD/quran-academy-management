/**
 * ⭐⭐⭐ فلترة القائمة الجانبية — بالصلاحية والدور.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * القائمة كانت **ثابتة**. أي حد داخل — معلم، ولي أمر، موظف
 * استقبال — كان بيشوف كل القوائم: `/students`، `/finance`،
 * `/settings`، `/audit-logs`… وكلهم يضغطوا ويلقوا **403**.
 *
 * ⭐ ده مش مجرد زهق: 
 *
 *   ① الصفحة بتطلب `/api/...` و**بيرجّع 403** — يعني الواجهة
 *      بتعمل طلبات واثنين بيفشلوا بهدوء.
 *
 *   ② **الأهم**: أي حد يفتح DevTools ويشوف أسماء الـendpoints.
 *      ده مش سر، بس بيsays أكتر مما لازم.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ ليه الفلترة **مش** هي الحماية
 *
 * ⭐ الفلترة **راحة**، مش أمان. أي حد يقدر يفتح `/students`
 * بسطر في الـURL، وبيطلب `/api/students` بـ`fetch` من الـconsole.
 * اللي بيمنعه هو `CheckPermission` على السيرفر.
 *
 * ⭐ لكن الفلترة مهمةDespite كده: هي بتخلي **الواجهة نفسها** صادقة
 * — ما بتوعدش المستخدم بحاجة هو مش قادر عليها.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه منطقها في ملف لوحده
 *
 * `(dashboard)/layout.tsx` كان فيه الشرط مكتوب جوه الـJSX.
 * والـJSX مش قابل للاختبار هنا (Vitest بيشتغل `node` من غير
 * React renderer). فبنتطلعه لوحده ونختبره.
 */

import type { AccessUser } from "@/lib/route-access";
import { isParentOnly, isTeacherOnly } from "@/lib/route-access";

export type NavItem = {
  href: string;
  label: string;
  icon: string;
  /**
   * ⭐⭐ الصلاحية المطلوبة للراو ده.
   *
   * ⭐ **اختياري** معناه «الكل». وده مقصود للصفحات اللي مفيش
   * فيها بيانات حسّاسة زي `/dashboard`.
   *
   * ⚠️ لازم تكون **نفس** اسم الصلاحية في `SeedRolesPermissions::ROLES`
   * — مش اسم راو، ومش تخمين. لازم تطابق `routes/api.php` كمان.
   */
  permission?: string;
};

export type NavGroup = {
  label: string;
  icon: string;
  items: NavItem[];
};

/**
 * ⭐⭐⭐ المستخدم الحاجب — **مش** واجهة.
 *
 * ⭐ ليه الحالة **فاضية** مش خطأ:
 *
 * الأول ما الصفحة ترسم، `fetchMe()` لسه شغّال و`user` =
 * `null`. فلو رجعنا `[]`، المستخدم **هيشوف قائمة من غير روابط**
 * لثانية وبعدين فلجأة تظهر — وده أسوأ من الـflash.
 *
 * ⭐ فبنرجّع `null` والـlayout بيعرض **Skeleton** لحد ما
 * `loading` يبقى `false`. المستخدم **مابيستناش** حاجة، بس
 * بيشوف «بيحمّل» بدل قائمة وهمية.
 *
 * @returns المجموعات بعد الفلترة، أو `null` لو لسه بنحمّل
 */
export function filterNavGroups(
  groups: readonly NavGroup[],
  user: AccessUser,
): NavGroup[] | null {
  // ⭐ⓘ لسه بنجيب المستخدم — مافيش قرار ناخده.
  if (!user) return null;

  // ⭐⭐ المعلم وولي الأمر: **مفيش قائمة إدارية أصلاً**.
  //
  // مش «فلترة» — صفر. لو بس فلترنا، ممكن يفضل عنده
  // `/dashboard` و`/programs`، وده بيفتحله صفحات مش ليه فيها.
  if (isTeacherOnly(user) || isParentOnly(user)) return [];

  const allowed = new Set(user.permissions ?? []);

  const result: NavGroup[] = [];

  for (const group of groups) {
    const items = group.items.filter(
      (item) => !item.permission || allowed.has(item.permission),
    );

    // ⭐⭐ القسم الفاضي **بينشال خالص**، مش بيترسم فاضي.
    //
    // مثال: موظف استقبال مالوش `groups.manage`، فقسم
    // «الإدارة» كله مش ليه. قسم فاضي في القائمة = واجهة مكسورة.
    if (items.length === 0) continue;

    result.push({ ...group, items });
  }

  return result;
}

/**
 * ⭐⭐⭐ الدعوة الحقيقية بعد تسجيل الدخول.
 *
 * ⭐ `/` هي entry point الوحيد للمستخدمين — فبتوديه على بيته.
 * مفيش `/` صفحة، فمحدش بيوصلها من غير تحويل.
 */
export function isRootPath(pathname: string): boolean {
  return pathname === "/";
}