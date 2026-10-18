import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

import { stripComments } from "@/tests/guard";

/**
 * ⭐⭐⭐ مافيش توكن في `localStorage` — القبول الأول للخارطة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه assertion على **نص الملف** مش على السلوك
 *
 * ⭐ السلوك هنا مش قابل للاختبار في البيئة دي: Vitest بيشتغل
 * `node` من غير `jsdom` ومن غير `@testing-library/react`،
 * فمافيش `window.localStorage` أصلاً.
 *
 * ⭐ والـassertion على النص دي **مش** حيلة — هي إصلاح textual
 * للمخاطرة:
 *
 *   `localStorage` في متناول أي سكربت شغّال في الصفحة. أي ثغرة
 *   XSS في أي حزمة بتقرا `auth_token` وتبعته لحد. الكوكي
 *   `HttpOnly` الم Serraferراه المتصفح **مينعش** السكربتات.
 *
 * ⭐ والصفحة بتحطّ `auth_token` في **3** أماكن بالظبط (الكود
 * القديم)، وكل واحد منهم كان فيCounte الملف.
 *
 * ⭐ وفيها **سابق في المشروع**: `ProgramPermissionTest` في
 * الـbackend بيقرأ نص الـseeder عشان يتحقق إنه بيعمل الدورين.
 * نفس الفكرة — بعض العقود مالهاش سلوك قابل للاختبار.
 *
 * @see lib/api.ts
 */

/**
 * ⭐⭐⭐ بنفحص **الكود بس** — التعليقات بتتشال.
 *
 * ⭐ السبب: `lib/api.ts` مستند **ليه** الكود القديم اتشال، يعني
 * التعليقات نفسها فيها `auth_token` و `localStorage.setItem(...)`
 * كأمثلة على الغلط. من غير التنضيف الاختبار بيفشل بسبب كلام
 * بيشرح الإصلاح.
 *
 * ⭐ `stripComments` موجودة أصلاً في `tests/guard.ts` وبنستخدمها
 * لنفس السبب بالظبط — فبنستعملها هنا كمان بدل ما نكتب نسخة.
 *
 * ⚠️ وبنشيل الأرقام من السطر بتفضيل مسافات — عشان رسائل الخطأ
 * تفضل بتقول رقم السطر الصح.
 */
const apiSource = readFileSync(
  fileURLToPath(new URL("../lib/api.ts", import.meta.url)),
  "utf8",
);

const API = stripComments(apiSource);

const PROXY = (() => {
  try {
    return readFileSync(fileURLToPath(new URL("../proxy.ts", import.meta.url)), "utf8");
  } catch {
    return "";
  }
})();

describe("localStorage must not hold credentials", () => {
  it("⭐⭐ lib/api.ts never writes auth_token", () => {
    const offenders = API.split("\n")
      .map((line, index) => ({ line: line.trim(), index: index + 1 }))
      .filter(({ line }) => /auth_token/.test(line));

    expect(
      offenders,
      `lib/api.ts لسه بتتعامل مع auth_token:\n  • ${offenders
        .map((o) => `سطر ${o.index}: ${o.line}`)
        .join("\n  • ")}`,
    ).toEqual([]);
  });

  it("⭐ lib/api.ts never writes any token-shaped key", () => {
    // ⭐ مش `auth_token` بس — أي مفتاح فيه «token» نفس المشكلة.
    // ⭐ والاختبار ده بيغطي لو حد أضاف مفتاح تاني من نفس النوع.
    const offenders = API.split("\n")
      .map((line, index) => ({ line: line.trim(), index: index + 1 }))
      .filter(({ line }) => /localStorage\.[a-zA-Z]+\([^)]*token/i.test(line));

    expect(
      offenders,
      `lib/api.ts بيكتب مفتاح فيه token في localStorage:\n  • ${offenders
        .map((o) => `سطر ${o.index}: ${o.line}`)
        .join("\n  • ")}`,
    ).toEqual([]);
  });

  it("⭐ lib/api.ts does not persist the user object either", () => {
    // ⭐⭐ الاسم والأدوار **مش** حساسية، بس وجود نسخة في
    // localStorage معناه إن:
    //
    //   ① مستخدم تاني على نفس المتصفح بيشوفها
    //   ② الـlogout بينسى يشيلها = صورة قديمة باقية
    //   ③ `getStoredUser()` بتخلي الـUI يرسم مستخدم **من غير
    //      ما يسأل السيرفر** — يعني بعد تغيير الصلاحيات مباشرة
    //      الواجهة لسه بتعرض القديم
    //
    // ⭐ المصدر الوحيد للحالة هو `fetchMe()`.
    const offenders = API.split("\n")
      .map((line, index) => ({ line: line.trim(), index: index + 1 }))
      .filter(({ line }) => /localStorage\.setItem\(\s*["'][a-z_]*user/i.test(line));

    expect(
      offenders,
      `lib/api.ts لسه بيحفظ المستخدم في localStorage:\n  • ${offenders
        .map((o) => `سطر ${o.index}: ${o.line}`)
        .join("\n  • ")}`,
    ).toEqual([]);
  });

  it("⭐⭐ lib/api.ts always sends cookies", () => {
    // ⭐ من غير `credentials: "include"` المتصفح مش هيبعت كوكي
    // الجلسة، وكل طلب بيرجع 401.
    expect(API).toMatch(/credentials\s*:\s*["']include["']/);
  });

  it("⭐ lib/api.ts sends the CSRF header on writes", () => {
    expect(API).toMatch(/X-XSRF-TOKEN/);
  });

  it("⭐⭐ the proxy is named proxy.ts, not middleware.ts", () => {
    // ⭐⭐⭐ Next 16 غيّر الاسم: `middleware.ts` بقى `proxy.ts`.
    // ⭐ الملف القديم **مش** بيتقرا أصلاً — فالحماية كلها بتتخفي
    // من غير أي تحذير.
    expect(
      PROXY,
      "proxy.ts مش موجود — في Next 16 الملف اسمه proxy.ts مش middleware.ts",
    ).not.toBe("");

    expect(PROXY).toMatch(/export\s+(default\s+)?(async\s+)?function\s+proxy/);
  });

  it("⭐ the proxy never does a data fetch for the user", () => {
    // ⭐⭐ وثائق Next صريحة: الـproxy «is _not_ intended for slow
    // data fetching». وهو بيشتغل على **كل** راوت حتى اللي
    // prefetched — فطلب شبكة لكل مستخدم على كل راوت ه.weaken
    // performs badly ويكسر التحقق المتفائل.
    expect(
      PROXY,
      "الـproxy بيعمل fetch — المفروض يفحص الكوكي بس، والتحقق الحقيقي في lib/dal.ts",
    ).not.toMatch(/await\s+fetch\(/);
  });
});