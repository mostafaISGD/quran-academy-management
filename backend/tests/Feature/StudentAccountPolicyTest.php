<?php

namespace Tests\Feature;

use App\Models\ParentalConsent;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentAge;
use App\Services\UserStatusService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ PR 1.12 — حسابات الطلاب والأطفال.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة: تحت 13 = **مفيش حساب**. فوق 13 = حساب، بس **مش
 * قبل موافقة مسجّلة من ولي الأمر**.
 *
 * ⭐ السبب عملي مش قانوني: طفل بينشئ حساب بإيميل أهله من غير ما
 * حد يعرف = هو ياخد الحساب كله + وعلى الأرجح الفاتورة.
 * والـconsent المسجّل بيخلي **الأهلي** هم اللي بيقرروا، ومين سحب
 * موافقته الـsuspend فوراً.
 *
 * ⭐ الـacceptance بتاع الـroadmap:
 *   • «مش ممكن حساب لطالب أقل من 13»
 *   • «طالب فوق 13 من غير موافقة مش بيتفعّل»
 *   • «سحب الموافقة بيلغي جلساته»
 */
class StudentAccountPolicyTest extends TestCase
{
    /** ⭐ تاريخ ميلاد يعطي عمر معيّن في 2026 */
    private function bornYearsAgo(int $years): string
    {
        return now()->subYears($years)->subDay()->toDateString();
    }

    // ═══════════════════════════════════════════════════════════
    // ① العمر
    // ═══════════════════════════════════════════════════════════

    public function test_the_age_calculation_uses_the_birth_date_not_the_creation_date(): void
    {
        $student = Student::create([
            'organization_id' => $this->org->id,
            'student_code' => 'AGE-1',
            'first_name' => 'أحمد',
            'last_name' => 'س',
            'date_of_birth' => $this->bornYearsAgo(12),
        ]);

        $this->assertSame(12, StudentAge::years($student));

        // ⭐ birthday edge: عمره 13 النهاردة بالظبط (ماشي قبل
        // تاريخ الميلاد بيوم واحد)
        $student->update(['date_of_birth' => now()->subYears(13)->addDay()->toDateString()]);

        $this->assertSame(12, StudentAge::years($student->fresh()), 'لسه 12 — birthday لسه ما وصلش');
    }

    public function test_a_student_without_a_birth_date_is_treated_as_a_minor(): void
    {
        $student = $this->makeStudent();

        // ⭐ مافيش تاريخ = مش معروف. الافتراضي **الأكثر تحفظاً**:
        // بيتعامل معاه على إنه صغير، فمافيش حساب لحد ما يتأكد.
        $this->assertTrue(StudentAge::isMinor($student));
    }

    // ═══════════════════════════════════════════════════════════
    // ② تحت 13 = مفيش حساب
    // ═══════════════════════════════════════════════════════════

    public function test_no_account_can_be_created_for_a_student_under_thirteen(): void
    {
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(10)]);

        $this->assertTrue(StudentAge::isMinor($student));

        // ⭐ الـguard على مستوى الخدمة: أي كود تاني يستدعيها
        // وبيحاول يعمل حساب بيتقفل — مش بس الـcontroller.
        $this->expectException(\DomainException::class);

        app(\App\Services\StudentAccountEligibility::class)->assertCanHaveAccount($student);
    }

    public function test_a_thirteen_year_old_can_get_an_account_but_only_after_consent(): void
    {
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(13)]);

        $this->assertFalse(StudentAge::isMinor($student));

        $eligibility = app(\App\Services\StudentAccountEligibility::class);

        // ⭐ فوق 13 وبلا موافقة = **مش مسموح** (مش محروم من
        // الحساب — محروم من التفعيل لحد ما ييجي ورق).
        $eligibility->assertCanHaveAccount($student);

        // ⭐ التفعيل بيتطلب **حساب + موافقة**. الحساب هنا اتعمل
        // (دعوة مستقلة قبل الـ13) بس لسه مش مفعّل.
        $account = $this->makeUser('طالب 13', 'student13a@example.test');
        $student->update(['user_id' => $account->id]);

        $this->assertFalse(
            $eligibility->canActivate($student->fresh()),
            'فوق 13 من غير موافقة لازم يكون غير قابل للتفعيل'
        );

        // ⭐ الموافقة بتفتح الباب
        $parent = $this->makeParentProfile($this->makeUser('ولي', 'p12@example.test'));

        ParentalConsent::create([
            'student_id' => $student->id,
            'parent_id' => $parent->id,
            'scope' => 'account_activation',
            'granted_at' => now(),
        ]);

        $this->assertTrue(
            app(\App\Services\StudentAccountEligibility::class)->canActivate($student->fresh())
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ③ سحب الموافقة بيلغي الجلسات
    // ═══════════════════════════════════════════════════════════

    public function test_revoking_the_consent_suspends_the_account_and_its_sessions(): void
    {
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(14)]);

        $parent = $this->makeParentProfile($this->makeUser('ولي', 'p13@example.test'));

        $consent = ParentalConsent::create([
            'student_id' => $student->id,
            'parent_id' => $parent->id,
            'scope' => 'account_activation',
            'granted_at' => now(),
        ]);

        $user = $this->makeUser('الطالب', 'student13@example.test');
        $token = $user->createToken('test')->plainTextToken;
        $student->update(['user_id' => $user->id]);

        $this->assertSame(1, $user->fresh()->tokens()->count());

        $staff = $this->makeUserWithRole('staff', ['students.update']);

        app(\App\Services\StudentAccountEligibility::class)
            ->revoke($consent, $staff);

        $consent->refresh();

        $this->assertNotNull($consent->revoked_at, 'الموافقة اتسحبت');
        $this->assertSame($staff->id, (int) $consent->revoked_by);

        // ⭐⭐ الحساب اتقفل
        $this->assertSame('suspended', $user->fresh()->status);

        // ⭐⭐ والتوكن اتشال = مافيش جلسة شغّالة
        $this->assertSame(0, $user->fresh()->tokens()->count());
    }

    public function test_the_consent_history_is_kept_after_revocation(): void
    {
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(14)]);
        $parent = $this->makeParentProfile($this->makeUser('ولي', 'p14@example.test'));

        $consent = ParentalConsent::create([
            'student_id' => $student->id,
            'parent_id' => $parent->id,
            'scope' => 'account_activation',
            'granted_at' => now(),
        ]);

        app(\App\Services\StudentAccountEligibility::class)
            ->revoke($consent, $this->makeUserWithRole('staff', ['students.update']));

        // ⭐ الصف بيفضل — «مين سحل إمتى» سؤال لازم يبقى
        // ليه جواب بعد سنة.
        $this->assertSame(1, DB::table('parental_consents')
            ->where('student_id', $student->id)
            ->count());
    }

    // ═══════════════════════════════════════════════════════════
    // ⑤ البوابة الحقيقية: الدعوة
    // ═══════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **الدعوة** نفسها بترفض الطالب القاصر.
     *
     * ⭐ الفحص في `InvitationService::linkProfile()` — مش في
     * controller — لأن دي البوابة الوحيدة اللي بتربط حساب بملف
     * طالب، وأي استدعاء تاني بيمرّ من هي.
     */
    public function test_an_invitation_for_a_minor_student_is_rejected(): void
    {
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(9)]);

        $inviter = $this->makeUserWithRole('reception', ['users.create']);

        $token = app(\App\Services\InvitationService::class)->create(
            ['email' => 'minor@example.test', 'profile_type' => 'student', 'profile_id' => $student->id],
            $inviter
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(\App\Services\InvitationService::class)->accept(
            $token,
            'NewPassword123!',
            'minor'
        );
    }

    public function test_an_invitation_for_a_thirteen_year_old_is_accepted(): void
    {
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(13)]);

        $inviter = $this->makeUserWithRole('reception', ['users.create']);

        $token = app(\App\Services\InvitationService::class)->create(
            ['email' => 'teen@example.test', 'profile_type' => 'student', 'profile_id' => $student->id],
            $inviter
        );

        app(\App\Services\InvitationService::class)->accept(
            $token,
            'NewPassword123!',
            'teen'
        );

        $this->assertNotNull(
            $student->fresh()->user_id,
            'طالب 13 سنة اتقبلت دعوته — الحساب اتربط'
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ④ دور الطالب: قراءة لنفسه بس
    // ═══════════════════════════════════════════════════════════

    public function test_the_student_role_is_read_only(): void
    {
        // ⭐ الكتالوج في الـseeder (`SeedRolesPermissions::ROLES`)
        // والاختبارات بتقراه من غير ما تشغّل seeder.
        $roles = \Database\Seeders\SeedRolesPermissions::ROLES;

        $this->assertArrayHasKey('student', $roles, 'دور الطالب مش في الكتالوج');

        $studentRole = $roles['student'];

        // ⭐⭐ **قراءة فقط** — مافيش أي صلاحية كتابة في الكتالوج
        foreach ($studentRole as $permission) {
            $this->assertStringNotContainsString(
                '.create',
                $permission,
                "صلاحية كتابة «{$permission}» في دور الطالب"
            );
            $this->assertStringNotContainsString(
                '.update',
                $permission,
                "صلاحية كتابة «{$permission}» في دور الطالب"
            );
            $this->assertStringNotContainsString(
                '.delete',
                $permission,
                "صلاحية كتابة «{$permission}» في دور الطالب"
            );
            $this->assertStringNotContainsString(
                'manage',
                $permission,
                "صلاحية إدارة «{$permission}» في دور الطالب"
            );
        }

        // ⭐ وبيلاقي program اللي متاح له
        $this->assertContains('programs.view', $studentRole);
    }

    /** ⭐ حصة جاهزة (برنامج + طالب + معلم) — لتجربة رموز الدخول */
    protected function readyLesson(): \App\Models\Lesson
    {
        $program = $this->makeProgram();

        return $this->makeLesson(
            $program,
            $this->makeStudent(),
            $this->makeTeacher(),
        );
    }

    // ═══════════════════════════════════════════════════════════
    // ⑥ دخول الحصة تحت 13 (بلا حساب)
    // ═══════════════════════════════════════════════════════════

    /**
     * ⭐ الطالب تحت 13 مالوش حساب — فدخول الحصة برمز من ولي الأمر.
     *
     * ⭐ الاختبارات هنا على **الخدمة** مش الـHTTP: الـservice هي
     * اللي فيها القاعدة (استعمال واحد، انتهاء، حصة واحدة).
     */
    public function test_a_join_token_works_once(): void
    {
        $lesson = $this->readyLesson();
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(8)]);
        $teacher = $this->makeUserWithRole('teacher', ['lessons.view']);

        $service = app(\App\Services\LessonJoinTokenService::class);

        $issued = $service->issue($lesson, $student, $teacher);

        $this->assertSame(6, strlen($issued['pin']), 'الرمز المختصر 6 خانات');

        $ok = $service->redeem($issued['pin'], $lesson->id);

        $this->assertTrue($ok['ok'], 'الرمز المفروض يشتغل');
        $this->assertSame($student->id, $ok['student_id']);

        // ⭐⭐ **مرة واحدة** — تاني استعمال مرفوض
        $again = $service->redeem($issued['pin'], $lesson->id);

        $this->assertFalse($again['ok'], 'الرمز اتقفل بعد أول استعمال');
    }

    public function test_a_join_token_does_not_work_for_another_lesson(): void
    {
        $lesson = $this->readyLesson();
        $other = $this->readyLesson();

        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(8)]);
        $teacher = $this->makeUserWithRole('teacher', ['lessons.view']);

        $service = app(\App\Services\LessonJoinTokenService::class);

        $issued = $service->issue($lesson, $student, $teacher);

        $this->assertFalse(
            $service->redeem($issued['pin'], $other->id)['ok'],
            'الرمز فتح حصة تانية — ثغرة'
        );
    }

    public function test_an_expired_join_token_is_rejected(): void
    {
        $lesson = $this->readyLesson();
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(8)]);
        $teacher = $this->makeUserWithRole('teacher', ['lessons.view']);

        $service = app(\App\Services\LessonJoinTokenService::class);

        $issued = $service->issue($lesson, $student, $teacher);

        $this->travel(2)->hours();

        $this->assertFalse(
            $service->redeem($issued['pin'], $lesson->id)['ok'],
            'رمز منتهي اتقبل'
        );
    }

    public function test_the_raw_token_is_never_stored(): void
    {
        $lesson = $this->readyLesson();
        $student = $this->makeStudent(['date_of_birth' => $this->bornYearsAgo(8)]);
        $teacher = $this->makeUserWithRole('teacher', ['lessons.view']);

        $issued = app(\App\Services\LessonJoinTokenService::class)
            ->issue($lesson, $student, $teacher);

        $stored = (string) DB::table('lesson_join_tokens')
            ->where('lesson_id', $lesson->id)
            ->value('token_hash');

        $this->assertSame(hash('sha256', $issued['token']), $stored);
        $this->assertNotSame(
            $issued['token'],
            $stored,
            'التوكن الخام متخزّن — نفس خطأ الـinvitations'
        );
    }
}
