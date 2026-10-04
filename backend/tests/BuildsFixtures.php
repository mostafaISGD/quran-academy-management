<?php

namespace Tests;

use App\Models\Branch;
use App\Models\Lesson;
use App\Models\Level;
use App\Models\Organization;
use App\Models\Program;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Employee;
use App\Models\Parent as ParentModel;
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
        ]);
    }

    /**
     * حساب بصلاحية معينة.
     *
     * بنعمل Role و Permission على-the-fly بدل ما نستدعي
     * SeedRolesPermissions، لأن الاختبار عايز أقل قدر ممكن من
     * البنية — لو الـ seeder اتغيّر، الاختبار مفروض ما يتأثرش.
     */
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