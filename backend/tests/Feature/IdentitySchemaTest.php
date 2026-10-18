<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Organization;
use App\Models\ParentModel;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⭐⭐⭐ أساس الهوية — المخطط اللي فات كل حاجة قبل كده.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️⚠️ الخارطة كاتبة «add» لكل حاجة — والحقيقة مختلفة
 *
 * 🚫 الخارطة بتقول «add» وهي **موجودة أصلاً**:
 *
 *   • `users.must_change_password`  ← PR 0.4
 *   • `users.password_changed_at`   ← PR 0.4
 *   • `students.user_id`            ← موجودة
 *   • `employees.user_id`           ← موجودة
 *   • `teachers.user_id`            ← موجودة (+ بقت nullable في PR 0.2)
 *   • `parents.user_id`             ← موجودة
 *
 * 🚫 واللي **مفقودش** فعلاً هو ده بس:
 *
 *   • `status`: ناقصين `invited` و `archived`
 *   • **قيد تفرّد** على `email` و `phone` — وده الـlogin bug الحقيقي
 *   • **قيد تفرّد** على `user_id` في كل جدول ملف شخصي
 *   • جدول `invitations`
 *   • `is_parent` بقى مشتقّ
 *
 * ⭐ ⭐⭐ عدّنا اللي موجود قبل ما نكتب migration. أول واحد في
 * (`doctrine/dbal`) مش مثبّت، ولو افترضنا الغياب كان هبنكتب
 * migration بتكرّر أعمدة موجودة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ الـlogin كان غلط في الداتابيز نفسها
 *
 * `AuthController::login` بيعمل:
 *
 *     User::where('email', $email)->first();
 *
 * ⭐ مافيش `organization_id` في الفلتر. والقيد الموجود كان
 * `unique(['organization_id', 'email'])` — يعني **نفس الإيميل في
 * منظمتين مسموح**.
 *
 * ⭐⭐ النتيجة: حد بيسجّل دخول بإيميل عنده حساب في الأكاديمية A،
 * الحساب بيرجّع الأول في الترتيب **من غير أي تحذير**. وممكن
 * يدخل على حساب حد تاني خالص.
 *
 * ⭐ الـschema ما كانتش بتمنعه — الـschema كانت بتسمح بيه.
 *
 * @see \App\Http\Controllers\Api\AuthController::login
 */
class IdentitySchemaTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① حالة الحساب
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ الحالات الأربعة اللي الخطة بتطلبها.
     *
     * ⭐ `invited` = اتبعت دعوة وما قبلهاش. و `archived` = خرج من
     * الأكاديمية بس بياناته لسه محفوظة (مرتبات قديمة، فواتير).
     *
     * ⭐ الفصل بين `suspended` و `archived` مهم: **`suspended` بيقفل
     * الدخول**، و `archived` بيقفل الدخول **و** بيخفي الحساب من
     * القوائم. لمّا الاتنين same، مينفعش نمسح حساب خد فلوس.
     */
    public function test_status_accepts_the_four_lifecycle_values(): void
    {
        $column = collect(Schema::getColumns('users'))
            ->firstWhere('name', 'status');

        $this->assertNotNull($column, "مافيش عمود status في users");

        // ⭐ في SQLite الـenum مبيترجمش، فبنفحص الدوال بس.
        // ⭐ السطر ده بيفشل في Postgres لو القيم ناقصة.
        $allowed = $column['allowed_values'] ?? null;

        if ($allowed !== null) {
            foreach (['invited', 'active', 'suspended', 'archived'] as $status) {
                $this->assertContains(
                    $status,
                    array_map('strval', $allowed),
                    "قيمة «{$status}» مش موجودة في `users.status`"
                );
            }

            return;
        }

        // ⭐ SQLite: نثبت إن القيم بتتقبل فعلياً
        foreach (['invited', 'active', 'suspended', 'archived'] as $status) {
            $user = $this->makeUser();
            $user->update(['status' => $status]);

            $this->assertSame(
                $status,
                $user->fresh()->status,
                "الحالة «{$status}» مرفضة"
            );
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ② تفرّد المعرّفات — الـlogin bug
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **نفس الإيميل مرتين = خطأ داتابيز، مش صدفة.**
     *
     * ⭐ قبل كده `unique(['organization_id','email'])` كان بيسمح
     * لنفس الإيميل في أكاديميتين. والـlogin بيطلب
     * `where('email', …)` **من غير** `organization_id` — فالنتيجة
     * إن أي حد عنده إيميل مكرر بيدخل على حساب **حد تاني**.
     */
    public function test_the_same_email_cannot_be_taken_twice(): void
    {
        $this->makeUser('الأول', 'dup@example.test');

        // ⭐⭐ **أكاديمية تانية فعلاً** — مش نفس المؤسسة.
        //
        // ⚠️ أول نسخة من الاختبار ده كانت بتعمل `update` على
        // `organization_id` **بعد** الإنشاء، فالحالتين كانتوا في
        // نفس المؤسسة والقيد المركّب كان هيمسكهم — والاختبار يعدّي
        // **غلط**. ده بالظبط السيناريو اللي بنفحصه.
        $other = Organization::create([
            'name' => 'أكاديمية تانية',
            'slug' => 'other-academy',
            'default_currency' => 'EGP',
            'default_timezone' => 'Africa/Cairo',
            'status' => 'active',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        User::create([
            'organization_id' => $other->id,
            'name' => 'في أكاديمية تانية',
            'email' => 'dup@example.test',
            'password' => bcrypt('password'),
            'status' => 'active',
            'must_change_password' => false,
        ]);
    }

    public function test_the_same_phone_cannot_be_taken_twice(): void
    {
        $first = $this->makeUser();
        $first->update(['phone' => '01000001111']);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->makeUser()->update(['phone' => '01000001111']);
    }

    /**
     * ⭐⭐ **`null` مش متعارض** — و ده مقصود مش إهمال.
     *
     * ⭐ في SQL، `NULL` مش بيساوي `NULL` في قيد التفرّد. يعني
     * أكتر من حساب من غير موبايل **عادي ومش بيكسر** القيد.
     *
     * ⭐ لو استبدلنا `null` بسلسلة فاضية لكسرنا الـfixtures كلها
     * وكل حساب مالهوش موبايل.
     */
    public function test_many_users_may_have_no_phone(): void
    {
        $first = $this->makeUser();
        $first->update(['phone' => null]);

        $second = $this->makeUser();
        $second->update(['phone' => null]);

        $this->assertNull($first->fresh()->phone);
        $this->assertNull($second->fresh()->phone);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ تفرّد الملف الشخصي
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **حساب واحد = ملف شخصي واحد.**
     *
     * ⭐ من غير القيد ده ممكن تعمل حسابينTeachers لنفس الـ`user_id`
     * — والـ`authorizeOwnership()` شغال بالعلاقة، فالدخول ممكن
     * يبقى غامض: `/teachers/{id}` بتاع أنهي واحد؟
     *
     * ⭐ والـAPI بيرجّعProfile بتاع الـuser — فلو اتنين، الـ
     * `AuthController::profile` هيرجّع **واحد** والـID التاني
     * مش موجود في أي رد. سجل **من غير واجهة**.
     */
    public function test_a_user_cannot_be_linked_to_two_teacher_records(): void
    {
        $user = $this->makeUser();

        $this->makeTeacher(['user_id' => $user->id]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->makeTeacher(['user_id' => $user->id, 'display_name' => 'تاني']);
    }

    /** ⭐ نفس الفكرة لأولياء الأمور */
    public function test_a_user_cannot_be_linked_to_two_parent_profiles(): void
    {
        $user = $this->makeUser();

$this->makeParentProfile($user);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

$this->makeParentProfile($user, ['name' => 'تاني']);
    }

    /** ⭐ ونفس الفكرة للطلاب — الحساب في PR 1.12 */
    public function test_a_user_cannot_be_linked_to_two_students(): void
    {
        $user = $this->makeUser();

        $this->makeStudent(['user_id' => $user->id]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->makeStudent(['user_id' => $user->id]);
    }

    /** ⭐ ونفس الفكرة للموظفين */
    public function test_a_user_cannot_be_linked_to_two_employees(): void
    {
        $user = $this->makeUser();

        $this->makeEmployee(['user_id' => $user->id]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->makeEmployee(['user_id' => $user->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ `is_parent` بقى مشتقّ
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **`is_parent` مش عمود بيانات — ده سؤال.**
     *
     * ⭐ ⭐⭐ **الخطر اللي بيسببه العمود:**
     *
     * `users.is_parent` و `student_phones.is_parent` **نفس الاسم
     * ونفس النوع (boolean) وعكس المعنى تماماً**:
     *
     *   • `users.is_parent`              = الحساب **هو** ولي أمر
     *   • `student_phones.is_parent`     = الرقم **لـ** ولي أمر
     *
     * ⭐ وأخطر من كده: العمودين **بيتحدّثوا في نفس الوقت** ولا
     * الداتابيز مش راضية. `ParentLinker` بيكتب `phones.is_parent`
     * و `SeedParentAccounts` بيكتب `users.is_parent` — ولو واحد
     * نسي، الحساب يولي «مش ولي أمر» وفي نفس الوقت عنده رقم
     * مسجّل كرقم ولي أمر.
     *
     * ⭐ ⭐ الجواب الوحيد: **الولي الأمر هو اللي عنده ملف
     * `parents`.** مش أنه حد كتب `true`.
     */
    public function test_being_a_parent_is_derived_from_the_parent_profile(): void
    {
        $user = $this->makeUser();

        $this->assertFalse($user->fresh()->is_parent, 'حساب بلا ملف ولي أمر مش ولي أمر');

$this->makeParentProfile($user, ['name' => 'الأب']);

        $this->assertTrue($user->fresh()->is_parent, 'اللي عنده ملف ولي أمر هو ولي أمر');
    }

    /**
     * ⭐⭐⭐ **`is_parent` مش قابل للكتابة بالعمود anymore.**
     *
     * ⭐ قبل كده `User::update(['is_parent' => true])` كان بيساوي
     * «خلّيه ولي أمر». وده كان بيسمح لأي حد عنده `students.edit`
     * إنه يعمل أي حساب ولي أمر بجملة واحدة — وفي نفس الوقت مافيش
     * `parents` record، فكل حاجة بتعمل `where('user_id')` عليه
     * هترجّع **فارغة**.
     */
    public function test_the_users_is_parent_column_cannot_be_written_anymore(): void
    {
        $user = $this->makeUser();

        // ⭐ الكتابة بتتجاهل بصمت — مش بترمي
        $user->update(['is_parent' => true]);

        $this->assertFalse(
            $user->fresh()->is_parent,
            'الكتابة المباشرة في `is_parent` لسه بتأثر — العمود لازم يتقرا بس'
        );

        // ⭐ والعمود نفسه موجود (الشيل جاي في migration بعدين)
        $this->assertTrue(
            Schema::hasColumn('users', 'is_parent'),
            'العمود اتشال قبل ما الاستعمالات تتحدّث — ده مش مقصود في PR 1.3'
        );
    }

    /**
     * ⭐⭐ وللي الأمر حقه في **بوابة ولي الأمر** — الحارس نفسه.
     */
    public function test_the_parent_guard_follows_the_derived_value(): void
    {
        $user = $this->makeUser();

        $this->getJsonAs('/api/parent/children', $user)->assertForbidden();

$this->makeParentProfile($user, ['name' => 'الأب']);

        // ⭐ دلوقتي بيفتح — بس مافيش أبناء، فبيرجّع 200 بقائمة فاضية
        $this->getJsonAs('/api/parent/children', $user->fresh())->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ العلاقات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ `User::employee()` **مش موجودة** — و`employees.user_id`
     * موجودة من زمان.
     *
     * ⭐ الـmodel كان بيسيب الموظف من غير علاقة. يعني مافيش طريقة
     * سهلة تسأل «حساب ده موظف؟» غير استعلام يدوي — وده اللي
     * يخلي حد يكتب الاستعلام **غلط** مرة واحدة.
     */
    public function test_the_user_knows_its_employee_profile(): void
    {
        $user = $this->makeUser();

        $employee = $this->makeEmployee(['user_id' => $user->id]);

        $this->assertTrue(
            $user->fresh()->employee !== null,
            'مافيش علاقة user → employee — accountants وreceptionists مالهمش ملف شخصي في الـAPI'
        );

        $this->assertSame(
            $employee->id,
            $user->fresh()->employee->id,
            'العلامة بترجّع الملف الغلط'
        );
    }

    /** ⭐ كل العلاقات الأربعة بتشتغل */
    public function test_every_profile_relationship_resolves(): void
    {
        $user = $this->makeUser();

        $student = $this->makeStudent(['user_id' => $user->id]);
        $this->assertSame($student->id, $user->fresh()->student->id, 'الطالب');

        $teacher = $this->makeTeacher(['user_id' => $user->id]);
        $this->assertSame($teacher->id, $user->fresh()->teacher->id, 'المعلم');

        $employee = $this->makeEmployee(['user_id' => $user->id]);
        $this->assertSame($employee->id, $user->fresh()->employee->id, 'الموظف');

$parent = $this->makeParentProfile($user, ['name' => 'الأب']);
        $this->assertSame($parent->id, $user->fresh()->parentProfile->id, 'ولي الأمر');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ جدول الدعوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ جدول `invitations` — أساس PR 1.4.
     *
     * ⭐ **مافيش أي حاجة بتبعت دعوة لحساب** — الحسابات كلها
     * بتتعمل بكلمة مرور `password`. ده اللي كان PR 0.4 بيقفله
     * (`must_change_password`)، بس الإصلاح جزئي: الحساب لسه
     * **بيتعمل** بنفس كلمة المرور.
     *
     * ⭐ الجدول ده مش «مستقبل» — هو اللي بيخلّي PR 1.4 ممكن.
     */
    public function test_the_invitations_table_exists_with_the_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('invitations'), 'مافيش جدول invitations');

        foreach ([
            'id', 'email', 'token_hash', 'profile_type', 'profile_id',
            'role_id', 'invited_by', 'expires_at', 'accepted_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('invitations', $column),
                "جدول invitations ناقصه «{$column}»"
            );
        }
    }

    /**
     * ⭐⭐ التوكن بيتخزّن **مُهشّر**.
     *
     * ⭐ العمود اسمه `token_hash` مش `token` — وده **الاسم** هو
     * التوثيق. لو رجعنا لـ`token`، أي حد يقرا الجدول يقدر يسجّل
     * دخول بحسابات الناس.
     *
     * ⭐ الطول 64 **hash** (SHA-256 hex) — الـtoken نفسه 64
     * **bytes** raw (`random_bytes(64)`)، hex بتاعه 128 حرف.
     */
    public function test_the_invitation_token_is_hashed_and_long_enough(): void
    {
        $this->assertFalse(
            Schema::hasColumn('invitations', 'token'),
            'العمود اسمه `token` مش `token_hash` — التوكن لازم يتخزّن مُهشّر'
        );

        $column = collect(Schema::getColumns('invitations'))->firstWhere('name', 'token_hash');

        $this->assertNotNull($column, 'مافيش عمود token_hash');

        // ⭐ char length: لازم يسع SHA-256 hex (128)
        $length = $column['length'] ?? null;

        if ($length !== null) {
            $this->assertGreaterThanOrEqual(
                128,
                (int) $length,
                "عمود token_hash طوله {$length} — أقل من 128 (hex لـSHA-256)"
            );
        }
    }

    /**
     * ⭐⭐ الدعوة لازم ترتبط بحساب موجود — دعوة لحساب مش موجود
     * معناها **صلاحية Wider** لمن حد يقدر ياخد الـid بتاع حد تاني.
     */
    public function test_an_invitation_points_at_a_real_user_and_role(): void
    {
        $inviter = $this->makeUserWithRole('admin', ['users.roles.assign']);
        $roleId = DB::table('roles')->where('name', 'teacher')->value('id');

        $id = DB::table('invitations')->insertGetId([
            'email' => 'new@example.test',
            'token_hash' => hash('sha256', str_repeat('a', 64)),
            'profile_type' => 'teacher',
            'profile_id' => null,
            'role_id' => $roleId,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(3),
            'accepted_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('invitations')->find($id);

        $this->assertSame($inviter->id, $row->invited_by);
        $this->assertNull($row->accepted_at, 'دعوة جديدة ما فيش قبول لسه');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑦ الرجوع — الـacceptance بيقول «migrate/rollback work»
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **الـmigration بترجع لورا.**
     *
     * ⭐⚠️ دي أكتر خطوة بتتنسي في المشروع، وسببها إن `down()`
     * لو اتكتب صح بيشتغل على قاعدة فاضية (بعد `migrate:fresh`)
     * وبيعدّي، وبيفشل **بس** على قاعدة فيها بيانات.
     *
     * ⭐ الاختبار ده بيبني البيانات **قبل** الـ`down()` عشان يفحص
     * الحالة الصعبة.
     */
    public function test_the_migration_rolls_back_cleanly(): void
    {
        // ⭐ بيانات قبل الـrollback — عشان القوود تكون فيها صفوف
        $this->makeUser('موجود', 'kept@example.test')->update(['phone' => '01000003333']);

        $migration = require database_path('migrations/2026_10_15_000001_identity_schema_foundation.php');

        $migration->down();

        $this->assertFalse(
            Schema::hasTable('invitations'),
            'الـdown() ما شالتش جدول invitations'
        );

        $migration->up();

        $this->assertTrue(
            Schema::hasTable('invitations'),
            'الـup() ما رجّعتش جدول invitations'
        );

        // ⭐⭐ والبيانات اللي كانت موجودة **لسه موجودة**.
        //
        // ⚠️ أول نسخة من السطر ده كانت بتعمل `makeUser(...)` — أي
        // بتعمل حساب **جديد** بنفس الإيميل. والقيد العام الجديد
        // رفضها، والاختبار فشل بـ`UniqueConstraintViolationException`
        // — والسبب بعيد تماماً عن اللي بيختبره. المطلوب **قراءة**
        // مش إنشاء.
        $survivor = User::where('email', 'kept@example.test')->first();

        $this->assertNotNull($survivor, 'الـdown() مسح الحساب');

        $this->assertSame('01000003333', $survivor->phone, 'الـdown() مسح البيانات');
    }
}