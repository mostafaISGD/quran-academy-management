<?php

namespace Tests;

use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\WaitingListEntry;
use App\Models\Branch;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Organization;
use App\Models\PeriodLock;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Employee;
use App\Models\ParentModel;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

/**
 * بيانات اختبار صغيرة ومحددة.
 *
 * ليها fixtures مش factories؟ لأن اللي احنا بنختبره مش «ولّد ١٠٠٠
 * طالب عشان الأداء» — ده شغل تاني. احنا بنختبر **علاقات وعقود**:
 * Does the pivot agree with reality? Does the count match between
 * two places? Does permission A block B?
 *
 * الـ fixtures هنا صغيرة ومقروءة، والاختبار بيبقى أوضح لما الـ
 * أرقام تكون ظاهرة. لو احتجناFactories كتير بعدين، نضيفها.
 */
trait BuildsFixtures
{
    protected Organization $org;
    protected Branch $branch;

    protected function bootFixtures(): void
    {
        $this->org = Organization::create([
            'id' => 1,
            'name' => 'أكاديمية الاختبار',
            'slug' => 'test-academy',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);

        $this->branch = Branch::create([
            'id' => 1,
            'organization_id' => $this->org->id,
            'name' => 'الفرع الرئيسي',
            'code' => 'MAIN',
            'timezone' => 'Africa/Cairo',
            'currency' => 'EGP',
            'status' => 'active',
        ]);
    }

    // ============================================================
    // ⭐ الأساس المالي — الخزائن والحسابات
    // ============================================================

    /**
     * خزينة أو حساب.
     *
     * ⭐ `openingBalance` **رصيد افتتاحي** مش رصيد حالي — الرصيد
     * الحالي بيتحسب من الحركات. الاختبارات اللي بتفصل بين
     * الاتنين دي اللي بتقفل قاعدة «الرصيد محسوب».
     */
    protected function makeAccount(array $attrs = []): FinancialAccount
    {
        static $seq = 0;
        $seq++;

        return FinancialAccount::create(array_merge([
            'organization_id' => $this->org->id,
            'name' => 'حساب '.($seq),
            'type' => 'cash',
            'currency' => 'EGP',
            'opening_balance' => 0,
            'status' => 'active',
        ], $attrs));
    }

    /** ⭐ حركة واحدة — لتجربة الرصيد والقفل */
    protected function makeMovement(
        FinancialAccount $account,
        array $attrs = [],
    ): AccountMovement {
        return AccountMovement::create(array_merge([
            'organization_id' => $this->org->id,
            'financial_account_id' => $account->id,
            'direction' => 'in',
            'category' => 'income',
            'amount' => 100,
            'currency' => $account->currency,
            'movement_date' => now()->toDateString(),
            'status' => 'posted',
        ], $attrs));
    }

    /** ⭐ قفل فترة */
    protected function makeLock(array $attrs = []): PeriodLock
    {
        [$start, $end] = PeriodLock::monthRange(now()->format('Y-m'));

        return PeriodLock::create(array_merge([
            'organization_id' => $this->org->id,
            'scope' => 'all',
            'period_start' => $start,
            'period_end' => $end,
            'locked_at' => now(),
        ], $attrs));
    }

    /**
     * فئة مصروف.
     *
     * ⭐ `slug` **إجباري** في الجدول — فأي `create` من غيره بيرمي
     * exception. عشان كده في fixture، عشان الاختبارات ما تكتبش
     * الـ slug بإيدها.
     */
    protected function makeExpenseCategory(array $attrs = []): \App\Models\ExpenseCategory
    {
        static $seq = 0;
        $seq++;

        return \App\Models\ExpenseCategory::create(array_merge([
            'organization_id' => $this->org->id,
            'name' => 'فئة '.$seq,
            'slug' => 'category-'.$seq,
        ], $attrs));
    }

    protected function makeUser(string $name = 'مستخدم', ?string $email = null): User
    {
        return User::create([
            'organization_id' => $this->org->id,
            'name' => $name,
            'email' => $email ?? 'user'.uniqid().'@test.local',
            'password' => bcrypt('password'),
            'timezone' => 'Africa/Cairo',
            'locale' => 'ar',
            'status' => 'active',
            // ⭐⭐ الحساب في الاختبار **جاهز** — مش مستدعى.
            //
            // ⭐ لازم محدد صريحاً: العمود بقى default `true` في
            // المخطط (لأن أي حساب موجود ورث كلمة مرور `password`
            // من الـseeder، وده بالظبط اللي PR 0.4 بيقفله)، ولو
            // تركنا الـdefault كل اختبارات الـAPI هتاخد 403 من
            // `EnsurePasswordChanged`.
            //
            // ⭐ الاختبارات اللي عايزة السلوك ده بتحدّده بنفسها
            // بـ `forceFill`.
            'must_change_password' => false,
            'password_changed_at' => now(),
        ]);
    }

    /**
     * حساب بصلاحية معينة.
     *
     * بنعمل Role و Permission على-the-fly بدل ما نستدعي
     * SeedRolesPermissions، لأن الاختبار عايز أقل قدر ممكن من
     * البنية — لو الـ seeder اتغيّر، الاختبار مفروض ما يتأثرش.
     */
    /**
     * ⭐⭐ دور **حقيقي** بصلاحيات مربوطة — وده اللي بيتصدّر.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ ليه انتقل هنا
     *
     * ⭐ كان مكتوب **تلات مرات**: `PrivilegeEscalationTest`،
     * `AccountInvitationTest`، والنسخة التانية different behavior
     * عن التلاتة الأولى (واحدة `syncPermissions` والأخري
     * `givePermissionTo`).
     *
     * ⭐ الاختلاف ده مش بريء: `assertCanAssignRole` بيقارن
     * **صلاحيات الدور** بالصلاحيات اللي الـactor ماسكها. فلو
     * `makeRole` مابتبطتش الصلاحيات فعلاً، الاختبار بيمرّ
     * والـ`assertCanAssignRole` ما اشتغلتش. الاختبار الأول في
     * `AccountInvitationTest` خبط في الفخ ده بالظبط ورجع **201**
     * بدل 403.
     *
     * @param  array<int, string>  $permissions  أسماء الصلاحيات — بتتعمل لو مش موجودة
     * @param  bool  $isSystem  أدوار النظام بتتقفل من التعديل (PR 0.3)
     *
     * @see \Tests\Feature\PrivilegeEscalationTest
     * @see \Tests\Feature\AccountInvitationTest
     */
    protected function makeRole(string $name, array $permissions = [], bool $isSystem = false): Role
    {
        $role = Role::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['slug' => $name, 'guard_name' => 'web', 'is_system' => $isSystem],
        );

        $models = [];

        foreach ($permissions as $permissionName) {
            $models[] = $this->permission($permissionName);
        }

        // ⭐⭐ `syncPermissions` مش `givePermissionTo`.
        //
        // المعنى: «الدور ده عنده **بالظبط** الأسماء دي».
        // `givePermissionTo` بتضيف على الموجود — فلو اختبار نادا
        // `makeRole('teacher', ['a'])` وبعدين `makeRole('teacher')`
        // من غير أسماء، الدور هيفضل ماسك `a` والاختبار التاني
        // بيبقى غلط بصمت.
        $role->syncPermissions($models);

        return $role->fresh();
    }

    /**
     * ⭐ صلاحية موجودة — بتتعمل لو مش موجودة.
     *
     * ⭐ `module = 'test'` عشان الـfixture ما تلمسش الكتالوج
     * الحقيقي (`SeedRolesPermissions`)، والاختبار مفروض ما يتأثرش
     * لو الكتالوج اتغيّر.
     */
    protected function permission(string $name): \Spatie\Permission\Models\Permission
    {
        return \Spatie\Permission\Models\Permission::firstOrCreate(
            ['name' => $name, 'guard_name' => 'web'],
            ['slug' => str_replace('.', '-', $name), 'module' => 'test', 'guard_name' => 'web'],
        );
    }

    protected function makeUserWithRole(string $roleName, array $permissions = []): User
    {
        $user = $this->makeUser();

        $role = Role::firstOrCreate(
            ['name' => $roleName, 'guard_name' => 'web'],
            ['slug' => $roleName, 'guard_name' => 'web', 'is_system' => false],
        );

        foreach ($permissions as $permName) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
                ['name' => $permName, 'guard_name' => 'web'],
                ['slug' => str_replace('.', '-', $permName), 'module' => 'test', 'guard_name' => 'web'],
            );
            $role->givePermissionTo($perm);
        }

        $user->assignRole($role);

        return $user->fresh();
    }

    /**
     * `makeEmployee(['status' => 'active', 'hourly_rate' => 80])`
     * — الـ array بتتحسب attributes تلقائياً.
     *
     * ملاحظة: الموظف **مش** مربوط بـ user_id هنا (null)، فمفيش
     * حساب دخول. الاختبارات اللي محتاجة حساب بيعملوا `makeUser()`
     * لوحدها وبيربطوها.
     */
    /**
     * ⭐⭐ ملف ولي أمر — مرتبط بـ`user_id` اختياري.
     *
     * ⭐ **`phone` إجباري** في الجدول (`NOT NULL`)، فمافيش تكرار
     * للتعبئة هنا. أول نسخة من `IdentitySchemaTest` كانت بتعمل
     * `ParentModel::create(['organization_id', 'user_id', 'name'])`
     * و `NOT NULL constraint failed: parents.phone` كان بيطلّع
     * **قبل** ما الاختبار يوصل لسلوكه.
     *
     * ⭐ والسطر ده مش «مشكلة في الاختبار» — ده سلوك الجدول. فـ
     * fixture وحدة بتعرف القواعد أفضل من ٦ نداءات `create` ناقصة.
     *
     * @param  User|null  $user  الحساب — `null` يعني ملف من غير
     *   حساب دخول (حالة حقيقية: رقم في `student_phones` بس ما لسه
     *   اتعملش حساب)
     */
    protected function makeParentProfile(?User $user = null, array $attrs = []): ParentModel
    {
        $n = ParentModel::count() + 1;

        return ParentModel::create(array_merge([
            'organization_id' => $this->org->id,
            'user_id' => $user?->id,
            'name' => 'ولي أمر '.$n,
            // ⭐ أرقام مختلفة لكل واحد — القيد العام على
            // `users.phone` (PR 1.3) مش على ده، بس التكرار هنا
            // يخلي الاختبارات بتلخبط فيResults غير متوقع.
            'phone' => '0155000'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'email' => null,
            'status' => 'active',
        ], $attrs));
    }

    /**
     * ⭐⭐ ليه `email` فاضي افتراضياً؟
     *
     * ⚠️ PR 1.3 عمل قيد تفرّد **عام** على `users.email` و
     * `users.phone`. فلو كل ملف ولي أمر في الـfixture كان ليه
     * إيميل، أي اختبار بيعمل اتنين منهم هيتصادم — والسبب هيبقى
     * **بعيد** عن اللي بيختبره.
     *
     * ⭐ فالإيميل فاضي بالافتراضي، والاختبار اللي محتاج إيميل
     * بحدده بنفسه.
     */
    protected function makeEmployee(array $attrs = []): Employee
    {
        $n = \App\Models\Employee::count() + 1;

        return \App\Models\Employee::create(array_merge([
            'organization_id' => $this->org->id,
            'name' => 'موظف '.$n,
            'phone' => '010000000'.$n,
            'job_title' => 'موظف',
            'department' => 'الإدارة',
            'employment_type' => 'full_time',
            'status' => 'active',
        ], $attrs));
    }

    /**
     * `makeTeacher(['display_name' => 'أحمد'])` — الـ array بتتحسب
     * attributes تلقائياً. نسيب `makeTeacher(null, [...])` متعب.
     */
    protected function makeTeacher(array|User|null $userOrAttrs = null, array $attrs = []): Teacher
    {
        if (is_array($userOrAttrs)) {
            $attrs = array_merge($userOrAttrs, $attrs);
            $userOrAttrs = null;
        }

        $user = $userOrAttrs ?? $this->makeUser();

        return Teacher::create(array_merge([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'user_id' => $user->id,
            'teacher_code' => 'TCH-'.str_pad((string) (Teacher::count() + 1), 3, '0', STR_PAD_LEFT),
            'display_name' => $attrs['display_name'] ?? 'معلم '.(Teacher::count() + 1),
            'phone' => '0100000000',
            'timezone' => 'Africa/Cairo',
            'specialization' => 'تحفيظ',
            'status' => 'active',
        ], $attrs));
    }

    protected function makeStudent(array $attrs = []): Student
    {
        $n = Student::count() + 1;

        return Student::create(array_merge([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_code' => 'STU-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'first_name' => 'طالب',
            'last_name' => (string) $n,
            'gender' => 'male',
            'status' => 'active',
        ], $attrs));
    }

    protected function makeProgram(array $attrs = []): Program
    {
        $n = Program::count() + 1;

        return Program::create(array_merge([
            'organization_id' => $this->org->id,
            'name' => 'برنامج '.$n,
            'slug' => 'program-'.$n,
            'description' => 'وصف',
            'status' => 'active',
            'color' => '#2563eb',
        ], $attrs));
    }

    protected function makeLevel(Program $program, array $attrs = []): Level
    {
        $n = $program->levels()->count() + 1;

        return Level::create(array_merge([
            'program_id' => $program->id,
            'name' => 'المستوى '.$n,
            'code' => $program->slug.'-'.$n,
            'sort_order' => $n,
            'status' => 'active',
        ], $attrs));
    }

    protected function makePlan(Program $program, array $attrs = []): SubscriptionPlan
    {
        return SubscriptionPlan::create(array_merge([
            'organization_id' => $this->org->id,
            'program_id' => $program->id,
            'name' => 'باقة '.(SubscriptionPlan::count() + 1),
            'billing_type' => 'monthly',
            'price' => 400,
            'currency' => 'EGP',
            'lessons_count' => 8,
            'lesson_duration_minutes' => 30,
            'duration_days' => 30,
            'status' => 'active',
        ], $attrs));
    }

    // ============================================================
    // ⭐ المجموعات وقائمة الانتظار
    // ============================================================

    /**
     * مجموعة أونلاين.
     *
     * ⭐ `capacity` **مفيش قيمة افتراضية** — عن قصد. لأن كل
     * اختبار محتاج يختبر حالة مختلفة:
     *   - عدد أقصى محدود (السعة بتخلص)
     *   - `null` (مفيش حد)
     *
     * لو حطّينا `10` كافتراضي، نص الاختبارات هتبقى غلط من غير
     * ما حد ينتبه. كل اختبار لازم يحدّد سعته بنفسه.
     */
    protected function makeGroup(array $attrs = [], ?Program $program = null): GroupClass
    {
        return GroupClass::create(array_merge([
            'organization_id' => $this->org->id,
            'program_id' => ($program ?? $this->makeProgram())->id,
            'name' => 'مجموعة '.(GroupClass::count() + 1),
            'status' => 'active',
        ], $attrs));
    }

    /** ⭐ يضيف طلاب للمجموعة ويفترض إنهم داخلين */
    protected function fillGroup(GroupClass $group, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            GroupMember::admit($group, $this->makeStudent());
        }
    }

    /**
     * ⭐ سطر في قائمة الانتظار — **من غير حساب**.
     *
     * ده الشكل الحقيقي: حد جديد بيحط اسمه وموبايله ومش له
     * طالب في النظام.
     */
    protected function makeWaitingEntry(GroupClass $group, array $attrs = []): WaitingListEntry
    {
        $n = WaitingListEntry::count() + 1;

        return WaitingListEntry::create(array_merge([
            'organization_id' => $this->org->id,
            'group_class_id' => $group->id,
            'name' => 'منتظر '.$n,
            // ⭐ أرقام مختلفة لكل واحد — القيد `unique` على الموبايل
            // هيرفض التكرار، والاختبار عايز يتأكد من ده
            'phone' => '0100000'.$n,
            'status' => 'waiting',
            'entered_at' => now(),
        ], $attrs));
    }

    /**
     * ⭐ يملأ قائمة الانتظار بعدد، **بترتيب زمني واضح**.
     *
     * كل واحد بعد التاني بـدقيقة — عشان اختبار الترتيب يبقى
     * محسوم مش «اتنين في نفس الثانية ونتشوف».
     */
    protected function fillWaitingList(GroupClass $group, int $count): array
    {
        $entries = [];
        $start = now()->subMinutes($count + 5);

        for ($i = 0; $i < $count; $i++) {
            $entries[] = $this->makeWaitingEntry($group, [
                'phone' => '0110000'.$i,
                'entered_at' => $start->copy()->addMinutes($i),
            ]);
        }

        return $entries;
    }

    /**
     * باقة البرنامج الافتراضية — بتتعمل مرة واحدة بس لكل برنامج.
     *
     * الباقة الافتراضية بتتعمل مرة واحدة بس لكل برنامج — تاني واحد
     * كان بيعمل باقة جديدة كل مرة من غير ما تقول، فلو اختبار عمل
     * باقات صريحة + اشتراك لقى عدد أكبر من المتوقع وتلخبط.
     * القاعدة: أي حاجة تتعمل مجاناً لازم تبقى **مرئية** في الكود.
     */
    protected function defaultPlan(Program $program): SubscriptionPlan
    {
        $existing = SubscriptionPlan::where('program_id', $program->id)
            ->where('status', 'active')
            ->first();

        return $existing ?? $this->makePlan($program);
    }

    /**
     * اشتراك — وده **المصدر الوحيد** لعدد طلاب البرنامج.
     *
     * ليش دالة واحدة؟ لأن تعريف «الطالب في البرنامج» لازم يكون
     * في مكان واحد. لو كل اختبار بيبنيه بإيده، أول اختبار يعدّل
     * التعريف يبقى باقي الاختبارات بتكذب.
     */
    protected function makeSubscription(
        Program $program,
        Student $student,
        array $attrs = [],
        ?Teacher $teacher = null,
    ): Subscription {
        $plan = $attrs['plan_id'] ?? $this->defaultPlan($program)->id;
        unset($attrs['plan_id']);

        return Subscription::create(array_merge([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'plan_id' => $plan,
            'program_id' => $program->id,
            'teacher_id' => $teacher?->id,
            'start_date' => now()->startOfMonth()->toDateString(),
            'billing_type' => 'monthly',
            'price' => 400,
            'currency' => 'EGP',
            'lesson_duration_minutes' => 30,
            'lessons_included' => 8,
            'status' => 'active',
        ], $attrs));
    }

    protected function makeLesson(
        Program $program,
        Student $student,
        Teacher $teacher,
        array $attrs = [],
        ?Level $level = null,
    ): Lesson {
        return Lesson::create(array_merge([
            'organization_id' => $this->org->id,
            'branch_id' => $this->branch->id,
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'level_id' => $level?->id,
            'scheduled_start_at' => now()->addDays(3)->setTime(16, 0),
            'scheduled_end_at' => now()->addDays(3)->setTime(16, 30),
            'duration_minutes' => 30,
            'status' => 'scheduled',
            'lesson_type' => 'regular',
        ], $attrs));
    }

    /**
     * ربط المعلم بالبرنامج — pistol بور.
     *
     * بنعملها بـ pivot مباشرة (مش relation) عشان نتحقق إن الـ pivot
     * نفسه هو مصدر الحقيقة في الاختبار، مش الـ relation اللي
     * بنختبره.
     */
    /**
     * معلم نشط — جاهز للاستعمال في مرتبات المعلمين.
     *
     * `makeActiveTeacher(['display_name' => 'أحمد'])`.
     */
    protected function makeActiveTeacher(array $attrs = []): Teacher
    {
        return $this->makeTeacher(array_merge([
            'status' => 'active',
            'display_name' => 'معلم نشط '.(Teacher::count() + 1),
        ], $attrs));
    }

    /**
     * سجل مستحق — **المصدر الوحيد** لمرتبات المعلمين.
     *
     * ملاحظة مهمة: `amount` هنا هو الرقم اللي بيتقفل عند الاعتماد.
     * `rate_id` و `lesson_id` بيوضّحوا مصدره بس مش بيتحسبوا تاني.
     */
    protected function makeEarning(Teacher $teacher, string $date, array $attrs = []): \App\Models\TeacherEarning
    {
        return \App\Models\TeacherEarning::create(array_merge([
            'teacher_id' => $teacher->id,
            'amount' => 100,
            'currency' => 'EGP',
            'earning_date' => $date,
            'status' => 'approved',
        ], $attrs));
    }

    /**
     * عقد نشط — افتراضياً `per_lesson` (مصدر أرباح المعلم جاي منه).
     *
     * `makeContract($teacher)` أو `makeContract($teacher, ['contract_type' => 'monthly'])`.
     */
    /**
     * ⭐⭐⭐ **PR 3.10 — حصة جاهزة للإتمام (سلة البوابة).**
     *
     * تجميعة خط إتمام الحصة: معلم متعاقد عليه بسعر، اشتراك
     * برصيد 8 حصص (حساب + حركة افتتاحية)، وحصة `scheduled`
     * مربوطة بالاشتراك.
     *
     * `makeCompletableLesson(['status' => 'in_progress'])` لو
     * محتاج تبدأ من منتصف الجري.
     *
     * @return array{0: Lesson, 1: Teacher, 2: Subscription}
     */
    protected function makeCompletableLesson(array $lessonAttrs = [], ?Teacher $teacher = null): array
    {
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);

        $account = \App\Models\LessonCreditAccount::create([
            'student_id' => $student->id,
            'subscription_id' => $subscription->id,
            'credit_type' => 'regular',
            'current_balance' => 8,
            'status' => 'active',
        ]);

        \App\Models\LessonCreditTransaction::create([
            'credit_account_id' => $account->id,
            'student_id' => $student->id,
            'subscription_id' => $subscription->id,
            'type' => 'initial',
            'quantity' => 8,
            'balance_after' => 8,
            'reason' => 'رصيد الباقة',
            'created_at' => now(),
        ]);

        $teacher = $teacher ?? $this->makeActiveTeacher();
        $this->makeContract($teacher);
        $this->makeRate($teacher, ['amount' => 50]);

        $lesson = $this->makeLesson(
            $this->makeProgram(),
            $student,
            $teacher,
            array_merge(['subscription_id' => $subscription->id], $lessonAttrs),
        );

        return [$lesson, $teacher, $subscription];
    }

    protected function makeContract(Teacher $teacher, array $attrs = []): \App\Models\TeacherContract
    {
        return \App\Models\TeacherContract::create(array_merge([
            'teacher_id' => $teacher->id,
            'contract_type' => 'per_lesson',
            'start_date' => now()->subDays(30)->toDateString(),
            'monthly_salary' => null,
            'currency' => 'EGP',
            'status' => 'active',
        ], $attrs));
    }

    /**
     * سعر نشط — يبدا من 30 يوم مضي (سعر جاري).
     *
     * `makeRate($teacher, ['amount' => 50, 'rate_type' => 'monthly'])`.
     */
    protected function makeRate(Teacher $teacher, array $attrs = []): \App\Models\TeacherRate
    {
        return \App\Models\TeacherRate::create(array_merge([
            'teacher_id' => $teacher->id,
            'rate_type' => 'per_lesson',
            'amount' => 50,
            'currency' => 'EGP',
            'effective_from' => now()->subDays(30)->toDateString(),
            'effective_to' => null,
        ], $attrs));
    }

    /**
     * فترة مرتبات — الجدول **مشترك** مع مرتبات المعلمين.
     *
     * `makePeriod('2026-03-01', '2026-03-31')`.
     *
     * ⚠️ التاريخ بيتخزّن **نضيف** (`2026-03-31` مش `2026-03-31
     * 00:00:00`). السبب إن الـ cast `date` في الـ Model بيخزّن
     * الوقت، فلو الفترة اتخزّنت بالوقت والكود قارن بنص تاريخ،
     * المقارنة النصية بتضيّع آخر يوم.
     *
     * الـ production سيبّر (date column في SQLite بيخزّن نص صريح)،
     * والـ fixtures لازم تطابق عشان الاختبار يحاكي الحقيقة.
     */
    protected function makePeriod(string $start, string $end, array $attrs = []): \App\Models\PayrollPeriod
    {
        // نمرّر التاريخ زي ما هو (نص) مش Carbon — الـ cast هيحوّله
        $period = new \App\Models\PayrollPeriod;
        $period->setRawAttributes([
            'organization_id' => $this->org->id,
            'name' => 'رواتب '.substr($start, 0, 7),
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'open',
        ]);

        $period->save();

        foreach ($attrs as $key => $value) {
            \Illuminate\Support\Facades\DB::table('payroll_periods')
                ->where('id', $period->id)->update([$key => $value]);
        }

        return $period->fresh();
    }

    /**
     * سجل حضور — المصدر الوحيد لساعات المرتب.
     *
     * `makeAttendance($e, '2026-03-10', ['worked_hours' => 6])`.
     *
     * لاحظ إن `worked_hours` بيتكتب **مباشرة**. في الإنتاج بيوصل
     * من `AttendanceController::resolveHours()` (اللي بيفرض صفر على
     * الغائب)، بس هنا بنكتبه كما هو عشان نختبر الاحتساب نفسه.
     */
    protected function makeAttendance(Employee $employee, string $date, array $attrs = []): \App\Models\AttendanceRecord
    {
        return \App\Models\AttendanceRecord::create(array_merge([
            'organization_id' => $this->org->id,
            'employee_id' => $employee->id,
            'date' => $date,
            'status' => 'present',
            'worked_hours' => 0,
        ], $attrs));
    }

    protected function linkTeacher(Program $program, Teacher $teacher, array $attrs = []): void
    {
        DB::table('program_teacher')->insert(array_merge([
            'program_id' => $program->id,
            'teacher_id' => $teacher->id,
            'is_primary' => false,
            'rate_multiplier' => 1.0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attrs));
    }
}
