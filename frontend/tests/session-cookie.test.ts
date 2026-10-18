import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, it } from "vitest";

import { stripComments } from "./guard";

/**
 * ⭐⭐⭐⭐ **اسم كوكي الجلسة — السيرفر والواجهة.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐⭐ الثغرة: الـproxy كان ميت في كل بيئة حقيقية
 *
 * ⭐ `config/session.php` في الـbackend كان بيحسب الاسم:
 *
 *     'cookie' => env('SESSION_COOKIE', Str::slug(env('APP_NAME', 'laravel'), '_').'_session'),
 *
 * ⭐ مع `APP_NAME="Quran Academy"` — القيمة الحقيقية في `.env` —
 * الاسم الفعلي على السيرفر كان **`quran_academy_session`**.
 *
 * ⭐ و`proxy.ts` كان فيه:
 *
 *     const SESSION_COOKIE = "laravel_session";
 *
 * ⭐⭐⭐ النتيجة: `hasSessionCookie()` **false دايمًا**، و
 * `decideRoute` بيعمل `redirect → /login` لأي صفحة محمية —
 * **حتى لمستخدم داخل فعلاً**. الـlogin شغّال، والـproxy ميت،
 * ومافيش error ولا build failure ولا warning.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه محدش شافها
 *
 * ⭐ ① `proxy.ts` اختباراته بتدوّر على **سلوك** `decideRoute`،
 *    والاسم ثابت جوه الملف — فمافيش حاجة بتقارنه بالواقع.
 *
 * ⭐ ② `SessionAuthenticationTest::spaLogin` كان بيعمل
 *    `getCookie('laravel_session')?->getValue()` → **null**،
 *    ويمرّر `null` لـ`withCookie`. فالاختبارات كانت شغّالة في
 *    **token mode** وبتعدّي، لأن السلوكين بيرجّعوا نفس الرد
 *    (`200` + توكن).
 *
 * ⭐⭐ الدرس: **اسم كوكي متفق عليه بين عمليتين مش اختبار
 * وحدة — هو اختبار بين الملفين.** مافيش type-checker بيقرا
 * `config/session.php` ولا `NEXT_PUBLIC_*`.
 *
 * @see frontend/proxy.ts
 * @see backend/config/session.php
 * @see backend/tests/Feature/SessionAuthenticationTest::test_the_session_cookie_name_is_shared_with_the_frontend
 */

const FRONTEND_ROOT = fileURLToPath(new URL("..", import.meta.url));
const BACKEND_ROOT = fileURLToPath(new URL("../../backend/", import.meta.url));

const proxy = (): string => stripComments(readFileSync(`${FRONTEND_ROOT}/proxy.ts`, "utf8"));
const backendEnv = (): string => readFileSync(`${BACKEND_ROOT}/.env.example`, "utf8");
const backendEnvLocal = (): string => {
  try {
    return readFileSync(`${FRONTEND_ROOT}/.env.local.example`, "utf8");
  } catch {
    return "";
  }
};

// ═══════════════════════════════════════════════════════════════
// ① الـbackend: الاسم ثابت، مش مشتق
// ═══════════════════════════════════════════════════════════════

describe("سيرفر — اسم كوكي الجلسة", () => {
  it("مش مشتق من APP_NAME", () => {
    const src = stripComments(readFileSync(`${BACKEND_ROOT}/config/session.php`, "utf8"));

    // ⭐⭐ السبب الجذري: `APP_NAME` اسم **عرض**، وربطه بكوكي
    // الـauth بيعني تغيير اسم المشروع يكسر الـlogin بصمت.
    expect(
      src,
      "⭐⭐ `config/session.php` رجع يشتق اسم الكوكي من APP_NAME",
    ).not.toContain("Str::slug");
  });

  it("بيقرا من SESSION_COOKIEE", () => {
    const src = stripComments(readFileSync(`${BACKEND_ROOT}/config/session.php`, "utf8"));

    expect(src).toContain("env('SESSION_COOKIE'");
  });

  it("الـfallback ثابت ومعروف", () => {
    const src = stripComments(readFileSync(`${BACKEND_ROOT}/config/session.php`, "utf8"));

    expect(src).toContain("'laravel_session'");
  });
});

// ═══════════════════════════════════════════════════════════════
// ② الـواجهة: بتقرا الاسم من env مش من ثابت
// ═══════════════════════════════════════════════════════════════

describe("واجهة — الـproxy", () => {
  it("الاسم من NEXT_PUBLIC_SESSION_COOKIE", () => {
    expect(
      proxy(),
      "⭐⭐ `proxy.ts` لسه بيعمل hardcode لاسم الكوكي — الـproxy هيبقى ميّت لو الاسم اتغيّر",
    ).toContain("process.env.NEXT_PUBLIC_SESSION_COOKIE");
  });

  it("الـfallback بيوافق السيرفر", () => {
    // ⭐ الـfallback هو اللي بيشتغل من غير `.env`، فلازم يبقى
    // **نفس** الـfallback في `config/session.php`.
    expect(proxy()).toContain('"laravel_session"');
  });

  it("بيستخدم الاسم في `cookies.get`", () => {
    const src = proxy();

    // ⭐ متغير بيتقرأ ومحدش بيستعمله = ميت. نفرض إنه في
    // `cookies.get(...)` فعلاً.
    expect(src).toMatch(/cookies\.get\(\s*SESSION_COOKIE\s*\)/);
  });
});

// ═══════════════════════════════════════════════════════════════
// ③ المتغيرين متساويين في ملفّي الـenv
// ═══════════════════════════════════════════════════════════════

describe("الـenv على الجانبين", () => {
  it("`.env.local.example` بيوثّق المتغير", () => {
    expect(
      backendEnvLocal(),
      "⭐⭐ مافيش `NEXT_PUBLIC_SESSION_COOKIE` في .env.local.example",
    ).toContain("NEXT_PUBLIC_SESSION_COOKIE=");
  });

  it("القيمة في ملف الواجهة = القيمة في ملف السيرفر", () => {
    const front = backendEnvLocal().match(/^NEXT_PUBLIC_SESSION_COOKIE=(.*)$/m)?.[1]?.trim();
    const back = backendEnv().match(/^SESSION_COOKIE=(.*)$/m)?.[1]?.trim();

    expect(front, "مافيش قيمة في .env.local.example").toBeDefined();
    expect(back, "مافيش قيمة في backend/.env.example").toBeDefined();
    expect(
      front,
      "⭐⭐⭐ الاسمين مختلفين — الـproxy بيدوّر على الاسم الغلط وكل صفحة محمية بتعمل redirect لـ/login",
    ).toBe(back);
  });
});