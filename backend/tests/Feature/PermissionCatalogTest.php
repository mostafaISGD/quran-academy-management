<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⭐⭐⭐ كتالوج الصلاحيات — المصدر الرسمي الوحيد.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ المشكلة
 *
 * ⭐ `SeedRolesPermissions::permissions()` كان المصدر الوحيد — وهو
 * **seeder**. يعني:
 *   • مافيش **وصف عربي** ولا **مجموعة** — الاسم `students.edit`
 *     بيقول كل حاجة، بس محدش بيعرف إن `edit` هنا بتغطي تعديل
 *     رقم الهاتف كمان ولا لأ
 *   • مافيش **خريطة**. أسماء زي `settings.manage` بتغطي **خمس**
 *     functionalities مختلفة (إعدادات + أدوار + سجل عمليات + تسعير)
 *   • أسماء مش متسقة: `students.edit` و `lessons.cancel` و
 *     `finance.accounts` — تلات أفعال مختلفة
 *   • `finance.view` في الكتالوج و **مش مستخدم في أي راو** —
 *     ولا حد بيعرف
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة الحاكمة
 *
 * ⭐ **اسم واحد = صلاحية واحدة = وظيفة واحدة.**
 *
 * ⭐ `settings.manage` كسر القاعدة دي: اللي عنده صلاحية واحدة يقدر
 * يغيّر التسعير **و** يشوف سجل العمليات **و** يعدّل أدوار. فأي
 * مراجعة لصلاحية «الإعدادات» مش مراجعة لسلطة حقيقية.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه `config/permissions.php` مش الـseeder
 *
 * ⭐ لأن الـseeder **بينكتب** الداتابيز، والكتالوج **بيوصف**
 * النظام. خلط الاتنين معناه:
 *   • مافيش طريقة نقرا الكتالوج من غير ما نشغّل seeder
 *   • و`config:` بتتقدّم على أي كود تاني — الواجهة كمان
 *   • والاختبارات بتحتاجه من غير ما تلمس الداتابيز
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ليه «مش بيحذف» في `permissions:sync`
 *
 * ⭐ حذف صلاحية = **كل مستخدم عندها يطنش**. والصلاحية الممسوحة
 * من ملف كتالوج **مش معناها** إنها ما بقاش مستخدمة — ممكن
 * تكون في `RoleIsolationTest` أو في seeder تاني.
 *
 * ⭐ فالأمر بيعمل **create only**، وبيحذف **بأمر صريح** جوه
 * `--prune` + تأكيد.
 *
 * @see config/permissions.php
 * @see \App\Console\Commands\SyncPermissions
 */
class PermissionCatalogTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /** ⭐⭐⭐ المصفوفة اللي الاختبارات كلها بتقرا منها */
    private function catalog(): array
    {
        return require base_path('config/permissions.php');
    }

    /** ⭐ @return array<string, array{module:string, group:string, description:string}> */
    private function permissions(): array
    {
        return $this->catalog()['permissions'];
    }

    /** ⭐ @return array<string, array<int, string>> قديم => [جديد, …] */
    private function migrationMap(): array
    {
        return $this->catalog()['migration'];
    }

    /**
     * ⭐⭐ كل الوجهات — **مسطّحة**.
     *
     * ⭐ لازم مسطّحة عشان اختبار الـorphan: صلاحية زي
     * `settings.view` مش في أي راو، بس هي **هدف** في خريطة
     * `settings.manage`. فلو فضّلنا nested، الاختبار كان هيقول
     * «يتيمة» وهي مش يتيمة.
     *
     * @return array<int, string>
     */
    private function migrationTargets(): array
    {
        return array_merge(...array_values($this->migrationMap()));
    }

    /**
     * ⭐⭐ كل صلاحية مستخدمة في `routes/api.php`.
     *
     * ⭐ بنقرا **الملف** مش الـ`Route` المُسجَّلة، عشان نقرا
     * `permission:` من النص. الـmiddleware الفعلي بتبقى
     * `Illuminate\Auth\Middleware\...` بعد البناء، فالاسم
     * بيضيع.
     *
     * @return array<string, int> الاسم => عدد الراوتات
     */
    private function routePermissions(): array
    {
        $source = (string) file_get_contents(base_path('routes/api.php'));

        $out = [];

        preg_match_all('/permission:([a-z][a-z.]*)/', $source, $matches);

        foreach ($matches[1] as $name) {
            $out[$name] = ($out[$name] ?? 0) + 1;
        }

        return $out;
    }

    /** ⭐ دور واحد من `SeedRolesPermissions::ROLES` بشكل مسطّح */
    private function rolePermissionNames(): array
    {
        $names = [];

        foreach (\Database\Seeders\SeedRolesPermissions::ROLES as $role => $list) {
            if ($list === '*') {
                continue;
            }

            foreach ($list as $permission) {
                $names[$permission] = $role;
            }
        }

        return $names;
    }

    // ═══════════════════════════════════════════════════════════════
    // ① الكتالوج موجود وشكله صح
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ `config/permissions.php` لازم يبقى المصدر.
     *
     * ⭐ `SeedRolesPermissions::permissions()` بقى **غلاف** يقرا منه،
     * ⭐ الشرط ظاهر إنه متعسّف: مينفعش نبقي عارفين اسم عربي
     * من غير علامات عربي. لو رجع الحروف اللاتينية بس يبقى نسيان.
     */
    public function test_the_config_file_is_the_single_source(): void
    {
        $this->assertFileExists(base_path('config/permissions.php'));

        $catalog = $this->catalog();

        $this->assertArrayHasKey('permissions', $catalog, 'مافيش `permissions` في الكتالوج');
        $this->assertArrayHasKey('migration', $catalog, 'مافيش `migration` (خريطة التحويل)');

        // ⭐ والـseeder بيقرا منه، مش بيحتفظ بقائمته
        $fromSeeder = collect(\Database\Seeders\SeedRolesPermissions::permissions())
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $fromConfig = array_keys($this->permissions());
        sort($fromConfig);

        $this->assertSame(
            $fromConfig,
            $fromSeeder,
            '⭐⭐ SeedRolesPermissions::permissions() مش بيساوي config/permissions.php — في تكرار أو انحراف'
        );
    }

    /**
     * ⭐⭐⭐ كل صلاحية: اسم + وحدة + **وصف عربي** + **مجموعة**.
     *
     * ⭐ الوصف العربي هو الحاجة اللي بتخلي الكتالوج **مصدر
     * قرار** مش مجرد قائمة أسماء. من غيره محدش يقدر يجاوب
     * «يعني `finance.movements.reverse` إيه بالظبط؟» من غير ما
     * يفتح الـcontroller.
     */
    public function test_every_permission_has_a_module_a_group_and_an_arabic_description(): void
    {
        foreach ($this->permissions() as $name => $meta) {
            foreach (['module', 'group', 'description'] as $key) {
                $this->assertArrayHasKey($key, $meta, "«{$name}» مافيشه `{$key}`");
                $this->assertNotSame('', (string) $meta[$key], "«{$name}» الـ`{$key}` فاضي");
            }

            // ⭐⭐ **الوصف عربي فعلاً** — لا لاتيني
            //
            // ⭐ شرط ظاهر إنه متعسّف: مينفعش نبقي عارفين اسم
            // عربي من غير علامات عربي. لو رجع الحروف الليستينية
            // بس يبقى نسيان، والوصف بيبقى **فارغ في المحل**.
            $this->assertMatchesRegularExpression(
                '/[\x{0600}-\x{06FF}]/u',
                (string) $meta['description'],
                "⭐ وصف «{$name}» مش عربي: «{$meta['description']}»"
            );

            // ⭐ والاسم يبدأ بالوحدة — `students.*` في `students`
            $this->assertStringStartsWith(
                $meta['module'].'.',
                $name,
                "⭐ «{$name}» مش في وحدته «{$meta['module']}»"
            );
        }
    }

    /**
     * ⭐⭐ **ما فيش صلاحية مكرّرة** — الاسم مفتاح، فالتكرار
     * بيترجم لآخر واحد في المصفوفة بصمت.
     */
    public function test_there_are_no_duplicate_permission_names(): void
    {
        // ⭐⭐⭐ بنمسح **قسم الصلاحيات بس** — مش الملف كله.
        //
        // ⭐ الـ`migration` section فيه **نفس الأسماء** كمفاتيح
        // (`'students.view' => ['students.view']`) — وده متعمّد
        // وممكن يتكرر. مسح الملف كله كان بيطلع «مكرر» على حاجة
        // مفيش حد بيكتب عليها.
        $source = (string) file_get_contents(base_path('config/permissions.php'));

        $start = strpos($source, "'permissions' => [");
        $end = strpos($source, "'migration' => [", $start);

        $this->assertNotFalse($start, 'مافيش قسم `permissions` في الكتالوج');
        $this->assertNotFalse($end, 'مافيش قسم `migration` في الكتالوج');

        $block = substr($source, $start, $end - $start);

        preg_match_all("/^\s*'([a-z][a-z.]*)'\s*=>\s*\[/m", $block, $matches);

        $names = $matches[1];

        $this->assertSame(
            array_values(array_unique($names)),
            $names,
            '⭐⭐ في اسم صلاحية **مكرر** — PHP بيبلع التاني بصمت وبيخسر تعريف'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الـacceptance: مافيش يا يتيم ولا ناقص
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **PR 1.7 — كل راوت بيستخدم اسم صلاحية canonical.**
     *
     * ⭐ ده الاختبار اللي بيحكم على PR 1.7 نفسه: الراوتات
     * لازم تكون بأسماء الكتالوج الجديدة **مش** القديمة.
     *
     * ⭐ ليه مهم: الاسم القديم `payments.view` مش في الكتالوج
     * الجديد خالص — هو في خريطة التحويل. فلو حد نسي يحدّث
     * راو، الاختبار ده بيقوله «لسه عندك اسم قديم».
     *
     * ⭐ والهدف النهائي: بعد ما كل الراوتات تستخدم الأسماء
     * الجديدة، خريطة التحويل تبقى **فارغة** — معناها مفيش
     * حد محتاج الاسم القديم.
     */
    public function test_every_route_permission_is_canonical(): void
    {
        $canonical = array_keys($this->permissions());
        $legacy = array_keys($this->migrationMap());

        $offenders = [];

        foreach ($this->routePermissions() as $name => $count) {
            if (in_array($name, $canonical, true)) {
                continue;
            }

            if (in_array($name, $legacy, true)) {
                $offenders[] = "{$name} ({$count} routes)";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            '⭐⭐⭐ الراوتات دي بتستخدم أسماء **قديمة** — لازم تتحوّل للكتالوج الجديد: '
                . implode(', ', $offenders)
        );
    }

    /**
     * ⭐⭐⭐ **كل صلاحية مستخدمة في راو معروفة في الكتالوج.**
     *
     * ⭐ أو **اسم قديم** لهentry في خريطة التحويل.
     *
     * ⭐ دي الحماية الحقيقية ضد **403 صامت**: لو حد كتب
     * `permission:students.updat` (إملاء غلط) والاسم مش في
     * الكتالوج، الـ`CheckPermission` بيرفض **كل** الطلبات —
     * والراوت بيفضل شغّال، ومافيش error، ومافيش test بيكشفه.
     *
     * ⭐ ليه بنسمح بالأسماء القديمة لحد ما PR 1.7 يطبّق الخريطة:
     * `routes/api.php` لسه بيستخدمها، ومنعها هنا كان هيكسر
     * الإنتاج. الحكم بيبقى على **كل** العملاء.
     */
    public function test_every_route_permission_is_known_to_the_catalog(): void
    {
        $known = array_keys($this->permissions());
        $legacy = array_keys($this->migrationMap());

        foreach (array_keys($this->routePermissions()) as $name) {
            $this->assertTrue(
                in_array($name, $known, true) || in_array($name, $legacy, true),
                "⭐⭐ الراوت بيطلب «{$name}» وهو **مش في الكتالوج ومش في خريطة التحويل** — ده 403 صامت لكل حد يطلب الراوت"
            );
        }
    }

    /**
     * ⭐⭐⭐ **مافيش صلاحية يتيمة.**
     *
     * ⭐ كل صلاحية في الكتالوج لازم تكون:
     *   • مستخدمة في `routes/api.php`، **أو**
     *   • هدف في خريطة التحويل (يعني بتستبدل قديمة)، **أو**
     *   • في قسم `introduced` — صلاحية جديدة مش متحوّلة من حاجة.
     *
     * ⭐ ⭐⭐ **الحالة اللي كشفها هذا الاختبار فعلاً:**
     * `finance.view` كانت في الكتالوج من أول يوم و **مش في أي
     * راوت** — ولا في دور، ولا في الخريطة. يعني صلاحية existed
     * في الداتابيز ومحدش بيستخدمها ومحدش بيعرف.
     *
     * ⭐ والـorphan خطر حقيقي: لو `admin => '*'` بياخد كل
     * الصلاحيات، فأي صلاحية يتيمة بتظهر عند المدير في شاشة
     * «الأدوار» كأن ليها معنى، ومحدش بيعرف بيشيلها ولا لأ.
     */
    public function test_no_permission_is_an_orphan(): void
    {
        $routeNames = array_keys($this->routePermissions());
        $mapTargets = $this->migrationTargets();
        $introduced = array_keys($this->catalog()['introduced'] ?? []);

        $orphans = [];

        foreach (array_keys($this->permissions()) as $name) {
            if (in_array($name, $routeNames, true)
                || in_array($name, $mapTargets, true)
                || in_array($name, $introduced, true)
            ) {
                continue;
            }

            $orphans[] = $name;
        }

        $this->assertSame(
            [],
            $orphans,
            '⭐⭐⭐ صلاحيات **يتيمة** — في الكتالوج، ومش في أي راو، ومش هدف في خريطة التحويل، ومش في `introduced`: '.implode(', ', $orphans)
        );
    }

    /**
     * ⭐⭐⭐ **خريطة التحويل كاملة** — كل قديم لهدف موجود.
     *
     * ⭐ الـtarget اللي مش موجود = خريطة بتوعد بإmigration وبيعمل
     * لا حاجة. والـPR 1.7 هيطبّقها، فلو الـtarget غلط هنفقد
     * صلاحية على السيرفر.
     */
    public function test_every_migration_target_exists_in_the_catalog(): void
    {
        $known = array_keys($this->permissions());

        foreach ($this->migrationMap() as $from => $targets) {
            $this->assertNotEmpty(
                $targets,
                "⭐ خريطة التحويل «{$from}» → **قائمة فاضية** — الـPR 1.7 هيمسح الصلاحية ومابياخدش غيرها"
            );

            foreach ($targets as $to) {
                $this->assertContains(
                    $to,
                    $known,
                    "⭐⭐ خريطة التحويل بتقول «{$from}» → «{$to}»، والوجهة **مش في الكتالوج** — الـmigration هيضيّع الصلاحية"
                );
            }

            // ⭐⭐ **قائمة من عنصر واحد = الاسم ما اتغيّرش**، وده
            // مش خطأ — هو الطريقة الوحيدة نقول بيها «الاسم القديم
            // ده لسه صحيح، بس لازم يفضل موجود لحد ما الـPR 1.7
            // يطبّق الخريطة».
            //
            // ⭐ والقائمة الفاضية معناها إن الخريطة نسيت تحسب حاجة.
            $this->assertGreaterThanOrEqual(
                1,
                count($targets),
                "⭐ «{$from}» → قائمة فاضية — مافيش صلاحية هتاخد مكانها"
            );
        }
    }

    /**
     * ⭐⭐⭐ **كل اسم قديم في الراوتات لازم يكون في الخريطة.**
     *
     * ⭐ دي بتكمّل Previous test: بتقول إن كل اللي شغال دلوقتي
     * ليه **طريق** للاسم الجديد. من غير كده الـPR 1.7 هيتوه
     * ويسيب راو على اسم قديم للأبد.
     */
    public function test_every_legacy_permission_in_use_has_a_migration_entry(): void
    {
        $known = array_keys($this->permissions());
        $map = $this->migrationMap();

        foreach (array_keys($this->routePermissions()) as $name) {
            if (in_array($name, $known, true)) {
                continue;
            }

            $this->assertArrayHasKey(
                $name,
                $map,
                "⭐⭐ الراوت بيستخدم الاسم القديم «{$name}» وهو **مش في خريطة التحويل** — مافيش طريق للاسم الجديد"
            );
        }
    }

    /**
     * ⭐⭐⭐ **الخريطة بتفكّ فعلاً** — مافيش قديم بيتحوّل لحده.
     *
     * ⭐ الحالة اللي كشفها: `finance.accounts` و`finance.movements`
     * كل واحد بيعمل **-job-ين**:
     *
     *   • `finance.accounts`  → إنشاء + تعديل الحسابات
     *   • `finance.movements`  → تسجيل حركة **و** عكسها
     *
     * ⭐ `finance.reverse` موجودة منفصلة، بس اللي عنده
     * `finance.movements` يقدر يعمل حاجة قريبة منها من غير
     * الصلاحية المخصصة. فالفصل لازم يكون في **الخريطة** مش
     * في الاسم بس.
     *
     * ⭐ الاختبار بيقول: **الصلاحيات المركّبة لازم تتفكّ** — مش
     * كل الأسماء. والأسماء اللي ليها هدف واحد **لازم تكون مسبوبة**
     * كـ«إعادة تسمية صرفة» في `\$pureRename`، والا الفصل ضاع.
     */
    public function test_the_map_actually_splits_compound_permissions(): void
    {
        // ⭐⭐⭐ **المركّبة فعلاً** — اللي كانت بتأكتر من وظيفة.
        //
        // ⭐ كل واحدة دول لازم تطلع **أكتر من هدف** — ولو طلعت واحد،
        // الفصل اللي بيضيفه الكتالوج الجديد اتلغى في الخريطة.
        $compound = [
            'settings.manage',    // إعدادات + أدوار + سجل + تسعير
            'payroll.manage',    // فترات + صرف
            'groups.manage',     // قراءة + إدارة
        ];

        foreach ($compound as $from) {
            $this->assertArrayHasKey(
                $from,
                $this->migrationMap(),
                "⭐⭐ «{$from}» مركّبة ومش في الخريطة أصلاً"
            );

            $this->assertGreaterThan(
                1,
                count($this->migrationMap()[$from]),
                "⭐⭐⭐ «{$from}» كانت بتأكتر من وظيفة — لازم تتفكّ لاكتر من صلاحية، غير كده الفصل اللي عملناه في الكتالوج اتلغى في الخريطة"
            );
        }

        // ⭐⭐⭐ **الأسماء اللي ليها هدف واحد** — معناها إعادة تسمية صرفة.
        //
        // ⭐ مش كل اسم قديم كان مركّب. `students.edit` →
        // `students.update` مجرد توحيد الفعل، ومفيش داعي نفروض واحدة
        // تتفكّ لـ2. بس لازم تكون **مسبوبة** — عشان لو حد شاف
        // `students.edit` في الخريطة وسأل «ليه مش اتفكّ؟»، يلاقي الجواب.
        $pureRename = [
            'students.edit' => 'توحيد الفعل: edit → update',
            'teachers.edit' => 'توحيد الفعل: edit → update',
            'lessons.edit' => 'توحيد الفعل: edit → update',
            'leads.edit' => 'توحيد الفعل: edit → update',
            'attendance.manage' => 'توحيد الفعل: manage → update',
            'pricing.manage' => 'الاسم بيوصف باقة واحدة بس — manage على الباقات',
            'finance.view' => 'كانت يتيمة — المقصود بيها الحسابات',
            'finance.accounts' => 'manage = create + update، بس نفس المورد',
            'finance.movements' => 'create بس — العكس في finance.reverse',
            'finance.reverse' => 'انتقال لـfinance.movements.reverse — إعادة تصنيف',
            'finance.exchange_rates' => 'manage على مورد واحد',
            'payments.view' => 'انتقال إلى وحدة finance',
            'payments.create' => 'انتقال إلى وحدة finance',
            'payments.edit' => 'انتقال إلى وحدة finance + توحيد الفعل',
            'payments.refund' => 'انتقال إلى وحدة finance',
            'expenses.view' => 'انتقال إلى وحدة finance',
            'expenses.create' => 'انتقال إلى وحدة finance',
            'phones.manage' => 'انتقال إلى وحدة students',
        ];

        foreach ($this->migrationMap() as $from => $targets) {
            if (count($targets) !== 1) {
                continue;
            }

            // ⭐ pass-through — الاسم القديم = الجديد. مقبول بدون تبرير.
            if ($targets[0] === $from) {
                continue;
            }

            $this->assertArrayHasKey(
                $from,
                $pureRename,
                "⭐⭐ «{$from}» → «{$targets[0]}» هدف واحد ومش مسبوب — إما مركّبة ونسيت تفكّها، أو إعادة تسمية صرفة وتنضيفها في `\$pureRename` مع سبب"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الأدوار
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ كل صلاحية في أي دور لازم تكون معروفة.
     *
     * ⭐ `syncPermissions` بـSpatie **بتعمل** الصلاحية لو مش
     * موجودة — فدور فيه اسم غلط بينتج صلاحية جديدة من العدم
     * وتمشي. الاختبار ده بيمنع ده.
     */
    public function test_every_role_permission_exists_in_the_catalog(): void
    {
        $known = array_keys($this->permissions());
        $legacy = array_keys($this->migrationMap());

        foreach ($this->rolePermissionNames() as $name => $role) {
            $this->assertTrue(
                in_array($name, $known, true) || in_array($name, $legacy, true),
                "⭐⭐ دور «{$role}» فيه صلاحية «{$name}» مش معروفة — Spatie هيعملها من العدم وتمشي بصمت"
            );
        }
    }

    /**
     * ⭐⭐ الأدوار الجاهزة — **الأسماء الثمانية**.
     *
     * ⭐ `ROLES` هو المصدر الوحيد للأدوار منذ PR 0.7، والـ
     * `admin` معرّف بـ`'*'`.
     */
    public function test_the_ready_made_roles_are_defined(): void
    {
        $roles = \Database\Seeders\SeedRolesPermissions::ROLES;

        foreach (['admin', 'accountant', 'receptionist', 'supervisor', 'teacher', 'parent'] as $role) {
            $this->assertArrayHasKey($role, $roles, "دور «{$role}» مش معرّف في ROLES");
        }

        $this->assertSame('*', $roles['admin'], 'المدير لازم ياخد `*` — قائمة مكتوبة بتفوته صلاحية جديدة');
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ `permissions:sync`
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **`permissions:sync` بيعمل الناقص ومش بيحذف.**
     *
     * ⭐ الاختبارين دول هما **العقد** اللي بيميّز الأداة عن
     * `migrate:fresh`.
     *
     * ⭐ «مش بيحذف» هو المهم أمنياً: حذف صلاحية = إبطالها عند كل
     * من عنده. والصلاحية الممسوحة من الكتالوج مش معناها إنها
     * بقت مستخدمة — يمكن في seeder تاني أو في اختبار.
     */
    public function test_sync_creates_what_is_missing_and_never_deletes(): void
    {
        // ⭐ صلاحية من الكتالوج **لسه** مش في الداتابيز
        $catalogNames = array_keys($this->permissions());
        $missing = collect($catalogNames)
            ->first(fn (string $n) => ! \Spatie\Permission\Models\Permission::where('name', $n)->exists());

        $this->assertNotNull($missing, 'الكتالوج كله موجود — الاختبار مش بيختبر حاجة');

        // ⭐ وصلاحية **برّه** الكتالوج — بتست simulate مستخدم لسه
        // عنده صلاحية قديمة
        $orphan = \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => 'legacy.permission.not.in.catalog', 'guard_name' => 'web'],
            ['slug' => 'legacy-permission-not-in-catalog', 'module' => 'test', 'guard_name' => 'web'],
        );

        $this->artisan('permissions:sync')
            ->assertSuccessful();

        $this->assertDatabaseHas('permissions', ['name' => $missing]);

        $this->assertDatabaseHas('permissions', ['name' => 'legacy.permission.not.in.catalog']);
    }

    /**
     * ⭐⭐ **الأمر بيعمل `syncRolesFromCatalog`** — الأدوار
     * بتتحدّث مع الكتالوج.
     */
    public function test_sync_applies_the_role_templates(): void
    {
        $this->artisan('permissions:sync')->assertSuccessful();

        $this->assertDatabaseHas('roles', ['name' => 'admin', 'guard_name' => 'web']);

        // ⭐ المحاسب مابيتقفلش على `finance.movements.reverse`
        $accountant = \Spatie\Permission\Models\Role::where('name', 'accountant')->first();

        if ($accountant) {
            $names = $accountant->permissions->pluck('name');

            $this->assertNotContains('finance.reverse', $names, '⭐⭐ المحاسب مابيقدرش يعكس حركة مالية');
        }
    }

    /**
     * ⭐⭐ الأعمدة اللي الكتالوج بيحتاجها في جدول `permissions`.
     *
     * ⭐ `module` موجودة من الأصل. `description` و`group` هما
     * الجديد، والـmigration بتاع PR 1.6 هيضيفهم.
     *
     * ⚠️ لازم يبقى **nullable** — في Production في صفوف من
     * قبل الـmigration، و`NOT NULL` من غير default بيكسر.
     */
    public function test_the_permissions_table_can_store_the_catalog_metadata(): void
    {
        $this->assertTrue(Schema::hasTable('permissions'));

        foreach (['name', 'slug', 'module', 'group', 'description'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('permissions', $column),
                "⭐⭐ جدول `permissions` مافيهوش عمود «{$column}» — الكتالوج مش قابل للتخزين"
            );
        }
    }
}
