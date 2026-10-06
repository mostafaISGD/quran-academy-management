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

/**
 * ⭐ وقت من نص `HH:MM` — زي ميعاد المجموعة.
 *
 * ⚠️ ليه مش `time()` العادي؟
 *
 * `new Date("18:00")` بيرجّع `Invalid Date` في معظم المتصفحات
 * (ساعة من غير تاريخ مش مقبولة). فالوقت بيبقى «—» والميعاد
 * بيختفي من الشاشة.
 *
 * ⭐ وبنطبّع `PM`/`AM` قبل التحويل، لأن `18:00` و`6:00 PM` نفس
 * الوقت بأرقام مختلفة — واللي جاي من السيرفر ممكن يكون بأي شكل.
 */
export function timeOnly(hhmm: string | null | undefined): string {
  if (!hhmm) return "—";

  // «6:00 PM» → «18:00»
  const m = /^(\d{1,2}):(\d{2})\s*(AM|PM)?$/i.exec(hhmm.trim());
  if (!m) return time(hhmm);

  let hours = Number(m[1]);
  const meridiem = m[3]?.toUpperCase();

  if (meridiem === "PM" && hours < 12) hours += 12;
  if (meridiem === "AM" && hours === 12) hours = 0;

  const d = new Date(2000, 0, 1, hours, Number(m[2]));
  if (Number.isNaN(d.getTime())) return "—";

  return d.toLocaleTimeString(LOCALE, { hour: "2-digit", minute: "2-digit" });
}

/**
 * ⭐ سطر الميعاد: «الخميس ٧:٠٠ م — ٨:٠٠ م».
 *
 * ⭐ **التركيب في الواجهة مش في السيرفر** — عن قصد.
 *
 * السبب: السيرفر كنا بنعمله بـ Carbon و`->locale('ar')`، وطلع
 * «6:00 PM» — إنجليزي. لأن Carbon محتاج بيانات اللغة متحمّلة،
 * وده بيختلف من بيئة لأخرى.
 *
 * التوقيت **عرض** — فلو السيرفر رجّع رقم غلط، الأرقام في الشاشة
 * هتبقى غلط برضه. كل الـ formats في مكان واحد عشان لو غيرنا
 * مرة واحدة.
 */
export function scheduleLine(
  weekday: string | null,
  start: string | null,
  end: string | null,
): string | null {
  const day = weekday?.trim();
  const from = start ? timeOnly(start) : null;

  if (!day && !from) return null;

  const to = end ? timeOnly(end) : null;
  const range = from ? (to && to !== "—" ? `${from} — ${to}` : from) : null;

  return [day, range].filter(Boolean).join(" ");
}

/** يوم في الأسبوع: الست */
export function weekday(value: string | Date | null | undefined): string {
  if (!value) return "—";
  const d = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(d.getTime())) return "—";
  return d.toLocaleDateString(LOCALE, { weekday: "long" });
}