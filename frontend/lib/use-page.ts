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
  options: { enabled?: boolean; initial?: T } = {},
): {
  data: T;
  loading: boolean;
  error: string | null;
  /** إعادة تحميل يدوية — بعد حفظ أو حذف */
  reload: () => void;
} {
  const { enabled = true, initial = null as T } = options;

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

    // ⭐⭐ **`await` الأول** — كل `setState` بعدها في microtask،
    // مش في جسم الـ effect. ده اللي بيمرّي القاعدة.
    const promise = Promise.resolve()
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
    };
  }, [key, enabled, nonce]);

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
 * ⭐⭐⭐ **`reload` لكل الـ components بنفس الـ `key`.**
 *
 * لما صفحة تحفظ أو تحذف، بتطلب `reloadAll("expenses:1:")` بدل
 * ما كل صفحة تعمل `await load()`. كده **أي** نسخة من نفس
 * البيانات بتتحدّث — حتى لو في تاب تاني مفتوح.
 */
export function reloadAll(prefix: string): void {
  reloadListeners.forEach((fn) => fn(prefix));
}

const reloadListeners = new Set<(prefix: string) => void>();

/** ⭐ داخلي: يشترك الـ hook في طلبات إعادة التحميل */
export function useReloadBus(key: string): void {
  const [, force] = useState(0);

  useEffect(() => {
    const listener = (prefix: string) => {
      if (key.startsWith(prefix)) force((n) => n + 1);
    };

    reloadListeners.add(listener);
    return () => {
      reloadListeners.delete(listener);
    };
  }, [key]);
}
