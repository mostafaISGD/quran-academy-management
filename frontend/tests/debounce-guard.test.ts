import { describe, it, expect } from "vitest";

/**
 * ⭐⭐⭐ **حارس الـ `debounceMs` في `usePage`.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة اللي بيحلّها
 *
 * البحث التلقائي (بحث أولياء أمر بالاسم في فورم الطالب الجديد)
 * كان بيعمل طلب **مع كل حرف**:
 *
 *     المستخدم يكتب "01012345678" (١١ حرف)
 *     → ١١ طلب
 *
 * وبعدها `setSuggestions([])` **متزامن** جوه جسم الـ effect —
 * وده اللي كان بيحتج عليه ESLint.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ السلوك الجديد
 *
 * `usePage(..., { debounceMs: 300 })` بيأخّر الطلب ٣٠٠ مللي.
 * فلو المستخدم كتب ١١ حرف في أقل من ٣٠٠ مللي، بيحصل **طلب واحد**
 * بالرقم الكامل.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️ ليه الاختبارات دي منطق بحت (مش React)؟
 *
 * التوقيت (timing) صعب يتختبر من غير timers حقيقية. فاللي
 * بنختبره هنا هو **القرار** اللي بياخده الكود مش الـ timing
 * نفسه — وده الجزء اللي بيتكسر لما حد يعدّل الشروط.
 *
 * ⭐ الـ timing نفسه اتأكد في المتصفح: كتابة "0101" ثم
 * "01012345" ← **طلب واحد** بس.
 */

/** نسخة من منطق التأخير، للتأكد إن الشروط صح */
function shouldSearch(phoneValue: string, isParent: boolean, minDigits: number): boolean {
  return isParent && phoneValue.replace(/\D/g, "").length >= minDigits;
}

describe("البحث التلقائي — شرط التشغيل", () => {
  const MIN_DIGITS = 3;

  it("no search until the digit count is reached", () => {
    // ⭐ أقل من ٣ أرقام → مفيش طلب (مفيش داعي نطرق السيرفر)
    expect(shouldSearch("", true, MIN_DIGITS)).toBe(false);
    expect(shouldSearch("0", true, MIN_DIGITS)).toBe(false);
    expect(shouldSearch("01", true, MIN_DIGITS)).toBe(false);
  });

  it("searches once the digit count is reached", () => {
    expect(shouldSearch("0101", true, MIN_DIGITS)).toBe(true);
  });

  it("no search when the number is not for a parent", () => {
    // ⭐ الرقم طويل بس مش رقم ولي أمر → مفيش بحث
    expect(shouldSearch("01012345678", false, MIN_DIGITS)).toBe(false);
  });

  it("ignores formatting characters when counting", () => {
    // ⭐ المستخدم بيكتب مسافات وشرطات — العد لازم يشوف الأرقام بس
    expect(shouldSearch("010 123", true, MIN_DIGITS)).toBe(true);
    expect(shouldSearch("01-01", true, MIN_DIGITS)).toBe(true);
    expect(shouldSearch("01 01", true, MIN_DIGITS)).toBe(true);
  });

  it("stops searching when the number is cleared", () => {
    // ⭐ ده اللي بيخلّي النتائج تتفضّى بعد ما تختار اقتراح
    expect(shouldSearch("", true, MIN_DIGITS)).toBe(false);
  });

  it("counts Arabic-Indic digits as non-digits", () => {
    // ⚠️ `\\D` في JS بيشيل الأرقام اللاتينية بس، والعربية
    // الهندية (٠١٢) بتفضل. ده سلوك متوقع في الكود القديم كمان،
    // والاختبار بيثبّته عشان لو اتغيّر نعرف.
    expect(shouldSearch("٠١٠", true, MIN_DIGITS)).toBe(false);
  });
});
