<?php

namespace Tests\Feature;

use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\WaitingListEntry;
use Tests\TestCase;

/**
 * ⭐ اختبارات صفحة **قائمة الانتظار المستقلة** (`/waitlist`).
 *
 * ليه ملف لوحده؟
 *
 * كل الاختبارات اللي كانت موجودة بتغطي `/groups/{g}/waitlist`
 * (طلب عام + admit من جوه لوحة المجموعة). الصفحة الجديدة دي
 * **مسارات مختلفة تمامًا** — مفيش ولا اختبار كان بيلمسها،
 * فاخترقت الـ 500 الأول ما الصفحة اتفتحت:
 * `Call to undefined method WaitlistController::authorize()`.
 *
 * ⭐ الاختبارات هنا بتغطي:
 *
 *  ① **الراوتات بترجع 200 مش 500** — أي استدعاء لـ `$this->authorize()`
 *     في controller الـ `Controller` الأساس في المشروع مش فيه
 *     trait `AuthorizesRequests`، فبيرمي exception.
 *  ② **الصلاحية** — `groups.manage` على كل الراوتات (403 لغير المخوّل).
 *  ③ **CRUD كامل** — إضافة/تعديل/حذف + الفلترة + البحث.
 *  ④ **أدخل** — الطالب + العضوية + الاشتراك الشهري كله في transaction واحد.
 */
class WaitlistPageTest extends TestCase
{
    // ============================================================
    // أدوات
    // ============================================================

    /** أدمن عنده صلاحية إدارة المجموعات */
    private function admin()
    {
        return $this->makeUserWithRole('admin', ['groups.manage', 'groups.view']);
    }

    /** موظف من غير صلاحية إدارة المجموعات — لازم يطلع 403 */
    private function receptionist()
    {
        return $this->makeUserWithRole('receptionist', ['groups.view']);
    }

    // ============================================================
    // ① الراوتات بترجع 200 — الحارس ضد خطأ الـ 500
    // ============================================================

    public function test_the_waitlist_page_loads_without_a_server_error(): void
    {
        $r = $this->getJsonAs('/api/waitlist', $this->admin());

        $r->assertOk();
        $r->assertJsonStructure(['data', 'meta']);
    }

    public function test_an_empty_waitlist_returns_an_empty_list_not_an_error(): void
    {
        $r = $this->getJsonAs('/api/waitlist', $this->admin());

        $r->assertOk();
        $r->assertJsonCount(0, 'data');
        $r->assertJsonPath('meta.total', 0);
    }

    public function test_per_page_defaults_to_50_and_is_capped_at_200(): void
    {
        // ⚠️ `integer()` بترجع 0 لو الـ param مش موجود — لو الكود كان
        // بيستخدم `?? 50` كانت هتبقى 0 و page فاضية. الاختبار بيقفل ده.
        $r = $this->getJsonAs('/api/waitlist', $this->admin());

        $r->assertOk();
        $r->assertJsonPath('meta.per_page', 50);

        $r2 = $this->getJsonAs('/api/waitlist?per_page=9999', $this->admin());
        $r2->assertOk();
        $r2->assertJsonPath('meta.per_page', 200);
    }

    // ============================================================
    // ② الصلاحية
    // ============================================================

    public function test_someone_without_the_permission_cannot_read_the_page(): void
    {
        $this->getJsonAs('/api/waitlist', $this->receptionist())
            ->assertForbidden();
    }

    public function test_someone_without_the_permission_cannot_add_an_entry(): void
    {
        $this->postJsonAs('/api/waitlist', [
            'name' => 'حد',
            'phone' => '01000000000',
        ], $this->receptionist())->assertForbidden();

        $this->assertSame(0, WaitingListEntry::count());
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->getJson('/api/waitlist')->assertUnauthorized();
    }

    // ============================================================
    // ③ الإضافة
    // ============================================================

    public function test_the_admin_can_add_an_entry_with_every_field(): void
    {
        $group = $this->makeGroup();
        $program = \App\Models\Program::find($group->program_id);
        $plan = $this->defaultPlan($program);

        $r = $this->postJsonAs('/api/waitlist', [
            'name' => 'أحمد محمد',
            'phone' => '01011112222',
            'parent_phone' => '01033334444',
            'current_level' => 'متقن الجزء الخامس',
            'package_id' => $plan->id,
            'proposed_group_id' => $group->id,
            'notes' => 'بيهتفح وقت الضهر بس',
        ], $this->admin());

        $r->assertCreated();
        $r->assertJsonPath('data.name', 'أحمد محمد');
        $r->assertJsonPath('data.parent_phone', '01033334444');
        $r->assertJsonPath('data.current_level', 'متقن الجزء الخامس');
        $r->assertJsonPath('data.status', 'waiting');
        $r->assertJsonPath('data.package.id', $plan->id);
        $r->assertJsonPath('data.proposed_group.id', $group->id);

        $this->assertSame(1, WaitingListEntry::count());
    }

    public function test_a_new_entry_has_no_group_so_the_admin_can_decide_later(): void
    {
        // ⭐ نقطة التصميم: طلب الانتظار المستقل **مش لازم** يكون مرتبط
        // بمجموعة — عشان الأدمن يفتح مجموعة جديدة لما يجي وقت وحطه فيها.
        $r = $this->postJsonAs('/api/waitlist', [
            'name' => 'بدون مجموعة',
            'phone' => '01055556666',
        ], $this->admin());

        $r->assertCreated();
        $r->assertJsonPath('data.group', null);
        $r->assertJsonPath('data.proposed_group', null);
    }

    public function test_name_and_phone_are_required(): void
    {
        $r = $this->postJsonAs('/api/waitlist', [], $this->admin());

        $r->assertStatus(422);

        // ⚠️ مش بنفحص على مفاتيح `name`/`phone` — الـ middleware
        // (`TranslateValidationErrors`) بيقفل المفاتيح دي وبيدي
        // **أسماء الحقول بالعربي** بدالها. فبنفحص على العربي.
        $r->assertJsonStructure(['message', 'errors']);

        $this->assertSame(0, WaitingListEntry::count());
    }

    public function test_the_validation_error_names_the_fields_in_arabic(): void
    {
        // ⭐ الواجهة كلها عربي — فلو الـ 422 رجع `current_level`
        // أو `parent_phone` بالإنجليزي، الأدمن هيشوف نص إنجليزي.
        $r = $this->postJsonAs('/api/waitlist', [
            'name' => 'واحد',
            'phone' => '01000000000',
            'package_id' => 999999,
            'proposed_group_id' => 999999,
        ], $this->admin());

        $r->assertStatus(422);

        $errors = array_keys($r->json('errors'));

        $this->assertContains('الباقة', $errors);
        $this->assertContains('المجموعة المقترحة', $errors);

        // ⚠️ مفيش اسم حقل إنجليزي فضل في الرد
        foreach ($errors as $key) {
            $this->assertDoesNotMatchRegularExpression('/^[a-z_]+$/', $key, "الحقل «{$key}» لسه إنجليزي");
        }
    }

    public function test_a_phone_that_is_too_short_is_refused(): void
    {
        // ⭐ نفس قاعدة الطلب العام في صفحة المجموعة (`min:6`) —
        // رقم ناقص مش موبايل، ومينفعش نخزّنه ونلاقي نفسنا بنتصل برقم غلط.
        $this->postJsonAs('/api/waitlist', [
            'name' => 'واحد',
            'phone' => '123',
        ], $this->admin())->assertStatus(422);

        $this->assertSame(0, WaitingListEntry::count());
    }

    public function test_the_guardians_phone_is_optional_but_checked_when_given(): void
    {
        // ⭐ من غير رقم ولي الأمر الطلب لسه صالح — الحقل اختياري
        $this->postJsonAs('/api/waitlist', [
            'name' => 'بدون ولي أمر',
            'phone' => '01010001000',
        ], $this->admin())->assertCreated();

        // لكن لو اتكتب، لازم يبقى رقم
        $this->postJsonAs('/api/waitlist', [
            'name' => 'بولي أمر غلط',
            'phone' => '01010001001',
            'parent_phone' => '12',
        ], $this->admin())->assertStatus(422);
    }

    public function test_the_same_phone_cannot_waive_for_the_same_group_twice(): void
    {
        $group = $this->makeGroup();

        $this->postJsonAs('/api/waitlist', [
            'name' => 'أول',
            'phone' => '01077778888',
            'group_class_id' => $group->id,
        ], $this->admin())->assertCreated();

        $this->postJsonAs('/api/waitlist', [
            'name' => 'تاني',
            'phone' => '01077778888',
            'group_class_id' => $group->id,
        ], $this->admin())->assertStatus(422);

        $this->assertSame(1, WaitingListEntry::count());
    }

    public function test_the_same_phone_can_wait_for_two_different_groups(): void
    {
        $a = $this->makeGroup();
        $b = $this->makeGroup();

        $this->postJsonAs('/api/waitlist', [
            'name' => 'واحد', 'phone' => '01088889999', 'group_class_id' => $a->id,
        ], $this->admin())->assertCreated();

        $this->postJsonAs('/api/waitlist', [
            'name' => 'واحد', 'phone' => '01088889999', 'group_class_id' => $b->id,
        ], $this->admin())->assertCreated();

        $this->assertSame(2, WaitingListEntry::count());
    }

    // ============================================================
    // ④ التعديل
    // ============================================================

    public function test_the_admin_can_edit_an_entry(): void
    {
        $entry = $this->makeWaitingEntry($this->makeGroup());

        $r = $this->putJsonAs('/api/waitlist/'.$entry->id, [
            'current_level' => 'مبتدئ',
            'parent_phone' => '01099990000',
        ], $this->admin());

        $r->assertOk();
        $r->assertJsonPath('data.current_level', 'مبتدئ');
        $r->assertJsonPath('data.parent_phone', '01099990000');

        // ⚠️ الحقول اللي مبعتناش ما تتغيرش — `sometimes` مش `required`
        $this->assertSame($entry->name, $entry->fresh()->name);
    }

    public function test_editing_rejects_a_package_that_does_not_exist(): void
    {
        $entry = $this->makeWaitingEntry($this->makeGroup());

        $this->putJsonAs('/api/waitlist/'.$entry->id, [
            'package_id' => 999999,
        ], $this->admin())->assertStatus(422);
    }

    // ============================================================
    // ⑤ الحذف
    // ============================================================

    public function test_the_admin_can_delete_an_entry_for_real(): void
    {
        $entry = $this->makeWaitingEntry($this->makeGroup());

        $this->deleteJsonAs('/api/waitlist/'.$entry->id, $this->admin())
            ->assertOk();

        // ⚠️ حذف فعلي — مش `markDeclined`. السطر مش موجود خالص.
        $this->assertSame(0, WaitingListEntry::count());
        $this->assertDatabaseMissing('waiting_list_entries', ['id' => $entry->id]);
    }

    public function test_someone_without_the_permission_cannot_delete(): void
    {
        $entry = $this->makeWaitingEntry($this->makeGroup());

        $this->deleteJsonAs('/api/waitlist/'.$entry->id, $this->receptionist())
            ->assertForbidden();

        $this->assertDatabaseHas('waiting_list_entries', ['id' => $entry->id]);
    }

    // ============================================================
    // ⑥ الفلترة والبحث
    // ============================================================

    public function test_the_list_can_be_filtered_by_status(): void
    {
        $group = $this->makeGroup();
        $waiting = $this->makeWaitingEntry($group, ['phone' => '01200000001']);
        $joined = $this->makeWaitingEntry($group, ['phone' => '01200000002']);
        $joined->markJoined();

        $r = $this->getJsonAs('/api/waitlist?status=waiting', $this->admin());

        $r->assertOk();
        $r->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.id', $waiting->id);
    }

    public function test_the_list_can_be_filtered_by_group(): void
    {
        $a = $this->makeGroup();
        $b = $this->makeGroup();
        $this->makeWaitingEntry($a, ['phone' => '01210000001']);
        $inB = $this->makeWaitingEntry($b, ['phone' => '01210000002']);

        $r = $this->getJsonAs('/api/waitlist?group_id='.$b->id, $this->admin());

        $r->assertOk();
        $r->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.id', $inB->id);
    }

    public function test_search_looks_in_the_name_phone_parent_and_level(): void
    {
        $group = $this->makeGroup();
        $this->makeWaitingEntry($group, [
            'name' => 'مريم', 'phone' => '01220000001',
            'parent_phone' => '01550000001', 'current_level' => 'متقن الحفظ',
        ]);
        $this->makeWaitingEntry($group, [
            'name' => 'خالد', 'phone' => '01220000002',
            'parent_phone' => '01550000002', 'current_level' => 'مبتدئ',
        ]);

        // ⭐ البحث على **المستوى** و**هاتف الولي** كمان — مش الاسم بس
        $this->getJsonAs('/api/waitlist?search='.urlencode('متقن الحفظ'), $this->admin())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'مريم');

        $this->getJsonAs('/api/waitlist?search=01550000002', $this->admin())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'خالد');

        $this->getJsonAs('/api/waitlist?search=0155', $this->admin())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_the_list_comes_back_ordered_by_when_they_joined_the_queue(): void
    {
        $group = $this->makeGroup();
        $first = $this->makeWaitingEntry($group, ['phone' => '01230000001']);
        $second = $this->makeWaitingEntry($group, ['phone' => '01230000002']);

        $r = $this->getJsonAs('/api/waitlist', $this->admin());

        $r->assertOk();
        $r->assertJsonPath('data.0.id', $first->id);
        $r->assertJsonPath('data.1.id', $second->id);
    }

    public function test_the_position_is_only_counted_for_still_waiting(): void
    {
        $group = $this->makeGroup();
        $first = $this->makeWaitingEntry($group, ['phone' => '01240000001']);
        $second = $this->makeWaitingEntry($group, ['phone' => '01240000002']);

        $r = $this->getJsonAs('/api/waitlist?status=waiting', $this->admin());
        $r->assertJsonPath('data.0.position', 1);
        $r->assertJsonPath('data.1.position', 2);

        // بعد ما الأول يدخل — التاني يبقى رقم 1
        $first->markJoined();

        // ⚠️ بنفلتر على `waiting` — الصفحة بترجّع كل الحالات لوحدها،
        // فلو طلبنا كلهم هنلاقي 2 سطر (واحد دخل وواحد مستني).
        $r2 = $this->getJsonAs('/api/waitlist?status=waiting', $this->admin());
        $r2->assertJsonCount(1, 'data');
        $r2->assertJsonPath('data.0.id', $second->id);
        $r2->assertJsonPath('data.0.position', 1);

        // ⭐ واللي دخل خلاص رقمه **صفر** — مش رقم في الطابور
        $r3 = $this->getJsonAs('/api/waitlist?status=joined', $this->admin());
        $r3->assertJsonCount(1, 'data');
        $r3->assertJsonPath('data.0.position', 0);
    }

    public function test_an_entry_added_from_the_page_gets_a_position_in_line(): void
    {
        // ⭐ `entered_at` هو اللي بيحدد الترتيب. لو سطر اتضاف من
        // صفحة الانتظار من غير وقت دخول، `positionInLine()` كانت
        // بترمي 500 (`Illegal operator and value combination`).
        $group = $this->makeGroup();

        $r = $this->postJsonAs('/api/waitlist', [
            'name' => 'في الطابور',
            'phone' => '01245000001',
            'group_class_id' => $group->id,
        ], $this->admin());

        $r->assertCreated();
        $r->assertJsonPath('data.position', 1);
        $r->assertJsonPath('data.entered_at', fn ($v) => $v !== null);
    }

    // ============================================================
    // ⑦ «أدخل» — الطالب + العضوية + الاشتراك
    // ============================================================

    public function test_admitting_creates_the_student_the_membership_and_the_subscription(): void
    {
        $group = $this->makeGroup();

        $entry = $this->makeWaitingEntry($group, [
            'name' => 'يوسف إبراهيم',
            'phone' => '01250000001',
        ]);

        $r = $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin());

        $r->assertCreated();
        $r->assertJsonPath('student_id', fn ($id) => is_int($id));

        // ① الطالب اتعمل
        $student = Student::find($r->json('student_id'));
        $this->assertNotNull($student);
        $this->assertSame('يوسف', $student->first_name);
        $this->assertSame('إبراهيم', $student->last_name);
        $this->assertSame('01250000001', $student->phone);

        // ② السطر اتعلم إنه دخل
        $this->assertDatabaseHas('waiting_list_entries', [
            'id' => $entry->id,
            'status' => 'joined',
            'student_id' => $student->id,
        ]);

        // ③ العضوية اتعملت
        $this->assertDatabaseHas('group_members', [
            'group_class_id' => $group->id,
            'student_id' => $student->id,
            'status' => 'active',
        ]);

        // ④ الاشتراك الشهري اتعمل
        $sub = Subscription::where('student_id', $student->id)->first();
        $this->assertNotNull($sub, 'الاشتراك الشهري لازم يتعمل مع الدخول');
        $this->assertSame('monthly', $sub->billing_type);
        $this->assertSame('active', $sub->status);
        $this->assertSame($group->program_id, $sub->program_id);
    }

    public function test_admitting_reuses_the_existing_student_instead_of_creating_a_second_one(): void
    {
        $group = $this->makeGroup();
        $student = $this->makeStudent(['phone' => '01260000001']);

        $this->assertSame(1, Student::count());

        $entry = $this->makeWaitingEntry($group, ['phone' => '01260000001']);

        $r = $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin());

        $r->assertCreated();
        $r->assertJsonPath('student_id', $student->id);
        $this->assertSame(1, Student::count(), 'ما ينفعش يتعمل طالب تاني بنفس الموبايل');
    }

    public function test_admitting_twice_does_not_double_the_membership(): void
    {
        $group = $this->makeGroup();
        $entry = $this->makeWaitingEntry($group, ['phone' => '01270000001']);

        $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin())
            ->assertCreated();

        // التاني لازم يترفض — السطر مبقاش في الطابور
        $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin())
            ->assertStatus(422);

        $this->assertSame(1, GroupMember::where('group_class_id', $group->id)->count());
    }

    public function test_admitting_without_any_group_is_refused_and_changes_nothing(): void
    {
        // ⭐ نقطة التصميم: الطلب المستقل ممكن يتعمل **من غير مجموعة**،
        // بس «أدخل» من غير مجموعة مالهوش معنى — فبترجع 422
        // بدل ما تعمل نص شغل.
        $entry = WaitingListEntry::create([
            'organization_id' => $this->org->id,
            'name' => 'محتاج مجموعة',
            'phone' => '01280000001',
            'status' => 'waiting',
            'entered_at' => now(),
        ]);

        $r = $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin());

        $r->assertStatus(422);
        $r->assertJsonStructure(['message']);

        // ⚠️ ومفيش أي حاجة اتغيرت — السطر لسه مستني
        $this->assertDatabaseHas('waiting_list_entries', [
            'id' => $entry->id,
            'status' => 'waiting',
            'student_id' => null,
        ]);
        $this->assertSame(0, Student::count());
        $this->assertSame(0, Subscription::count());
    }

    public function test_admitting_uses_the_proposed_group_when_there_is_no_fixed_one(): void
    {
        $group = $this->makeGroup();

        $entry = WaitingListEntry::create([
            'organization_id' => $this->org->id,
            'name' => 'مقترح',
            'phone' => '01290000001',
            'proposed_group_id' => $group->id,
            'status' => 'waiting',
            'entered_at' => now(),
        ]);

        $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin())
            ->assertCreated();

        $this->assertDatabaseHas('group_members', [
            'group_class_id' => $group->id,
            'status' => 'active',
        ]);
    }

    public function test_admitting_through_the_proposed_group_records_where_they_went(): void
    {
        // ⭐ لو دخل من **المقترحة** من غير ما نثبّت `group_class_id`،
        // السطر بيفضل «مش مربوط بأي مجموعة» في الصفحة — رغم إنه داخل
        // واحدة. بعد أسبوعين من admissions مش هتعرف راح فين.
        $group = $this->makeGroup();

        $entry = WaitingListEntry::create([
            'organization_id' => $this->org->id,
            'name' => 'مقترح بس',
            'phone' => '01295000001',
            'proposed_group_id' => $group->id,
            'status' => 'waiting',
            'entered_at' => now(),
        ]);

        $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin())
            ->assertCreated();

        $this->assertDatabaseHas('waiting_list_entries', [
            'id' => $entry->id,
            'status' => 'joined',
            'group_class_id' => $group->id,
        ]);

        // ⭐ والصفحة بترجّعها فعلاً مربوطة
        $r = $this->getJsonAs('/api/waitlist?status=joined', $this->admin());
        $r->assertOk();
        $r->assertJsonPath('data.0.group.id', $group->id);
        $r->assertJsonPath('data.0.group.name', $group->name);
    }

    public function test_admitting_reuses_an_already_active_subscription(): void
    {
        $program = $this->makeProgram();
        $group = $this->makeGroup([], $program);
        $student = $this->makeStudent(['phone' => '01300000001']);

        $existing = $this->makeSubscription($program, $student, ['status' => 'active']);

        $entry = $this->makeWaitingEntry($group, ['phone' => '01300000001']);

        $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin())
            ->assertCreated();

        // ⚠️ الطالب كان مشترك أصلاً — ما ينفعش نعمل له اشتراك تاني
        $this->assertSame(1, Subscription::where('student_id', $student->id)->count());
        $this->assertSame($existing->id, Subscription::where('student_id', $student->id)->first()->id);
    }

    public function test_admitting_into_a_full_group_still_works_because_the_queue_is_the_gate(): void
    {
        // ⭐ «أدخل» من صفحة الانتظار **مش** خاضع للسعة — السعة بتتحكم
        // في الطلب العام (`/groups/{g}/waitlist`). الأدمن بيقدّم أول واحد.
        $group = $this->makeGroup(['capacity' => 1]);
        $this->fillGroup($group, 1);
        $this->assertTrue($group->fresh()->occupancy()['is_full']);

        $entry = $this->makeWaitingEntry($group, ['phone' => '01310000001']);

        $r = $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->admin());

        $r->assertCreated();
        $this->assertSame(2, GroupMember::where('group_class_id', $group->id)->count());
    }

    public function test_someone_without_the_permission_cannot_admit(): void
    {
        $group = $this->makeGroup();
        $entry = $this->makeWaitingEntry($group, ['phone' => '01320000001']);

        $this->postJsonAs('/api/waitlist/'.$entry->id.'/admit', [], $this->receptionist())
            ->assertForbidden();

        $this->assertSame(0, GroupMember::count());
        $this->assertSame(0, Student::count());
    }

    // ============================================================
    // ⑧ الصفحة شايفة من صفحة المجموعات (السياق)
    // ============================================================

    public function test_an_entry_made_through_the_group_still_shows_up_here(): void
    {
        $group = $this->makeGroup();

        // الطلب العام (من صفحة المجموعة) — من غير تسجيل دخول
        $this->postJson('/api/groups/'.$group->id.'/waitlist', [
            'name' => 'طلب عام',
            'phone' => '01330000001',
        ])->assertCreated();

        $r = $this->getJsonAs('/api/waitlist', $this->admin());

        $r->assertOk();
        $r->assertJsonCount(1, 'data');
        $r->assertJsonPath('data.0.name', 'طلب عام');
        $r->assertJsonPath('data.0.group.id', $group->id);
    }

    public function test_every_entry_carries_the_fields_it_can_be_searched_by(): void
    {
        // ⭐ حقول البحث (الاسم/الموبايل/هاتف الولي/المستوى) لازم
        // تطلع زي ما اتخزنت — مفيش حقل بيتناشى بالغلط في الرد.
        $this->makeWaitingEntry($this->makeGroup(), [
            'name' => 'سارة',
            'phone' => '01340000001',
            'parent_phone' => '01340000002',
            'current_level' => 'متقن الحفظ',
        ]);

        $r = $this->getJsonAs('/api/waitlist', $this->admin());

        $r->assertOk();
        $r->assertJsonPath('data.0.name', 'سارة');
        $r->assertJsonPath('data.0.phone', '01340000001');
        $r->assertJsonPath('data.0.parent_phone', '01340000002');
        $r->assertJsonPath('data.0.current_level', 'متقن الحفظ');
    }
}
