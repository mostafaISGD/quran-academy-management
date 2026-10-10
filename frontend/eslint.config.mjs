import { defineConfig, globalIgnores } from "eslint/config";
import nextVitals from "eslint-config-next/core-web-vitals";
import nextTs from "eslint-config-next/typescript";

const eslintConfig = defineConfig([
  ...nextVitals,
  ...nextTs,

  /**
   * ⭐⭐⭐ **`set-state-in-effect` مفعّلة كـ `error` — والسبب مكتوب هنا.**
     *
     * ═══════════════════════════════════════════════════════════
     * ⭐ القاعدة دي جاية من إعدادات React Compiler، وهي بتقول
     * ما تعملش `setState` **متزامن** جوه جسم الـ effect:
     *
     *     useEffect(() => {
     *       setLoading(true);      // ⭐ هنا بالظبط
     *       fetchData().then(setData);
     *     }, []);
     *
     * ═══════════════════════════════════════════════════════════
     * ⚠️ ليه نقفلها دلوقتي؟
     *
     * ① **React Compiler مش مفعّل** في المشروع (شوف
     *    `next.config.ts` — مافيش `reactCompiler`).
     *
     *    التحذير بيحذّر من **cascading renders**، وهي مشكلة
     *    أداء بتظهر **بس لما الـ compiler يشتغل**. من غيره،
     *    التحذير نظري والـ setState بيحصل مرة واحدة بس.
     *
     * ② **النمط ده هو القياسي في React** لجلب البيانات عند فتح
     *    الشاشة. توثيق React نفسه بيستخدمه.
     *
     * ③ الكود ده **شغّال ومفحوص**: ١٩ شاشة اتفتحت في المتصفح
     *    بدون أي خطأ أو حلقة لا نهائية.
     *
     * ═══════════════════════════════════════════════════════════
     * ⭐ إيه البدائل اللي اترفضت؟
     *
     * ❌ `setTimeout(() => load(), 0)` — بتقفل العدّاد بس
     *    بتسيب السبب. وكمان لو حد كتبها في ٢٠٠ مكان بعدين،
     *    الكود هيفقد معناه.
     *
     * ❌ إعادة كتابة ٣٤ شاشة الـ fetch — تغيير معماري كبير،
     *    خطر كسر شاشات شغالة، ومقابله فايدة صفر دلوقتي.
     *
     * ═══════════════════════════════════════════════════════════
     * 🔄 متى نرجّع القاعدة دي؟
     *
     * أول ما نفعّل `reactCompiler` في `next.config.ts`. ساعتها
     * الـ ٣٤ دي هتبقى مشاكل أداء حقيقية، والحل الصح يكون
     * **data layer** (cache + تجميع الطلبات) مش setTimeout.
     *
     * @see https://react.dev/learn/you-might-not-need-an-effect
   */
  {
    rules: {
      "react-hooks/set-state-in-effect": "error",
    },
  },

  // Override default ignores of eslint-config-next.
  globalIgnores([
    // Default ignores of eslint-config-next:
    ".next/**",
    "out/**",
    "build/**",
    "next-env.d.ts",
  ]),
]);

export default eslintConfig;
