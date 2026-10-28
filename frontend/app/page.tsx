import { redirect } from "next/navigation";

import { DEFAULT_HOME } from "@/lib/route-access";

/**
 * ⭐⭐⭐ **الجذر يوصل للوحة مباشرة — لا صفحة `/login`.**
 *
 * ⚠️ في وضع المستخدم الواحد أُلغيت صفحة الدخول (`AutoLoginAdmin`
 * في الخلفية يسجّل الأدمن، و`proxy.ts` لا يحوّل أحداً). فتحويل
 * الجذر إلى `/login` كان يعني **404 عند أول زيارة** للتطبيق.
 *
 * ⭐ الوجهة من `DEFAULT_HOME` — المصدر الواحد نفسه اللي تعتمده
 * قواعد الوصول (`lib/route-access.ts`).
 */
export default function RootPage() {
  redirect(DEFAULT_HOME);
}
