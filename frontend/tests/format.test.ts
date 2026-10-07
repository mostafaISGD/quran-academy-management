import { readFileSync, statSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import {
  ALLOWED_INTL,
  codeFiles,
  describeViolations,
  scan,
} from "./guard";

/**
 * ⭐⭐ الاختبار الحامي — يمنع رجوع مشاكل التنسيق.
 *
 * ═══════════════════════════════════════════════════════════════
 * إيه اللي كان بيحصل
 * ═══════════════════════════════════════════════════════════════
 *
 * كل رقم وتاريخ في التطبيق لازم يعدّي من `lib/format.ts`.
 * أول ما حد يكتب `toLocaleString("ar-EG")` في صفحة، بتبدأ
 * ٣ مشاكل مع بعض:
 *
 *   ١. أرقام عربية بتظهر (٧٠٠) — لأن `ar-EG` لوحدها مش
 *      بتضمن أرقام لاتينية. ده محتاج `-u-nu-latn` صريح.
 *   ٢. `toLocaleString()` من غير خيارات بيضيف **الثواني**:
 *      «7/10/2026، 7:05:00 م» — سطر طويل غامض.
 *   ٣. نفس المبلغ بيطلع شكلين مختلفين في صفحتين.
 *
 * والاختبار ده بيوقفها **قبل** ما تتكتب، مش بعد ما تتكتب.
 *
 * ⭐ منطق الفحص نفسه في `guard.ts` — منفصل عن هنا، عشان
 * `format-guard-self.test.ts` يقدر يختبر نفس الكود.
 */

// ─────────────────────────────────────────────────────────────
// تحديد الجذر
// ─────────────────────────────────────────────────────────────

/**
 * ⭐ جذر الواجهة.
 *
 * `vitest` بيشتغل بـ cwd = مجلد `frontend`. بس لو حد شغّله من
 * جذر الـ repo، الـ cwd هيبقى مختلف. فبنشوف فين الملف
 * الشهير ونقيس عليه.
 */
function findRoot(): string {
  const fromHere = process.cwd();
  if (isFile(join(fromHere, "lib", "format.ts"))) return fromHere;

  const oneUp = join(fromHere, "frontend");
  if (isFile(join(oneUp, "lib", "format.ts"))) return oneUp;

  throw new Error(
    "مش لاقي `lib/format.ts`. شغّل `npx vitest` من مجلد `frontend` أو من جذر الـ repo.",
  );
}

function isFile(path: string): boolean {
  try {
    return statSync(path).isFile();
  } catch {
    return false;
  }
}

const ROOT = findRoot();
const FILES = codeFiles(ROOT);

// ─────────────────────────────────────────────────────────────
// الاختبارات
// ─────────────────────────────────────────────────────────────

describe("حارس التنسيق", () => {
  it("بيفحص ملفات فعلاً", () => {
    // ⭐ اختبار صمّام: لو `FILES` رجّع فاضي، كل القواعد هتعدّي
    //   على الفاضي والاختبار هيبقى مكسور من غير ما حد ياخد باله.
    expect(FILES.length).toBeGreaterThan(40);
    expect(FILES).toContain(ALLOWED_INTL);
  });

  it("مفيش أي مخالفة في المشروع", () => {
    const violations = scan(ROOT);

    expect(
      violations,
      violations.length
        ? `${violations.length} مخالفة:\n${describeViolations(violations)}`
        : "",
    ).toEqual([]);
  });

  /**
   * ⭐⭐ `lib/format.ts` هو **وحده** اللي بيتعامل مع `Intl`.
   *
   * القواعد بتمنع أي `toLocale*` في أي ملف — بس الملف ده
   * نفسه لازم يستخدمها! فمن غير هذا الشرط، إما الحارس بيفشل
   * على الملف الصح، أو بنشيل الاستثناء وتبقى القاعدة بلا معنى.
   *
   * فهنا بنتأكد إن الاستثناء **مستعمل فعلاً ومش زائد** — لو
   * الملف اتغيّر ومفيش `Intl`، الاستثناء بقى بلا فايدة وما حدش
   * هيعرف.
   */
  it("`lib/format.ts` بيستخدم `Intl` فعلاً — الاستثناء مش زائد", () => {
    const source = readFileSync(join(ROOT, ALLOWED_INTL), "utf8");

    expect(
      source,
      "الملف المفروض فيه `toLocaleString`. لو مش موجود، يبقى استثناء الحارس بقى بلا فايدة.",
    ).toMatch(/toLocaleString|toLocaleDateString|toLocaleTimeString/);
  });

  /**
   * ⭐⭐ والأهم: الملف ده بيبقى **المصدر الوحيد** للتنسيق.
   *
   * لو التصدير اتغيّر بالغلط، الدوال اللي بتستورده في كل صفحة
   * هتبقى `undefined` — والصفحة هترمي error وقت التشغيل مش
   * وقت البناء. الـ `tsc` مش هيلقط ده لو التصدير اتشال من
   * ملفين مع بعض.
   */
  it("`lib/format.ts` بيصدّر الدوال اللي باقي المشروع بيعتمد عليها", () => {
    const source = readFileSync(join(ROOT, ALLOWED_INTL), "utf8");

    const REQUIRED = [
      // الأرقام والمبالغ
      "num", "egp", "money", "currencyLabel", "toNumber",
      // التواريخ
      "date", "dateNoYear", "dateLong", "dateTime", "dateSmart",
      "monthYear", "monthName", "weekday",
      // الأوقات
      "time", "clock", "timeOnly", "scheduleLine",
      // أسماء مُركّبة ونصوص
      "payrollPeriodName", "text",
    ];

    const missing = REQUIRED.filter(
      (name) => !new RegExp(`export\\s+(?:function|const)\\s+${name}\\b`).test(source),
    );

    expect(missing, `التصدير الناقص: ${missing.join("، ")}`).toEqual([]);
  });
});
