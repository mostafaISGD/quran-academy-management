import { describe, it, expect } from "vitest";
import { weekdaysKey, parseWeekdaysKey } from "@/lib/weekdays";

/**
 * ⭐⭐⭐ **ضمانة: `useEffect` بيشتغل مرة واحدة مش كل رسم.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة اللي كانت موجودة
 *
 * `TeacherPicker` عنده `useEffect` بيعمل طلب شبكة، ومفروض
 * يعتمد على `weekdays`. بس الآباء بيرسلوا مصفوفة **جديدة في
 * كل رسم**:
 *
 *     weekdays={form.weekdays}
 *
 * فلو المصفوفة كانت في الاعتماديات مباشرة، React بيقارن
 * **بالهوية** (`Object.is`) فkova بتتغيّر كل مرة:
 *
 *     ① الأب يرسم → مصفوفة جديدة
 *     ② الـ effect يشتغل → setState
 *     ③ state بتتغيّر → الأب يرسم تاني
 *     ④ ← رجعنا لـ ① ♾️
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الحل المطبّق
 *
 * `weekdaysKey` بتحوّل المصفوفة لـ **نص مرتَّب**. النصوص
 * بتتساوى **بالقيمة** في `Object.is`، فمش بتتغيّر إلا لما
 * المحتوى يتغيّر فعلاً.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ إيه اللي الاختبارات دي بتحرس بالظبط؟
 *
 * ① نفس المحتوى بمصفوفة **مختلفة** → نفس المفتاح
 *    (وده اللي بيمنع الحلقة)
 *
 * ② نفس المحتوى بـ **ترتيب مختلف** → نفس المفتاح
 *    (وده اللي بيمنع طلب فاضي لمجرد إن المستخدم ضغط بماما
 *     ترتيب غلط)
 *
 * ③ محتوى مختلف فعلاً → مفتاح مختلف
 *    (عشان ما نعملش cache مفقود — لازم الطلب يروح)
 */
describe("weekdaysKey", () => {
  // ============================================================
  // ① نفس المحتوى — مصفوفة مختلفة الهوية
  // ============================================================

  it("gives the same key for the same days in a different array", () => {
    const a = [0, 2, 4];
    const b = [0, 2, 4]; // نفس المحتوى، هوية مختلفة تمامًا

    expect(a).not.toBe(b); // ⭐ اتأكد إنهم فعلاً مصفوفتين مختلفتين
    expect(weekdaysKey(a)).toBe(weekdaysKey(b));
  });

  it("does not mutate the array it was given", () => {
    // ⭐ مهم: `weekdaysKey` بتعمل sort — لو كانت بتعدّل
    // المصفوفة الأصلية، ده هيبوّط ترتيب الـ state عند الأب.
    const original = [4, 0, 2];
    const copy = [...original];

    weekdaysKey(original);

    expect(original).toEqual(copy);
  });

  // ============================================================
  // ② نفس المجموعة بترتيب مختلف
  // ============================================================

  it("sorts so a different order does not look like a change", () => {
    expect(weekdaysKey([4, 0, 2])).toBe(weekdaysKey([0, 2, 4]));
    expect(weekdaysKey([2, 4, 0])).toBe("0,2,4");
  });

  it("is stable across repeated calls on the same input", () => {
    const days = [3, 1, 5];
    const first = weekdaysKey(days);

    for (let i = 0; i < 100; i++) {
      expect(weekdaysKey(days)).toBe(first);
    }
  });

  // ============================================================
  // ③ محتوى مختلف فعلاً → لازم يتغيّر (عشان الطلب يروح)
  // ============================================================

  it("changes the key when the days actually change", () => {
    expect(weekdaysKey([0, 2, 4])).not.toBe(weekdaysKey([0, 2, 5]));
    expect(weekdaysKey([0])).not.toBe(weekdaysKey([0, 1]));
  });

  it("handles the empty list", () => {
    expect(weekdaysKey([])).toBe("");
  });

  it("handles a single day", () => {
    expect(weekdaysKey([3])).toBe("3");
  });

  it("keeps a zero in the list instead of dropping it as falsy", () => {
    // ⭐ فخ شائع: `filter(Boolean)` أو `||` كان ممكن يشيل `0`
    //    لأن `0` بيتعامل كـ false في JavaScript.
    //    يوم الأحد رقمه 0 — فلازم يفضل موجود.
    expect(weekdaysKey([0])).toBe("0");
    expect(weekdaysKey([0, 1])).toBe("0,1");
    expect(parseWeekdaysKey(weekdaysKey([0]))).toEqual([0]);
  });
});

describe("parseWeekdaysKey", () => {
  it("is the exact inverse of weekdaysKey", () => {
    const cases: number[][] = [
      [],
      [0],
      [0, 2, 4],
      [1, 3, 5, 6],
      [0, 1, 2, 3, 4, 5, 6],
    ];

    for (const days of cases) {
      expect(parseWeekdaysKey(weekdaysKey(days)).sort((a, b) => a - b))
        .toEqual([...days].sort((a, b) => a - b));
    }
  });

  it("returns an empty list for an empty key", () => {
    expect(parseWeekdaysKey("")).toEqual([]);
  });

  it("parses a single day", () => {
    expect(parseWeekdaysKey("3")).toEqual([3]);
  });
});
