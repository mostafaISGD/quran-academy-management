"use client";

import { useCallback } from "react";
import { usePage } from "@/lib/use-page";
import { getGroupAlerts, type GroupAlert } from "@/lib/api";

/**
 * ⭐⭐⭐ **تنبيهات المجموعات — طلب واحد لكل التطبيق.**
 *
 * ⭐ المشكلة: `/groups/alerts` كان بيتنادى من مكانين:
 * الـ layout (عشان رقم الجرس) والصفحة (عشان القائمة).
 * والاستجابة بترجع الاتنين مع بعض: { count, groups }.
 *
 * ⭐⚠️ ليه ما نعملش `usePage` مرتين بنفس الـ key؟
 *
 * لأن `usePage` بيشارك النتيجة مش الطلب بس. فلو الـ layout
 * سجّل `number` والصفحة سجّلت `GroupAlert[]` بنفس الـ key،
 * أي واحد هياخد النوع الغلط — و`tsc` مش هيلقط لأن كل واحد
 * مسجّل بنوعه.
 *
 * ⭐ الحل: hook واحد بيرجّع الاثنين والـ key واحد. فبيطلب مرة
 * واحدة والاثنين بياخدوا نصيحهم صح.
 *
 * ⭐ scope: جزء من الـ key. الـ layout بيمرّ groups لمّا يكون
 * في صفحة المجموعات، فالتنبيه بيتحدّث أول ما تفتحها — مش بس
 * لمّا تفتح التطبيق.
 *
 * ⭐ enabled: groups.manage مش لكل الموظفين. من غير البوابة
 * دي كل موظف استقبال كان هياخد 403 على كل فتح صفحة.
 *
 * @see usePage
 */
export function useGroupAlerts(enabled: boolean, scope: string = "main"): {
  count: number;
  groups: GroupAlert[];
  reload: () => void;
} {
  const fetcher = useCallback(async () => {
    const r = await getGroupAlerts();

    const groups = Array.isArray(r?.groups) ? r.groups : [];

    return {
      count: typeof r?.count === "number" ? r.count : groups.length,
      groups,
    };
  }, []);

  const { data, reload } = usePage<{ count: number; groups: GroupAlert[] }>(
    `group-alerts:${scope}`,
    fetcher,
    { initial: { count: 0, groups: [] }, enabled },
  );

  return { count: data.count, groups: data.groups, reload };
}