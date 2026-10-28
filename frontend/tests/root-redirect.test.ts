import { describe, expect, it, vi } from "vitest";

/**
 * ⭐ **إصلاح تحويل الجذر `/` إلى `/dashboard`.**
 *
 * ⚠️ قبل الإصلاح كان الجذر يحوّل إلى `/login` — ورقة الدخول دي
 * **محذوفة** في وضع المستخدم الواحد (`proxy.ts` لا يحوّل أحداً
 * و`AutoLoginAdmin` في الخلفية يسجّل الدخول). النتيجة: فتح
 * `http://localhost:3000/` يرجّع 404 بدل اللوحة.
 *
 * ⭐ `redirect()` من `next/navigation` يرمي استثناءً داخلياً
 * ليكمل Next التحويل — فنستبدله هنا برمي رسالة ثابتة، ونتحقق من
 * الوجهة نفسها بلا حاجة إلى Next runtime أو React renderer.
 */
vi.mock("next/navigation", () => ({
  redirect: (url: string): never => {
    throw new Error(`REDIRECT:${url}`);
  },
}));

import RootPage from "@/app/page";

describe("root route", () => {
  it("يحوّل الجذر إلى /dashboard لا إلى صفحة الدخول المحذوفة", () => {
    expect(() => RootPage()).toThrowError("REDIRECT:/dashboard");
  });
});
