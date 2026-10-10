import { defineConfig } from "vitest/config";
import { fileURLToPath } from "node:url";

/**
 * ⭐ اختبارات الواجهة.
 *
 * فيها **اختبار حامي** لـ `format.test.ts` — بيمنع رجوع مشاكل
 * التنسيق اللي اتصلحت (شوف الملف ده).
 *
 * ⭐ ليه `node` مش `jsdom`؟
 *
 * لأن الاختبارات دي بتقرأ **ملفات من القرص** أو بتستدعي
 * **دوال منطق بحت** — مش بتركّب React في متصفح. فمفيش دوم
 * ولا محاكي متصفح لازم، والاختبار أسرع بكتير.
 *
 * ⭐ الـ alias لازم يتعرّف هنا؟
 *
 * Vitest بيقرا `tsconfig.json` للترجمة، بس **مش** بيطبّق
 * `paths` لوحده. فلو الاختبار استورد `@/lib/...` من غير
 * السطر ده، يفشل بـ:
 *
 *     Failed to load url @/lib/weekdays
 */
export default defineConfig({
  resolve: {
    alias: {
      "@": fileURLToPath(new URL(".", import.meta.url)),
    },
  },
  test: {
    environment: "node",
    include: ["tests/**/*.test.ts"],
  },
});
