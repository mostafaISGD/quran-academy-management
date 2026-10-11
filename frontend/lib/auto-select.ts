/**
 * ⭐⭐⭐ **قرار «اختار الفترة دي» — منطق بحت عشان يتختبر.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه الكود ده منفصل أصلاً؟
 *
 * ═══════════════════════════════════════════════════════════════
 *
 * في `payroll` و `teacher-payroll` الاختيار ده كان **جوه الرسم**
 * (مش في `useEffect`) عشان نتفادى تحذير `set-state-in-effect`:
 *
 *     const [autoFor, setAutoFor] = useState<string | null>(null);
 *     const periodsKey = periods.map((p) => p.id).join(",");
 *
 *     if (autoFor !== periodsKey) {
 *       setAutoFor(periodsKey);
 *       setPeriodId(autoSelectPeriod(periods, periodId));
 *     }
 *
 * ⚠️⚠️⚠️ **الغلطة اللي اتعملت أول مرة — واللي الاختبار بيحرسها.**
 *
 *     // ❌ الغلط:
 *     if (!periodId || !periods.some((p) => p.id === periodId)) {
 *       setPeriodId(periods[0]?.id ?? null);
 *     }
 *
 * الـ `if` ده بيفضل `true` **طول ما مفيش فترات في القاعدة**،
 * وReact بيعيد الرسم مع كل `setState` اتنادى أثناء الرسم:
 *
 *     Error: Too many re-renders. React limits the number of
 *     renders to prevent an infinite loop.
 *
 * ⭐ **السبب إن `setState` أثناء الرسم مش زي `setState` جوه
 * event**: React بيعيد الرسم **فوراً** قبل ما يلمس الـ DOM، وما
 * بيعملش bailout على نفس القيمة بالطريقة اللي بيحصل بيها في
 * الـ event handlers.
 *
 * ⭐ الحارس الصح: نقارن **معرف القائمة نفسها** (`periodsKey`).
 * فلما مفيش فترات، `periodsKey === ""` بنختار `null` **مرة
 * واحدة**، وبعدها `autoFor === ""` فمفيش إعادة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ القاعدة نفسها بتتحقق في الاختبار
 *
 * `periodsKey` هيتحسب من نفس الدالة هنا — `periodsKeyOf` — عشان
 * الاختبار يتأكد إن الحارس **ماشي مع نفس البيانات** اللي بتتبنى
 * عليه القائمة.
 */

type PeriodLike = { id: number; status: string };

/**
 * ⭐⭐⭐ **الفترة اللي المفروض تكون مختارة.**
 *
 * @param periods الفترات من السيرفر (يمكن تكون فاضية)
 * @param currentId اللي معناه «مختار دلوقتي» — قد يكون `null`
 *
 * @returns الفترة المختارة، أو `null` لو مفيش فترات
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ترتيب الأولوية
 *
 * ① لو فيه اختيار **صالح** — بنصونه.
 *    ده مهم: المستخدم يكون مختار فترة، عملنا `reload`، والقائمة
 *    رجعت. ما نرجعش نغيّر اختياره من غير سبب.
 *
 * ② أول فترة **مفتوحة** (`status === "open"`).
 *    دي الفترة اللي المفروض العمل بيبدأ بيها — لأنها الوحيدة
 *    اللي لسه بتتسجل فيها مسودّات.
 *
 * ③ أحدث فترة (أول واحدة في الـ array).
 *    fallback: كل الفترات مقفولة، فالأحدث أحسن من «اختر فترة».
 *
 * ④ `null` — مفيش فترات خالص.
 */
export function autoSelectPeriod(
  periods: readonly PeriodLike[],
  currentId: number | null,
): number | null {
  // ① الاختيار الصالح بيتصون
  if (currentId !== null && periods.some((p) => p.id === currentId)) {
    return currentId;
  }

  // ② أول فترة مفتوحة
  const open = periods.find((p) => p.status === "open");
  if (open) return open.id;

  // ③ أحدث فترة
  return periods[0]?.id ?? null;
}

/**
 * ⭐⭐⭐ **بصمة القائمة — دي اللي بيحمي من الحلقة اللانهائية.**
 *
 * ⚠️ لازم ترجع **نفس القيمة** لنفس القائمة، وتختلف فور ما
 * القائمة تتغير. لو رجعت `""` دايماً (مثلاً بـ
 * `periods.length === 0` مع وسم فاضي)، الحارس مش هيشتغل.
 *
 * @example
 * ```ts
 * periodsKeyOf([])                    // → ""
 * periodsKeyOf([{id:3},{id:1}])       // → "3,1"
 * ```
 */
export function periodsKeyOf(periods: readonly PeriodLike[]): string {
  return periods.map((p) => p.id).join(",");
}