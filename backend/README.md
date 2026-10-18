# Quran Academy — Backend

Laravel 11 · PHP 8.2+ · PostgreSQL · Redis · Sanctum · spatie/laravel-permission

الواجهة (Next.js) في `../frontend`. الخارطة الكاملة في `../ROADMAP.md`،
وقواعد الشغل في `../AGENTS.md`.

---

## التشغيل

```bash
composer install
cp .env.example .env          # ⭐ APP_DEBUG=false افتراضياً — خلّيه كده في الإنتاج
php artisan key:generate

php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan migrate

# البيانات: كل الـseeders بتتنفّذ مرّة واحدة وبتتشغّل-seeders المحمية
# بـ RefusesToRunInProduction، فمش هتشتغل في الإنتاج.
php artisan db:seed

php artisan serve
```

### متطلبات بيئية لازم تتظبط

| المتغير | ليش |
|---|---|
| `APP_DEBUG=false` | `true` بيكشف كل استثناء: جداول، أعمدة، مسارات. |
| `CORS_ALLOWED_ORIGINS` | قائمة مفصولة بفاصلة. فاضية = كل الطلبات من المتصفح مرفوضة (وهو الاتجاه الآمن). |
| `SESSION_SECURE_COOKIES=true` | في الإنتاج بس. |
| `SANCTUM_TOKEN_EXPIRATION` | بالدقائق. `null` = التوكن ما بينتهيش. |
| `CORS_ALLOWED_ORIGINS` مش لوحده — كمان `SANCTUM_STATEFUL_DOMAINS` لو بتستخدم cookies (PR 1.1). |

---

## الاختبارات

```bash
php artisan test                          # الكل
php artisan test --filter=RoleIsolation   # مصفوفة الأدوار
```

القاعدة **SQLite في الذاكرة** من `.env.testing` — مفيش أي خطر على بيانات
التطوير، والـ57 migration بتتحمل مرة واحدة في العملية.

### ⭐ مصفوفة الأدوار — `RoleIsolationTest`

`tests/Feature/RoleIsolationTest.php` هو **حارس الأمان** بتاع Phase 0.
بيشتغل من اتنين:

- **`RouteProtectionTest`** بيقول: كل راو محمي إما عليه صلاحية أو باستثناء موثّق.
- **`RoleIsolationTest`** بيقول: **مين** بيوصل لكل راو دا.

المصفوفة **بتتولّد من جدول الراوتس نفسها** وقت التشغيل، مش مكتوبة بإيدنا:

```php
foreach (Route::getRoutes()->getRoutes() as $route) {
    // …
}
```

يعني راو جديد بيدخل المصفوفة **تلقائياً** — لا حد يقدر ينسى يحدّثها.
وملاحظة مهمة: المصفوفة ليها اختبارين بيحرسوها نفسها
(`test_the_matrix_actually_covers_the_routes` و
`test_a_route_added_at_runtime_joins_the_matrix`) — عشان لو رجّعت **فاضية**
الاختبار يعدّي من غير ما يفحص حاجة، وده أسوأ بكتير من إنه يفشل.

### إزاي تضيف دور جديد

1. ضيف الدور في **`SeedRolesPermissions::ROLES`** — وده **المصدر الوحيد**.
   متكتبش قائمة صلاحيات في أي seeder تاني؛ أي seeder تاني بيقرأ من هنا.

   ```php
   'supervisor' => [
       'students.view',
       'lessons.edit',
   ],
   ```

   `'admin' => '*'` معناها كل الصلاحيات.

2. ضيف اسمه في **`RESTRICTED_ROLES`** في `RoleIsolationTest` لو دور منخفض
   — عشان المصفوفة تفحصه. دور متوسط زي `accountant` ليه اختباراته
   المستقلة (إيه اللي يشوفه وإيه اللي لأ).

3. لو الدور بيتاشق من علاقة المستخدم (معلم، ولي أمر) — ضيفه في
   `grantDerivedRoles()`.

> ⚠️ **`syncPermissions` بيمسح اللي قبله.** لو دور معرّف في أكتر من مكان،
> اللي بيشتغل **آخر** بيفوز والنتايج بتبقى **ناقصة بصمت**.
> `test_no_role_is_defined_by_two_different_seeders` بيحرس ده.

### إزاي تضيف راو محمي

1. حطّ `permission:xxx` **جوه** مجموعة `auth:sanctum` في `routes/api.php` —
   مش `auth` و `permission` في array واحدة، ولا `permission` برّه من الجروب.
   (`RouteProtectionTest` بيقرا الـmiddleware **الفعلي** مش المكتوب.)

2. اسمّل الصلاحية في `SeedRolesPermissions::PERMISSIONS` لو لسه جديدة.
   `syncRolesFromCatalog()` **بيرمي استثناء** لو الدور فيه اسم صلاحية
   مش موجود في الكتالوج — عشان غلطة إملائية ما تبانش بس كـ«دور ناقص».

3. `RoleProtectionTest` بيكفي من غير تعديل. `RoleIsolationTest` كمان.

### ترتيب الـmiddleware — الفخ اللي بيقتل كل حاجة

Laravel **مش** بيشغّل الراوت بالترتيب المكتوب في `->middleware()`.
بيعمل sort بـ `$middlewarePriority`:

```
AuthenticatesRequests  →  CheckPermission  →  SubstituteBindings  →  Authorize
                           ↑ ضُيف في bootstrap/app.php
```

`CheckPermission` متحطّ **قبل** `SubstituteBindings` عن قصد في
`bootstrap/app.php`. من غير كده:

```
GET /api/students/1/payments     →  SELECT … WHERE id = 1  →  404 لو مش موجود
GET /api/students/999/payments   →  SELECT … WHERE id = 999 →  404
```

يعني حد مالوش `payments.view` يقدر يفحص الأرقام ويعرف أيهم موجود —
**تسريب وجود**. بعد التعديل كل طلب مرفوض بيرجّع **403 دايماً**، سواء الـid
موجود أو لأ. والمستخدم اللي **عنده** الصلاحية لسه بياخد 404 الصادق.

⚠️ لو استخدمت `$middleware->priority()` بدل `prependToPriorityList`،
هتحطّ `CheckPermission` فوق `AuthenticatesRequests`، فـ`$request->user()`
هيبقى `null` وكل مستخدم شرعي هياخد **403 غلط**.

---

## ملاحظات على البنية

- **الأموال بالـpiastres (أصغر وحدة).** `app/Casts/Piastres.php` مقفول على
  exponent 2 / EGP، وبيستخدم `round((float)…)` في `set()`. **PR 2.1 هو اللي
  هيمسحه** ويبدّله بـ value object. أي aggregate (`sum()`, `selectRaw`)
  بيتخطّى الـcast — لازم يروح من `fromDatabase()` أو بتخلط الوحدات.

- **السجلات المالية ما بتتعدّلش وما بتتحذفش.** التصحيح = سطر عكسي
  (`reversal_of_id`).

- **الدفع بيتقيّد** بـ `idempotency_key` فريدة. أي كتابة أكتر من جدول:
  `DB::transaction` + `lockForUpdate` على الصف المتنافس عليه.

- **أعمدة `currency` موجودة** على ~20 جدول مالي. الـmulti-currency حقيقي.

- **`Piastres` بيقصري في الـpresentation** — أي رقم مالي بيطلع للواجهة
  بيعدّي منود واحد. لو لقيت `float` في مسار مالي، ده مكان مراجعة.

---

## الأدوار والصلاحيات

الكتالوج في **`database/seeders/SeedRolesPermissions.php`**:

| الـconstant | إيه اللي فيها |
|---|---|
| `PERMISSIONS` | كل صلاحية في النظام + الـmodule بتاعها. |
| `ROLES` | كل دور وإيه صلاحياته. **مصدر واحد.** |

```bash
php artisan permissions:sync    # يطبّق PERMISSIONS على قاعدة قائمة
```

⚠️ `syncPermissions` بيمسح. تشغيله على دور في عليه تعديلات يدوية هيمسحها.

### الفصل المالي

`accountant` بيسجّل حركة، بس **مش** بيعكس ولا بيفتح فترة مقفولة. السبب:
لو قدر يعكس أي دفعة، الأرقام اللي الإدارة وقّعتها بقت قابلة للتغيير
بإيد حد في التحصيل. `finance.periods.lock` مسموحة (مين غيره هيقفل شهر
أول الشهر؟) والفتح ممنوع — الفصل في الاتجاه مش في العدم.

**تغيّر سلوكي في PR 0.7:** كان فيه **قائمتين مختلفتين** لدور
`accountant` — واحدة في `SeedRolesPermissions` وواحدة في `SeedEmployees`،
والاتنين `syncPermissions`، فاللي بيشتغل آخر بيفوز. النتيجة إن المحاسب
في البيانات التجريبية **كان فقد 8 صلاحيات مالية** ومقدرش يفتح صفحة
الحسابات. دلوقتي `ROLES` هو المصدر الوحيد.

> `ROLES['accountant']` ما فيهاش `payroll.manage` — يعني **اعتماد وصرف
> المرتبات بقى المدير بس**. ده restoring للتصميم المكتوب، مش اختيار
> جديد. لو عايز تغيّره، غيّره في `ROLES` واختبر.

**استبدال الأسماء** (`settings.manage` → `settings.update`، كتالوج
`module.resource.action`) هو **PR 1.6** — لسه بالسماء الحالية عشان
`routes/api.php` شغال بيها.

---

## المصادقة — session مش token

تسجيل الدخول بيشتغل **بوضعين في نفس الوقت**:

| العميل | إيه اللي بيرجع | الـCSRF |
|---|---|---|
| **الواجهة (SPA)** | كوكي `HttpOnly` — **مافيش `token` في الرد** | ✅ 419 من غيره |
| **موبايل / أي عميل تاني** | توكن شخصي | ❌ مش داخل في المسار |

التمييز مش `Accept` ولا `User-Agent` — هو **نفس** فحص Sanctum بالظبط
(`EnsureFrontendRequestsAreStateful::fromFrontend`، اللي بيفحص
`Referer`/`Origin` ضد `SANCTUM_STATEFUL_DOMAINS`). فمفيش حالة وسطى.

### ليه الـcookie مش الـtoken

`localStorage` في متناول **أي سكربت شغّال في الصفحة**. أي ثغرة XSS — في
أي حزمة، أو إعلان، أو كود مستقبلي — بتقرأ التوكن وتبعته لحد. الكوكي
`HttpOnly` المتصفح **مينعش** السكربتات من قراءته.

### إزاي تضيف راوت بسجل

- **PSA (session):** حطّه في مجموعة `auth:sanctum` زي العادي. Sanctum
  بيقرا الجلسة لو الطلب جاي من دومين الـSPA.
- **عميل توكن:** `/auth/token` بيرجّع توكن. **ما تستخدمش `localStorage`.**

### ⚠️ فخّان

① **`statefulApi()` لازم تكون مفعّلة** في `bootstrap/app.php`. من غيرها
   مجموعة `api` مافيش فيها `StartSession`، فالـcookie مش هتشتغل **خالص
   ومفيش أي رسالة خطأ**.

② **التوكن القديم لسه شغال** كـfallback (`getToken()` في `api.ts`).
   ده **مؤقت** — قاعدة «متخزّنش token جديد» في `AGENTS.md` مازال
   سارية على أي كود جديد. الشيل الكامل في PR 1.2، والـ`logout()`
   بيشيله على أي حال.

---

## الردود والأخطاء

`app/Support/ErrorResponder.php` هو الـhandler الموحّد:

- **4xx** بيحتفظ برسالته — دي غلط المستخدم وبيتحقق من شرط حقيقي.
- **5xx** بيتستبدل برسالة عربية عامة. التفاصيل (class، stack، path، IP)
  بتروح **اللوج بس**.
- `SAFE_EXCEPTIONS` = قائمة صريحة بالاستثناءات اللي مسموح يتشاف ردها.
  أي حاجة برّه منها = مخفية.

**ممنوع** يرجع `$e->getMessage()` للعميل. لو محتاج رسالة مستخدم،
ارمي استثناء 4xx أو 422 — والـhandler هيترجمها.

⚠️ الرد النهائي `{"message": …, "errors"?: …}`. `frontend/lib/api.ts` بيقرا
`message` ومعاه fallback على أول خطأ في `errors`.

---

## ملفات لازم تعرفها

```
app/
  Exceptions/            استثناءات ليها معنى (محاسبي / نطاق عمل)
  Http/Middleware/       CheckPermission · EnsureAccountActive ·
                         EnsurePasswordChanged · EnsureUserIsParent ·
                         SecurityHeaders
  Support/               ErrorResponder — الرد الموحّد للأخطاء
  Services/              منطق العمل. financial/accounts ماشي في
                         FinancialLedgerService
tests/
  BuildsFixtures.php     ⭐ fixtures مش factories — صغيرة ومقروءة
  TestCase.php           authHeaders() بتعمل forgetGuards()
  Feature/
    RouteProtectionTest  كل راو محمي = صلاحية أو استثناء موثّق
    RoleIsolationTest    ⭐ مين بيوصل لكل راو
    OwnershipTest        IDOR — ملكية الموارد
    PrivilegeEscalationTest  إنشاء/تعديل الأدوار
    PasswordPolicyTest   كلمات المرور + إعادة التعيين
    LoginHardeningTest   throttle + الحساب الموقوف
    ErrorHygieneTest     مافيش تسريب داخلي
```

### `BuildsFixtures` — fixtures مش factories

اختبارات **علاقات وعقود** مش أداء. الأرقام الظاهرة أوضح من أرقام متولّدة.
`makeUserWithRole('x', ['perm'])` بتعمل دور من الصفر بصلاحيات الاختبار —
استخدمها لما تختبر صلاحية **بعينها**. لكن لما تختبر **عزل دور**، استخدم
`RoleIsolationTest` اللي بيبني الدور من الـseeders الحقيقيين، عشان اللي
بيختبره يكون نفس اللي في الإنتاج.
