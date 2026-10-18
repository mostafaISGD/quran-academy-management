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
 * ⭐⭐ `loading` مضافة عشان الـ**Skeleton**. من غيرها الـlayout كان
 * بيرسم **القائمة كاملة** أول رسم (`user = null`)، وبعد ثانية
 * تختفي لمعلم. ده الـflash اللي الخارطة بتتكلم عنه بالظبط.
 *
 * ⭐ `loading` بيبدأ `true` في الحالة الابتدائية نفسها، فمافيش
 * رسم إضافي وده اللي بيمرّي قاعدة `set-state-in-effect`.
 *
 * @example
 * ```tsx
 * const { user, loading } = useCurrentUser();
 * if (loading) return <SidebarSkeleton />;
 * if (user?.is_teacher) return <TeacherView />;
 * ```
 */
export function useCurrentUser(): {
  user: AuthUser | null;
  /** ⭐⭐ لسه بنجيب المستخدم — للـSkeleton */
  loading: boolean;
  /** إعادة الجلب — بعد تغيير الصلاحيات مثلاً */
  reload: () => void;
} {
  const fetcher = useCallback(() => fetchMe(), []);

  const { data, loading, reload } = usePage<AuthUser | null>("auth-me", fetcher, {
    initial: null,
  });

  return { user: data ?? null, loading, reload };
}