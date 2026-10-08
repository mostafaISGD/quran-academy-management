<?php

namespace Tests\Feature;

use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\Invoice;
use App\Models\Program;
use App\Models\Student;
use App\Models\StudentLedgerEntry;
use App\Models\Subscription;
use Tests\TestCase;

/**
 * ⭐⭐ الاشتراك والفاتورة عند دخول مجموعة + النقل + قفل الباقة.
 *
 * ليه ملف لوحده؟
 *
 * كود «ادخل الطالب» كان **مكرر** في مكانين (`GroupController@admit`
 * و `WaitlistController@admit`) والنسخة كانت **ناقصة**: مفيش
 * فاتورة خالص. والإضافة اليدوية (`addMember`) كانت بتعمل عضوية
 * بس — فالطالب بيدفع في وحدة وبياخد حصص في التانية.
 *
 * ⭐ بعد كده كلهم بيروحوا على `GroupEnrollmentService` — مصدر واحد.
 *
 * ⭐ القرارات اللي الاختبارات دي بتقفلها:
 *
 *  ① دخول أي طالب = اشتراك شهري + **فاتورة في نفس اللحظة**
 *  ② الباقة على **المجموعة** (مش كل طالب لوحده)
 *  ③ تغيير باقة مجموعة فيها أعضاء = **ممنوع**
 *  ④ نقل طالب ليه اشتراك شغال أو فاتورة مفتوحة = **ممنوع**
 */
class GroupEnrollmentTest extends TestCase
{
    private function admin()
    {
        return $this->makeUserWithRole('admin', ['groups.manage', 'groups.view']);
    }

    private function receptionist()
    {
        return $this->makeUserWithRole('receptionist', ['groups.view']);
    }

    // ============================================================
    // ① الدخول = اشتراك + فاتورة
    // ============================================================

    public function test_adding_a_member_creates_the_subscription_and_the_invoice(): void
    {
        $program = $this->makeProgram();
        $plan = $this->makePlan($program, ['price' => 350]);
        $group = $this->makeGroup(['capacity' => 5], $program);
        $student = $this->makeStudent();

        $r = $this->postJsonAs("/api/groups/{$group->id}/members", [
            'student_id' => $student->id,
        ], $this->admin());

        $r->assertCreated();

        // ① اشتراك شهري بسعر الباقة
        $sub = Subscription::where('student_id', $student->id)->first();
        $this->assertNotNull($sub);
        $this->assertSame('monthly', $sub->billing_type);
        $this->assertSame('active', $sub->status);
        $this->assertSame('350.00', $sub->price);
        $this->assertSame($plan->id, $sub->plan_id);

        // ② فاتورة في نفس اللحظة
        $invoice = Invoice::where('student_id', $student->id)->first();
        $this->assertNotNull($invoice, 'الفاتورة لازم تتعمل مع الاشتراك');
        $this->assertSame('issued', $invoice->status);
        $this->assertSame('350.00', $invoice->total);
        $this->assertSame('350.00', $invoice->balance_due);
        $this->assertSame($sub->id, $invoice->subscription_id);

        // ③ سطر في كشف حساب الطالب — من غيره رصيده مش هيتحدث
        $ledger = StudentLedgerEntry::where('invoice_id', $invoice->id)->first();
        $this->assertNotNull($ledger);
        $this->assertSame('350.00', $ledger->debit);
        $this->assertSame('350.00', $ledger->balance_after);

        // ④ الرد بيقول الواجهة إيه اللي اتعمل
        $r->assertJsonPath('subscription_created', true);
        $r->assertJsonPath('invoice_created', true);
    }

    public function test_a_student_with_an_active_subscription_gets_no_second_one(): void
    {
        $program = $this->makeProgram();
        $this->makePlan($program, ['price' => 350]);
        $group = $this->makeGroup([], $program);
        $student = $this->makeStudent();

        $existing = $this->makeSubscription($program, $student);

        $r = $this->postJsonAs("/api/groups/{$group->id}/members", [
            'student_id' => $student->id,
        ], $this->admin());

        $r->assertCreated();
        $r->assertJsonPath('subscription_created', false);
        $r->assertJsonPath('invoice_created', false);
        $r->assertJsonPath('subscription_id', $existing->id);

        $this->assertSame(1, Subscription::where('student_id', $student->id)->count());
        $this->assertSame(0, Invoice::where('student_id', $student->id)->count());
    }

    public function test_no_invoice_when_there_is_no_price_to_charge(): void
    {
        // ⭐ اشتراك سعره 0 (باقة مجانية) → ما نعملش فاتورة
        // بـ «0 ج.م». بتبوّظ الجداول وتلخبط التقارير.
        //
        // ⚠️ بنثبّت الباقة المجانية على المجموعة صراحةً، لأن
        // الـ migration بيعمل باقات **مشتركة** بسعر > 0 —
        // فالتركيز على «مفيش باقة خالص» مش بيوصلCase ده.
        $program = $this->makeProgram();
        $free = $this->makePlan($program, ['price' => 0, 'name' => 'باقة مجانية']);

        $group = $this->makeGroup(['package_id' => $free->id], $program);
        $student = $this->makeStudent();

        $this->postJsonAs("/api/groups/{$group->id}/members", [
            'student_id' => $student->id,
        ], $this->admin())->assertCreated();

        $sub = Subscription::where('student_id', $student->id)->first();
        $this->assertNotNull($sub, 'الاشتراك بيتعمل حتى لو مجاني');
        $this->assertSame('0.00', $sub->price);

        $this->assertSame(0, Invoice::where('student_id', $student->id)->count());
    }

    // ============================================================
    // ② الباقة على المجموعة
    // ============================================================

    public function test_the_group_package_wins_over_the_program_package(): void
    {
        $program = $this->makeProgram();
        $this->makePlan($program, ['price' => 100, 'name' => 'باقة البرنامج']);
        $pinned = $this->makePlan($program, ['price' => 750, 'name' => 'باقة المجموعة']);

        $group = $this->makeGroup(['package_id' => $pinned->id], $program);
        $student = $this->makeStudent();

        $this->postJsonAs("/api/groups/{$group->id}/members", [
            'student_id' => $student->id,
        ], $this->admin())->assertCreated();

        $sub = Subscription::where('student_id', $student->id)->first();
        $this->assertSame($pinned->id, $sub->plan_id);
        $this->assertSame('750.00', $sub->price);
    }

    public function test_a_group_without_a_package_falls_back_to_the_program_one(): void
    {
        $program = $this->makeProgram();
        $plan = $this->makePlan($program, ['price' => 250]);
        $group = $this->makeGroup(['package_id' => null], $program);
        $student = $this->makeStudent();

        $this->postJsonAs("/api/groups/{$group->id}/members", [
            'student_id' => $student->id,
        ], $this->admin())->assertCreated();

        $this->assertSame($plan->id, Subscription::where('student_id', $student->id)->first()->plan_id);
    }

    public function test_a_non_monthly_package_is_never_used_for_a_group(): void
    {
        // ⭐ المجموعات **شهرية بس** قرار. الباقات المتاحة غير
        // الشهرية في المشروع: `per_lesson` و `custom`.
        // أي واحدة فيهم ما ينفعش تتاخد اشتراك شهري عليها.
        $program = $this->makeProgram();
        $perLesson = $this->makePlan($program, ['billing_type' => 'per_lesson', 'price' => 60]);
        $monthly = $this->makePlan($program, ['billing_type' => 'monthly', 'price' => 400]);

        $group = $this->makeGroup([], $program);
        $student = $this->makeStudent();

        $this->postJsonAs("/api/groups/{$group->id}/members", [
            'student_id' => $student->id,
        ], $this->admin())->assertCreated();

        $sub = Subscription::where('student_id', $student->id)->first();
        $this->assertSame($monthly->id, $sub->plan_id);
        $this->assertNotSame($perLesson->id, $sub->plan_id);
        $this->assertSame('monthly', $sub->billing_type);
    }

    // ============================================================
    // ③ قفل تغيير الباقة
    // ============================================================

    public function test_the_package_can_be_set_when_the_group_is_empty(): void
    {
        $program = $this->makeProgram();
        $a = $this->makePlan($program, ['price' => 100]);
        $b = $this->makePlan($program, ['price' => 200]);
        $group = $this->makeGroup(['package_id' => $a->id], $program);

        // ⚠️ `update()` بتطلب `program_id` و `name` — لازم نبعثهم
        $r = $this->putJsonAs("/api/groups/{$group->id}", [
            'program_id' => $program->id,
            'name' => $group->name,
            'package_id' => $b->id,
        ], $this->admin());

        $r->assertOk();
        $this->assertSame($b->id, $group->fresh()->package_id);
    }

    public function test_the_package_is_locked_while_the_group_has_members(): void
    {
        // ⭐ الباقة = **سعر** اشتراك كل عضو. تغييرها معناه تغيير
        // سعرهم من غير ما حد يوافق.
        $program = $this->makeProgram();
        $a = $this->makePlan($program, ['price' => 100]);
        $b = $this->makePlan($program, ['price' => 200]);
        $group = $this->makeGroup(['package_id' => $a->id], $program);
        $this->fillGroup($group, 3);

        $r = $this->putJsonAs("/api/groups/{$group->id}", [
            'program_id' => $program->id,
            'name' => $group->name,
            'package_id' => $b->id,
        ], $this->admin());

        $r->assertStatus(422);
        $r->assertJsonPath('message', 'مش مسموح بتغيير باقة المجموعة دلوقتي');
        $this->assertNotEmpty($r->json('blockers'));

        $this->assertSame($a->id, $group->fresh()->package_id);
    }

    public function test_saving_the_same_package_again_is_not_a_change(): void
    {
        // ⚠️ غير كده، تعديل أي حاجة تانية في المجموعة (الاسم مثلاً)
        // كان هيبقى مقفول بسبب الباقة حتى لو ما اتغيرتش.
        $program = $this->makeProgram();
        $a = $this->makePlan($program);
        $group = $this->makeGroup(['package_id' => $a->id], $program);
        $this->fillGroup($group, 2);

        $r = $this->putJsonAs("/api/groups/{$group->id}", [
            'program_id' => $program->id,
            'package_id' => $a->id,
            'name' => 'اسم جديد',
        ], $this->admin());

        $r->assertOk();
        $this->assertSame('اسم جديد', $group->fresh()->name);
    }

    public function test_editing_other_fields_still_works_on_a_locked_group(): void
    {
        $program = $this->makeProgram();
        $a = $this->makePlan($program);
        $group = $this->makeGroup(['package_id' => $a->id], $program);
        $this->fillGroup($group, 2);

        $this->putJsonAs("/api/groups/{$group->id}", [
            'program_id' => $program->id,
            'name' => $group->name,
            'description' => 'وصف جديد',
            'capacity' => 12,
        ], $this->admin())->assertOk();

        $this->assertSame('وصف جديد', $group->fresh()->description);
        $this->assertSame(12, $group->fresh()->capacity);
    }

    public function test_the_group_response_tells_the_ui_why_the_package_is_locked(): void
    {
        $program = $this->makeProgram();
        $a = $this->makePlan($program);
        $group = $this->makeGroup(['package_id' => $a->id], $program);
        $this->fillGroup($group, 2);

        $r = $this->getJsonAs('/api/groups', $this->admin());

        $r->assertOk();
        $g = collect($r->json('data'))->firstWhere('id', $group->id);

        $this->assertNotNull($g['package_lock'], 'الواجهة محتاجة تعرف القفل قبل ما تفتح التعديل');
        $this->assertSame($a->id, $g['package_id']);
        $this->assertSame($a->name, $g['package']['name']);
    }

    public function test_an_empty_group_reports_no_package_lock(): void
    {
        $program = $this->makeProgram();
        $group = $this->makeGroup([], $program);

        $r = $this->getJsonAs('/api/groups', $this->admin());
        $g = collect($r->json('data'))->firstWhere('id', $group->id);

        $this->assertSame([], $g['package_lock']);
    }

    // ============================================================
    // ④ النقل بين المجموعات
    // ============================================================

    /** ⭐ عضو في مجموعة، من غير اشتراك وفواتير — نقلة مسموحة */
    private function memberWithNoMoney(GroupClass $group): array
    {
        $student = $this->makeStudent();
        $member = GroupMember::admit($group, $student, 'manual');

        return [$member, $student];
    }

    public function test_moving_a_clear_student_keeps_the_old_row_and_makes_a_new_one(): void
    {
        // ⭐ السطر القديم **يفضل** بحالة `left` — عشان نعرف
        // «كان في المجموعة الأولى من شهر...». لو اتمسح، ضاع
        // تاريخ الطالب.
        $from = $this->makeGroup(['capacity' => 5]);
        $to = $this->makeGroup(['capacity' => 5]);
        [$member] = $this->memberWithNoMoney($from);

        $r = $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $to->id],
            $this->admin(),
        );

        $r->assertOk();

        // ① السطر القديم بقى `left` وما اتمسحش
        $this->assertDatabaseHas('group_members', [
            'id' => $member->id,
            'group_class_id' => $from->id,
            'status' => 'left',
        ]);
        $this->assertNotNull($member->fresh()->left_at);

        // ② سطر **جديد** في التانية
        $new = GroupMember::where('group_class_id', $to->id)
            ->where('status', 'active')
            ->first();
        $this->assertNotNull($new);
        $this->assertNotSame($member->id, $new->id);

        // ③ الأرقام اتحدّثت
        $this->assertSame(0, $from->fresh()->occupancy()['members']);
        $this->assertSame(1, $to->fresh()->occupancy()['members']);
    }

    public function test_moving_is_blocked_while_the_subscription_is_active(): void
    {
        // ⭐ القاعدة: **ما ينفعش ينقل قبل ما اشتراكه يخلص.**
        $program = $this->makeProgram();
        $this->makePlan($program);

        $from = $this->makeGroup([], $program);
        $to = $this->makeGroup([], $program);

        $student = $this->makeStudent();
        $member = GroupMember::admit($from, $student, 'manual');
        $this->makeSubscription($program, $student, ['status' => 'active']);

        $r = $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $to->id],
            $this->admin(),
        );

        $r->assertStatus(422);
        $r->assertJsonPath('message', 'الطالب مش قابل للنقل دلوقتي');

        $codes = array_column($r->json('blockers'), 'code');
        $this->assertContains('active_subscription', $codes);

        // ⭐ ومفيش حاجة اتغيرت
        $this->assertDatabaseHas('group_members', [
            'id' => $member->id,
            'status' => 'active',
        ]);
        $this->assertSame(0, $to->fresh()->occupancy()['members']);
    }

    public function test_moving_is_blocked_while_an_invoice_is_unpaid(): void
    {
        $from = $this->makeGroup(['capacity' => 5]);
        $to = $this->makeGroup(['capacity' => 5]);
        [$member, $student] = $this->memberWithNoMoney($from);

        Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-TEST-1',
            'currency' => 'EGP',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'subtotal' => 400,
            'total' => 400,
            'paid_amount' => 0,
            'balance_due' => 400,
            'status' => 'issued',
        ]);

        $r = $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $to->id],
            $this->admin(),
        );

        $r->assertStatus(422);
        $codes = array_column($r->json('blockers'), 'code');
        $this->assertContains('unsettled_invoice', $codes);
    }

    public function test_a_paid_invoice_does_not_block_the_move(): void
    {
        $from = $this->makeGroup(['capacity' => 5]);
        $to = $this->makeGroup(['capacity' => 5]);
        [$member, $student] = $this->memberWithNoMoney($from);

        Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $student->id,
            'invoice_number' => 'INV-PAID-1',
            'currency' => 'EGP',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'subtotal' => 400,
            'total' => 400,
            'paid_amount' => 400,
            'balance_due' => 0, // ⭐ مدفوعة بالكامل
            'status' => 'paid',
        ]);

        $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $to->id],
            $this->admin(),
        )->assertOk();

        $this->assertSame(1, $to->fresh()->occupancy()['members']);
    }

    public function test_moving_into_a_full_group_is_refused_with_a_clear_reason(): void
    {
        $from = $this->makeGroup(['capacity' => 5]);
        $to = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($to, 2);
        [$member] = $this->memberWithNoMoney($from);

        $r = $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $to->id],
            $this->admin(),
        );

        $r->assertStatus(422);
        $this->assertStringContainsString('مليانة', $r->json('message'));

        $codes = array_column($r->json('blockers'), 'code');
        $this->assertContains('destination_full', $codes);

        $this->assertSame(1, $from->fresh()->occupancy()['members']);
    }

    public function test_a_student_cannot_be_moved_to_the_same_group(): void
    {
        $from = $this->makeGroup(['capacity' => 5]);
        [$member] = $this->memberWithNoMoney($from);

        $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $from->id],
            $this->admin(),
        )->assertStatus(422);
    }

    public function test_moving_creates_a_subscription_on_the_new_group(): void
    {
        // ⭐ المجموعة الأولى على برنامج مفيش له اشتراك (مفيش باقة
        // خالص) عشان نضمن إن النقل مش بيستفيد من اشتراك قديم.
        $newProgram = $this->makeProgram();
        $this->makePlan($newProgram, ['price' => 500]);

        $from = $this->makeGroup(['capacity' => 5]);
        $to = $this->makeGroup(['capacity' => 5], $newProgram);

        [$member, $student] = $this->memberWithNoMoney($from);

        $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $to->id],
            $this->admin(),
        )->assertOk();

        $sub = Subscription::where('student_id', $student->id)
            ->where('program_id', $newProgram->id)
            ->first();

        $this->assertNotNull($sub, 'لازم اشتراك على برنامج المجموعة الجديدة');
        $this->assertSame('500.00', $sub->price);
    }

    public function test_someone_without_the_permission_cannot_move(): void
    {
        $from = $this->makeGroup(['capacity' => 5]);
        $to = $this->makeGroup(['capacity' => 5]);
        [$member] = $this->memberWithNoMoney($from);

        $this->postJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move",
            ['to_group_id' => $to->id],
            $this->receptionist(),
        )->assertForbidden();

        $this->assertSame(1, $from->fresh()->occupancy()['members']);
        $this->assertSame(0, $to->fresh()->occupancy()['members']);
    }

    public function test_a_member_of_another_group_cannot_be_moved_through_this_one(): void
    {
        $a = $this->makeGroup(['capacity' => 5]);
        $b = $this->makeGroup(['capacity' => 5]);
        [$member] = $this->memberWithNoMoney($a);

        $this->postJsonAs(
            "/api/groups/{$b->id}/members/{$member->id}/move",
            ['to_group_id' => $a->id],
            $this->admin(),
        )->assertNotFound();
    }

    // ============================================================
    // ⑤ شاشة النقل — الخيارات قبل الاختيار
    // ============================================================

    public function test_move_options_lists_every_other_group_with_its_reason(): void
    {
        $from = $this->makeGroup(['capacity' => 5]);
        $full = $this->makeGroup(['capacity' => 1]);
        $this->fillGroup($full, 1);
        $roomy = $this->makeGroup(['capacity' => 9]);
        [$member] = $this->memberWithNoMoney($from);

        $r = $this->getJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move-options",
            $this->admin(),
        );

        $r->assertOk();

        $byId = collect($r->json('data'))->keyBy('id');

        // ⭐ المجموعة الحالية **مش** في القائمة
        $this->assertArrayNotHasKey($from->id, $byId);

        // ⭐ مليانة → ممنوعة بسبب السعة
        $this->assertFalse($byId[$full->id]['can_move']);
        $this->assertContains('destination_full', array_column($byId[$full->id]['blockers'], 'code'));

        // ⭐ فيها مكان → مسموحة
        $this->assertTrue($byId[$roomy->id]['can_move']);
        $this->assertSame([], $byId[$roomy->id]['blockers']);
    }

    public function test_move_options_repeats_the_student_blocker_on_every_group(): void
    {
        // ⭐ السبب «الطالب عنده اشتراك» بيمنع **كل** المجموعات —
        // فالأدمن لازم يشوفه مرة واحدة فوق، مش رسالة لكل مجموعة.
        $program = $this->makeProgram();
        $from = $this->makeGroup(['capacity' => 5], $program);
        $this->makeGroup(['capacity' => 5], $program);
        $this->makeGroup(['capacity' => 5], $program);

        $student = $this->makeStudent();
        $member = GroupMember::admit($from, $student, 'manual');
        $this->makeSubscription($program, $student, ['status' => 'active']);

        $r = $this->getJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move-options",
            $this->admin(),
        );

        $r->assertOk();

        // ⭐ القفل العام مرة واحدة في الأعلى
        $this->assertCount(1, $r->json('blockers'));

        // ⭐ وكل مجموعة مع“为什么 مقفولة”
        foreach ($r->json('data') as $target) {
            $this->assertFalse($target['can_move']);
            $this->assertContains('active_subscription', array_column($target['blockers'], 'code'));
        }
    }

    public function test_move_options_needs_the_permission(): void
    {
        $from = $this->makeGroup(['capacity' => 5]);
        [$member] = $this->memberWithNoMoney($from);

        $this->getJsonAs(
            "/api/groups/{$from->id}/members/{$member->id}/move-options",
            $this->receptionist(),
        )->assertForbidden();
    }
}