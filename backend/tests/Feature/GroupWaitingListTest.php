<?php

namespace Tests\Feature;

use App\Models\GroupClass;
use App\Models\GroupMember;
use App\Models\WaitingListEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐ المجموعات الأونلاين + قائمة الانتظار.
 *
 * ─────────────────────────────────────────────────────────────
 * القواعد اللي الملف ده بيحميها:
 *
 *  ① **الاسم + الموبايل بس** — مفيش حساب، مفيش طالب مطلوب.
 *     لأن أكتر الناس اللي بتطلب هي اللي لسه ما عندهمش اشتراك.
 *
 *  ② **السعة بتتحسب في كل مرة** — مافيش عمود عدد الأعضاء.
 *     لو خزّنّاه، أول إضافة أو شيل بتبوّه.
 *
 *  ③ **`capacity = null` معناها «مفيش حد»** — مش صفر.
 *
 *  ④ **الترتيب ثابت** — `entered_at` ثم `id`. الأول في القائمة
 *     لازم يفضل الأول مهما عملنا استعلامات.
 *
 *  ⑤ **الصف بيفضل** لما حد يخرج أو يرفض — عشان التاريخ، ولحد ما
 *     يسجّل تاني.
 *
 *  ⑥ **نفس الموبايل ما يسجّلش مرتين** في نفس المجموعة.
 */
class GroupWaitingListTest extends TestCase
{
    // ============================================================
    // ① السعة — التعريف الواحد
    // ============================================================

    public function test_an_empty_group_has_all_its_seats_free(): void
    {
        $group = $this->makeGroup(['capacity' => 10]);

        $o = $group->occupancy();

        $this->assertSame(10, $o['capacity']);
        $this->assertSame(0, $o['members']);
        $this->assertSame(10, $o['seats_left']);
        $this->assertFalse($o['is_full']);
        $this->assertTrue($o['has_space']);
    }

    public function test_seats_shrink_as_students_join(): void
    {
        $group = $this->makeGroup(['capacity' => 5]);

        $this->fillGroup($group, 3);

        $o = $group->fresh()->occupancy();

        $this->assertSame(3, $o['members']);
        $this->assertSame(2, $o['seats_left']);
        $this->assertTrue($o['has_space']);
    }

    /**
     * ⭐ ⭐ الأهم: **السعة بتتحسب لحظتها**.
     *
     * لو كان فيه عمود `members_count` محفوظ، الاختبار ده هينجح
     * أول مرة ويفشل بعدها. ده بالظبط اللي بنحمي منه.
     */
    public function test_seats_are_recomputed_not_stored(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 2);

        $this->assertTrue($group->fresh()->occupancy()['is_full']);

        // ⭐ حد ساب — لازم يفيق مقعد **فورًا**
        GroupMember::where('group_class_id', $group->id)
            ->active()
            ->first()
            ->markLeft();

        $o = $group->fresh()->occupancy();
        $this->assertSame(1, $o['members']);
        $this->assertSame(1, $o['seats_left']);
        $this->assertFalse($o['is_full']);

        // ⭐ وبعدين حد رجع — المقعد بيتاخد تاني
        GroupMember::admit($group, $this->makeStudent());

        $this->assertTrue($group->fresh()->occupancy()['is_full']);
    }

    /** ⭐ `capacity = null` = مفتوحة — مش صفر ومش ممتلئة */
    public function test_null_capacity_means_no_limit(): void
    {
        $group = $this->makeGroup(['capacity' => null]);
        $this->fillGroup($group, 25);

        $o = $group->fresh()->occupancy();

        $this->assertNull($o['capacity']);
        $this->assertNull($o['seats_left'], 'مفيش حد ⇒ مفيش رقم مقاعد فاضية');
        $this->assertSame(25, $o['members']);
        $this->assertFalse($o['is_full']);
        $this->assertTrue($o['has_space']);
    }

    /** ⭐ العدد الأقصى صفر = مفيش حد يدخل (حالة صريحة مش `null`) */
    public function test_zero_capacity_blocks_everyone(): void
    {
        $group = $this->makeGroup(['capacity' => 0]);

        $o = $group->occupancy();

        $this->assertSame(0, $o['seats_left']);
        $this->assertTrue($o['is_full']);
        $this->assertFalse($o['has_space']);
    }

    /**
     * ⭐ ⭐ **السعة مش بتتحميك من الزيادة**.
     *
     * قرار: لو المجموعة امتلأت وحد ضيف زيادة، النظام يسمح —
     * الأدمن هو اللي شاف وهو اللي ضغط.
     *
     * السبب: في أكاديمية، «ابني فضل مع صاحبه» بيحصل. لو
     * النظام رفض، الأدمن هيلفّر على حيلة، أو المجموعة هتبقى
     * بلا معنى.
     *
     * الفرق: `seats_left` **مش بيبقى بالسالب** — بيقف عند صفر.
     */
    public function test_going_over_capacity_is_allowed_but_seats_never_go_negative(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 4);

        $o = $group->fresh()->occupancy();

        $this->assertSame(4, $o['members']);
        $this->assertSame(0, $o['seats_left'], 'مابيديش بالسالب');
        $this->assertTrue($o['is_full']);
    }

    /** ⭐ ⭐ الخالد ينفع مرتين مش — ولا يتحسب مرتين */
    public function test_a_student_cannot_be_added_to_the_same_group_twice(): void
    {
        $group = $this->makeGroup(['capacity' => 5]);
        $student = $this->makeStudent();

        GroupMember::admit($group, $student);
        GroupMember::admit($group, $student);

        $this->assertSame(1, $group->fresh()->occupancy()['members']);
        $this->assertSame(1, GroupMember::where('group_class_id', $group->id)->count());
    }

    /** ⭐ الطالب اللي رجع بياخد **نفس الصف** — عشان التاريخ يفضل متصل */
    public function test_rejoining_reuses_the_same_row(): void
    {
        $group = $this->makeGroup(['capacity' => 5]);
        $student = $this->makeStudent();

        $first = GroupMember::admit($group, $student);
        $first->markLeft('agreed to leave');

        $again = GroupMember::admit($group, $student);

        $this->assertSame($first->id, $again->id, 'نفس الصف');
        $this->assertSame(1, GroupMember::where('group_class_id', $group->id)->count());
        $this->assertSame('active', $again->status);
        $this->assertNull($again->left_at);
    }

    /** ⭐ ⭐ تاريخ الخروج الأول **مش** بيتكتب تاني */
    public function test_the_first_exit_date_is_never_overwritten(): void
    {
        $group = $this->makeGroup(['capacity' => 5]);
        $student = $this->makeStudent();

        $m = GroupMember::admit($group, $student);
        $m->markLeft('أول مرة');
        $firstExit = $m->fresh()->left_at;

        GroupMember::admit($group, $student)->markLeft('تاني مرة');

        $this->assertEquals(
            $firstExit,
            $m->fresh()->left_at,
            'تاريخ أول خروج هو المهم'
        );
    }

    // ============================================================
    // ② ⭐ المسجّل من غير حساب
    // ============================================================

    /** ⭐ ⭐ **الاسم + الموبايل بس** — من غير طالب ولا حساب */
    public function test_a_waiting_entry_needs_only_a_name_and_a_phone(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 2);

        $entry = WaitingListEntry::create([
            'organization_id' => $this->org->id,
            'group_class_id' => $group->id,
            'name' => 'أحمد محمد — وليّ الأمر',
            'phone' => '01001234567',
            'entered_at' => now(),
        ]);

        $this->assertSame('waiting', $entry->status);
        $this->assertNull($entry->student_id, 'مش لازم يكون في النظام');
        $this->assertNull($entry->parent_id);
        $this->assertTrue($entry->isWaiting());
    }

    /** ⭐ نفس الموبايل مرتين في نفس المجموعة = مرفوض */
    public function test_the_same_phone_cannot_enter_the_same_group_twice(): void
    {
        $group = $this->makeGroup();

        $this->makeWaitingEntry($group, ['phone' => '01009998888']);

        $this->expectException(QueryException::class);

        $this->makeWaitingEntry($group, ['phone' => '01009998888']);
    }

    /** ⭐ نفس الموبايل في **مجموعة تانية** = عادي */
    public function test_the_same_phone_can_join_two_different_groups(): void
    {
        $a = $this->makeGroup();
        $b = $this->makeGroup();

        $this->makeWaitingEntry($a, ['phone' => '01007776666']);
        $this->makeWaitingEntry($b, ['phone' => '01007776666']);

        $this->assertSame(2, WaitingListEntry::count());
    }

    // ============================================================
    // ③ ⭐ الترتيب
    // ============================================================

    public function test_the_queue_is_ordered_by_registration_time(): void
    {
        $group = $this->makeGroup();
        $entries = $this->fillWaitingList($group, 4);

        $ordered = WaitingListEntry::waitingOrder()->pluck('id')->all();

        $this->assertSame(
            collect($entries)->pluck('id')->all(),
            $ordered,
            'الترتيب وقت التسجيل'
        );
    }

    /**
     * ⭐ ⭐ **نفس الثانية ⇒ `id` هو الفيصل**.
     *
     * من غير الـ `id` كان الترتيب هيتغيّر من استعلام للتاني، والأول
     * في القائمة ممكن يبقى التالت — وده معناه حد بيتنزل من
     * أول القائمة من غير سبب.
     */
    public function test_same_second_is_broken_by_id(): void
    {
        $group = $this->makeGroup();
        $sameTime = now();

        $first = $this->makeWaitingEntry($group, ['phone' => '0100001', 'entered_at' => $sameTime]);
        $second = $this->makeWaitingEntry($group, ['phone' => '0100002', 'entered_at' => $sameTime]);
        $third = $this->makeWaitingEntry($group, ['phone' => '0100003', 'entered_at' => $sameTime]);

        $this->assertSame(
            [$first->id, $second->id, $third->id],
            WaitingListEntry::waitingOrder()->pluck('id')->all()
        );

        $this->assertSame(1, $first->positionInLine());
        $this->assertSame(2, $second->positionInLine());
        $this->assertSame(3, $third->positionInLine());
    }

    public function test_position_is_renumbered_when_someone_is_admitted(): void
    {
        $group = $this->makeGroup();
        $entries = $this->fillWaitingList($group, 3);

        $this->assertSame(1, $entries[0]->positionInLine());
        $this->assertSame(3, $entries[2]->positionInLine());

        // ⭐ الأول دخل
        $entries[0]->markJoined();

        // ⭐ الباقي بيترقّم من جديد: ١ و ٢
        $this->assertSame(0, $entries[0]->positionInLine(), 'اللي دخل مش في الطابور');
        $this->assertSame(1, $entries[1]->positionInLine());
        $this->assertSame(2, $entries[2]->positionInLine());
    }

    /** ⭐ ⭐ «مين الأول» — واحد بس، وبترتيب ثابت */
    public function test_next_in_line_picks_the_earliest_waiting_person(): void
    {
        $group = $this->makeGroup();
        $entries = $this->fillWaitingList($group, 3);

        $this->assertSame($entries[0]->id, WaitingListEntry::nextInLine($group)?->id);

        $entries[0]->markJoined();

        $this->assertSame($entries[1]->id, WaitingListEntry::nextInLine($group)?->id);
    }

    /** ⭐ مفيش حد مستني ⇒ `null` — مش استثناء */
    public function test_next_in_line_is_null_when_nobody_waits(): void
    {
        $group = $this->makeGroup();

        $this->assertNull(WaitingListEntry::nextInLine($group));
    }

    // ============================================================
    // ④ ⭐ ⭐ التنبيه: «فيه مقعد فاضي وفي حد مستني»
    // ============================================================

    /**
     * ⭐ ⭐ التنبيه = **فيه مقعد فاضي** + **فيه حد مستني**.
     *
     * الحالة الأصعب: مجموعة فاضية (٣ مقاعد) و٢ مستنيين. التنبيه
     * لازم يشتغل — دي بالظبط اللحظة اللي لازم الأدمن يتحرك
     * فيها. «المجموعة فاضية» سبب إضافي للشغل، مش سبب لإخفاء
     * التنبيه.
     */
    public function test_the_alert_state_is_waiting_person_plus_free_seat(): void
    {
        $group = $this->makeGroup(['capacity' => 3]);
        $this->fillWaitingList($group, 2);

        // ⭐ ٣ مقاعد فاضية + ٢ مستنيين ⇒ ⭐ ده التنبيه بأعلى صوره
        $this->assertTrue($group->fresh()->hasWaitingAndSpace());

        // ⭐ اتلعت مقعدين — لسه فيه مقعد فاضي ومستنيين ⇒ لسه تنبيه
        $this->fillGroup($group, 2);
        $this->assertTrue($group->fresh()->hasWaitingAndSpace());
    }

    /** ⭐ اتلعت المقاعد كلها ⇒ مفيش تنبيه (محدش يقدر يدخل) */
    public function test_no_alert_when_the_group_is_full(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 2);
        $this->fillWaitingList($group, 3);

        $this->assertFalse($group->fresh()->hasWaitingAndSpace());
    }

    /**
     * ⭐ ⭐ المستنيين اللي دخلوا **مش** مستنيين.
     *
     * لو همنا «دخلوا» مستنيين، التنبيه هيشتغل ما فيش حد يدخل
     * وبيبقى جرس واعي.
     */
    public function test_no_alert_when_everyone_waiting_has_been_admitted(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillWaitingList($group, 2);

        WaitingListEntry::where('group_class_id', $group->id)->update(['status' => 'joined']);

        $this->assertSame(0, $group->fresh()->occupancy()['waiting']);
        $this->assertFalse($group->fresh()->hasWaitingAndSpace());
    }

    /** ⭐ الرافضين برضو مش مستنيين */
    public function test_no_alert_when_everyone_waiting_has_declined(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillWaitingList($group, 2);

        WaitingListEntry::where('group_class_id', $group->id)->update(['status' => 'declined']);

        $this->assertSame(0, $group->fresh()->occupancy()['waiting']);
        $this->assertFalse($group->fresh()->hasWaitingAndSpace());
    }

    // ============================================================
    // ⑤ ⭐ الرفض — السطر بيفضل
    // ============================================================

    /** ⭐ ⭐ الرفض **مش** حذف — عشان محدش يسجّل تاني على طول */
    public function test_declining_keeps_the_row_but_takes_it_out_of_the_queue(): void
    {
        $group = $this->makeGroup();
        [$first, $second] = $this->fillWaitingList($group, 2);

        $first->markDeclined('مش مهتم');

        $this->assertSame('declined', $first->fresh()->status);
        $this->assertSame(1, $group->fresh()->occupancy()['waiting']);

        // ⭐ ⭐ السطر **باقى** — ده هو المقصود من الرفض
        $this->assertSame(
            2,
            WaitingListEntry::where('group_class_id', $group->id)->count(),
            'الرفض بيخلي السطر، مش بيمسحه'
        );

        $this->assertSame($second->id, WaitingListEntry::nextInLine($group)?->id);
    }

    // ============================================================
    // ⑥ ⭐ الدخول بيعلّم بس — مافيش طالب ولا اشتراك
    // ============================================================

    /**
     * ⭐ ⭐ القرار: **الأدمن يدخّل ويكمّل بنفسه**.
     *
     * فـ `markJoined()` لازم يغيّر حالة السطر وخلاص. لو أخذنا
     * initiative من عندنا وعملنا طالب أو اشتراك من هنا، احنا
     * غيرنا القرار من غير ما حد قال.
     */
    public function test_admitting_someone_only_marks_the_row(): void
    {
        $group = $this->makeGroup(['capacity' => 1]);
        $this->fillGroup($group, 1);

        $entry = $this->makeWaitingEntry($group);
        $admin = $this->makeUserWithRole('admin', []);

        $before = [
            'students' => DB::table('students')->count(),
            'subscriptions' => DB::table('subscriptions')->count(),
        ];

        $entry->markJoined($admin);

        $this->assertSame('joined', $entry->fresh()->status);
        $this->assertNotNull($entry->fresh()->joined_at);
        $this->assertSame($admin->id, $entry->fresh()->admitted_by);

        // ⭐ مافيش حاجة اتعملت من غير ما حد يطلب
        $this->assertSame($before['students'], DB::table('students')->count());
        $this->assertSame($before['subscriptions'], DB::table('subscriptions')->count());

        // ⭐ ولا اتضاف عضو من غير ما حد يعملها
        $this->assertSame(1, $group->fresh()->occupancy()['members']);
    }

    // ============================================================
    // ⑦ ⭐ حالة المجموعة
    // ============================================================

    /** ⭐ المجموعة الموقفة **مش** بتستقبل طلبات جديدة */
    public function test_a_paused_group_stops_accepting_waitlist_requests(): void
    {
        $paused = $this->makeGroup(['capacity' => 5, 'status' => 'paused']);
        $open = $this->makeGroup(['capacity' => 5]);

        $this->assertFalse($paused->isOpenForWaitlist());
        $this->assertTrue($open->isOpenForWaitlist());
    }

    /**
     * ⭐ ⭐ المجموعة **ممتلئة لسه بتستقبل** طلبات.
     *
     * ده أصلاً معنى «قائمة انتظار» — لو رفضنا الطلب لما تكون
     * ممتلئة، اللي هييجي بعدين مش هيعرف يطلب أصلاً.
     */
    public function test_a_full_group_still_accepts_waitlist_requests(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 2);

        $this->assertTrue($group->fresh()->occupancy()['is_full']);
        $this->assertTrue($group->fresh()->isOpenForWaitlist());
    }

    /** ⭐ ⭐ «امتلأت» **مش** عمود — بيتحسب */
    public function test_full_is_derived_not_stored(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 2);

        $this->assertTrue($group->fresh()->occupancy()['is_full']);

        // ⭐ مافيش عمود `is_full` في الجدول أصلاً
        $columns = array_map(
            fn ($c) => $c->name,
            DB::select('PRAGMA table_info(group_classes)')
        );

        $this->assertNotContains('is_full', $columns);
        $this->assertNotContains('members_count', $columns);
        $this->assertNotContains('seats_left', $columns);
    }

    // ============================================================
    // ============================================================
    // ⑧ ⭐ الـ API — العرض عام والدخول محمي
    // ============================================================

    /**
     * ⭐ ⭐ **العرض عام**.
     *
     * زي الأسعار: الأولاد بيسألوا «فيه مجموعة للتحفيظ؟» من غير
     * ما يعملوا حساب. لو حطينا العرض جوه `auth:sanctum`، الرد
     * هيتقابل «لازم تسجّل دخول» — وده جواب غلط على سؤال صحيح.
     */
    public function test_the_public_group_list_needs_no_login(): void
    {
        $group = $this->makeGroup(['capacity' => 8, 'name' => 'مجموعة التحفيظ الليلية']);
        $this->fillGroup($group, 3);
        $this->fillWaitingList($group, 2);

        $r = $this->getJson('/api/groups')->assertOk();

        $this->assertSame(1, $r->json('meta.total'));

        $data = $r->json('data.0');
        $this->assertSame('مجموعة التحفيظ الليلية', $data['name']);
        $this->assertSame(3, $data['members_count']);
        $this->assertSame(2, $data['waiting_count']);
        $this->assertSame(5, $data['seats_left']);
        $this->assertTrue($data['has_space']);
        $this->assertTrue($data['needs_attention']);
    }

    /** ⭐ ⚠️ الرد العام **مافيش فيه** أسماء طلاب ولا موبايلات */
    public function test_the_public_response_leaks_no_student_data(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 1);
        $entry = $this->makeWaitingEntry($group, ['name' => 'أحمد محمد', 'phone' => '01001234567']);

        $r = $this->getJson('/api/groups')->assertOk();

        $body = json_encode($r->json('data.0'), JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString('أحمد محمد', $body, 'مفيش أسماء في الرد العام');
        $this->assertStringNotContainsString('01001234567', $body, 'مفيش أرقام موبايل');
        $this->assertStringNotContainsString($entry->phone, $body);
    }

    // ============================================================
    // ⑧ ⭐ ⭐ التسجيل في الطابور — أي حد من غير حساب
    // ============================================================

    /** ⭐ ⭐ **أي حد** — من غير حساب، من غير بريد، من غير كلمة سر */
    public function test_anyone_can_join_the_waiting_list_without_an_account(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 2);

        $r = $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'أحمد محمد',
            'phone' => '01001234567',
        ]);

        $r->assertCreated()
            ->assertJsonPath('position', 1)
            ->assertJsonPath('waiting_count', 1);

        $entry = WaitingListEntry::where('group_class_id', $group->id)->firstOrFail();

        $this->assertSame('أحمد محمد', $entry->name);
        $this->assertSame('01001234567', $entry->phone);
        $this->assertTrue($entry->isWaiting());

        // ⭐ ومافيش حساب اتعمل بالغلط
        $this->assertSame(0, DB::table('users')->count() - $this->makeUser()->count() + 1);
    }

    /** ⭐ الرقم اللي بيرجع = ترتيبه في الطابور */
    public function test_the_reply_tells_the_person_their_place_in_line(): void
    {
        $group = $this->makeGroup(['capacity' => 1]);
        $this->fillGroup($group, 1);
        $this->fillWaitingList($group, 3);

        $r = $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'الرابع',
            'phone' => '01009999999',
        ])->assertCreated();

        $this->assertSame(4, $r->json('position'));
        $this->assertSame(4, $r->json('waiting_count'));
    }

    /** ⭐ نفس الرقم تاني = «مسجّل قبل كده» مش 500 */
    public function test_registering_twice_is_reported_not_crashed(): void
    {
        $group = $this->makeGroup(['capacity' => 1]);
        $this->fillGroup($group, 1);

        $payload = ['name' => 'أحمد', 'phone' => '01001234567'];

        $this->postJson("/api/groups/{$group->id}/waitlist", $payload)->assertCreated();
        $this->postJson("/api/groups/{$group->id}/waitlist", $payload)->assertCreated();

        $this->assertSame(1, WaitingListEntry::where('group_class_id', $group->id)->count());
    }

    /** ⭐ ⭐ لو داخل بالفعل، الرد بيقول كده بصراحة */
    public function test_someone_already_admitted_is_told_so(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 1);

        $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'أحمد',
            'phone' => '01001234567',
        ])->assertCreated();

        WaitingListEntry::where('group_class_id', $group->id)->firstOrFail()->markJoined();

        $r = $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'أحمد',
            'phone' => '01001234567',
        ])->assertOk();

        $this->assertSame(0, $r->json('position'));
        $this->assertSame('أنت داخل المجموعة بالفعل', $r->json('message'));
    }

    /** ⭐ ⭐ لو رفض وقفل ورجع يطلب → رجع للطابور */
    public function test_a_declined_person_can_come_back_and_re_enter_the_queue(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $entry = $this->makeWaitingEntry($group, ['phone' => '01001234567']);
        $entry->markDeclined('مش مهتم دلوقتي');

        $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'أحمد',
            'phone' => '01001234567',
        ])->assertCreated();

        $this->assertSame('waiting', $entry->fresh()->status);
        $this->assertSame(1, $entry->fresh()->positionInLine());
        $this->assertSame(1, WaitingListEntry::where('group_class_id', $group->id)->count());
    }

    /** ⭐ ⭐ المجموعة الموقفة **مش** بتستقبل طلبات */
    public function test_a_paused_group_refuses_waitlist_requests(): void
    {
        $group = $this->makeGroup(['capacity' => 5, 'status' => 'paused']);

        $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'أحمد',
            'phone' => '01001234567',
        ])->assertStatus(422);

        $this->assertSame(0, WaitingListEntry::count());
    }

    /** ⭐ ⭐ وكمان المجموعة الممتلئة **لسه** بتستقبل (ده معنى الانتظار) */
    public function test_a_full_group_still_accepts_waitlist_requests_over_api(): void
    {
        $group = $this->makeGroup(['capacity' => 1]);
        $this->fillGroup($group, 1);

        $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'أحمد',
            'phone' => '01001234567',
        ])->assertCreated();

        $this->assertSame(1, WaitingListEntry::count());
    }

    /** ⭐ الاسم والموبايل مطلوبين — برسالة عربية */
    public function test_name_and_phone_are_required_in_arabic(): void
    {
        $group = $this->makeGroup(['capacity' => 5]);

        $r = $this->postJson("/api/groups/{$group->id}/waitlist", [])->assertStatus(422);

        $this->assertArrayHasKey('الاسم', $r->json('errors'));
        $this->assertArrayHasKey('رقم الهاتف', $r->json('errors'));
    }

    // ============================================================
    // ⑨ ⭐ الفصل: اللي بياخد الطلب ≠ اللي بيقرّر يدخل
    // ============================================================

    /**
     * ⭐ ⭐⭐ القرار الأهم في المشروع كله.
     *
     * الاستقبال بياخد الطلب (وده **عام**)، بس «ادخل» محمي.
     * لو الاستقبال قدر يدخّل، حد هيدخل من غير ما حد يبقى مسؤول.
     */
    public function test_reception_can_take_the_request_but_not_admit_anyone(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 1);
        $this->fillWaitingList($group, 1);

        // ① الطلب — **مافيش تسجيل دخول أصلاً**، فمافيش حاجة نمنعها
        $this->postJson("/api/groups/{$group->id}/waitlist", [
            'name' => 'عميل جديد',
            'phone' => '01005556666',
        ])->assertCreated();

        // ② «ادخل» — محمي
        $entry = WaitingListEntry::where('group_class_id', $group->id)->firstOrFail();
        $reception = $this->makeUserWithRole('reception', []);

        $this->postJson(
            "/api/groups/{$group->id}/waiting/{$entry->id}/admit",
            [],
            $this->authHeaders($reception)
        )->assertStatus(403);

        $this->assertTrue($entry->fresh()->isWaiting(), 'لسه مستني');
    }

    /** ⭐ نفس الكلام للرفض */
    public function test_reception_cannot_decline_either(): void
    {
        $group = $this->makeGroup(['capacity' => 2]);
        $entry = $this->makeWaitingEntry($group);

        $reception = $this->makeUserWithRole('reception', []);

        $this->postJson(
            "/api/groups/{$group->id}/waiting/{$entry->id}/decline",
            [],
            $this->authHeaders($reception)
        )->assertStatus(403);

        $this->assertTrue($entry->fresh()->isWaiting());
    }

    /** ⭐ الأدمن هو اللي يقدر يدخل ورفض ويدير المجموعة */
    public function test_admin_can_admit_decline_and_manage(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 5]);

        // ① يعمل مجموعة
        $created = $this->postJson('/api/groups', [
            'program_id' => $this->makeProgram()->id,
            'name' => 'مجموعة جديدة',
            'capacity' => 12,
        ], $this->authHeaders($admin))->assertCreated();

        $groupId = $created->json('data.id');

        // ② حد يسجّل
        $this->postJson("/api/groups/{$groupId}/waitlist", [
            'name' => 'أحمد',
            'phone' => '01001234567',
        ])->assertCreated();

        $entry = WaitingListEntry::where('group_class_id', $groupId)->firstOrFail();

        // ③ يدخله
        $this->postJson(
            "/api/groups/{$groupId}/waiting/{$entry->id}/admit",
            [],
            $this->authHeaders($admin)
        )->assertOk();

        $this->assertSame('joined', $entry->fresh()->status);

        // ④ يضيف طالب
        $this->postJson("/api/groups/{$groupId}/members", [
            'student_id' => $this->makeStudent()->id,
        ], $this->authHeaders($admin))->assertCreated();

        // ⑤ يعدّل العدد الأقصى
        $this->putJson("/api/groups/{$groupId}", [
            'program_id' => $this->makeProgram()->id,
            'name' => 'مجموعة جديدة',
            'capacity' => 20,
        ], $this->authHeaders($admin))->assertOk();
    }

    /** ⭐ ⭐ رقم الجرس — محمي، لأنه بيقول «مين مستني» */
    public function test_the_alert_count_needs_permission(): void
    {
        $reception = $this->makeUserWithRole('reception', []);

        $g1 = $this->makeGroup(['capacity' => 3]);
        $this->fillGroup($g1, 1);
        $this->fillWaitingList($g1, 2);

        // ⭐ المجموعة دي: فيه مقعدين فاضيين وفيه ناس ⇒ ⭐ تنبيه
        $this->assertTrue($g1->fresh()->hasWaitingAndSpace());

        // ① من غير حساب = 401
        $this->getJson('/api/groups/alerts')->assertStatus(401);

        // ② بدور من غير صلاحية = 403
        $this->getJson('/api/groups/alerts', $this->authHeaders($reception))->assertStatus(403);

        // ⭐ `admin` **بعد** الـ reception عن قصد.
        //
        // `Role::firstOrCreate('admin')` بيعمل الدور **مرة واحدة** وبيشترك
        // في الكاش بتاع Spatie. فلو الـ admin اتعمل قبل ما الـ reception
        // ياخد دوره، طلب الـ admin الأول بيفتتح الكاش واللي جواه
        // مافيش `groups.manage` لسه — فبيرجّع 403 **غلط**.
        //
        // الترتيب ده بيخلّي الاختبار يقيس الحاجة الصح: بعد ما كل
        // الأدوار والصلاحيات تبقى موجودة.
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);

        $r = $this->getJson('/api/groups/alerts', $this->authHeaders($admin))->assertOk();
        $this->assertSame(1, $r->json('count'));

        // ⭐ الأرقام **أرقام** — مش نصوص. عشان الواجهة تقدر
        // تعرضها بالعربي من غير ما تعمل cast.
        $this->assertSame(2, $r->json('groups.0.waiting'));
        $this->assertSame(2, $r->json('groups.0.seats_left'));
    }

    /** ⭐ ⭐ «ادخل» بيعلّم السطر وخلاص — مافيش طالب ولا اشتراك */
    public function test_admitting_over_the_api_creates_no_student_or_subscription(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 2]);
        $this->fillGroup($group, 1);
        $entry = $this->makeWaitingEntry($group);

        $before = [
            'students' => DB::table('students')->count(),
            'subscriptions' => DB::table('subscriptions')->count(),
            'members' => GroupMember::count(),
        ];

        $this->postJson(
            "/api/groups/{$group->id}/waiting/{$entry->id}/admit",
            [],
            $this->authHeaders($admin)
        )->assertOk();

        $this->assertSame($before['students'], DB::table('students')->count());
        $this->assertSame($before['subscriptions'], DB::table('subscriptions')->count());
        $this->assertSame($before['members'], GroupMember::count(), 'لسه في المجموعة فعلاً واحد');
    }

    /** ⭐ ⭐ ⚠️ «ادخل» **مش** بيعمل عضو من لوحده — يقولك تعمل إيه */
    public function test_admitting_tells_the_admin_the_student_is_not_in_the_group_yet(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 2]);
        $entry = $this->makeWaitingEntry($group);

        $r = $this->postJson(
            "/api/groups/{$group->id}/waiting/{$entry->id}/admit",
            [],
            $this->authHeaders($admin)
        )->assertOk();

        // ⭐ السطر مربوطش بحساب ⇒ لازم الشغل التاني
        $this->assertTrue($r->json('needs_member'));
        $this->assertStringContainsString(
            'اعمل للطالب حساب',
            $r->json('next_step')
        );
    }

    /** ⭐ مرتبط بحساب ⇒ الرد بيقول «ضيفه للقائمة» */
    public function test_admitting_a_linked_student_says_add_them_to_the_roster(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 2]);
        $entry = $this->makeWaitingEntry($group, [
            'student_id' => $this->makeStudent()->id,
        ]);

        $r = $this->postJson(
            "/api/groups/{$group->id}/waiting/{$entry->id}/admit",
            [],
            $this->authHeaders($admin)
        )->assertOk();

        $this->assertFalse($r->json('needs_member'));
        $this->assertStringContainsString('ضيف الطالب', $r->json('next_step'));
    }

    /** ⭐ ⭐ الشيل بيغيّر الحالة — مش بيمسح السطر */
    public function test_removing_a_member_keeps_the_row(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 2]);
        $student = $this->makeStudent();
        $member = GroupMember::admit($group, $student);

        $this->deleteJson(
            "/api/groups/{$group->id}/members/{$member->id}",
            [],
            $this->authHeaders($admin)
        )->assertOk();

        $this->assertSame('left', $member->fresh()->status);
        $this->assertSame(1, GroupMember::count(), 'السطر لسه موجود');
        $this->assertSame(0, $group->fresh()->occupancy()['members']);
    }

    /** ⭐ الرفض بيعمل سجل (مراجعة) — وشكله في الـ API */
    public function test_declining_records_a_reason_and_removes_from_queue(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 2]);
        $entry = $this->makeWaitingEntry($group);

        $this->postJson(
            "/api/groups/{$group->id}/waiting/{$entry->id}/decline",
            ['reason' => 'مش عايز أونلاين'],
            $this->authHeaders($admin)
        )->assertOk();

        $this->assertSame('declined', $entry->fresh()->status);
        $this->assertSame('مش عايز أونلاين', $entry->fresh()->notes);

        $list = $this->getJson("/api/groups/{$group->id}/waiting", $this->authHeaders($admin))
            ->assertOk();

        $this->assertSame('declined', $list->json('data.0.status'));
        $this->assertSame(0, $list->json('data.0.position'), 'الرافض مش في الطابور');
        $this->assertSame(0, $list->json('occupancy.waiting'));
    }

    /** ⭐ الطابور مرتّب وكل سطر برقمه — نفس حساب الشاشة */
    public function test_the_waiting_list_api_matches_the_model_positions(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 2]);
        $entries = $this->fillWaitingList($group, 3);

        $r = $this->getJson("/api/groups/{$group->id}/waiting", $this->authHeaders($admin))
            ->assertOk();

        $this->assertSame([1, 2, 3], array_column($r->json('data'), 'position'));

        // ⚠️ `fillWaitingList` بترجّع مصفوفة (مش Collection) — والسبب
        // إنها مصفوفة أرقام موبايل، مش موديلات. فلازم نحوّلها الأول.
        $this->assertSame(
            collect($entries)->pluck('id')->all(),
            array_column($r->json('data'), 'id'),
            'بنفس ترتيب التسجيل'
        );
    }

    // ============================================================
    // ⑩ الحقول
    // ============================================================

    /** ⭐ `capacity: 0` مرفوض — الصفر معناه «مفيش حد يدخل» وده غلط */
    public function test_capacity_zero_is_rejected(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);

        $this->postJson('/api/groups', [
            'program_id' => $this->makeProgram()->id,
            'name' => 'مجموعة',
            'capacity' => 0,
        ], $this->authHeaders($admin))->assertStatus(422);
    }

    /** ⭐ مافيش حد = `null` مش `0` — وده مسموح */
    public function test_capacity_null_means_open_and_is_accepted(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);

        $r = $this->postJson('/api/groups', [
            'program_id' => $this->makeProgram()->id,
            'name' => 'مجموعة مفتوحة',
            'capacity' => null,
        ], $this->authHeaders($admin))->assertCreated();

        $this->assertNull($r->json('data.capacity'));
        $this->assertNull($r->json('data.seats_left'));
        $this->assertTrue($r->json('data.has_space'));
    }

    /** ⭐ الوقت لازم يبقى ترتيبه صح — نهايته بعد بدايته */
    public function test_end_time_must_be_after_start_time(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);

        $this->postJson('/api/groups', [
            'program_id' => $this->makeProgram()->id,
            'name' => 'مجموعة',
            'weekday' => 4,
            'start_time' => '18:00',
            'end_time' => '17:00',
        ], $this->authHeaders($admin))->assertStatus(422);
    }

    /** ⭐ حذف المجموعة = **أرشفة** — مش مسح */
    public function test_deleting_a_group_archives_it(): void
    {
        $admin = $this->makeUserWithRole('admin', ['groups.manage']);
        $group = $this->makeGroup(['capacity' => 5]);
        $this->fillWaitingList($group, 2);

        $r = $this->deleteJson("/api/groups/{$group->id}", [], $this->authHeaders($admin))
            ->assertOk();

        // ⭐ ⚠️ الرسالة بتقول «في ناس مستنيين» — ده المهم
        $this->assertSame(2, $r->json('waiting_left'));
        $this->assertStringContainsString('مستنيين', $r->json('message'));

        // ⭐ الصف لسه موجود (soft delete)
        $this->assertNotNull($group->fresh()->trashed());
        $this->assertSame('archived', $group->fresh()->status);

        // ⭐ ومش في العرض العام
        $this->getJson('/api/groups')->assertOk()->assertJsonPath('meta.total', 0);
    }

    // ============================================================
    // ⑪ الميعاد
    // ============================================================

    public function test_the_schedule_label_reads_the_weekday_and_time(): void
    {
        $group = $this->makeGroup([
            'weekday' => 4,
            'start_time' => '16:00',
            'end_time' => '17:00',
        ]);

        $label = $group->scheduleLabel();

        $this->assertStringContainsString('الخميس', $label);
        $this->assertMatchesRegularExpression('/\d{1,2}:\d{2}/', $label);
    }

    public function test_no_schedule_label_when_there_is_no_schedule(): void
    {
        $this->assertNull($this->makeGroup()->scheduleLabel());
    }

    // ============================================================
    // ⑨ ⭐ الأرقام متفق عليها في كل مكان
    // ============================================================

    /**
     * ⭐ ⭐ لو اتحسب الرقم في مكانين هيبقى في رقمين.
     *
     * `occupancy()` من الموديل و`withCount()` لازم يطلعوا نفس
     * الرقم — لأن الشاشة والـ API بياخدوا من `occupancy()`.
     */
    public function test_eager_loaded_counts_match_the_computed_ones(): void
    {
        $group = $this->makeGroup(['capacity' => 4]);
        $this->fillGroup($group, 2);
        $this->fillWaitingList($group, 3);

        $eager = GroupClass::withOccupancy()->findOrFail($group->id)->occupancy();

        $this->assertSame(2, $eager['members']);
        $this->assertSame(3, $eager['waiting']);
        $this->assertSame(2, $eager['seats_left']);
    }

    /** ⭐ ⭐ «متحسبش مرتين» — الـ scope بيشتغل صح على قوايم */
    public function test_with_occupancy_does_not_double_count(): void
    {
        $group = $this->makeGroup(['capacity' => 6]);
        $this->fillGroup($group, 3);

        $o = GroupClass::withOccupancy()->findOrFail($group->id)->occupancy();

        $this->assertSame(3, $o['members'], 'مش ٦');
        $this->assertSame(3, $o['seats_left']);
    }
}
