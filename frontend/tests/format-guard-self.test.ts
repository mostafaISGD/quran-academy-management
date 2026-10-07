import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { RULES, scan, stripComments } from "./guard";

/**
 * ⭐⭐ الاختبار اللي بيختبر الاختبار.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه ده ضروري
 * ═══════════════════════════════════════════════════════════════
 *
 * الحارس بيفحص **نص الملفات**. فلو الـ regex بتاعه اتكسر،
 * أو `codeFiles()` رجّعت فاضي، أو المسار بقى غلط — الحارس هيعدّي
 * **وأنا مش عارف**، والمشكلة هتظهر بعدين في الشاشة.
 *
 * الاختبارات دي بتكتب كود فيه مخالفات **في مجلد مؤقت**، وبعدين
 * بتتأكد إن الحارس بيمسكها فعلاً. فلو أي قاعدة بقت ميتة،
 * الاختبار ده هيفشل.
 *
 * ⭐ وبنستخدم `scan()` — **نفس الدالة** اللي بتحمي المشروع،
 * مش نسخة منها. فلو الدالة اتغيرت، الاختبارات دي بتتغير معاها.
 *
 * ⭐ والمجلد المؤقت في `os.tmpdir()` بيتمسح بعد كل اختبار —
 *   فمفيش أي خطر على شغلك الحقيقي.
 */

let dir: string;

/** ⭐ مثال على مخالفة كل قاعدة — لو الـ regex غلط، الاختبار هيفشل */
const VIOLATION: Record<string, string> = {
  "toLocaleString / toLocaleDateString / toLocaleTimeString":
    'const s = n.toLocaleString("ar-EG");\n',
  "new Intl.* مباشرة": 'const f = new Intl.NumberFormat("ar-EG");\n',
  "رقم من ٠-٩ أو ۰-۹": 'const label = "الفاتورة ٤٠٠ جنيه";\n',
  "`text-left` أو `text-right`": '<td className="px-4 text-left">السعر</td>\n',
  "محاذاة فيزيائية جوا CSS": "<style>th { text-align: right; }</style>\n",
  "اسم شهر أو يوم إنجليزي": 'const p = "رواتب October 2026";\n',
  "`AM` / `PM` إنجليزية": 'const t = "الحصة 6:00 PM";\n',
};

/** ⭐ كود نضيف تمامًا — لازم يعدّي من غير أي ملاحظة */
const CLEAN = `
  import { num, egp, money, dateSmart } from "@/lib/format";

  export function Price({ v, c }: { v: number; c: string }) {
    return (
      <>
        <td className="text-start font-medium">{money(v, c)}</td>
        <td className="text-end">{dateSmart(v)}</td>
        <td className="text-center">{num(v, 0)}</td>
        <td dir="ltr"><span className="inline-block text-end">{egp(v)}</span></td>
      </>
    );
  }
`;

beforeEach(() => {
  dir = mkdtempSync(join(tmpdir(), "format-guard-"));

  // ⭐ نفس الشكل الحقيقي: مجلدات المسارات + الملف المسموح
  mkdirSync(join(dir, "lib"), { recursive: true });
  mkdirSync(join(dir, "app", "(dashboard)", "students"), { recursive: true });
  mkdirSync(join(dir, "components"), { recursive: true });

  writeFileSync(
    join(dir, "lib", "format.ts"),
    'export function num(n: number) { return n.toLocaleString("ar-EG-u-nu-latn"); }\n',
    "utf8",
  );
});

afterEach(() => {
  rmSync(dir, { recursive: true, force: true });
});

// ─────────────────────────────────────────────────────────────

describe("الحارس بيمسك كل قاعدة", () => {
  for (const rule of RULES) {
    it(`بيمسك: ${rule.name}`, () => {
      const sample = VIOLATION[rule.name];
      expect(sample, `مفيش مثال مخالفة للقاعدة: ${rule.name}`).toBeTruthy();

      writeFileSync(join(dir, "app", "probe.tsx"), sample, "utf8");

      const violations = scan(dir);
      const matched = violations.filter((v) => v.hint === rule.hint);

      expect(
        matched.length,
        `القاعدة «${rule.name}» ما أمسكتش مثالها.\n` +
          `اللي اتقابل: ${JSON.stringify(violations)}`,
      ).toBeGreaterThan(0);
    });
  }

  it("الكود النضيف ما بيمسكش", () => {
    writeFileSync(join(dir, "app", "clean.tsx"), CLEAN, "utf8");

    expect(scan(dir), "الكود النضيف اتقابل فيه مخالفة").toEqual([]);
  });

  /**
   * ⭐⭐ الاستثناء شغال — `lib/format.ts` بيتخطّى.
   *
   * لو الاستثناء اتكسر، الحارس هيفشل على **الملف الصح** وده
   * هيلخبط أي حد بيحاول يفهم السبب.
   */
  it("`lib/format.ts` معفى — هو المكان الوحيد المسموح", () => {
    // ⭐ الملف المسموح فيه كل المخالفات — ومع كده ما بيمسكش
    writeFileSync(
      join(dir, "lib", "format.ts"),
      'const a = new Intl.NumberFormat("ar-EG");\n' +
        'const b = n.toLocaleString("ar-EG");\n' +
        'const c = "٤٠٠";\n' +
        ".text-left { }\n" +
        "text-align: right;\n",
      "utf8",
    );

    expect(
      scan(dir),
      "الملف المسموح اتمسك — الاستثناء مش شغال",
    ).toEqual([]);
  });

  /**
   * ⭐⭐ التعليقات بتعدّي — وده **بالعمد**.
   *
   * فيه في المشروع تعليقات فاضلة بتشرح الخطأ القديم
   * (`toLocaleString` و أرقام `ar-EG`). لو التعليقات ما
   * بتعدّيش، المطوّر مش هيقدر يشرح ليه عمل حاجة غلط.
   *
   * وكمان بنتأكد إن شيل التعليقات **مش بيكسر أرقام الأسطر** —
   * عشان رسالة الخطأ تقول السطر الصح.
   */
  it("التعليقات بتعدّي والأرقام بتفضل صح", () => {
    writeFileSync(
      join(dir, "app", "commented.tsx"),
      [
        "// سطر 1 — رايح على toLocaleString",
        "const a = 1;",
        "/* سطر 3 — new Intl */",
        'const b = "٤٠٠";',
        "const c = 2;",
        'const d = 3.toLocaleString("ar-EG");',
      ].join("\n"),
      "utf8",
    );

    const violations = scan(dir);

    // ⭐ سطر ٤ فيه «٤٠٠» = ٣ أرقام عربية (كل حرف مخالفة لوحده)
    // ⭐ سطر ٦ فيه `toLocaleString` = مخالفة واحدة
    // ⭐ سطر ١ و ٣ تعليقات — لازم يعدّوا
    const byLine = violations
      .map((v) => v.line)
      .sort((a, b) => a - b);

    expect(byLine).toEqual([4, 4, 4, 6]);

    // ⭐ وآخر مخالفة `toLocaleString` — والسطر ٦ مش ١
    //   (المطابقة بتبقى `.toLocaleString(` — بنقطة قدامها)
    const toLocale = violations.find((v) => v.match.includes("toLocaleString"));
    expect(toLocale?.line).toBe(6);
    expect(toLocale?.file).toBe("app/commented.tsx");
  });

  it("شيل التعليقات بيحافظ على أرقام الأسطر", () => {
    const source = [
      "// أول",
      "const a = 1;",
      "/* تاني */",
      'const b = "٤";',
      "const c = 3;",
    ].join("\n");

    const lines = stripComments(source).split("\n");

    // ⭐ نفس عدد السطور
    expect(lines).toHaveLength(5);

    // ⭐ السطر ٢ و ٤ و ٥ زي ما هما — التعليق اتشال بس
    expect(lines[1]).toBe("const a = 1;");
    expect(lines[3]).toBe('const b = "٤";');
    expect(lines[4]).toBe("const c = 3;");

    // ⭐ سطور التعليقات بقت مسافات فاضية (مش اتمسحت)
    expect(lines[0].trim()).toBe("");
    expect(lines[2].trim()).toBe("");
  });

  /**
   * ⭐⭐ يفرق بين **المحاذاة** و**المسافة**.
   *
   * `text-left-0` في Tailwind معناها مسافة على الشمال، مش محاذاة
   * نص. والاختبار لازم يفرق — لو الـ regex بقى `/text-left/`
   * من غير حدود، هيبتلع الصنفين.
   */
  it("يفرق بين المحاذاة والمسافة", () => {
    const SPACING = [
      '<div className="ps-4 pe-2 ms-auto text-left-0" />',
      '<div className="text-right-2" />',
      '<div className="inset-x-0" />',
    ].join("\n");

    writeFileSync(join(dir, "app", "spacing.tsx"), SPACING, "utf8");
    expect(scan(dir), "أصناف المساحة اتحسبت محاذاة").toEqual([]);

    writeFileSync(join(dir, "app", "spacing.tsx"), '<div className="text-left" />', "utf8");
    expect(scan(dir)).toHaveLength(1);
  });
});
