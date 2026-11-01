<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * الأدوار والصلاحيات.
 *
 * ليش seeder لوحده؟ `RealisticDataSeeder` بيعمل wipe وبيحط بيانات
 * واقعية، بس لو شغّلته بعد `migrate:fresh` بيبقى مفيش ولا صلاحية —
 * يعني كل الـ endpoint اللي عليه `permission:` بيرجّع 403 والتطبيق
 * مش بيفتح أصلاً.
 *
 * Permissions دي taken من `routes/api.php` — أي صلاحية مستخدمة هناك
 * لازم تكون موجودة هنا.
 */
class SeedRolesPermissions extends Seeder
{
    /**
     * ⭐⭐⭐ **المصدر الرسمي الوحيد لتعريف الأدوار** — مين عنده إيه.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⚠️⚠️ ليه الـconstant ده موجود أصلاً
     *
     * كان فيه **قائمتين مختلفتين لدور `accountant`** في ملفين:
     *
     *   • `SeedRolesPermissions::grantAccountantRole()`
     *     → `finance.*`, `expenses.view`, `payroll.view`, `payments.*`
     *
     *   • `SeedEmployees::$rolePermissions['accountant']`
     *     → `attendance.view`, `payroll.manage`, `reports.view`, `payments.*`
     *
     * والاتنين بيستخدموا **`syncPermissions`** — فاللي بيشتغل **آخر**
     * هو اللي بيفوز. و`RealisticDataSeeder` بيشغّل `SeedEmployees`
     * **بعد** `SeedRolesPermissions`، فالنتيجة النهائية إن المحاسب:
     *
     *     ❌ فقد ٨ صلاحيات مالية:
     *        finance.view · finance.accounts · finance.movements
     *        finance.exchange_rates · finance.periods.lock
     *        expenses.view · payroll.view · teachers.view
     *
     * يعني **مش بيقدر يفتح صفحة الحسابات أصلاً** — في البيانات اللي
     * بتتسلم للعميل. والاختبار اللي كشف ده:
     * `@see \Tests\Feature\RoleIsolationTest::test_no_role_is_defined_by_two_different_seeders`
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ القاعدة
     *
     * **كل دور معرّف هنا وبس.** أي seeder تاني بيحتاج دور —
     * بيقرأ من هنا، وبيحطّه على حد. مش بيكتب قائمته.
     *
     * ⭐ `'admin' => '*'` معناها كل الصلاحيات. مكتوبة كده مش
     * Baef تختصر، عشان لو صلاحية جديدة اتضافت ما تبقاش ناقصة
     * على المدير.
     *
     * `settings.manage` إلى `settings.update`، وتوسيع
     * اسم كل صلاحية إلى `module.resource.action` هو PR 1.6
     * اقرأه قبل ما تلمس الأسماء. الملف ده لسه بالأسماء الحالية
     * عشان `routes/api.php` شغال بيها، وما فيش استثناء.
     *
     * @see ROADMAP.md PR 1.6
     *
     * @var array<string, string[]|'*'>
     */
    public const ROLES = [
        // ⭐ المدير = كل حاجة. `*` مش قائمة مكتوبة عشان ما
        // تفوتش صلاحية جديدة.
        'admin' => '*',

        /**
         * ⭐⭐ المحاسب — **ده اللي بيمثّل الفصل في الخطة.**
         *
         * الخطة بتقول الصلاحيات منفصلة: «تسجيل دفعة» ≠ «عكس سجل» ≠
         * «اعتماد كشف مرتبات أو فتح فترة مقفلة». وده عشان لو المحاسب
         * قدر يعكس أي دفعة، الأرقام اللي الإدارة وقّعتها تبقى قابلة
         * للتغيير بإيد حد في التحصيل — وده بالظبط اللي الأرقام
         * المالية كلها مبنية عشانه تمنعه.
         *
         * فالمحاسب بياخد:
         *   ✅ `finance.view`          يشوف الأرصدة والحركات
         *   ✅ `finance.accounts`      ينشئ خزينة
         *   ✅ `finance.movements`     يسجّل تحصيل ومصروف ويحوّل بين الخزائن
         *   ✅ `finance.exchange_rates` يسجّل سعر الصرف
         *   ✅ `finance.periods.lock`  **مسموحة عن قصد** (شوف الملاحظة)
         *   ❌ `finance.reverse`       ممنوع — محتاج مدير
         *   ❌ `finance.periods.unlock` ممنوع — قرار الإدارة
         *
         * ⭐ **`finance.periods.lock` مسموحة والفتح ممنوع — ليه؟**
         *
         * القفل بيحمي الدفاتر (بيمنع أي كتابة جديدة في شهر خلص)،
         * والفتح بيلغي الحماية. فلو القفل ممنوع على المحاسب كمان،
         * يبقى لازم المدير يكون متاح عشان يقفل شهر — وده غير منطقي:
         * مين بيقفل شهر أول الشهر؟ المحاسب نفسه.
         *
         * الفصل بيبقى في **الاتجاه**: اللي بيقفل مش اللي بيفتح.
         *
         * ⚠️ **تغيّر سلوكي (PR 0.7):** القائمة اللي كانت في
         * `SeedEmployees` كانت بتدي المحاسب `payroll.manage` و
         * `attendance.view` و `reports.view` وبتاخد **`finance.*`**.
         * دلوقتي `finance.*` هي اللي متبقية، والـ3 التانية راحت.
         * يعني **المرتبات** (`payroll.manage`) بقت **المدير بس**.
         * ده restoring للتصميم المكتوب فوق، مش اختيار جديد.
         *
         * @see \App\Models\AccountMovement::reversalOf
         */
        'accountant' => [
            // ============ الحسابات
            'finance.accounts.view',
            'finance.accounts.manage',
            'finance.movements.create',
            'finance.exchange_rates.manage',
            'finance.periods.lock',

            // ⭐ PR 2.16 — المحاسب إله شغل متابعة وتقارير: يشوف
            // التقارير ويصدّرها (تصدير صلاحية منفصلة من تشغيلي)
            'reports.view',
            'reports.export',

            // ============ عشان يشوف الطلاب واشتراكاتهم
            'students.view',
            'teachers.view',

            // ⭐⭐ المصروفات — **قراءة بس، عن قصد.** المحاسب بيشوف
            // الأرقام عشان يقفل الشهر، بس **تسجيل** المصروف شغل موظف
            // الاستقبال. لو المحاسب سجّل مصروف كمان، اللي راجع
            // المصروفات هيراجع نفسه — وده بيكسر فصل المهام.
            'finance.expenses.view',

            // ⭐ مستحقات المعلمين — قراءة بس. الصرف محتاج `payroll.manage`.
            'payroll.view',

            // ⭐ الفواتير والمدفوعات
            'finance.payments.view',
            'finance.payments.create',
        ],

        /**
         * ⭐ موظف الاستقبال — **مفيش مالي خالص.**
         *
         * ده الفصل اللي `RoleIsolationTest` بيحرسه: الاستقبال
         * بياخد الطلاب والـleads والحضور، ومش ليه ن/subpixel واحد
         * في `finance.*` ولا `payroll.*`.
         *
         * ⚠️ **تغيّر سلوكي (PR 0.7):** كان معرّف في `SeedEmployees`
         * بس، فكان بينتج role في الـDB مش موجود في الكتالوج.
         */
        'receptionist' => [
            'students.view',
            'students.create',
            'students.update',
            'leads.view',
            'leads.create',
            'leads.update',
            'programs.view',
            'attendance.view',
            'attendance.update',
        ],

        /**
         * ⭐ المشرفة الأكاديمية — المتابعة اليومية.
         *
         * ⭐ عندها `lessons.edit` عشان تعدّل حالة الحصة، بس **مفيش**
         * `students.delete` — الحذف قرار إدارة.
         */
        'supervisor' => [
            'students.view',
            'students.update',
            'teachers.view',
            'lessons.view',
            'lessons.update',
            // ⭐ PR 3.2 — يقدر يشوف سياسات الحصص (قرار التعديل
            // لسه إدارة بس).
            'lessons.policies.view',
            'reports.view',
            'programs.view',
            'attendance.view',
            'attendance.update',
        ],

        /**
         * ⚠️ المعلم — **`programs.view` وخلاص.**
         *
         * ⭐ ليش بالذات؟ المعلم لازم يعرف بيحضّر إيه، بس
         * `lessons.view` كانت هتفتحله حصص الأكاديمية كلها — ده
         * تسريب. فالبرنامج (= كتالوج الخدمة والأسعار) مفتوح،
         * والحصص مقفولة.
         *
         * ⭐ والجدول بتاعه هو استثناء في `RouteProtectionTest` —
         * محمي بـ`authorizeOwnership()` جوه الـcontroller مش بصلاحية.
         */
        'teacher' => [
            'programs.view',
            // ⭐⭐⭐ **PR 3.10 — بوابة المعلم**: المعلم يشتغل من
            // بوابة له صلاحيتها (`teacher.portal`)، حصصه هو فقط —
            // الملكية في `LessonPolicy` (PR 1.8) مش في الصلاحية.
            'teacher.portal',
        ],

        /**
         * ⭐ ولي الأمر — المتاح للتسجيل + ابنه في البوابة.
         *
         * ⭐ مفيش أي صلاحية إدارية. البوابة محمية بـ`parent.only`
         * مش بصلاحية — وده الوضع الصح لولي الأمر.
         */
        'parent' => [
            'programs.view',
        ],

        /**
         * ⭐⭐⭐ الطالب المستقل — **قراءة لنفسه فقط** (PR 1.12).
         *
         * ⭐ القاعدة: تحت 13 مالوش حساب أصلاً. فوق 13 الحساب
         * موجود بس **مفيش تفعيل** من غير موافقة ولي أمر مسجّلة
         * (`parental_consents`).
         *
         * ⭐ والصلاحيات **قراءة فقط** بالتصميم: الجدول والحضور
         * والتقدم بتاعه هو. ومافيش `.create` / `.update` /
         * `.delete` / `manage` — وده مش اتفاق، ده **مفروض**.
         * الحساب ده بيقرا عن نفسه، وما ينفعش يعدّل بيانات غيره
         * ولا ينشئ ولا يحذف ولا يوقّع على مصروف.
         *
         * ⭐ `lessons.view` و`attendance.view` مش هنا عن قصد: لو
         * الطالب لقى جدول العرض العام للمتابعة (`programs.view`)،
         * الـpolicies (PR 1.8) بتقيّد التفاصيل ل student's نفسه
         * والـpolicies هي اللي بيمنع، مش الصلاحيات.
         */
        'student' => [
            'programs.view',
            // ⭐ PR 4.6 — بوابة الطالب: قراءة نفسه فقط. الملكية
            // من `students.user_id`، ومفيش أي صلاحية كتابة أو مال.
            'student.portal',
        ],
    ];
    /**
     * ⭐⭐⭐ الكتالوج بقى في `config/permissions.php` — ومش هنا.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه مش `const PERMISSIONS` هنا تاني
     *
     * ⭐ كان `public const PERMISSIONS = [ ... ]` في الـseeder. وبقّى
     * في `config/permissions.php` لأن الكتالوج **بيانات مش شغل**:
     *
     *   • seeder بينشئ صفوف — والكتالوج بيوصف النظام
     *   • `config:` بتتقدّم على أي كود تاني، والاختبارات بتقراها
     *     من غير ما تشغّل seeder على قاعدة
     *   • والوصف العربي والـgroup مالهم مكان غير ملف config
     *
     * ⭐ وتكرار الكتالوج في مكانين هو **سبب** عدم اتساق المشروع:
     * `accountant` و `teacher` اتعرّفوا في `SeedEmployees` **و**
     * هنا بقيمتين مختلفة — وده اللي كشفه `RoleIsolationTest`.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه بقا **دالة** مش `const`
     *
     * ⭐ `const` في PHP بيسمح بـ**constant expression** بس:
     *
     *     const PERMISSIONS = self::CATALOG();   // ⭐ Constant
     *                                                 expression
     *                                                 contains
     *                                                 invalid
     *                                                 operations
     *
     * ⭐ مافيش static initializer في PHP 8.2 ولا 8.3. ف.Options
     * اتنين بس: `require` جوّه الـconst (ممنوع)، أو **دالة**.
     *
     * ⭐ والدالة أحسن من `const` هنا على أي حال: بتسمح لبقية
     * الكود تستخدم `config('permissions.permissions')` من غير ما
     * تعرف حاجة عن الـseeder أصلاً.
     *
     * @see config/permissions.php
     * @see self::permissions()
     * @see \App\Console\Commands\SyncPermissions
     */

    /**
     * ⭐⭐⭐ الصلاحيات بالشكل اللي بيحتاجه Spatie.
     *
     * ⭐ `config('permissions.permissions')` بترجّع
     * `['name' => ['module' => …]]` — مفهرسة بالاسم، وده اللي
     * الكتالوج كله بيشتغل بيه.
     *
     * ⭐ والدالة دي بتعمل التحويل لـ
     * `[['name' => …, 'module' => …], …]` — الشكل اللي
     * `array_column(…, 'name')` بيشتغل عليه واللي
     * `PermissionsSyncTest` بيقرا.
     *
     * ⭐ وليه **نُسخ واحدة** مش نتيجة `require` مباشرة: عشان
     * الـcache بتاع `config()` في production بيكون مُجمّد من أول
     * طلب. الـ`require` بيرجع **نسخة جديدة كل مرة** — فأي
     * تعديل على الكتالوج فيـruntime (مفيش، بس__) كان هيبقى
     * متسرّب. عن طريق `config()` الـcache هو المصدر الوحيد.
     *
     * @return array<int, array{name:string, module:string, group:string, description:string}>
     */
    public static function permissions(): array
    {
        $out = [];

        foreach (config('permissions.permissions', []) as $name => $meta) {
            $out[] = [
                'name' => $name,
                'module' => $meta['module'] ?? '',
                'group' => $meta['group'] ?? '',
                'description' => $meta['description'] ?? '',
            ];
        }

        return $out;
    }

    /**
     * ⭐⭐⭐ خريطة التحويل: الاسم القديم → الجديد.
     *
     * ⭐ الـراوتات لسه بالأسماء القديمة، والـ`PR 1.7` هو اللي
     * يطبّق الخريطة دي على `routes/api.php` وعلى الداتابيز.
     *
     * ⭐ بتخلي `permissions:sync` **يعمل الاتنين**: الأسماء
     * الجديدة canonical، والأسماء القديمة اللي لسه مستخدمة —
     * عشان مافيش راو يموت بين الـPRين.
     *
     * @see config/permissions.php
     * @see \Tests\Feature\PermissionCatalogTest::test_every_migration_target_exists_in_the_catalog
     *
     * @return array<string, string>
     */
    public static function migrationMap(): array
    {
        return config('permissions.migration', []);
    }

    public function run(): void
    {
        // ⭐⭐⭐ الكتالوج ** الجديد** — الاسم والوحدة والوصف والـgroup.
        foreach (self::permissions() as $perm) {
            $this->upsertPermission($perm['name'], $perm['module'], $perm['group'], $perm['description']);
        }

        // ⭐⭐⭐ **الأسماء القديمة لسه مستخدمة** — لازم موجودة.
        //
        // ⭐ `routes/api.php` لسه بيطلب `settings.manage` و
        // `payments.view`… والـ`PR 1.7` هو اللي يطبّق الخريطة.
        //
        // ⭐ من غير السطر ده، أول تشغيل للـseeder بعد PR 1.6 كان
        // هيمسح الـpermissions دي (من غير `migrate:fresh`) و**كل
        // راو بيطلبها بيرجع 403** — من غير error، من غير build
        // failure، ومن غير حد ياخد باله.
        foreach (self::legacyPermissionsInUse() as $name) {
            $this->upsertPermission($name, self::moduleOf($name), 'أسماء قديمة', 'اسم قديم — بيشتغل لحد ما PR 1.7 يطبّق الخريطة');
        }

        // ⭐⭐⭐ **صلاحيات الأدوار القديمة** — مش في الراوتات بس الأدوار
        // بتشير إليها.
        //
        // ⭐ الحالة الحقيقية: `ROLES['accountant']` فيه `finance.view`
        // و `finance.exchange_rates` — دي **مش** في `routes/api.php`
        // (حد شالهم من الراوات من غير ما يشيلهم من الدور).
        //
        // ⭐ من غير السطر ده، `syncRolesFromCatalog()` بيرمي
        // `InvalidArgumentException` لأن الصلاحية مش موجودة في
        // الداتابيز — يعني **الـseeder كله يفشل** والمشروع مش
        // بيشتغل خالص.
        foreach (self::legacyPermissionsInRoles() as $name) {
            $this->upsertPermission($name, self::moduleOf($name), 'أسماء قديمة', 'اسم قديم — بيشير ليه دور لحد ما PR 1.7 يطبّق الخريطة');
        }

        $adminRole = Role::firstOrCreate(
            ['name' => 'admin', 'guard_name' => 'web'],
            ['slug' => 'admin', 'guard_name' => 'web', 'is_system' => true],
        );
        $adminRole->syncPermissions(Permission::all());

        // ⚠️ مين الـ admin الحقيقي؟
        //
        // كان مكتوب: `where('email', 'like', '%@quran-academy.com')` —
        // بس **كل** حسابات الموظفين إيميله ينتهي بـ @quran-academy.com،
        // فكلهم كان بياخد دور admin كامل (٢٥ صلاحية) فوق دورهم
        // الحقيقي. النتيجة: المحاسب كان يقدر يسجّل حضور رغم إننا
        // قاعدين نفصل بين المسجّل والمحاسب.
        //
        // القاعدة الصح: الـ admin هو اللي إيميله `admin@` بالظبط،
        // أو حد مفيش له دور تاني أصلاً.
        $admins = User::where('email', 'like', 'admin@%')
            ->whereDoesntHave('teacher')
            ->get();

        foreach ($admins as $admin) {
            $admin->assignRole($adminRole);
        }

        // حساب إيميله @quran-academy.com ومعاه دور بالفعل → نسيبه
        // على دوره. لو مفيش له دور خالص → ده ناسي، نشيله admin
        // عشان ما يفتحش حاجة بالغلط.
        $others = User::where('email', 'like', '%@quran-academy.com')
            ->whereDoesntHave('teacher')
            ->where('email', 'not like', 'admin@%')
            ->get();

        foreach ($others as $user) {
            if ($user->roles->isEmpty()) {
                Log::warning('Account with academy email but no role', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                ]);
            }
        }

        $this->syncRolesFromCatalog();
        $this->grantDerivedRoles();

        $this->command?->info(
            '   → ' . count(self::permissions()) . ' صلاحية · '
            . count(self::legacyPermissionsInUse()) . ' اسم قديم (لحد PR 1.7) · '
            . count(self::ROLES) . ' دور · ' . $admins->count() . ' حساب إداري'
        );
    }

    /**
     * ⭐⭐⭐ ينشئ/يحدّث صلاحية — **والوصف والـgroup كمان**.
     *
     * ⭐ `firstOrCreate` لوحدها مش كفاية: الصلاحيات الموجودة من
     * قبل `2026_10_15_000004` هتاخد `description = null` و
     * `group = null` للأبد.
     *
     * ⭐ فبنعمل `updateOrCreate` — الـbackfill جزء من إنشاء
     * الصلاحية، مش عملية منفصلة بتتنسى.
     *
     * ⚠️ **`module` بيتحدّث** والاسم **لا**. تغيير الاسم لPermission
     * موجودة = إبطالها عند كل من عنده، والاسم هو المفتاح اللي
     * `routes/api.php` و`CheckPermission` بيشتغلوا بيه.
     */
    private function upsertPermission(string $name, string $module, string $group, string $description): void
    {
        Permission::updateOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            [
                'slug' => str_replace('.', '-', $name),
                'module' => $module,
                'group' => $group,
                'description' => $description,
                'guard_name' => 'web',
            ],
        );
    }

    /**
     * ⭐⭐⭐ **الأسماء القديمة اللي لسه في الراوتات**.
     *
     * ⭐ بنقرا `routes/api.php` **من الملف** مش من الـ`Route`
     * المُسجَّلة — لأن `CheckPermission` بيفكّ `permission:x` لـclass
     * بعد البناء، فالاسم بيضيع.
     *
     * ⭐ وبنفلتر على «اللي مش canonical» — يعني القديم لسه شغال.
     * واللي خلص منه الـPR 1.7 **مش هيتعمل تاني** من غير تعديل
     * هنا.
     *
     * @return array<int, string>
     */
    public static function legacyPermissionsInUse(): array
    {
        $source = @file_get_contents(base_path('routes/api.php'));

        if ($source === false) {
            return [];
        }

        preg_match_all('/permission:([a-z][a-z.]*)/', $source, $matches);

        $canonical = array_keys(config('permissions.permissions', []));

        $legacy = array_values(array_unique($matches[1]));

        return array_values(array_filter(
            $legacy,
            fn (string $name) => ! in_array($name, $canonical, true)
        ));
    }

    /**
     * ⭐⭐⭐ **صلاحيات الأدوار القديمة** — مش في الراوتات بس الأدوار
     * بتشير إليها.
     *
     * ⭐ الحالة الحقيقية: `ROLES['accountant']` فيه `finance.view`
     * و `finance.exchange_rates` — دي **مش** في `routes/api.php`
     * (حد شالهم من الراوات من غير ما يشيلهم من الدور).
     *
     * ⭐ من غير الدالة دي، `syncRolesFromCatalog()` بيرمي
     * `InvalidArgumentException` لأن الصلاحية مش موجودة في
     * الداتابيز — يعني **الـseeder كله يفشل** والمشروع مش
     * بيشتغل خالص.
     *
     * @return array<int, string>
     */
    public static function legacyPermissionsInRoles(): array
    {
        $canonical = array_keys(config('permissions.permissions', []));

        $names = [];

        foreach (self::ROLES as $permissions) {
            if ($permissions === '*') {
                continue;
            }

            foreach ($permissions as $name) {
                if (! in_array($name, $canonical, true)) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * ⭐⭐⭐ وحدة الاسم القديم — من **آخر مقطع** مش أول واحد.
     *
     * ⭐ `finance.payments.view` → الوحدة `finance` (الأولى).
     * و `students.edit` → `students`.
     *
     * ⭐ ⚠️ **مش** `explode('.')[0]` على طول: الاسم القديم
     * `settings.manage` → `settings` صحيح، بس `phones.manage` →
     * `phones` وهي وحدة **مش موجودة** أصلاً. فالاسم القديم
     * `phones.manage` بياخد `phones`، والوجهة في الكتالوج
     * `students.phones.manage`.
     *
     * ⭐ والوحدة **بتفضَّل من الوجهة** جوه الخريطة — دي الأصح:
     * `payments.view` → الوجهة `finance.payments.view` فالوحدة
     * `finance`، مش `payments` اللي مش موجودة.
     */
    private static function moduleOf(string $name): string
    {
        $targets = self::migrationMap()[$name] ?? null;

        if ($targets !== null && $targets !== []) {
            // ⭐ الخريطة قيمها **قوائم** — والوحدة من أول وجهة
            return explode('.', $targets[0])[0];
        }

        return explode('.', $name)[0];
    }

    /**
     * ⭐⭐⭐ يطبّق `self::ROLES` على جدول الأدوار — **المصدر الوحيد**.
     *
     * ⭐ كل دور في الكتالوج بيتعمل بـ`firstOrCreate` + `syncPermissions`.
     * `syncPermissions` بيمسح اللي قبله، فإعادة تشغيل الـseeder
     * **idempotent**: نفس النتيجة كل مرة.
     *
     * ⚠️ `'admin' => '*'` بتتوسّع لـ`Permission::all()` وقت التشغيل
     * مش وقت التعريف. فصلاحية جديدة اتضافت امبارح، المدير بياخدها
     * من غير ما نعدّل حاجة.
     */
    private function syncRolesFromCatalog(): void
    {
        foreach (self::ROLES as $roleName => $permissions) {
            $role = Role::firstOrCreate(
                ['name' => $roleName, 'guard_name' => 'web'],
                [
                    'slug' => $roleName,
                    'guard_name' => 'web',
                    // ⭐ المدير بس هو النظام. أي دور تاني قابل
                    // للتعديل من شاشة الأدوار.
                    'is_system' => $roleName === 'admin',
                ],
            );

            $granted = $permissions === '*'
                ? Permission::all()
                : Permission::whereIn('name', $permissions)->get();

            // ⭐⭐ **الاسم مش موجود في الكتالوج = غلط، مش بصمت.**
            //
            // `whereIn` بيسيب الاسم اللي مش موجود في `PERMISSIONS`
            // من غير أي أثر. فلو حد كتب `financ.view` بدل
            // `finance.view`، الدور هيتكوّن ناقصAnchor وما حدش
            // هيعرف. فبنرمي.
            $missing = array_values(array_diff($permissions === '*' ? [] : $permissions, Permission::pluck('name')->all()));

            if ($missing !== []) {
                throw new \InvalidArgumentException(sprintf(
                    'دور «%s» فيه صلاحيات مش موجودة في %s::PERMISSIONS: %s. '
                    .'إما ضيفها في PERMISSIONS، أو امسحها من ROLES.',
                    $roleName,
                    self::class,
                    implode(', ', $missing),
                ));
            }

            $role->syncPermissions($granted);
        }
    }

    /**
     * ⭐ الأدوار اللي بتتشتق من **علاقة المستخدم** مش من الـcatalog.
     *
     * المعلم وأولياء الأمور مالهمش ملف «موظف» يتربط بيه دور —
     * فلازم يتولّد الدور من الـuser نفسه. فالـcatalog بيقول إيه
     * الصلاحيات، والخطوة دي بتقول لمين.
     *
     * ⚠️ `if ($t->roles->isEmpty())` — بنسيب أي حد معاه دور
     * تاني (زي `admin`)، مش بنكتب فوق اختيار إداري.
     */
    private function grantDerivedRoles(): void
    {
        // ⭐ `firstOrCreate` مش `findByName` — الخطوة دي شغالة بعد
        // `syncRolesFromCatalog()` فالدور موجود، بس لو حد نادى
        // `grantDerivedRoles` لوحدها ما ينفجرش.
        $teacherRole = Role::firstOrCreate(
            ['name' => 'teacher', 'guard_name' => 'web'],
            ['slug' => 'teacher', 'guard_name' => 'web', 'is_system' => false],
        );

        $parentRole = Role::firstOrCreate(
            ['name' => 'parent', 'guard_name' => 'web'],
            ['slug' => 'parent', 'guard_name' => 'web', 'is_system' => false],
        );

        $teachers = User::whereHas('teacher')->get();

        foreach ($teachers as $teacher) {
            if ($teacher->roles->isEmpty()) {
                $teacher->assignRole($teacherRole);
            }
        }

        // ⭐⭐ `is_parent` بقى **مشتقّ** من `parents.user_id` (PR 1.3).
        //
        // ⭐ قبل كده: `User::where('is_parent', true)` — استعلام على
        // عمود كان بيتكتب بإيد، ومحدش بيضمن إن كل ولي أمر عنده
        // سجل `parents` فعلاً. فلو الحساب اتعمل بالدور من غير
        // الملف، هنا كان بيلاقيه ويسلّمه دور **بوابة مش موجودة
        // ليه**.
        //
        // ⭐ دلوقتي: «الحسابات اللي ليها ملف ولي أمر». مفيش
        // احتمال عدم تطابق — هو **نفس** التعريف.
        $parents = User::whereHas('parentProfile')->get();

        foreach ($parents as $parent) {
            if ($parent->roles->isEmpty()) {
                $parent->assignRole($parentRole);
            }
        }

        $this->command?->info(
            '   → دور teacher ('.$teachers->count().' حساب) · دور parent ('.$parents->count().' حساب)'
        );
    }
}
