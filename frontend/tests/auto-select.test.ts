import { describe, it, expect } from "vitest";
import { autoSelectPeriod, periodsKeyOf } from "@/lib/auto-select";

/**
 * ⭐⭐⭐ **حارس اختيار الفترة في `payroll` و `teacher-payroll`.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ **اللي حصل فعلاً**
 *
 * أول نسخة من التحويل لـ `usePage` كانت بتختار الفترة كده:
 *
 *     if (!periodId || !periods.some((p) => p.id === periodId)) {
 *       setPeriodId(periods[0]?.id ?? null);
 *     }
 *
 * ⭐ `setState` **جوه الرسم** (مش جوه event) بيعيد الرسم فوراً.
 * فلو الشرط فضل `true` — وبيفضل `true` **طول ما مفيش فترات في
 * القاعدة** — React بيعيد الرسم للأبد:
 *
 *     Error: Too many re-renders. React limits the number of
 *     renders to prevent an infinite loop.
 *
 * ⭐ النتيجة: صفحتي `/payroll` و `/teacher-payroll` رجّعت **500**
 * والصفحة بتقول «This page couldn't load». وكمان `tsc` و
 * `eslint` **نضيفين** — لأن التحويل كان صح من ناحية النحو.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه الاختبار ده يباكي الرسم يدوي؟
 *
 * الاختبارات هنا **منطق بحت** من غير React (زي باقي ملفات
 * `tests/`). فبنحاكي دورة الرسم بإيدنا: كل دورة نطبّق القاعدة
 * ونشوف **الجولة تكون قد إيه**.
 *
 * ⭐ دورة الرسم بتخلص في **جولتين** بالكتير. الحارس الصح
 * (`periodsKeyOf`) بيخلّي الجولة التانية تطلع `false` فمفيش
 * `setState` تاني.
 */

type Period = { id: number; status: string };

/** فترة مفتوحة (اللي المفروض الاختيار يفضل عليها) */
const open = (id: number): Period => ({ id, status: "open" });
/** فترة مقفولة */
const closed = (id: number): Period => ({ id, status: "finalized" });

/**
 * ⭐⭐⭐ **محاكاة دورة الرسم واحدة — بالحارس القديم أو الجديد.**
 *
 * ⚠️ `autoFor` موجود بس في النمط الجديد. في القديم مافيش حاجة
 * بتتذكر إننا سبق وحدّثنا — وده **هو** سبب المشكلة.
 *
 * بترجع `setState: false` لو مفيش حالة اتغيّرت، وده اللي
 * بيوقف دورة الرسم.
 */
function renderPass(
  periods: readonly Period[],
  periodId: number | null,
  autoFor: string | null,
  mode: "old" | "new",
): { setState: boolean; periodId: number | null; autoFor: string | null } {
  if (mode === "new") {
    // ⭐ الحارس الصح: على بصمة القائمة
    const key = periodsKeyOf(periods);
    if (autoFor !== key) {
      return { setState: true, periodId: autoSelectPeriod(periods, periodId), autoFor: key };
    }
    return { setState: false, periodId, autoFor };
  }

  // ⭐⭐⭐ الحارس الغلط (اللي عمل الـ 500): على صحة الاختيار
  if (!periodId || !periods.some((p) => p.id === periodId)) {
    const o = periods.find((p) => p.status === "open");
    return { setState: true, periodId: o?.id ?? periods[0]?.id ?? null, autoFor };
  }

  return { setState: false, periodId, autoFor };
}

/** ⭐ كم دورة رسم اتعملت لحد ما يهدا؟ — `Infinity` = حلقة لا نهائية */
function passesToSettle(
  periods: readonly Period[],
  startId: number | null,
  mode: "old" | "new",
): number {
  let periodId = startId;
  let autoFor: string | null = null;
  let passes = 0;

  for (;;) {
    if (passes > 50) return Infinity;

    const pass = renderPass(periods, periodId, autoFor, mode);
    if (!pass.setState) return passes;

    periodId = pass.periodId;
    autoFor = pass.autoFor;
    passes++;
  }
}

describe("اختيار الفترة في شاشتي المرتبات", () => {
  // ============================================================
  // ① الحارس الصح بيقفل الحلقة — دي الاختبارات الأساسية
  // ============================================================

  it("القائمة الفاضية بتهدا فورًا (وهي اللي كانت بتعمل الحلقة)", () => {
    /**
     * ⭐⭐⭐ **الاختبار اللي بيحمي من الـ 500.**
     *
     * القاعدة فيها **صفر فترات**، فكان `!periodId` بيفضل
     * `true` للأبد.
     */
    expect(passesToSettle([], null, "new")).toBeLessThanOrEqual(2);
  });

  it("قائمة فيها فترات مختومة بتهدا فورًا", () => {
    expect(passesToSettle([closed(3), closed(1)], null, "new")).toBeLessThanOrEqual(2);
  });

  it("قائمة عادية بتهدا", () => {
    expect(passesToSettle([open(1), closed(2), open(3)], null, "new")).toBeLessThanOrEqual(2);
  });

  it("اختيار يدوي محفوظ بتهدا", () => {
    expect(passesToSettle([open(1), open(2), open(3)], 3, "new")).toBeLessThanOrEqual(2);
  });

  // ============================================================
  // ② ⭐⭐⭐ **الدليل إن الحارس القديم كان بيعمل حلقة لا نهائية**
  // ============================================================

  it("الحارس القديم كان بيعمل حلقة لا نهائية على القائمة الفاضية", () => {
    /**
     * ⭐⭐⭐ **ده اللي كان بيحصل فعلاً.**
     *
     * مفيش فترات → `!periodId` بيفضل `true` → React بيعيد الرسم
     * مع كل `setState` أثناء الرسم → «Too many re-renders».
     *
     * ⭐ الاختبار بيثبت إن `periodsKeyOf` مش مجرد تنسيق — هو
     * **الفرق** بين صفحة شغالة وصفحة بترجع 500.
     */
    expect(passesToSettle([], null, "old")).toBe(Infinity);
  });

  it("الحارس القديم بيفشل **بس** لما مفيش فترات خالص", () => {
    /**
     * ⭐⭐⭐ **الفرق الدقيق.**
     *
     * الحارس القديم بيشتغل مع فترات موجودة:
     *
     *     periodId = null  → !periodId = true  → periodId = 3
     *     periodId = 3     → some(p.id === 3) = true → يهدا
     *
     * يعني **جولتين بس** — مفيش حلقة.
     *
     * ⭐ الحلقة بتحصل **بس** لما `periods[0]?.id` كمان يبقى
     * `undefined` — يعني `periods = []` خالص. ساعتها `setPeriodId(null)`
     * مابيتغيّرش، والشرط بيفضل `true` للأبد.
     *
     * ⭐ وده بالظبط حال القاعدة الفاضية لمشروع جديد: مفيش فترات
     * مرتبات لسه، فالصفحة **بتقع 500**. مش حالة نادرة — هي
     * الحالة الطبيعية قبل أول احتساب.
     */
    expect(passesToSettle([closed(3), closed(1)], null, "old")).toBeLessThanOrEqual(3);
    expect(passesToSettle([], null, "old")).toBe(Infinity);
  });

  it("الحارس الجديد بيشتغل في **كل** الحالات — حتى الفاضية", () => {
    /**
     * ⭐⭐⭐ **دي القيمة الحقيقية للحارس الجديد.**
     *
     * مش بس بيقفل الحلقة — ده بيشتغل على كل الحالات. يعني
     * ما فيش حالة نقدر نقول «هنا نرجع للقديم عشان أبسط».
     */
    for (const periods of [[], [closed(1)], [open(1), open(2)], [closed(3), open(1)]] as Period[][]) {
      expect(passesToSettle(periods, null, "new")).toBeLessThanOrEqual(2);
    }
  });

  // ============================================================
  // ③ البصمة نفسها
  // ============================================================

  it("البصمة ثابتة لنفس القائمة وبتتغير فورًا مع أول تغيير", () => {
    /**
     * ⭐ لازم `periodsKeyOf` ترجع نص **ثابت** لكل رمية من
     * نفس القائمة. لو رجّع نص متغيّر، الحارس يفشل.
     */
    const periods: Period[] = [];
    const key = periodsKeyOf(periods);

    for (let i = 0; i < 10; i++) {
      expect(periodsKeyOf(periods)).toBe(key);
    }

    expect(periodsKeyOf([open(1)])).not.toBe(key);
  });

  // ============================================================
  // ③ القرار نفسه — أولوية الاختيار
  // ============================================================

  it("مفيش فترات → null", () => {
    expect(autoSelectPeriod([], null)).toBeNull();
    expect(autoSelectPeriod([], 5)).toBeNull();
  });

  it("أول فترة مفتوحة هي المختارة", () => {
    expect(autoSelectPeriod([closed(3), open(7), open(9)], null)).toBe(7);
  });

  it("لو مفيش مفتوحة → أحدث فترة", () => {
    expect(autoSelectPeriod([closed(3), closed(1), closed(2)], null)).toBe(3);
  });

  it("الاختيار اليدوي بيتصون لو الفترة لسه موجودة", () => {
    /**
     * ⭐ ده مهم: المستخدم مختار الفترة ٢، عملنا reload،
     * والقائمة رجعت. ما نرجعش نختار ١ ونلغي اختياره.
     */
    expect(autoSelectPeriod([open(1), open(2)], 2)).toBe(2);
  });

  it("لو الفترة المختارة اتمسحت → بنختار تاني", () => {
    expect(autoSelectPeriod([open(1), open(2)], 99)).toBe(1);
  });

  // ============================================================
  // ④ البصمة نفسها
  // ============================================================

  it("البصمة بتتميز بالقائمة وبتثبت ليها", () => {
    expect(periodsKeyOf([])).toBe("");
    expect(periodsKeyOf([open(1)])).toBe("1");
    expect(periodsKeyOf([open(3), open(1)])).toBe("3,1");

    expect(periodsKeyOf([open(1), open(3)])).toBe(periodsKeyOf([open(1), open(3)]));
  });

  it("البصمة بتفرق بين قائمتين مختلفتين", () => {
    expect(periodsKeyOf([open(1)])).not.toBe(periodsKeyOf([open(2)]));
    expect(periodsKeyOf([open(1), open(2)])).not.toBe(periodsKeyOf([open(2), open(1)]));
  });

  it("البصمة بتتغير بإضافة فترة جديدة", () => {
    /**
     * ⭐ ده اللي بيخلّي `autoFor !== periodsKeyOf(periods)` يتفعّل
     * لما السيرفر يرجّع فترة جديدة — من غير ما نعمل polling.
     */
    const before = [open(1)];
    const after = [open(1), open(2)];

    expect(periodsKeyOf(before)).not.toBe(periodsKeyOf(after));
    // ⭐ والقرار بعد الإضافة بيفضل على الفترة الموجودة
    expect(autoSelectPeriod(after, null)).toBe(1);
  });
});