"use client";

import { useCallback } from "react";
import { usePage } from "@/lib/use-page";
import { fetchMe, type AuthUser } from "@/lib/api";

/**
 * ⭐⭐⭐ **المستخدم الحالي — طلب واحد لكل التطبيق.**
 *
 * ⭐ المشكلة: `fetchMe()` كان بيتنادى من **5 أماكن** كل واحد لوحده
 * (layout مرتين، groups، my-children، my-schedule، pricing).
 * فأي صفحة منهم كانت بتطلبه **مرتين** في وضع التطوير.
 *
 * ⭐ الحل: كل الـ components بتستعمل **نفس الـ key** (`auth-me`)،
 * و`usePage` بيشارك الطلب عبر خريطة `inFlight` — فكلهم بيستنّوا
 * **طلب واحد** ويأخذوا نفس النتيجة.
 *
 * ⭐ ده معناه إن الـ layout والصفحة مبتبقاش تطلب مرتين **من غير
 * ما ننسّق بينهم** — البنية نفسها بتمنع التكرار.
 *
 * ⭐ ليه `null` مش error؟ فلو الـ fetch فشل المستخدم بيبقى ضيف
 * (لا sidebar ولا جرس) — الخطأ ما يظهرش على الشاشة.
 *
 * @example
 * ```tsx
 * const { user } = useCurrentUser();
 * if (user?.is_teacher) return <TeacherView />;
 * ```
 */
export function useCurrentUser(): {
  user: AuthUser | null;
  /** إعادة الجلب — بعد تغيير الصلاحيات مثلاً */
  reload: () => void;
} {
  const fetcher = useCallback(() => fetchMe(), []);

  const { data, reload } = usePage<AuthUser | null>("auth-me", fetcher, {
    initial: null,
  });

  return { user: data ?? null, reload };
}