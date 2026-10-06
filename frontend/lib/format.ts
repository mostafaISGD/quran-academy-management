/**
 * ⭐ تنسيق الأرقام والتواريخ بالعربي — في مكان واحد.
 *
 * ليه ده في ملف؟ لأن `toLocaleString("ar-EG")` من غير خيارات
 * بيطلع **أرقام إنجليزية** (Gregorian) — تقدر تشوف في كل
 * صفحة تاريخ بيطلع `05/10/2026`. الطريقة الصح:
 *
 *   - `'ar-EG'` + خيارات → أرقام إنجليزية مع شهر عربي
 *   - `'ar-EG-u-nu-arab'` → أرقام عربية (٠١٢٣) والشهر العربي
 *
 * التطبيق كله عربي، فالأرقام العربية هي الصح. وكل التواريخ
 * والأرقام بتعدّي من هنا عشان ما ننسى.
 */

/**
 * ⭐ الأرقام العربية الـtrue: `ar-EG-u-nu-arab`.
 *
 * `ar-EG` لوحدها بتطلع **أرقام إنجليزية** (٠١٢٣ مش 123). الفرق
 * في الـ Unicode extension `-u-nu-arab` اللي بيقول للـ Intl:
 * «عايز أرقام عربية». التطبيق كله عربي، فده الصح.
 *
 * لو المستخدم يفضل الأرقام اللاتينية (لأن الأرقام بتبان أسرع
 * في الجداول المالية)، ده تغيير في سطر واحد هنا مش في كل ملف.
 */
const LOCALE = "ar-EG-u-nu-arab";

/** رقم بفاصلة آلاف وأرقام عربية */
export function num(value: number | string | null | undefined, maxFrac = 2): string {
  const n = typeof value === "string" ? parseFloat(value) : value;
  if (n === null || n === undefined || Number.isNaN(n)) return "—";
  return n.toLocaleString(LOCALE, { maximumFractionDigits: maxFrac });
}

/** جنيه مصري */
export function egp(value: number | string | null | undefined, maxFrac = 2): string {
  const n = typeof value === "string" ? parseFloat(value) : value;
  if (n === null || n === undefined || Number.isNaN(n)) return "—";
  return `${n.toLocaleString(LOCALE, { maximumFractionDigits: maxFrac })} ج.م`;
}

/** تاريخ قصير: ٥ أكتوبر ٢٠٢٦ */
export function date(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString(LOCALE, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

/** تاريخ طويل: ٥ أكتوبر ٢٠٢٦ */
export function dateLong(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString(LOCALE, {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

/** تاريخ ووقت: ٥ أكتوبر ٢٠٢٦، ٤:٣٠ م */
export function dateTime(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleString(LOCALE, {
    day: "numeric",
    month: "short",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

/** وقت بس: ٤:٣٠ م */
export function time(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleTimeString(LOCALE, { hour: "2-digit", minute: "2-digit" });
}

/** يوم في الأسبوع: الست */
export function weekday(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString(LOCALE, { weekday: "long" });
}