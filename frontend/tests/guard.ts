/**
 * ⭐⭐ منطق الحارس — منفصل عن الاختبارين عن قصد.
 *
 * ليه في ملف لوحده؟
 *
 * لأن في **مكانين** بيفحص:
 *
 *   ١. `format.test.ts` — بيفحص المشروع الحقيقي.
 *   ٢. `format-guard-self.test.ts` — بيكسر القواعد في مجلد
 *      مؤقت وبيتأكد إن الحارس بيمسكها.
 *
 * فلو المنطق كان جوّه الاختبار، الاختبار التاني كان هيبقى
 * فيه **نسختين** من نفس الكود — وأول ما حد يعدّل واحدة
 * هيفضل التانية قديمة.
 *
 * ⭐ يعني الاختبار التاني بيختبر **نفس الكود** اللي بيحمي
 * المشروع — مش نسخة منه.
 */

import { readFileSync, readdirSync, statSync } from "node:fs";
import { join, relative, sep } from "node:path";

// ─────────────────────────────────────────────────────────────
// الإعدادات
// ─────────────────────────────────────────────────────────────

/**
 * ⭐ المجلدات اللي المفروض كل تنسيق يطلع منها.
 *
 * ⚠️ **`tests` مش هنا عن قصد.**
 *
 * لأن الحارس نفسه فيه الأنماط المحظورة مكتوبة كـ regex:
 *
 *     pattern: /[٠-٩۰-۹]/g,
 *     pattern: /\btext-(?:left|right)(?![\w-])/g,
 *
 * فلو ضفنا `tests`، الحارس هيبلع نفسه. وكمونو ملف الاختبار
 * بيستخدم عن قصد كود فيه مخالفات — فلازم بره النطاق.
 */
export const SCANNED_DIRS = ["app", "components", "lib"] as const;

/**
 * ⭐ الملف الوحيد المسموح ياخد `toLocale*` و `Intl`.
 *
 * هذا **هو** المكان الوحيد المفروض يتعامل مع `Intl` — لأنه
 * هو اللي بيعرف `LOCALE` الصح.
 */
export const ALLOWED_INTL = "lib/format.ts";

const CODE_EXTENSIONS = [".ts", ".tsx"];

/** مجلدات بنتجاهلها — مخرجات بناء أو ملفات مثبّتة */
const IGNORED = new Set(["node_modules", ".next", ".git", "dist", "build", "coverage"]);

// ─────────────────────────────────────────────────────────────
// القواعد المحظورة
// ─────────────────────────────────────────────────────────────

export type Rule = {
  name: string;
  pattern: RegExp;
  /** ⭐ الحل — عشان المطوّر ما يقعدش يدوّر */
  hint: string;
};

export const RULES: readonly Rule[] = [
  {
    name: "toLocaleString / toLocaleDateString / toLocaleTimeString",
    pattern: /\.toLocale(?:String|DateString|TimeString)\s*\(/g,
    hint: "استخدم `num` / `date` / `time` / `dateSmart` من `@/lib/format`",
  },
  {
    name: "new Intl.* مباشرة",
    pattern: /\bnew\s+Intl\s*\./g,
    hint: "استخدم `num` / `egp` / `money` من `@/lib/format`",
  },
  {
    name: "رقم من ٠-٩ أو ۰-۹",
    pattern: /[٠-٩۰-۹]/g,
    hint: "أرقام لاتينية — ولو بتقرأ رقم كتبه المستخدم بالعربي، حوّله بـ `toNumber`",
  },
  {
    name: "`text-left` أو `text-right`",
    // ⭐ `(?![\w-])` مش `\b`.
    //
    //   `\b` معناها «حدود كلمة»، و`-` **مش** حرف كلمة. فـ
    //   `text-left-0` الـ `\b` بتتحقق بعد `left` — لأن اللي
    //   بعدها محايد — والحارس هيبتلع `text-left` من class اسمه
    //   `text-left-0` اللي معناها **مسافة** مش محاذاة.
    //
    //   `(?![\w-])` بتقول: اللي بعد الكلمة لازم يكون حاجة
    //   تانية غير حرف كلمة أو شرطة.
    pattern: /\btext-(?:left|right)(?![\w-])/g,
    hint: "استخدم `text-start` / `text-end` — دول بيعرفوا اتجاه الصفحة",
  },
  {
    name: "محاذاة فيزيائية جوا CSS",
    pattern: /text-align\s*:\s*(?:left|right)\b/g,
    hint: "استخدم `text-align: start` / `end`",
  },
];

// ─────────────────────────────────────────────────────────────
// الأدوات
// ─────────────────────────────────────────────────────────────

/**
 * ⭐ يشيل التعليقات.
 *
 * السبب: التعليقات فيها أمثلة على **الغلط** — زي «كان
 * `toLocaleString` بيطلع السطر الطويل» — فلازم تعدّي. ولو
 * ما شِلناها، الحارس هيفشل بسبب كلام في تعليق، والمطوّر
 * يبقى مش عارف السبب.
 *
 * ⭐ وبنستبدل كل حرف بمسافة (مش بنمسح) عشان **أرقام الأسطر
 * تفضل صحيحة** — عشان رسالة الخطأ تقول السطر الصح.
 */
export function stripComments(source: string): string {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "))
    .replace(/\/\/[^\n]*/g, (m) => m.replace(/[^\n]/g, " "));
}

/** ⭐ رقم السطر لحد offset معيّن */
export function lineAt(source: string, index: number): number {
  return source.slice(0, index).split("\n").length;
}

/**
 * ⭐ كل ملفات الكود تحت المسارات المطلوبة، بمسارات نسبية بـ `/`.
 *
 * بنوحّد الفاصل لـ `/` عشان المقارنة مع `ALLOWED_INTL` تشتغل
 * على ويندوز زي ما شغالة على لينكس.
 */
export function codeFiles(root: string): string[] {
  const found: string[] = [];

  for (const dir of SCANNED_DIRS) {
    walk(join(root, dir));
  }

  function walk(abs: string): void {
    for (const entry of readdirSync(abs)) {
      if (IGNORED.has(entry)) continue;

      const full = join(abs, entry);

      if (statSync(full).isDirectory()) {
        walk(full);
      } else if (CODE_EXTENSIONS.some((ext) => entry.endsWith(ext))) {
        found.push(relative(root, full).split(sep).join("/"));
      }
    }
  }

  return found.sort();
}

// ─────────────────────────────────────────────────────────────
// الفحص
// ─────────────────────────────────────────────────────────────

/** ⭐ مخالفة واحدة */
export type Violation = {
  file: string;
  line: number;
  match: string;
  hint: string;
};

/**
 * ⭐⭐ يفحص جذر واحد ويرجّع كل المخالفات.
 *
 * @param root جلد الواجهة
 * @param allow ملفات معفاة من الفحص كله (عادةً `lib/format.ts`)
 */
export function scan(root: string, allow: readonly string[] = [ALLOWED_INTL]): Violation[] {
  const allowed = new Set(allow);
  const violations: Violation[] = [];

  for (const file of codeFiles(root)) {
    if (allowed.has(file)) continue;

    const source = stripComments(readFileSync(join(root, file), "utf8"));

    for (const rule of RULES) {
      // ⭐ بنعمل regex جديد لكل ملف — لأن `lastIndex` بتتحرك
      //   مع الـ `g`، ولو استعملنا نفس الـ object الـ matches
      //   التانية هتطلع فاضية.
      const pattern = new RegExp(rule.pattern.source, rule.pattern.flags);

      for (const match of source.matchAll(pattern)) {
        violations.push({
          file,
          line: lineAt(source, match.index),
          match: match[0].trim(),
          hint: rule.hint,
        });
      }
    }
  }

  return violations;
}

/** ⭐ المخالفات في شكل رسالة مقروءة — للطباعة في فشل الاختبار */
export function describeViolations(violations: readonly Violation[]): string {
  const byFile = new Map<string, Violation[]>();

  for (const v of violations) {
    const list = byFile.get(v.file) ?? [];
    list.push(v);
    byFile.set(v.file, list);
  }

  const lines: string[] = [];

  for (const [file, list] of [...byFile].sort()) {
    lines.push(`  ${file}`);
    for (const v of list) {
      lines.push(`    سطر ${v.line}  →  ${v.match}   (${v.hint})`);
    }
  }

  return lines.join("\n");
}
