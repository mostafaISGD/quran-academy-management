"use client";

import { useCallback, useEffect, useRef, useState } from "react";

/**
 * ⭐⭐⭐ **الطبقة اللي بتجيب البيانات — بدل `useEffect` في كل صفحة.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة اللي بتحلّها
 *
 * الكود القديم في كل صفحة كان بالشكل ده:
 *
 *     useEffect(() => {
 *       setLoading(true);        // ⭐ setState **متزامن**
 *       setError(null);          // ⭐ setState **متزامن**
 *       getData().then(setData);
 *     }, [page]);
 *
 * الـ `setState` المتزامن ده بيعمل **cascading render** — React
 * بيحس إن الـ effect نفسه بيغيّر الحالة، فالرسم بيحصل مرتين.
 *
 * ⚠️ مع React Compiler ده موضوع أداء حقيقي، وهو اللي خلّى
 * قاعدة `set-state-in-effect` تتحذّر (34 مرة في المشروع).
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ الحل
 *
 * ① **`loading` بيبدأ `true`** في الـ state نفسه، فمفيش
 *    `setLoading(true)` متزامن — الحالة الابتدائية هي اللي
 *    بتقول "بيحمّل".
 *
 * ② **كل `setState` بيحصل بعد `await`**، يعني في microtask —
 *    مش في جسم الـ effect. ده اللي القاعدة عايزاه.
 *
 * ③ **مفيش طلب مكرر**: لو الـ `key` واحد اتطلب وهو شغّال،
 *    الطلب التاني بيستنى نفس النتيجة بدل ما يطلب تاني.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ `key` — إيه هو وليه مهم؟
 *
 * `key` هو **بصمة الطلب**: لو اتغيّر، البيانات بتتحدّث.
 * لو زي ما هي، مفيش إعادة تحميل.
 *
 *     usePage(`expenses:${page}:${status}`, () => getExpenses(...))
 *
 * ⚠️ لازم `fetcher` يبقى **جوه `useCallback`** في الصفحة، وإلا
 * هوية الدالة تتغير كل رسم فالطلب هيتكرر.
 */

/** ⭐ حالة طلب جاري — بيتبادل بين كل الـ components اللي نفس الـ `key` */
type Pending = {
  promise: Promise<unknown>;
  controller: AbortController;
};

const inFlight = new Map<string, Pending>();

/**
 * ⭐⭐⭐ جلب بيانات مع منع التكرار.
 *
 * @param key بصمة الطلب — بتتغير مع كل تغيير في المعاملات
 * @param fetcher دالة بترجع `Promise` — **لازم** `useCallback`
 * @param options.enabled لو `false`، مفيش طلب خالص
 * @param options.initial القيمة الابتدائية — بتتحط فوراً بدل `null`
 *
 * @example
 * ```tsx
 * const fetcher = useCallback(() => getSettings(), []);
 * const { data, loading, error, reload } =
 *   usePage("settings", fetcher, { initial: {} as Record<string, unknown> });
 * ```
 */
export function usePage<T>(
  key: string,
  fetcher: () => Promise<T>,
  options: { enabled?: boolean; initial?: T; debounceMs?: number } = {},
): {
  data: T;
  loading: boolean;
  error: string | null;
  /** إعادة تحميل يدوية — بعد حفظ أو حذف */
  reload: () => void;
} {
  const { enabled = true, initial = null as T, debounceMs = 0 } = options;

  /**
   * ⭐ `loading` يبدأ `true`.
   *
   * ⭐⭐ ده هو اللي بيلغي `setLoading(true)` المتزامن:
   * بدل ما نعمل setState وقت التركيب، الحالة الابتدائية نفسها
   * بتقول "بيحمّل". فمفيش رسم إضافي.
   */
  const [data, setData] = useState<T>(initial);
  const [loading, setLoading] = useState(enabled);
  const [error, setError] = useState<string | null>(null);

  /**
   * ⭐ بنحتفظ بـ `key` اللي اتحمّل بيه آخر مرة.
   *
   * لازم نعرفه **وقت الرسم** مش بعدين، عشان لو الـ key اتغيّر
   * نعرف نرجّع البيانات القديمة فوراً (تحسين شعور) ونحدّث.
   */
  const loadedKey = useRef<string | null>(null);

  const [nonce, setNonce] = useState(0);
  const reload = useCallback(() => setNonce((n) => n + 1), []);

  useEffect(() => {
    if (!enabled) return;

    // ⭐ التحقق من freshness بيحصل وقت الرسم، هنا في الـ effect
    // بنعمل الشغل الحقيقي بس.
    let cancelled = false;
    loadedKey.current = key;

    /**
     * ⭐ المؤقتات اللي محتاجة تتلغي لو الـ key اتغيّر أو
     * الـ component اتشال — عشان بحث قديم ما يطلعش بعدين.
     */
    const cleanupTimers: ReturnType<typeof setTimeout>[] = [];

    // ⭐ لو فيه طلب شغّال بنفس الـ key، نستنّاه بدل ما نكرر
    const existing = inFlight.get(key);

    if (existing) {
      existing.promise
        .then((result) => {
          if (!cancelled) {
            setData(result as T);
            setError(null);
            setLoading(false);
          }
        })
        .catch((err: unknown) => {
          if (!cancelled) {
            setError(messageOf(err));
            setLoading(false);
          }
        });

      return () => {
        cancelled = true;
      };
    }

    const controller = new AbortController();

    /**
     * ⭐⭐⭐ **التأخير (debounce) — اختياري.**
     *
     * ⭐ ده بت-solving مشكلتين:
     *
     * ① **البحث أثناء الكتابة** (زي بحث أولياء أمر بالاسم):
     *    المستخدم بيكتب حرف، فبيطلب طلب. وبيكتب تاني، تاني.
     *    من غير تأخير، ده ٢٠٠ طلب وهو بيكتب كلمة واحدة.
     *
     * ② **`setState` المتزامن في جسم الـ effect:**
     *    لو كنا بنعمل `setLoading(true)` فوراً، القاعدة بتتحذّر.
     *    مع `enabled`، لما الشرط ينفع بنستخدم `loading` الابتدائي.
     *
     * ⚠️ لما `debounceMs > 0`، الـ cleanup لازم **يلغي الـ timer**
     * عشان طلبات البحث ما تتراكمش.
     */
    const delay = debounceMs > 0
      ? new Promise<void>((resolve) => {
          const timer = setTimeout(resolve, debounceMs);
          cleanupTimers.push(timer);
        })
      : Promise.resolve();

    // ⭐⭐ **`await` الأول** — كل `setState` بعدها في microtask،
    // مش في جسم الـ effect. ده اللي بيمرّي القاعدة.
    const promise = delay
      .then(() => fetcher())
      .then((result) => {
        if (!cancelled) {
          setData(result);
          setError(null);
          setLoading(false);
        }
        return result;
      })
      .catch((err: unknown) => {
        if (!cancelled) {
          setError(messageOf(err));
          setLoading(false);
        }
        throw err;
      })
      .finally(() => {
        inFlight.delete(key);
      });

    inFlight.set(key, { promise, controller });

    return () => {
      cancelled = true;
      // ⭐ إلغاء المؤقتات — عشان البحث-old ما يطلعش بعد ما
      // المستخدم يكون اتحرك للرقم الجديد
      cleanupTimers.forEach(clearTimeout);
    };
  }, [key, enabled, nonce, debounceMs]);

  /**
   * ⭐ لو الـ key اتغيّر، البيانات القديمة **مش** تنفع
   * للعرض الجديد — فبنصفّرها وقت الرسم.
   *
   * ⚠️ ده **آمن** لأن React بيعيد الرسم فوراً قبل ما يرسم
   * الـ DOM. يعني المستخدم **مش** بيشوف بيانات قديمة
   * مع فلتر جديد.
   */
  if (loadedKey.current !== null && loadedKey.current !== key) {
    loadedKey.current = key;
    setData(initial);
    setError(null);
    setLoading(true);
  }

  return { data, loading, error, reload };
}

/** ⭐ تحويل أي خطأ لرسالة عربية */
function messageOf(err: unknown): string {
  return err instanceof Error ? err.message : "حدث خطأ غير متوقع";
}
/**
 * ⭐⭐⭐ **نسخة مختصرة للصفحات اللي بترجع `Paginated<T>`.**
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه دي موجودة؟
 *
 * نص الصفحات بترجع نفس الشكل بالظبط:
 *
 *     const r = await getExpenses({ page, per_page });
 *     setItems(r.data);
 *     setMeta({ total: r.total, last_page: r.last_page });
 *
 * تكرار ده في كل صفحة. فهنا لفّيناه، والصفحة تكتب سطرين.
 *
 * @example
 * ```tsx
 * const [page, setPage] = useState(1);
 * const fetch = useCallback(() => getExpenses({ page, per_page: 100 }), [page]);
 *
 * const { items, lastPage, total, loading, error, reload } =
 *   usePaginated(`expenses:${page}`, fetch);
 * ```
 */
export function usePaginated<T>(
  key: string,
  fetcher: () => Promise<{
    data: T[];
    total?: number;
    last_page?: number;
    current_page?: number;
    per_page?: number;
  }>,
  options: { enabled?: boolean } = {},
): {
  items: T[];
  total: number;
  lastPage: number;
  currentPage: number;
  loading: boolean;
  error: string | null;
  reload: () => void;
} {
  const { enabled = true } = options;

  const { data, loading, error, reload } = usePage(key, fetcher, {
    enabled,
    initial: { data: [] as T[], total: 0, last_page: 1, current_page: 1 },
  });

  return {
    items: data.data,
    total: data.total ?? 0,
    lastPage: data.last_page ?? 1,
    currentPage: data.current_page ?? 1,
    loading,
    error,
    reload,
  };
}
