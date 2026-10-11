import { describe, it, expect } from "vitest";

/**
 * ⭐⭐⭐ **حارس `useResetPageOnChange`.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ **اللي حصل فعلاً**
 *
 * أول تحويل لـ `usePage` استعمل اختيار الفترة جوه الرسم
 * بالحارس ده:
 *
 *     if (!periodId || !periods.some((p) => p.id === periodId)) {
 *       setPeriodId(periods[0]?.id ?? null);
 *     }
 *
 * والشرط ده **بيفضل `true`** طول ما مفيش فترات — و`setState`
 * جوّه الرسم بيعيد الرسم فوراً:
 *
 *     Error: Too many re-renders.
 *
 * ⭐ النتيجة: `/payroll` و `/teacher-payroll` رجّعت 500.
 * و`tsc` و`eslint` نضيفين — الغلط كان منطقي مش نحوي.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه الاختبار ده يباكي الرسم بإيده؟
 *
 * الاختبارات هنا منطق بحت (زي باقي `tests/`). فنحاكي دورة
 * الرسم: كل دورة نطبّق القاعدة ونعدّ.
 *
 * ⭐ **الفرق الجوهري**: `ref` مش حالة. تعديله ما بيعملش
 * إعادة رسم، فـ `setPage` بتتصلّى مرة واحدة لكل بصمة
 * فلاتر — حتى لو الشرط «يفضل» متحقق.
 */

/** ⭐ نسخة مبسّطة من الـ hook — نفس المنطق بالحرف */
function simulateReset(
  filtersKey: string,
  page: number,
  lastKey: { current: string | null },
): { setPageCalled: boolean; page: number } {
  // ⭐ الـ hook الحقيقي بيستعمل `ref`، مش `useState`
  if (lastKey.current !== filtersKey) {
    lastKey.current = filtersKey;
    return { setPageCalled: true, page: 1 };
  }

  return { setPageCalled: false, page };
}

/** ⭐ كم دورة رسم لحد ما يهدا؟ — `Infinity` = حلقة لا نهائية */
function passesToSettle(filtersKey: string, startPage: number): number {
  let page = startPage;
  const lastKey: { current: string | null } = { current: null };
  let passes = 0;

  for (;;) {
    if (passes > 50) return Infinity;

    const pass = simulateReset(filtersKey, page, lastKey);
    if (!pass.setPageCalled) return passes;

    page = pass.page;
    passes++;
  }
}

/** ⭐ الحارس الغلط — نسخة من اللي كان في `payroll` بالظبط */
function simulateResetWrong(
  target: number | null,
  page: number | null,
): { setState: boolean; page: number | null } {
  /**
   * ⭐⚠️ الكود الأصلي كان `!periodId || ...` — أي **falsy check**،
   * مش `!== null`. الفرق مهم جداً: `!null` = `true`، بينما
   * `null !== null` = `false`.
   *
   * ⭐ دي بالظبط نقطة الفشل: الـ `falsy check` بيفضل `true`
   * والـ setter بيرجّع نفس القيمة ← كل دورة بتعمل نداء جديد.
   */
  if (!page) {
    return { setState: true, page: target };
  }
  return { setState: false, page };
}

function passesToSettleWrong(target: number | null, startPage: number | null): number {
  let page = startPage;
  let passes = 0;

  for (;;) {
    if (passes > 50) return Infinity;

    const pass = simulateResetWrong(target, page);
    if (!pass.setState) return passes;

    page = pass.page;
    passes++;
  }
}

describe("useResetPageOnChange — تصفير الصفحة عند تغيّر الفلاتر", () => {
  // ============================================================
  // ① الحارس الصح بيقفل الحلقة
  // ============================================================

  it("بيصفّر **مرة واحدة بس** لو الفلاتر ثابتة", () => {
    /**
     * ⭐⭐⭐ ده اللي بنحميه: الـ `ref` بيتحدّث في أول نداء،
     * فبعدها مش بيتصلّى تاني — **حتى لو الصفحة فضلت ١**.
     */
    expect(passesToSettle("a|b|c", 5)).toBe(1);
    expect(passesToSettle("a|b|c", 1)).toBe(1);
  });

  it("بيهدا فورًا مع أي قيمة بداية", () => {
    for (const start of [1, 2, 10, 100]) {
      expect(passesToSettle("search=ahmed", start)).toBe(1);
    }
  });

  it("الصفحة بتتنتصف فعلاً", () => {
    const ref = { current: null };
    expect(simulateReset("x", 7, ref).page).toBe(1);
  });

  // ============================================================
  // ② الحارس الغلط بيفشل — والدليل
  // ============================================================

  it("الحارس الغلط كان بيعمل حلقة لا نهائية", () => {
    /**
     * ⭐ الحالة الحقيقية في `/payroll`: القيمة المستهدفة `null`
     * والقيمة الحالية `null` — فالشرط مش بيتغيّر أبداً.
     */
    expect(passesToSettleWrong(null, null)).toBe(Infinity);
  });

  it("الحارس الغلط بيفشل بس لو مش قادر يغيّر القيمة", () => {
    /**
     * ⭐⭐⭐ **الدرس الحقيقي من `/payroll`.**
     *
     * `if (page !== 1) setPage(1)` **بيهدا** — لأنه بيوصل للصفحة
     * ١ فعلاً والجولة الجاية بتفضل. ده معناه إن المشكلة مش في
     * «صفّر أثناء الرسم» — المشكلة في **قيمة مش بتتغيّر**.
     *
     * ⭐ في `payroll` كان `setPeriodId(periods[0]?.id ?? null)`:
     * القاعدة فاضية → `periods[0]` مش موجود → `null` والـ state
     * أصلاً `null` → الشرط `!periodId` بيفضل `true` للأبد.
     *
     * ⭐ فرق دقيق: **الشرط بيفضل true + الـ setter مش بيغيّر
     * الحاجة** = حلقة لا نهائية.
     */
    // ⭐ يبدأ من `null` فيصفّر — جولة واحدة
    expect(passesToSettleWrong(1, null)).toBe(1);
    // ⭐ `null` مع هدف `null`: الـ falsy check بيفضل true
    // والـ setter مش بيغيّر حاجة ← حلقة لا نهائية
    expect(passesToSettleWrong(null, null)).toBe(Infinity);
  });

  it("الـ falsy check لوحده كمان بيقع في فخ معاكس", () => {
    /**
     * ⭐⭐⚠️ `if (!page) setPage(1)` — في حاجة تانية غلط:
     * لما `page = 5`، `!5` = `false`، فالتصفير **مش بيحصل خالص**.
     *
     * يعني الفلتر مش بيرجّعك لأول صفحة. صامت — المستخدم بيفتكر
     * إن الفلتر اتطبّق على صفحة ٥ وفيها ٣ نتايج.
     *
     * ⭐ في `payroll` كان فيه `|| !periods.some(...)` فالسلسلة
     * كانت بتغطي النقطة دي. لو الحارس الأول بس، كانت الصفحة
     * هتضل على رقمها.
     *
     * ⭐ **الدرس**: الحارس بأمان الطريقين — لا بيفضّل صفحات خطأ،
     * ولا بيعمل حلقة. `useResetPageOnChange` بيعتمد على
     * **البصمة** فمفيش حالة من دول.
     */
    expect(passesToSettleWrong(1, 5)).toBe(0); // ⚠️ ما صفّرش!
    expect(simulateResetWrong(1, 5).page).toBe(5);
  });

  // ============================================================
  // ③ تغيير الفلاتر يعيد التصفير
  // ============================================================

  it("فلتر جديد بيعمل تصفير تاني", () => {
    const ref = { current: null };

    // أول نداء
    expect(simulateReset("a", 5, ref)).toEqual({ setPageCalled: true, page: 1 });
    // نفس البصمة — مش بيتصلّى
    expect(simulateReset("a", 1, ref).setPageCalled).toBe(false);
    // بصمة جديدة — بيتصلّى تاني
    expect(simulateReset("b", 1, ref)).toEqual({ setPageCalled: true, page: 1 });
  });

  it("الصفحة اللي المستخدم اتنقل ليها بتفضل محفوظة", () => {
    /**
     * ⭐⭐ تغيّر مهم عن الكود القديم.
     *
     * الكود القديم كان بيعمل التصفير في `useEffect` — وكل ما
     * الـ `load` بيتغيّر هوية (لأنه بيعتمد على الفلاتر) بيعيد
     * التصفير. فلو المستخدم كان في صفحة ٣ والفلتر اتغيّر، رجع ١
     * (صح) — بس لو رجع تاني لنفس الفلتر، كان هيفصله كمان.
     *
     * ⭐ الـ `key` جوّه `usePage` هو اللي بيتعامل مع ده: كل
     * تغيير فلاتر = بصمة جديدة = طلب جديد بصفحة ١.
     */
    const ref = { current: null };

    simulateReset("a", 5, ref);          // فلتر أ أول مرة →صفحة ١
    const goToPage3 = simulateReset("a", 3, ref); // المستخدم راح ٣
    expect(goToPage3.setPageCalled).toBe(false);
    expect(goToPage3.page).toBe(3);       // ⭐ الصفحات بتتحرك عادي

    // فلتر ب — بيرجع لصفحة ١
    expect(simulateReset("b", 3, ref).page).toBe(1);
  });
});