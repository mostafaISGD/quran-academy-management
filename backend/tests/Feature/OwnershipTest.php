<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Teacher;
use App\Models\TeacherSchedule;
use Tests\TestCase;

/**
 * ⭐⭐⭐ فحص الملكية (IDOR) — «مين يقدر يشوف/يعدّل بتاع مين».
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة اللي الاختبار ده بيثبتها
 *
 * ① `NotificationController::markAsRead` مفيش فيه **أي** فحص ملكية:
 *
 *      public function markAsRead(Notification $notification)
 *      {
 *          $notification->update(['read_at' => now()]);
 *          return response()->json($notification);   // ⭐ بيرجّع البيانات كاملة
 *      }
 *
 *    الـ route model binding بيلاقي الإشعار بـ id **أي حد** عنده —
 *    فأي مستخدم مسجّل يقدر:
 *      • يعلّم إشعار حد تاني كمقروء
 *      • وياخد **محتوى** الإشعار في الرد (payload + user_id)
 *
 *    الإشعار بيحمل payload زي «اشتراك ابنك خلص» أو «فاتورة كذا» —
 *    يعني بيانات شخصية بتتسرب لحد مش صاحبها.
 *
 * ② `TeacherController::store` بيربط المعلم الجديد بـ **user_id** بتاع
 *    الـ admin اللي بيعمله:
 *
 *      $data['user_id'] = $request->user()->id;   // ⭐
 *
 *    النتيجة: **حساب المدير** بقى مربوط بسجل معلم. ده اللي خلى
 *    المدير يتحوّل لواجهة المعلم (شُف `AuthController::profile` والـ
 *    hack بتاع `is_teacher` اللي اتعمل عشان يخفي العَرَض).
 *
 * ③ `TeacherScheduleController::completeness` **نسي** ينادي
 *    `authorizeOwnership()` — فأي حد يقدر يستعلم على جدول أي معلم.
 *
 * ④ ⭐ `authorizeOwnership()` بتنادي `$user->hasPermissionTo('teachers.update')`
 *    من غير try/catch — فحد ما يكونش عنده الصلاحية **في الداتابيز**
 *    بياخد **500** مش 403.
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه 404 مش 403 في الإشعارات
 *
 * 404 بيقول «مفيش حاجة هنا» — وده الصح: إشعار حد تاني **مش موجود**
 * بالنسبة للمستخدم ده أصلاً. 403 بيكشف إن الـ id موجود أصلاً،
 * فبيبقى كأننا بنقول «ده إشعار حد، بس مش بتاعك».
 */
class OwnershipTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① الإشعارات
    // ═══════════════════════════════════════════════════════════════

    private function makeNotificationFor($user, array $payload = []): Notification
    {
        return Notification::create(array_merge([
            'user_id' => $user->id,
            'event_type' => 'payment_received',
            'channel' => 'in_app',
            'payload' => ['note' => 'اشتراك ابنك خلص'],
        ], $payload));
    }

    public function test_a_user_cannot_mark_another_users_notification_as_read(): void
    {
        $owner = $this->makeUserWithRole('parent', []);
        $attacker = $this->makeUserWithRole('parent', []);

        $notification = $this->makeNotificationFor($owner);

        $this->postJsonAs("/api/notifications/{$notification->id}/read", [], $attacker)
            ->assertNotFound();

        // ⭐ والأهم: الإشعار **ما اتغيّرش**. 403/404 بلا تعديل.
        $this->assertNull(
            $notification->fresh()->read_at,
            'إشعار حد تاني اتعلّم كمقروء — التعديل لازم ما يحصلش أصلاً'
        );
    }

    public function test_marking_another_users_notification_read_leaks_no_data(): void
    {
        $owner = $this->makeUserWithRole('parent', []);
        $attacker = $this->makeUserWithRole('parent', []);

        $notification = $this->makeNotificationFor($owner, [
            'payload' => ['secret' => 'رقم-الهاتف-01099999999'],
        ]);

        $body = $this->postJsonAs("/api/notifications/{$notification->id}/read", [], $attacker)
            ->assertNotFound()
            ->getContent();

        // ⭐ الرد لازم ما يفضحش **محتوى** الإشعار.
        //
        // ⚠️ بنفحص القيم الحقيقية بس (السر ورقم الموبايل)، مش
        // `user_id` — لأن الأرقام الصغيرة بتلاقي نفسها بالصدفة
        // في `line: 1423` بتاعة صفحة الـ debug، والاختبار كان
        // بيفشل بسبب مطابقة رقم سطر مش تسريب حقيقي.
        $this->assertStringNotContainsString('secret', $body);
        $this->assertStringNotContainsString('01099999999', $body);
        $this->assertStringNotContainsString('رقم-الهاتف', $body);
    }

    /**
     * ⭐ ملاحظة: رد الـ 404 في بيئة الاختبار بيطلع
     * `exception` + `file` + `line` — لأن `APP_DEBUG=true`.
     * ده **مش** حقيقي في الإنتاج (`APP_DEBUG=false`)، لكنه
     *يستحق إن PR 0.6 يوحّد ردود الأخطاء. الاختبار ده بيوثّق
     * السلوك الحالي بدل ما يفترض حاجة غلط.
     */
public function test_the_404_does_not_echo_the_owners_notification_payload(): void
    {
        $owner = $this->makeUserWithRole('parent', []);
        $attacker = $this->makeUserWithRole('parent', []);

        $notification = $this->makeNotificationFor($owner, [
            'payload' => ['student_id' => 987654, 'invoice' => 'INV-SECRET-001'],
        ]);

        $json = $this->postJsonAs("/api/notifications/{$notification->id}/read", [], $attacker)
            ->assertNotFound()
            ->json();

        // ⭐ الرد لازم يبقى رسالة الخطأ بس — مافيش payload ولا
        // أي معرّف من الـ payload بتاع حد تاني.
        $this->assertArrayNotHasKey('payload', $json);
        $this->assertArrayNotHasKey('user_id', $json);
        $this->assertArrayNotHasKey('event_type', $json);
        $this->assertStringNotContainsString('INV-SECRET-001', json_encode($json));
    }

    public function test_the_owner_can_still_mark_their_own_notification_as_read(): void
    {
        $owner = $this->makeUserWithRole('parent', []);
        $notification = $this->makeNotificationFor($owner);

        $this->postJsonAs("/api/notifications/{$notification->id}/read", [], $owner)
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    // ═══════════════════════════════════════════════════════════════
    // ② إنشاء معلم — مين الـ user_id؟
    // ═══════════════════════════════════════════════════════════════

    public function test_creating_a_teacher_does_not_attach_the_creating_admins_account(): void
    {
        $admin = $this->makeUserWithRole('admin', ['teachers.create']);

        $r = $this->postJsonAs('/api/teachers', [
            'display_name' => 'معلم جديد',
            'phone' => '01000000001',
        ], $admin)->assertCreated();

        $teacher = Teacher::findOrFail($r->json('id'));

        // ⭐ المعلم الجديد مالوش حساب لحد ما يتبعت له دعوة (PR 1.4).
        // ربطه بحساب الـ admin معناه إن المدير بيتقنع إنه معلم.
        $this->assertNull(
            $teacher->user_id,
            'المعلم الجديد اتربط بحساب الـ admin — لازم يبقى null لحد الدعوة'
        );

        // ⭐ والحماية الحقيقية: لازم ما يبقاش في **أي** معلم مربوط.
        $this->assertSame(
            0,
            Teacher::where('user_id', $admin->id)->count(),
            'حساب الـ admin بقى مربوط بسجل معلم — ده اللي كان بيحوّله لواجهة معلم'
        );
    }

    public function test_a_teacher_can_still_be_created_and_be_read_back(): void
    {
        $admin = $this->makeUserWithRole('admin', ['teachers.create', 'teachers.view']);

        $r = $this->postJsonAs('/api/teachers', [
            'display_name' => 'معلم سليم',
            'phone' => '01000000002',
        ], $admin)->assertCreated();

        $this->getJsonAs('/api/teachers/'.$r->json('id'), $admin)->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ جدول أوقات المعلم
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ حساب مسجّل — **بلا** `teachers.edit` في الداتابيز.
     *
     * ده اللي كان بيخلّي `hasPermissionTo()` **يرمي**
     * `PermissionDoesNotExist` والنتيجة 500 بدل 403.
     */
    private function teacherUser(array $permissions = ['lessons.view'])
    {
        $user = $this->makeUserWithRole('teacher', $permissions);
        $teacher = $this->makeTeacher($user);

        return [$user, $teacher];
    }

    public function test_a_teacher_cannot_read_another_teachers_schedule(): void
    {
        [$me] = $this->teacherUser();
        [, $victim] = $this->teacherUser();

        $this->getJsonAs("/api/teachers/{$victim->id}/availability", $me)
            ->assertForbidden();
    }

    public function test_a_teacher_cannot_add_a_block_to_another_teachers_schedule(): void
    {
        [$me] = $this->teacherUser();
        [, $victim] = $this->teacherUser();

        $this->postJsonAs("/api/teachers/{$victim->id}/availability", [
            'weekday' => 0,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
            'kind' => 'academy',
        ], $me)->assertForbidden();

        $this->assertSame(0, TeacherSchedule::where('teacher_id', $victim->id)->count());
    }

    public function test_a_teacher_cannot_delete_another_teachers_block(): void
    {
        [$me] = $this->teacherUser();
        [, $victim] = $this->teacherUser();

        $block = TeacherSchedule::create([
            'teacher_id' => $victim->id,
            'weekday' => 1,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
            'kind' => 'academy',
        ]);

        $this->deleteJsonAs("/api/teachers/{$victim->id}/availability/{$block->id}", $me)
            ->assertForbidden();

        $this->assertDatabaseHas('teacher_schedules', ['id' => $block->id]);
    }

    /**
     * ⭐⭐ `completeness` كانت **بلا** `authorizeOwnership()` أصلاً —
     * فأي مستخدم مسجّل كان يقدر يستعلم على جدول أي معلم.
     */
    public function test_a_teacher_cannot_check_another_teachers_completeness(): void
    {
        [$me] = $this->teacherUser();
        [, $victim] = $this->teacherUser();

        $this->getJsonAs("/api/teachers/{$victim->id}/availability/completeness", $me)
            ->assertForbidden();
    }

    /**
     * ⭐⭐ والفحص لازم يبقى على **كل** الراوتس، مش بس القراءة.
     */
    public function test_completeness_does_not_leak_another_teachers_block_count(): void
    {
        [$me] = $this->teacherUser();
        [, $victim] = $this->teacherUser();

        foreach (range(0, 2) as $weekday) {
            TeacherSchedule::create([
                'teacher_id' => $victim->id,
                'weekday' => $weekday,
                'starts_at' => '16:00',
                'ends_at' => '17:00',
                'kind' => 'academy',
            ]);
        }

        $this->getJsonAs("/api/teachers/{$victim->id}/availability/completeness", $me)
            ->assertForbidden()
            ->assertJsonMissing(['blocks_count' => 3]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ 500 بدل 403
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ الاختبار ده بيمسك الباج اللي **ماتسببش في ثغرة**
     * بس كان بيطلّع 500 بدل 403 — وده بيبعت صفحة خطأ ومفيش
     * distinction بين «مالكش صلاحية» و«خطأ في السيرفر».
     *
     * ⭐ `CheckPermission` عندها try/catch وبيحوّل الاستثناء لـ 403.
     * `authorizeOwnership` جوه الـ controller مالوش نفس الحماية.
     */
    public function test_a_missing_permission_row_gives_a_clean_403_not_500(): void
    {
        [$me, $mine] = $this->teacherUser(['lessons.view']);
        [, $other] = $this->teacherUser();

        // ⭐ `teachers.edit` مش موجود في جدول permissions.
        $this->assertDatabaseMissing('permissions', ['name' => 'teachers.update']);

        // ⭐ الاتجاهين مهمين:
        //
        // ① جدول **حد تاني** → 403. قبل الإصلاح ده كان 500 لأن
        //    `hasPermissionTo()` بيرمي.
        $this->getJsonAs("/api/teachers/{$other->id}/availability", $me)
            ->assertForbidden();

        $this->getJsonAs("/api/teachers/{$other->id}/availability/completeness", $me)
            ->assertForbidden();

        // ② جدول **المعلم نفسه** → لازم يفضل شغّال.
        //
        //    ⭐⭐ ده هو الجزء اللي كان **مكسور فعلياً**: قبل
        //    الإصلاح أي معلم حقيقي (مالوش صف teachers.edit في
        //    داتابيز بيئته) كان بياخد 500 على جدوله هو.
        //    فحماية زي دي ممكن تقفل المعلّم برّه من جدوله —
        //    وده اللي الاختبار ده بيمنعه.
        $this->getJsonAs("/api/teachers/{$mine->id}/availability", $me)
            ->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ الاتجاه الصح — المعلم لازم يقدر يشتغل على جدوله
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ الفحوصات اللي فوق لو ما كانش متلوش **الاتجاه
     * المعاكس**: الإصلاح ممكن يخلي المعلّم نفسه يتقفل برّه من جدوله.
     * ده اللي بيحصل لما تضيف `permission` غلط.
     */
    public function test_a_teacher_can_manage_their_own_schedule(): void
    {
        [$me, $mine] = $this->teacherUser(['lessons.view']);

        $this->getJsonAs("/api/teachers/{$mine->id}/availability", $me)->assertOk();

        $this->postJsonAs("/api/teachers/{$mine->id}/availability", [
            'weekday' => 2,
            'starts_at' => '16:00',
            'ends_at' => '17:00',
            'kind' => 'academy',
        ], $me)->assertCreated();

        $this->getJsonAs("/api/teachers/{$mine->id}/availability/completeness", $me)
            ->assertOk()
            ->assertJsonPath('has_schedule', true);
    }

    /**
     * ⭐ الأدمن (عنده `teachers.edit`) يعدّل جدول أي حد — الوضع
     * المقصود أصلاً. الفحص لازم يفرّق بين «مش بتاعي» و«مش مسؤول».
     */
    public function test_an_admin_with_teachers_edit_can_manage_any_schedule(): void
    {
        $admin = $this->makeUserWithRole('admin', ['teachers.update', 'teachers.view']);
        [, $other] = $this->teacherUser();

        $this->getJsonAs("/api/teachers/{$other->id}/availability", $admin)->assertOk();

        $this->getJsonAs("/api/teachers/{$other->id}/availability/completeness", $admin)
            ->assertOk();
    }
}