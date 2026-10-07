import { describe, expect, it } from "vitest";
import {
  clock,
  date,
  dateSmart,
  egp,
  money,
  monthYear,
  num,
  payrollPeriodName,
  text,
  time,
  timeOnly,
  toNumber,
} from "../lib/format";

/**
 * ⭐⭐ اختبار **سلوك** دوال التنسيق — مش بس وجودها.
 *
 * ⭐ ليه ده مهم؟
 *
 * الفحص النصي (`guard.ts`) بيمنع `toLocaleString` في الصفحات.
 * بس لو `egp()` نفسها اتغيّرت، الفحص مش هيلقط — لأن الملف
 * مسموح له. والصفحات هتعرض `700 ج.م` أو `700 ج.م.` أو
 * `ج.م700` من غير ما حد ياخد باله.
 *
 * ⭐ فهنا بنثبّت **الشكل** اللي اتفقنا عليه:
 *
 *     400 ج.م          ← رقم وفاصلة، وحملة بالعربي
 *     1,234.5 ج.م      ← كسور لما تكون موجودة، وصفر لما لأ
 *     7 أكتوبر 2026   ← شهر عربي وسنة لاتينية
 *     7:05 م           ← ١٢ ساعة بالحرف العربي
 */

/** ⭐ حروف عربية ٠١٢ (٠-٩) — لو ظهرت، يبقى الحارس اتخطّى */
const ARABIC_DIGIT = /[٠-٩۰-۹]/;

/** ⭐ أي حرف لاتيني في أسماء الشهور/الأيام */
const ENGLISH_WORD = /\b(?:January|February|March|April|May|June|July|August|September|October|November|December|Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday|AM|PM)\b/;

describe("الأرقام والمبالغ", () => {
  it("الأرقام لاتينية دايماً", () => {
    expect(num(400)).toBe("400");
    expect(num(1200)).toBe("1,200");
    expect(num(1234567)).toBe("1,234,567");
    expect(num(0)).toBe("0");

    // ⭐ الرقم العربي اللي كتبه المستخدم بيتقرا
    expect(num("٤٠٠")).toBe("400");
    expect(num("١٬٢٠٠")).toBe("1,200");
    expect(num("1,234")).toBe("1,234");

    expect(ARABIC_DIGIT.test(num(1234))).toBe(false);
  });

  it("المبلغ: `400 ج.م` — بحملة، وبفاصلة آلاف", () => {
    expect(egp(400)).toBe("400\u00A0ج.م");
    expect(egp(1200)).toBe("1,200\u00A0ج.م");
    expect(egp(1234.5)).toBe("1,234.5\u00A0ج.م");
    expect(ENGLISH_WORD.test(egp(1200))).toBe(false);
  });

  it("المبلغ ما بيقسمش على سطرين", () => {
    // ⭐ المسافة بين الرقم و«ج.م» لازم تكون NBSP (U+00A0)
    //   — مش مسافة عادية. المسافة العادية بتسمح للمتصفح يكسر
    //   السطر، فيطلع الرقم فوق والعملة تحت.
    expect(egp(59_932)).toContain("\u00A0");
    expect(egp(59_932)).not.toMatch(/\s ج\.م$/);
  });

  it("المبلغ بأي عملة", () => {
    expect(money(400, "EGP")).toBe("400\u00A0ج.م");
    expect(money(400, "SAR")).toBe("400\u00A0ر.س");
    expect(money(400, "AED")).toBe("400\u00A0د.إ");
    expect(money(400, "USD")).toBe("400\u00A0$");

    // ⭐ عملة مش معروفة — نعرض الكود نفسه مش `undefined`
    expect(money(400, "XYZ")).toBe("400\u00A0XYZ");
    expect(money(400)).toBe("400\u00A0ج.م");
  });

  it("الحقل الفاضي بيبقى «—» مش صفر", () => {
    // ⭐ ده الفرق بين «ما فيش رقم» و«فعلاً صفر»
    expect(num(null)).toBe("—");
    expect(num(undefined)).toBe("—");
    expect(num("")).toBe("—");
    expect(egp(null)).toBe("—");
    expect(money(null)).toBe("—");

    // ⭐ بس الصفر الحقيقي يفضل صفر
    expect(num(0)).toBe("0");
    expect(egp(0)).toBe("0\u00A0ج.م");
  });

  it("`toNumber` بيرجّع `null` مش `NaN`", () => {
    expect(toNumber("٤٠٠")).toBe(400);
    expect(toNumber("١٬٢٠٠")).toBe(1200);
    expect(toNumber("1,234")).toBe(1234);
    expect(toNumber("٤٫٥")).toBe(4.5);
    expect(toNumber("abc")).toBeNull();
    expect(toNumber("")).toBeNull();
    expect(toNumber(null)).toBeNull();

    // ⭐ `Number("")` بترجّع 0 — وده بالظبط اللي كان بيكسر الأرقام
    expect(toNumber("")).toBeNull();
  });
});

describe("التواريخ", () => {
  it("التاريخ: شهر عربي + سنة لاتينية", () => {
    const out = date("2026-10-07T12:00:00Z");

    expect(out).toContain("2026");
    expect(out).toContain("أكتوبر");
    expect(out).toContain("7");
    expect(ENGLISH_WORD.test(out)).toBe(false);
    expect(ARABIC_DIGIT.test(out)).toBe(false);
  });

  it("`monthYear` — الشهر والسنة بس", () => {
    const out = monthYear("2026-10-07T12:00:00Z");

    expect(out).toContain("أكتوبر");
    expect(out).toContain("2026");
    expect(out).not.toContain("7");
    expect(ENGLISH_WORD.test(out)).toBe(false);
  });

  it("`dateSmart` بيظهر السنة بس لو مش السنة الحالية", () => {
    const thisYear = dateSmart(new Date().toISOString());
    const oldYear = dateSmart("2020-03-15T12:00:00Z");

    // ⭐ سنة قديمة — لازم تظهر عشان يبقى واضح إمتى
    expect(oldYear).toContain("2020");
    expect(oldYear).toContain("مارس");

    // ⭐ السنة الحالية — بتتقفل عشان بتتزحم مع الوقت
    const pattern = /\b\d{4}\b/;
    expect(
      thisYear.match(pattern),
      `السنة لسه ظاهرة في تاريخ السنة الحالية: «${thisYear}»`,
    ).toBeNull();

    // ⭐ بس الوقت لازم يفضل موجود
    expect(thisYear).toMatch(/\d{1,2}:\d{2}\s*[صم]/);
  });

  it("التاريخ الفاضي أو الغلط بيبقى «—»", () => {
    expect(date(null)).toBe("—");
    expect(date("مش تاريخ")).toBe("—");
    expect(monthYear("")).toBe("—");
  });
});

describe("الأوقات", () => {
  it("الوقت ١٢ ساعة بالحرف العربي", () => {
    const out = time("2026-10-07T18:05:00Z");

    expect(out).toMatch(/م$/);
    expect(out).not.toMatch(/AM|PM/i);
    expect(out).not.toContain(":00 م"); // ⭐ مش 6:00 — تبقى 6 م
    expect(ARABIC_DIGIT.test(out)).toBe(false);
  });

  it("الوقت الصباحي بالحرف العربي التاني", () => {
    const out = time("2026-10-07T06:05:00Z");

    expect(out).toMatch(/ص$/);
    expect(out).not.toMatch(/AM|PM/i);
  });

  it("`timeOnly` بيقرا نص `HH:MM` — المتصفح مش بيعرفه كـ Date", () => {
    expect(timeOnly("18:00")).toBe("6:00 م");
    expect(timeOnly("06:00")).toBe("6:00 ص");
    expect(timeOnly("6:00 PM")).toBe("6:00 م");
    expect(timeOnly("6:00 AM")).toBe("6:00 ص");
    expect(timeOnly("00:30")).toBe("12:30 ص");
    expect(timeOnly(null)).toBe("—");
  });

  it("`clock` بيجمع بداية ونهاية", () => {
    // ⭐ بنقارن الشكل مش الرقم — لأن الساعة بتتحسب بتوقيت
    //   المتصفح، والوقت في `'2026-10-07T18:00:00Z'` بيتحول
    //   لمنطقة المستخدم. الشكل هو اللي نثبّته.
    const out = clock("2026-10-07T18:00:00Z", "2026-10-07T19:00:00Z");

    expect(out).toMatch(/\d{1,2}:\d{2}\s*م\s*-\s*\d{1,2}:\d{2}\s*م/);
    expect(ENGLISH_WORD.test(out)).toBe(false);

    // ⭐ بداية بس (من غير نهاية) — مفيش شرطة
    const startOnly = clock("2026-10-07T18:00:00Z");
    expect(startOnly).not.toContain("-");
    expect(clock(null)).toBe("—");
  });
});

describe("أسماء مُركّبة", () => {
  /**
   * ⭐⭐ المشكلة اللي相位 دي طلعت.
   *
   * الـ seeder كان بيخزّن `رواتب October 2026` — شهر إنجليزي
   * في الداتابيز. فالدالة دي بتتجاهل النص المخزّن وتبنيه من
   * `start_date`.
   */
  it("`payrollPeriodName` — شهر عربي مش إنجليزي", () => {
    const out = payrollPeriodName("2026-10-01");

    expect(out).toBe("رواتب أكتوبر 2026");
    expect(ENGLISH_WORD.test(out)).toBe(false);
    expect(ARABIC_DIGIT.test(out)).toBe(false);
  });

  it("`payrollPeriodName` — كل الشهور بالعربي", () => {
    const ARABIC_MONTHS = [
      "يناير", "فبراير", "مارس", "أبريل", "مايو", "يونيو",
      "يوليو", "أغسطس", "سبتمبر", "أكتوبر", "نوفمبر", "ديسمبر",
    ];

    for (let m = 1; m <= 12; m++) {
      const iso = `2026-${String(m).padStart(2, "0")}-01`;
      const out = payrollPeriodName(iso);

      expect(out).toContain(ARABIC_MONTHS[m - 1]);
      expect(ENGLISH_WORD.test(out)).toBe(false);
    }
  });

  it("`payrollPeriodName` بيقبل بادئة مختلفة", () => {
    expect(payrollPeriodName("2026-10-01", "مرتبات")).toContain("مرتبات");
    expect(payrollPeriodName(null)).toBe("—");
  });
});

describe("نصوص نضيف", () => {
  /**
   * ⭐⭐ المشكلة التانية اللي طلعت في نفس المرحلة.
   *
   * كان بيظهر `Lead null` — لأن الـ API رجّع قيمة الـ id
   * `null` والـ fallback كان `${a.lead_id}`.
   */
  it("`text` بيمسح `null` و `undefined` و `NaN`", () => {
    expect(text(null)).toBe("—");
    expect(text(undefined)).toBe("—");
    expect(text("null")).toBe("—");
    expect(text("undefined")).toBe("—");
    expect(text("NaN")).toBe("—");
    expect(text("")).toBe("—");
    expect(text("   ")).toBe("—");
  });

  it("`text` بيسيب النص السليم زي ما هو", () => {
    expect(text("محمد")).toBe("محمد");
    expect(text("  محمد  ")).toBe("محمد");
    expect(text(0)).toBe("0");
    expect(text("nullPointer")).toBe("nullPointer"); // ⭐ مش الكلمة بحدها
  });

  it("`text` بيقبل بديل مخصص", () => {
    expect(text(null, "بدون اسم")).toBe("بدون اسم");
    expect(text("null", "بدون اسم")).toBe("بدون اسم");
    expect(text("أحمد", "بدون اسم")).toBe("أحمد");
  });
});
