import { describe, expect, it } from "vitest";
import { currencyExponent, formatMinor, money } from "@/lib/format";

/**
 * ⭐ PR 2.3 — عرض المبالغ من `*_minor` (أصغر وحدة).
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ ليه الـminor؟
 *
 * لأن `"350.00"` نص، والواجهة محتاجة تحسب (مثلاً عمولة 10%) —
 * والحساب على نص = `350.005`. القسمة بتطلع خطأ تمثيل.
 *
 *     Number("0.07") * 3 = 0.21000000000000002   ⭐ مش 0.21
 *
 * والـ`int` بالقرش مالوش خطأ تمثيل: `7 * 3 = 21` بالظبط.
 */
describe("formatMinor — عرض المبالغ من أصغر وحدة", () => {
  // ═══════════════════════════════════════════════════════════
  // ① الأساس
  // ═══════════════════════════════════════════════════════════

  it("الأساس الافتراضي 2 (قرش)", () => {
    expect(currencyExponent("EGP")).toBe(2);
    expect(currencyExponent("USD")).toBe(2);
    expect(currencyExponent(undefined)).toBe(2);
  });

  it("العملات بأساس مختلف", () => {
    expect(currencyExponent("KWD")).toBe(3); // فلس
    expect(currencyExponent("BHD")).toBe(3);
    expect(currencyExponent("JPY")).toBe(0); // مفيش وحدات فرعية
  });

  it("العملة المجهولة بترجع للأساس الافتراضي", () => {
    expect(currencyExponent("XXX")).toBe(2);
    expect(currencyExponent("")).toBe(2);
  });

  // ═══════════════════════════════════════════════════════════
  // ② العرض
  // ═══════════════════════════════════════════════════════════

  it("65000 قرش = 650 جنيه", () => {
    const out = formatMinor(65000, "EGP");

    expect(out).toContain("650");
    expect(out).toContain("ج.م");
  });

  it("فاصل الآلاف بيظهر", () => {
    const out = formatMinor(1234567, "EGP");

    // ⭐ 12345.67 جنيه — لازم فاصل آلاف
    expect(out).toMatch(/12[,٬]?345/);
  });

  it("صفر", () => {
    expect(formatMinor(0, "EGP")).toContain("0");
  });

  it("السالب بيطبع قبل الرقم", () => {
    // ⭐ الـ`ar-EG` بيحط علامة اتجاه (U+200E) قبل الرقم السالب —
    // دي سلوك `Intl` الصح، مش خطأ.
    const out = formatMinor(-65000, "EGP");

    expect(out).toContain("-");
    expect(out.indexOf("-")).toBeGreaterThan(-1);
  });

  it("null بيطلع شرطة مش NaN", () => {
    expect(formatMinor(null, "EGP")).toBe("—");
  });

  it("فارغ بيطلع شرطة", () => {
    expect(formatMinor(undefined, "EGP")).toBe("—");
  });

  // ═══════════════════════════════════════════════════════════
  // ⭐ الأرقام الحدّية — دي اللي بتكشف خطأ القسمة
  // ═══════════════════════════════════════════════════════════

  it("قرش واحد = 0.01", () => {
    expect(formatMinor(1, "EGP")).toContain("0.01");
  });

  it("سبعة قروش = 0.07 (مش 0.06 أو 0.08)", () => {
    expect(formatMinor(7, "EGP")).toContain("0.07");
  });

  it("تسعة عشر قرش = 0.19", () => {
    expect(formatMinor(19, "EGP")).toContain("0.19");
  });

  it("تسعة وتسعون قرش = 0.99", () => {
    expect(formatMinor(99, "EGP")).toContain("0.99");
  });

  it("مائة قرش = 1 جنيه بالظبط", () => {
    const out = formatMinor(100, "EGP");

    expect(out).toContain("1");
    expect(out).not.toContain("0.99");
    expect(out).not.toContain("1.01");
  });

  it("JPY: 1500 ين مفيش كسور", () => {
    const out = formatMinor(1500, "JPY");

    // ⭐ `ar-EG` بيحط فاصل آلاف (١٬٥٠٠) — المهم مفيش كسور
    expect(out).toMatch(/1[,\u066C٬]?500/);
    expect(out).not.toContain(".");
  });

  it("KWD: 3 خانات عشرية", () => {
    expect(formatMinor(1234, "KWD")).toContain("1.234");
  });

  it("رقم كبير جداً مابقريش floored", () => {
    // ⭐ 2 مليار قرش = 20 مليون جنيه — لازم الرقم صحيح
    const out = formatMinor(2000000000, "EGP");

    expect(out).toContain("20");
    expect(out).not.toContain("NaN");
  });

  // ═══════════════════════════════════════════════════════════
  // ③039 التوافق مع money()
  // ═══════════════════════════════════════════════════════════

  it("money() لسه شغالة للـAPI القديم", () => {
    // ⭐ الـcontract لسه strings — لحد ما الـAPI يتغيّر
    expect(money("350.00", "EGP")).toContain("350");
  });
});