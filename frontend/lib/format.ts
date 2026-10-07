/**
 * ⭐ تنسيق الأرقام والتواريخ والأوقات — في مكان واحد.
 *
 * ليه ده في ملف؟ لأن كل صفحة كانت بتعمل `toLocaleString("ar-EG")`
 * لوحدها، فطلعت **٣٠ شكل مختلف** في برنامج واحد:
 *
 *   - سطر طويل غامض: `toLocaleString` من غير خيارات بيضيف
 *     **الثواني**: `7/10/2026، 7:05:00 م`
 *   - تواريخ بأشكال مختلفة: `2026-10-07` / `10/7/2026` / `٧ أكتوبر`
 *
 * فكل تاريخ ورقم في التطبيق يعدّي من هنا. لو تغيّر شكل غلط، ده
 * سطر واحد في الملف ده مش ٣٠ سطر في ١٧ ملف.
 *
 * ─────────────────────────────────────────────────────────────
 * ⭐ ليه `ar-EG-u-nu-latn` بالذات؟
 *
 * ⚠️ **`ar-EG` لوحدها مش كافية** — ودي نقطة اتأكدت منها في
 * المتصفح نفسه:
 *
 *   `ar-EG`           → ٧ أكتوبر ٢٠٢٦   (عربية)
 *   `ar-EG-u-nu-arab` → ٧ أكتوبر ٢٠٢٦   (عربية)
 *   `ar-EG-u-nu-latn` → 7 أكتوبر 2026   (لاتينية)  ✅
 *
 * `-u-nu-latn` بيقول للـ Intl صريح: «عايز الأرقام اللاتينية».
 * من غيرها النتيجة بتختلف من متصفح لمتصفح ومن بيئة لليومدة.
 *
 * التطبيق كله عربي (الشهر والأيام عربية)، بس الأرقام لATIN
 * لأنها اللي بتتقرا أسرع في الجداول المالية — وعندنا أرقام
 * بتعرض كل يوم.
 */

/** أرقام لاتينية + شهر وأيام عربية */
const LOCALE = "ar-EG-u-nu-latn";

/**
 * ⭐ خيارات الوقت — **مشتركة** ومكتوبة صراحةً.
 *
 * `hour12: true` ضروري: لو اتشال، النتيجة بتختلف بين إصدارات
 * ICU — ساعة هنا و24 ساعة هناك.
 *
 * `hour: "numeric"` مش `"2-digit"`: عشان `7:05 م` بدل
 * `07:05 م`. الصفر البادي مش بيقول هنا حاجة.
 */
const TIME_OPTS: Intl.DateTimeFormatOptions = {
  hour: "numeric",
  minute: "2-digit",
  hour12: true,
};

/** رقم بفاصلة آلاف: `1,200` */
export function num(value: number | string | null | undefined, maxFrac = 2): string {
  const n = toNumber(value);
  if (n === null) return "—";
  return n.toLocaleString(LOCALE, { maximumFractionDigits: maxFrac });
}

/**
 * ⭐ المسافة اللي **ما بتكسرش السطر** (`U+00A0`).
 *
 * من غيرها، المتصفح بيعتبر المسافة بين الرقم و«ج.م» فرصة كسر،
 * فالكارت الصغير بيقسّم الرقم عن العملة:
 *
 *     59,932        ← سطر
 *     ج.م           ← سطر تاني
 *
 * ونفس الكارت في كارت تاني بيطلع `59,932 ج.م` في سطر واحد —
 * حسب عرض المكان. الحيلة دي بتخلي الشكل **واحد** في كل مكان.
 *
 * ⚠️ الـ NBSP بتفضل مسافة عادية في الـ bidi — يعني الرقم لسه
 * على يمين «ج.م» في النص العربي زي ما هو.
 */
const NBSP = "\u00A0";

/**
 * ⭐ أسماء العملات بالعربي.
 *
 * البيانات كلها `EGP` حالياً، بس `database/data/countries.php`
 * بيعرف عملات تانية — فلازم ما نعرضش كود زي `SAR` للمستخدم
 * العربي لو وصلنا عملة مش معروفة.
 */
const CURRENCY_LABEL: Record<string, string> = {
  EGP: "ج.م",
  SAR: "ر.س",
  AED: "د.إ",
  KWD: "د.ك",
  QAR: "ر.ق",
  BHD: "د.ب",
  OMR: "ر.ع",
  JOD: "د.أ",
  MAD: "د.م",
  TND: "د.ت",
  DZD: "د.ج",
  SDG: "ج.س",
  LYD: "د.ل",
  IQD: "د.ع",
  USD: "$",
  EUR: "€",
};

/** ⭐ اسم العملة بالعربي، ويرجع الكود نفسه لو مش معروف */
export function currencyLabel(currency: string | null | undefined): string {
  const code = (currency || "EGP").trim().toUpperCase();
  return CURRENCY_LABEL[code] ?? code;
}

/**
 * جنيه مصري: `400 ج.م` أو `1,234.5 ج.م`
 *
 * ⭐ من غير `minimumFractionDigits` — يعني `400` بتبقى `400`
 * مش `400.00`. ده اللي المفروض يبان في الفواتير.
 */
export function egp(value: number | string | null | undefined, maxFrac = 2): string {
  const n = toNumber(value);
  if (n === null) return "—";
  return `${n.toLocaleString(LOCALE, { maximumFractionDigits: maxFrac })}${NBSP}ج.م`;
}

/**
 * ⭐ مبلغ **بأي عملة**: `money(400, "EGP")` → `400 ج.م`
 *
 * ⭐ دي الدالة اللي كل صفحات الجداول لازم تستخدمها بدل
 * `` `${x.price} ${x.currency}` `` — للأسباب دي:
 *
 *   1. الشكل القديم كان بيطبع `EGP` إنجليزي للمستخدم العربي.
 *   2. الرقم الخام كان بيطلع `4000` من غير فاصلة آلاف،
 *      بينما نفس الرقم في صفحة تانية بيطلع `4,000`.
 *   3. لو الحقل فاضي، الشكل القديم كان بيطبع `null EGP`.
 *
 *قبل كده كان في ٣ ملفات بتكرّر نفس الشرط
 *(`currency === "EGP" ? egp(n) : ...`) — دلوقتي سطر واحد.
 */
export function money(
  value: number | string | null | undefined,
  currency: string | null | undefined = "EGP",
  maxFrac = 2,
): string {
  const n = toNumber(value);
  if (n === null) return "—";
  return `${n.toLocaleString(LOCALE, { maximumFractionDigits: maxFrac })}${NBSP}${currencyLabel(currency)}`;
}

/** تاريخ بس: `7 أكتوبر 2026` */
export function date(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";
  return d.toLocaleDateString(LOCALE, {
    day: "numeric",
    month: "short",
    year: "numeric",
  });
}

/**
 * تاريخ بس **من غير السنة**: `7 أكتوبر`
 *
 * للمكان اللي محتاج تاريخ مختصر — لو الحصة يوم ٧ في نفس
 * السنة، الـ«2026» بتzierعشو وتضيّع المساحة.
 */
export function dateNoYear(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";
  return d.toLocaleDateString(LOCALE, { day: "numeric", month: "short" });
}

/**
 * شهر وسنة بس: `أكتوبر 2026`
 *
 * ⭐ لفلاتر الشهور («آخر ٦ شهور») والفلاتر اللي بتعرض شهر.
 * من غير يوم — لأن الفلتر بيختار شهر، مش يوم.
 */
export function monthYear(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";
  return d.toLocaleDateString(LOCALE, { month: "long", year: "numeric" });
}

/**
 * ⭐⭐ اسم فترة الرواتب: `رواتب أكتوبر 2026`
 *
 * ⚠️ **ليه الدالة دي موجودة أصلاً؟**
 *
 * لأن الـ seeder كان بيخزّن الاسم جاهز:
 *
 *     'name' => 'رواتب ' . $start->format('F Y')
 *
 * و`Carbon::format('F')` بيشتغل **بالإنجليزي** — فالداتابيز
 * اتخزّنت فيها `رواتب October 2026`، والمستخدم العربي شايف
 * اسم شهر إنجليزي في زرار الفترة.
 *
 * ⚠️ ليه هنصلّحها في الواجهة مش في السيرفر؟
 *
 * لأن الاسم ده **نص** مش تاريخ. فلو صلّحناه في الـ seeder،
 * أي فترة تنشأ بعد كده من الواجهة هترجع إنجليزي تاني — لأن
 * الأدمن بيكتب الاسم بإيده.
 *
 * فالحل الصح إننا **نتجاهل النص المخزّن** ونبني الاسم من
 * `start_date` — اللي تاريخ حقيقي، متاح في كل رد، ومش قابل
 * للخطأ.
 */
export function payrollPeriodName(
  startDate: string | Date | null | undefined,
  prefix = "رواتب",
): string {
  const d = toDate(startDate);
  if (!d) return "—";
  return `${prefix} ${monthYear(d)}`;
}

/**
 * شهر بس: `أكتوبر`
 *
 * ⭐ للشبكة اللي بتورّي الشهر في أول عمود (تقويم الـ اشتراك).
 * التسمية صغيرة، فالسنة بتتزحم عليها.
 */
export function monthName(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";
  return d.toLocaleDateString(LOCALE, { month: "short" });
}

/**
 * تاريخ طويل: `7 أكتوبر 2026`
 *
 * ⚠️ في `ar-EG` كلمة «long» و«short» بتطلع **نفس الشكل**.
 * الفرق بيظهر مع لغات تانية (الإنجليزية: `Oct 7` vs `October 7`).
 * فالناتج واحد في الحالتين — وده مسجّل هنا عشان ما نسألش تاني.
 */
export function dateLong(value: string | Date | null | undefined): string {
  return date(value);
}

/** يوم في الأسبوع: `الأربعاء` */
export function weekday(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";
  return d.toLocaleDateString(LOCALE, { weekday: "long" });
}

/** وقت بس: `7:05 م` */
export function time(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";
  return d.toLocaleTimeString(LOCALE, TIME_OPTS);
}

/**
 * ⭐ تاريخ ووقت **في سطر واحد**: `7 أكتوبر، الأربعاء - 7:05 م`
 *
 * ⚠️ ليه مش `toLocaleString` بخيارات؟
 *
 * لأن `toLocaleString` — حتى بخيارات — بيرتّب الحقول بترتيب الـ
 * locale مش بترتيب عربي مقروء. الطريقة المضمونة إننا نبنيه **إحنا**
 * من ثلاث قطع: اليوم، اليوم، الوقت.
 *
 * ⭐ مافيش سنة هنا — لأنها بتزحم. لو محتاجها، استخدم `dateSmart()`.
 */
export function dateTime(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";

  const dayPart = d.toLocaleDateString(LOCALE, { day: "numeric", month: "short" });
  const weekdayPart = d.toLocaleDateString(LOCALE, { weekday: "long" });
  const timePart = d.toLocaleTimeString(LOCALE, TIME_OPTS);

  return `${dayPart}، ${weekdayPart} - ${timePart}`;
}

/**
 * ⭐ تاريخ ووقت + **السنة لو مش السنة الحالية**.
 *
 * السبب: في سجل العمليات أو إشعار قديم، «7 أكتوبر» من غير سنة
 * = مش واضح إمتى. أما حاجة من النهاردة، السنة بتzierعشو والوقت
 * أهم.
 *
 *   حدث النهاردة → `7 أكتوبر، الأربعاء - 7:05 م`
 *   حدث سنة فاتتة → `7 أكتوبر 2025، الثلاثاء - 7:05 م`
 */
export function dateSmart(value: string | Date | null | undefined): string {
  const d = toDate(value);
  if (!d) return "—";

  const sameYear = d.getFullYear() === new Date().getFullYear();

  const dayPart = sameYear
    ? d.toLocaleDateString(LOCALE, { day: "numeric", month: "short" })
    : d.toLocaleDateString(LOCALE, { day: "numeric", month: "short", year: "numeric" });

  const weekdayPart = d.toLocaleDateString(LOCALE, { weekday: "long" });
  const timePart = d.toLocaleTimeString(LOCALE, TIME_OPTS);

  return `${dayPart}، ${weekdayPart} - ${timePart}`;
}

/**
 * ⭐ وقت بداية ونهاية: `7:05 م - 8:00 م`
 *
 * للمكان اللي بيعرض **مدى** (ميعاد مجموعة، وردية في الجدول).
 */
export function clock(start: string | Date | null | undefined, end?: string | Date | null): string {
  const from = time(start);
  if (from === "—") return "—";

  const to = end ? time(end) : "—";
  return to === "—" ? from : `${from} - ${to}`;
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

  return new Date(2000, 0, 1, hours, Number(m[2])).toLocaleTimeString(LOCALE, TIME_OPTS);
}

/**
 * ⭐ سطر الميعاد: «الخميس 7:05 م - 8:00 م».
 *
 * ⭐ **التركيب في الواجهة مش في السيرفر** — عن قصد.
 *
 * السبب: السيرفر كنا بنعمله بـ Carbon و`->locale('ar')`، وطلع
 * «6:00 PM» — إنجليزي. لأن Carbon محتاج بيانات اللغة متحمّلة،
 * وده بيختلف من بيئة لأخرى.
 *
 * التوقيت **عرض** — فلو السيرفر رجّع رقم غلط، الأرقام في الشاشة
 * هتبقى غلط برضه.
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
  const range = from ? (to && to !== "—" ? `${from} - ${to}` : from) : null;

  return [day, range].filter(Boolean).join(" ");
}

// ============================================================
// المساعدين — عشان كل دالة فوق تتصرّف زي واحدة
// ============================================================

/**
 * ⭐⭐ الأرقام العربية → لاتينية.
 *
 * للأدمن اللي بيكتب على **لوحة عربية**: لو كتب `٤٠٠` في خانة
 * السعر، `parseFloat` مش هيعرفها (بترجّع `NaN`) والرقم هيروح
 * للسيرفر فاضي.
 *
 * ⭐ ده **مدخل** مش **عرض** — العرض دايماً لاتيني زي ما
 * `LOCALE` بيقول. الفاوت هنا إن **الإدخال** يتسامح.
 *
 * بنغطي ٠-٩ (العربية) و ۰-۹ (الفارسية)، وكمان الفاصلة
 * العربية `٫` عشان لوحة keyboards تطلعها كده أحياناً.
 */
const DIGIT_MAP: Record<string, string> = {
  "٠": "0", "١": "1", "٢": "2", "٣": "3", "٤": "4",
  "٥": "5", "٦": "6", "٧": "7", "٨": "8", "٩": "9",
  "۰": "0", "۱": "1", "۲": "2", "۳": "3", "۴": "4",
  "۵": "5", "۶": "6", "۷": "7", "۸": "8", "۹": "9",
  "٫": ".", // الفاصلة العشرية العربية
  "٬": ",", // فاصلة الآلاف العربية
};

function latinizeDigits(text: string): string {
  return text.replace(/[٠-٩۰-۹٫٬]/g, (d) => DIGIT_MAP[d] ?? d);
}

/**
 * ⭐ رقم من نص المستخدم — بيتعامل مع كل الأرقام العربية.
 *
 * ⚠️ ليه مش `latinizeDigits` لوحدها؟
 *
 * لأن `parseFloat("1,200")` بترجّع `1` — **مش** 1200. الـ
 * `parseFloat` بتقف عند أول فاصلة. فمهما حوّلنا الأرقام
 * العربية، لو المستخدم كتب فاصلة آلاف هتبوظ الرقم.
 *
 * ⭐ فبنشيل الفواصل (عادية `,` وعربية `٬`) قبل التحويل:
 *
 *     "١٬٢٠٠"  →  "1200"   →  1200  ✅
 *     "٤٠٠"    →  "400"    →  400   ✅
 *     "1,234"  →  "1234"   →  1234  ✅
 *
 * بعد `toNumber` بتعمل `format` تاني — فالفاصلة هترجع
 * بالشكل الصح.
 */
function userNumber(text: string): number | null {
  const cleaned = latinizeDigits(text).replace(/[,٬\s]/g, "");
  if (cleaned === "") return null;

  const n = parseFloat(cleaned);
  return Number.isFinite(n) ? n : null;
}

/**
 * ⭐ تحويل لرقم صالح واحد — بتقرأ `null` و`undefined` و`""`.
 *
 * ⚠️ ليه مش `parseFloat` لوحدها؟
 *
 * `parseFloat("abc")` بترجّع `NaN` — تمام. لكن `Number("")`
 * بترجّع `0` مش `NaN`! فلو دالة اتنادىت بنص فاضي (الحقل اتسيب
 * فاضي)، كانت هتعرض `0` بدل «—». و`0` معناها «فعلاً صفر» —
 * فبتكسر الأرقام بشكل صامت.
 *
 * ⭐ وبتتقبل الأرقام العربية كمان — شوف `latinizeDigits`.
 *
 * ⭐ وبترجّع `null` مش `NaN` — عشان `null` معناها «مفيش رقم»،
 * و`NaN` كرقم بيلبس أي حساب بعدين.
 */
export function toNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === "") return null;

  const n = typeof value === "string" ? userNumber(value) : value;

  return typeof n === "number" && Number.isFinite(n) ? n : null;
}

/** ⭐ تحويل لـ `Date` صالح — بترجّع `null` بدل `Invalid Date` */
function toDate(value: string | Date | null | undefined): Date | null {
  if (value === null || value === undefined || value === "") return null;

  const d = value instanceof Date ? value : new Date(value);
  return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * ⭐ نص نضيف للعرض — بيرجّع «—» بدل `null` أو `undefined`.
 *
 * ⚠️ ليه مش `value ?? "—"`؟
 *
 * لأن المشكلة الحقيقية مش `null` — هي إن الـ API بيرجّع
 * **نص فيه كلمة `null` حروفها**. يعني الحقل اتخزّن فاضي في
 * الداتابيز وطلع للمستخدم كـ:
 *
 *     Lead null
 *
 * و`??` مش بيلمسها لأنها string مش null. فبدنا نمسح الـ
 * النص اللي هو حرفياً «null» كمان.
 *
 * ⭐ وكمان بتشيل المسافات — الاسم اللي فيه مسافات بس بيبان
 * فاضي قدام المستخدم.
 */
export function text(value: string | number | null | undefined, fallback = "—"): string {
  if (value === null || value === undefined) return fallback;

  const trimmed = String(value).trim();

  if (/^(null|undefined|NaN)$/i.test(trimmed)) return fallback;

  return trimmed === "" ? fallback : trimmed;
}
