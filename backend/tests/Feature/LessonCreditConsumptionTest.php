<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonCreditAccount;
use App\Models\LessonCreditTransaction;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Services\LessonCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.3 — استهلاك رصيد الحصص (Lesson Credit Consumption).**
 *
 * ⭐ **المصدر للحقيقة** = `lesson_credit_transactions`؛
 * `current_balance` **مشتق/متزامن** (كاش). قبل PR ده: الحساب
 * كان بيتفتح و**مفيش حاجة بتتخصم منه** — فالرصيد اللي ولي الأمر
 * بيشوفه كان دايماً 8 (أو أي رقم أولي) ومكانش بيتحرك.
 *
 * ⭐ **متى يُخصم؟** لما الحصة تتحوّل لنتيجة `deduct_credit =
 * true` — والقيمة دي **من لقطة السياسة** على الحصة نفسها
 * (`policy_snapshot` من PR 3.2)، فالتعديل بعدين ما بيغيّرش
 * التاريخ.
 *
 * ⭐ **السحب (overdraft) ممنوع افتراضياً** — وإلا الحصة بتخلص
 *andbox ما ينفعش تتصوّر في السالب.
 *
 * ⭐ القبول: اشتراك 8 حصص + 3 مكتملة + 1 غياب معلم = **رصيد 5**.
 */
class LessonCreditConsumptionTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول: 8 حصص − 3 مكتملة − (غياب معلم بلا خصم) = 5
    // ═══════════════════════════════════════════════════════════════

    public function test_a_subscription_of_eight_lessons_ends_with_five_after_three_completed_and_one_teacher_absence(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $account = $this->openCreditAccount($subscription, 8);

        // ⭐ 3 حصص بتخلص عادي (بتخصم)، وحصة رابعة غياب معلم (مش بتخصم).
        for ($i = 0; $i < 3; $i++) {
            $this->completeLesson($manager, $student, $subscription, 'in_progress');
        }

        $absence = $this->makeLessonFor($student, $subscription, 'confirmed');

        $this->postJsonAs(
            "/api/lessons/{$absence->id}/transition",
            ['to' => 'teacher_absent', 'reason' => 'المعلم تعذر عليه الحضور'],
            $manager,
        )->assertOk();

        $balance = app(LessonCreditService::class)->balance($account->id);

        $this->assertEquals(5.0, (float) $balance, 'الرصيد بعد 3 حصص مكتملة + غياب معلم لازم يكون 5');
    }

    public function test_a_teacher_absence_does_not_consume_a_lesson(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $account = $this->openCreditAccount($subscription, 8);

        $absence = $this->makeLessonFor($student, $subscription, 'confirmed');

        $this->postJsonAs(
            "/api/lessons/{$absence->id}/transition",
            ['to' => 'teacher_absent', 'reason' => 'ظرف طارئ'],
            $manager,
        )->assertOk();

        $this->assertEquals(
            8.0,
            (float) app(LessonCreditService::class)->balance($account->id),
            'غياب المعلم ما بيخصمش من رصيد الطالب (الافتراضي الآمن)'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ المصدر للحقيقة: الحركات
    // ═══════════════════════════════════════════════════════════════

    public function test_the_balance_is_derived_from_the_transactions(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $account = $this->openCreditAccount($subscription, 8);

        $this->completeLesson($manager, $student, $subscription, 'in_progress');

        $sum = (float) LessonCreditTransaction::where('credit_account_id', $account->id)->sum('quantity');

        $this->assertEquals(
            (float) app(LessonCreditService::class)->balance($account->id),
            $sum,
            'الرصيد لازم يكون مجموع الحركات (المصدر للحقيقة)'
        );

        $this->assertEquals(7.0, $sum);
    }

    public function test_consuming_a_lesson_records_the_lesson_and_the_reason(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $account = $this->openCreditAccount($subscription, 8);

        $lesson = $this->completeLesson($manager, $student, $subscription, 'in_progress');

        $transaction = LessonCreditTransaction::where('credit_account_id', $account->id)
            ->where('type', 'lesson_used')
            ->latest('id')
            ->first();

        $this->assertNotNull($transaction, 'مفيش حركة خصم مسجّلة');
        // ⭐ الكمية **سالبة** (خصم) — فمجموع الحركات = الرصيد.
        $this->assertEquals(-1.0, (float) $transaction->quantity);
        $this->assertEquals(7.0, (float) $transaction->balance_after);
        $this->assertSame($lesson->id, (int) $transaction->lesson_id);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ السحب المسموح / الممنوع
    // ═══════════════════════════════════════════════════════════════

    public function test_a_lesson_cannot_be_completed_without_enough_credit(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $account = $this->openCreditAccount($subscription, 1);

        // ⭐ الحصة الأولى بتاخد آخر حصة…
        $this->completeLesson($manager, $student, $subscription, 'in_progress');

        // ⭐… والتانية مش ليها رصيد — والسحب ممنوع افتراضياً.
        $second = $this->makeLesson(
            $this->makeProgram(),
            $student,
            $this->makeTeacher(),
            ['subscription_id' => $subscription->id, 'status' => 'in_progress'],
        );

        $r = $this->postJsonAs("/api/lessons/{$second->id}/transition", ['to' => 'completed'], $manager);

        $r->assertStatus(422);
        $this->assertSame('in_progress', $second->fresh()->status, 'الحصة اتغيّرت رغم رفض العملية');
        $this->assertEquals(
            0.0,
            (float) app(LessonCreditService::class)->balance($account->id),
            'الرصيد نزل تحت الصفر رغم إن السحب ممنوع'
        );
    }

    public function test_the_overdraft_is_allowed_when_the_setting_says_so(): void
    {
        Setting::set('lesson_credit_overdraft_allowed', true, $this->org->id);

        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $account = $this->openCreditAccount($subscription, 0);

        $this->completeLesson($manager, $student, $subscription, 'in_progress');

        $this->assertEquals(
            -1.0,
            (float) app(LessonCreditService::class)->balance($account->id),
            'السحب المسموح لازم يسمح بالرصيد السالب'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ الإرجاع
    // ═══════════════════════════════════════════════════════════════

    public function test_a_refund_gives_the_lesson_back(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $account = $this->openCreditAccount($subscription, 8);

        $lesson = $this->completeLesson($manager, $student, $subscription, 'in_progress');

        $this->assertEquals(7.0, (float) app(LessonCreditService::class)->balance($account->id));

        $rows = \App\Models\LessonCreditTransaction::where('credit_account_id', $account->id)->get(['type', 'lesson_id', 'quantity'])->toArray();
        app(LessonCreditService::class)->refund($lesson, $manager, 'تصحيح إداري');

        $this->assertEquals(
            8.0,
            (float) app(LessonCreditService::class)->balance($account->id),
            'الإرجاع لازم يرجّع الحصة للطالب'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ ذرّية العملية: المستحق + الخصم + الحالة مع بعض
    // ═══════════════════════════════════════════════════════════════

    public function test_a_refused_completion_does_not_leave_a_teacher_earning_behind(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $this->openCreditAccount($subscription, 0);

        // ⭐ معلم متعاقد عليه ب سعر — عشان المستحق يتسجّل فعلاً
        // (لولا كده الاختبار ما كانش بيثبت حاجة).
        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher);
        $this->makeRate($teacher, ['amount' => 50]);

        $lesson = $this->makeLesson(
            $this->makeProgram(),
            $student,
            $teacher,
            ['subscription_id' => $subscription->id, 'status' => 'in_progress'],
        );

        // ⭐ السحب ممنوع ⇒ الإكمال مرفوض…
        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)
            ->assertStatus(422);

        // ⭐… فمفيش **مستحق معلم** لحصة ما اتقبلتش. (المستحق
        // financial — لو اتسجّل لوحده بقى فيفلتر علىTv.)
        $this->assertSame(0, \App\Models\TeacherEarning::where('lesson_id', $lesson->id)->count());
        $this->assertSame('in_progress', $lesson->fresh()->status);
    }

    public function test_the_teacher_earning_is_created_when_the_completion_goes_through(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent();
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $this->openCreditAccount($subscription, 8);

        $teacher = $this->makeActiveTeacher();
        $this->makeContract($teacher);
        $this->makeRate($teacher, ['amount' => 50]);

        $lesson = $this->makeLesson(
            $this->makeProgram(),
            $student,
            $teacher,
            ['subscription_id' => $subscription->id, 'status' => 'in_progress'],
        );

        $this->postJsonAs("/api/lessons/{$lesson->id}/complete", [], $manager)->assertOk();

        $this->assertSame(
            1,
            \App\Models\TeacherEarning::where('lesson_id', $lesson->id)->count(),
            'المستحق لازم يتسجّل — ودي البيئة اللي بيخلّي الاختبار السابق حاسم'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ بوابة ولي الأمر تقرأ الرصيد المشتق
    // ═══════════════════════════════════════════════════════════════

    public function test_the_parent_portal_reads_the_derived_balance(): void
    {
        $manager = $this->makeUserWithRole('credit-manager', ['lessons.update']);
        $student = $this->makeStudent(['first_name' => 'خالد']);
        $subscription = $this->makeSubscription($this->makeProgram(), $student);
        $this->openCreditAccount($subscription, 8);

        $this->completeLesson($manager, $student, $subscription, 'in_progress');

        $parent = $this->makeParent();
        $student->parents()->attach($parent['profile']->id, [
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        $r = $this->getJsonAs('/api/parent/children', $parent['user']);

        $r->assertOk();

        $child = collect($r->json('children'))->firstWhere('student.id', $student->id);

        $this->assertNotNull($child, 'الابن مش ظاهر في البوابة');
        $this->assertEquals(
            7.0,
            (float) data_get($child, 'lesson_credit.balance'),
            'بوابة ولي الأمر بتعرض رصيد ثابت مش المشتق — الرد: '.json_encode($child, JSON_UNESCAPED_UNICODE)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /** حساب رصيد برصيد ابتدائي + حركة `initial` (زي ما الاشتراك بيفتحه). */
    private function openCreditAccount(Subscription $subscription, float $balance): LessonCreditAccount
    {
        $account = LessonCreditAccount::create([
            'student_id' => $subscription->student_id,
            'subscription_id' => $subscription->id,
            'credit_type' => 'regular',
            'current_balance' => $balance,
            'status' => 'active',
        ]);

        LessonCreditTransaction::create([
            'credit_account_id' => $account->id,
            'student_id' => $subscription->student_id,
            'subscription_id' => $subscription->id,
            'type' => 'initial',
            'quantity' => $balance,
            'balance_after' => $balance,
            'reason' => 'رصيد الباقة',
            'created_at' => now(),
        ]);

        return $account->fresh();
    }

    /** يحضّر حصة للطالب وبيبدأها (in_progress) — ويرجّعها. */
    private function completeLesson(User $manager, $student, Subscription $subscription, string $status): Lesson
    {
        $lesson = $this->makeLessonFor($student, $subscription, $status);

        $this->postJsonAs("/api/lessons/{$lesson->id}/transition", ['to' => 'completed'], $manager)
            ->assertOk();

        return $lesson->fresh();
    }

    /** ⭐ حصة جديدة للطالب على نفس الاشتراك. */
    private function makeLessonFor($student, Subscription $subscription, string $status): Lesson
    {
        return $this->makeLesson(
            $this->makeProgram(),
            $student,
            $this->makeTeacher(),
            ['subscription_id' => $subscription->id, 'status' => $status],
        );
    }

    /** @return array{user: User, profile: \App\Models\Parent} */
    private function makeParent(): array
    {
        $user = $this->makeUser();
        $profile = $this->makeParentProfile($user);

        return ['user' => $user->fresh(), 'profile' => $profile];
    }
}
