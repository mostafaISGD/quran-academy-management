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
    /** @var array<int, array{name:string, module:string}> */
    private const PERMISSIONS = [
        ['name' => 'students.view', 'module' => 'students'],
        ['name' => 'students.create', 'module' => 'students'],
        ['name' => 'students.edit', 'module' => 'students'],
        ['name' => 'students.delete', 'module' => 'students'],
        ['name' => 'teachers.view', 'module' => 'teachers'],
        ['name' => 'teachers.create', 'module' => 'teachers'],
        ['name' => 'teachers.edit', 'module' => 'teachers'],
        ['name' => 'teachers.delete', 'module' => 'teachers'],
        ['name' => 'lessons.view', 'module' => 'lessons'],
        ['name' => 'lessons.create', 'module' => 'lessons'],
        ['name' => 'lessons.edit', 'module' => 'lessons'],
        ['name' => 'lessons.cancel', 'module' => 'lessons'],
        ['name' => 'payments.view', 'module' => 'payments'],
        ['name' => 'payments.create', 'module' => 'payments'],
        ['name' => 'payments.edit', 'module' => 'payments'],
        ['name' => 'payments.refund', 'module' => 'payments'],
        ['name' => 'leads.view', 'module' => 'leads'],
        ['name' => 'leads.create', 'module' => 'leads'],
        ['name' => 'leads.edit', 'module' => 'leads'],
        ['name' => 'reports.view', 'module' => 'reports'],
        ['name' => 'programs.view', 'module' => 'programs'],
        // ===== الحضور والمرتبات =====
        // فصل المسؤوليات: موظف الاستقبال يسجّل الحضور، والمحاسب
        // يشغّل المرتبات — مش لازم يكونوا نفس الشخص.
        ['name' => 'attendance.view', 'module' => 'attendance'],
        ['name' => 'attendance.manage', 'module' => 'attendance'],
        ['name' => 'payroll.manage', 'module' => 'payroll'],
        // ===== الأسعار =====
        // الأسعار نفسها **عامة** (الأهالي بيسألوا عنها من غير
        // حساب) — بس تعديلها لازم يكون صلاحية. فبنخليها للإدارة
        // بس: ده قرار تجاري، مش شغل محاسب ولا موظف استقبال.
        ['name' => 'pricing.manage', 'module' => 'pricing'],
        // ===== المجموعات وقائمة الانتظار =====
        // ⭐ الفصل مهم هنا زي الحضور: **الاستقبال** بياخد الطلب
        // العام (وده عام من غير صلاحية أصلاً)، بس **اللي بيدخّل حد**
        // في المجموعة هو الإدارة.
        //
        // السبب: الدخول معناه «الطالب داخل» — لازم حد مسؤول
        // يشوف ده. الاستقبال بياخد الطلب ويحطّه في الطابور بس.
        ['name' => 'groups.manage', 'module' => 'groups'],
        ['name' => 'settings.manage', 'module' => 'settings'],

        // ===== الخزائن والحسابات (الأساس المالي) =====
        //
        // ⭐ الفصل بين **التسجيل** و**العكس/القفل** مقصود:
        //
        //   • `finance.view`       — يشوف الأرصدة والحركات
        //   • `finance.accounts`    — يعمل ويعدّل خزينة/حساب
        //   • `finance.movements`   — يسجّل حركة (تحصيل/مصروف)
        //   • `finance.reverse`     — **يعكس** حركة أو فاتورة
        //   • `finance.periods.lock`   — يقفل فترة
        //   • `finance.periods.unlock` — يفتح فترة مقفولة
        //
        // قرار الإدارة: **فتح الفترات المقفلة للمدير بس** — فبنتخلى
        // `finance.periods.unlock` من أي دور غير `admin`.
        ['name' => 'finance.view', 'module' => 'finance'],
        ['name' => 'finance.accounts', 'module' => 'finance'],
        ['name' => 'finance.movements', 'module' => 'finance'],
        ['name' => 'finance.reverse', 'module' => 'finance'],
        ['name' => 'finance.periods.lock', 'module' => 'finance'],
        ['name' => 'finance.periods.unlock', 'module' => 'finance'],
        ['name' => 'finance.exchange_rates', 'module' => 'finance'],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                [
                    'slug' => str_replace('.', '-', $perm['name']),
                    'module' => $perm['module'],
                    'guard_name' => 'web',
                ],
            );
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

        $this->grantTeacherAndParentRoles();
        $this->grantAccountantRole();

        $this->command?->info(
            '   → ' . count(self::PERMISSIONS) . ' صلاحية · دور admin · '
            . $admins->count() . ' حساب إداري'
        );
    }

    /**
     * ⭐ دور المحاسب.
     *
     * ⭐⭐ **القرار اللي بيمثّل الفصل في الخطة:**
     *
     * الخطة بتقول الصلاحيات منفصلة: «تسجيل دفعة» ≠ «عكس سجل» ≠
     * «اعتماد كشف مرتبات أو فتح فترة مقفلة». وده **عشان** لو
     * المحاسب قدر يعكس أي دفعة، الأرقام اللي الإدارة وقّعت عليها
     * تبقى قابلة للتغيير بإيد حد في التحصيل — وده بالظبط اللي
     * الأرقام المالية كلها مبنية عشان تمنعه.
     *
     * فالمحاسب بياخد:
     *   ✅ `finance.view`        يشوف الأرصدة والحركات
     *   ✅ `finance.accounts`    Excerpt ينشئ خزينة
     *   ✅ `finance.movements`    يسجّل تحصيل ومصروف
     *   ❌ `finance.reverse`     **ممنوع** — محتاج مدير
     *   ❌ `finance.periods.unlock` **ممنوع** — قرار الإدارة: المدير بس
     *
     * @see \App\Models\AccountMovement::reversalOf
     */
    private function grantAccountantRole(): void
    {
        $accountantRole = Role::firstOrCreate(
            ['name' => 'accountant', 'guard_name' => 'web'],
            ['slug' => 'accountant', 'guard_name' => 'web', 'is_system' => false],
        );

        /**
         * ⭐ القائمة **مكتوبة بنصها** مش بـ `whereNotIn`.
         *
         * السبب: لو ضفنا صلاحية مالية جديدة بعد كده، ولو الـكود
         * كان «كل حاجة ما عدا X»، كانت هتاخدها المحاسب **من غير
         * قصد**. هنا كل صلاحية لازم تتكتب بإيدها — عشان لو
         * حد أضاف صلاحية قوية، صلاحية المحاسب ماتتغيّرتش.
         */
        $accountantRole->syncPermissions(
            Permission::whereIn('name', [
                // ============ الحسابات
                'finance.view',
                'finance.accounts',
                'finance.movements',
                'finance.exchange_rates',

                /**
                 * ⭐ **`finance.periods.lock` مسموحة — عن قصد.**
                 *
                 * ⭐ **ليه القفل مسموح والفتح ممنوع؟**
                 *
                 * القفل بيحمي الدفاتر (بيمنع أي كتابة جديدة في
                 * شهر خلص)، والفتح بيلغي الحماية دي.
                 *
                 * فلو القفل ممنوع على المحاسب كمان، يبقى لازم
                 * المدير متاح عشان يقفل شهر — وده غير منطقي: مين
                 * بيقفل شهر أول الشهر؟ المحاسب نفسه.
                 *
                 * والفصل بيبقى في **الاتجاه**: اللي بيقفل **مش**
                 * اللي بيفتح. فمحاسب بالغلط قفل شهر، المدير هو
                 * اللي بيفتح ويكتب السبب.
                 *
                 * ⚠️ ده اللي اسمه «فصل المهام» فعلاً: مش إننا
                 * نمنع، لكن إنمين يعمل إيه يبقى مختلف عن مين
                 * يراجع.
                 */
                'finance.periods.lock',

                // ⭐ عشان يشوف الطلاب واشتراكاتهم
                'students.view',

                // ⭐ الفواتير والمدفوعات — صلاحياتها موجودة فعلاً في
                // الراوتات (`invoices` بيستخدم `payments.*`)
                'payments.view',
                'payments.create',
                'teachers.view',
            ])->get()
        );

        $this->command?->info(
            '   → دور accountant ('.$accountantRole->permissions()->count().' صلاحية — بدون reverse/unlock)'
        );
    }

    /**
     * أدوار المعلم وولي الأمر.
     *
     * ليش دي هنا مش فيSeeder تاني؟ لأن المعلمين وأولياء الأمور مالهمش
     * ملف «موظف» يتربط بيه دور — فلازم يتولّد الدور من الـ user نفسه.
     *
     * ⚠️ ليه `programs.view` بس؟ المعلم لازم يعرف بيحضّر إيه، بس
     * `lessons.view` كانت هتفتحله حصص الأكاديمية كلها — وده تسريب.
     * فالبرنامج (= كتالوج الخدمة + الأسعار) مفتوح، والحصص مقفولة.
     */
    private function grantTeacherAndParentRoles(): void
    {
        $teacherRole = Role::firstOrCreate(
            ['name' => 'teacher', 'guard_name' => 'web'],
            ['slug' => 'teacher', 'guard_name' => 'web', 'is_system' => false],
        );
        $teacherRole->syncPermissions(
            Permission::whereIn('name', ['programs.view'])->get()
        );

        // ولي الأمر: بيشوف المتاح للتسجيل + ابنه في البوابة
        $parentRole = Role::firstOrCreate(
            ['name' => 'parent', 'guard_name' => 'web'],
            ['slug' => 'parent', 'guard_name' => 'web', 'is_system' => false],
        );
        $parentRole->syncPermissions(
            Permission::whereIn('name', ['programs.view'])->get()
        );

        $teachers = User::whereHas('teacher')->get();
        foreach ($teachers as $t) {
            if ($t->roles->isEmpty()) {
                $t->assignRole($teacherRole);
            }
        }

        $parents = User::where('is_parent', true)->get();
        foreach ($parents as $p) {
            if ($p->roles->isEmpty()) {
                $p->assignRole($parentRole);
            }
        }

        $this->command?->info(
            "   → دور teacher ({$teachers->count()} حساب) · دور parent ({$parents->count()} حساب)"
        );
    }
}
