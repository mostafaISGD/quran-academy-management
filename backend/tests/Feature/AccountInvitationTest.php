<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\InvitationMail;
use App\Services\InvitationService;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * ⭐⭐⭐ الدعوات — الحساب بيتعمل بدعوة، مش بكلمة مرور مُسلَّمة.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * قبل PR 0.4، أي حساب جديد كان بيتعمل بـ:
 *
 *     'password' => Hash::make('password'),
 *
 * ⭐ **كل الحسابات في النظام بتشارك كلمة مرور واحدة.** مافيش حد
 * يقدر يدخل بحسابه لو عرف الإيميل.
 *
 * PR 0.4 قفل النتيجة بـ `must_change_password` — بس **غيّرش آلية
 * الإنشاء**. الحساب لسه بيتعمل بكلمة مرور، وأول حاجة الـ`admin`
 * يعملها إنه يسلّم كلمة المرور **على ورق** أو في واتساب.
 *
 * ⭐ الـPR ده بيشيل **السبب** مش العرض.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة: التوكن موجود في مكان واحد فقط
 *
 * `invitations.token_hash` = SHA-256 لـ64 byte عشوائي.
 * **التوكن الخام بيرجع مرة واحدة** — في رد `201` أو في الإيميل —
 * ومش بيتخزّن **في أي مكان**.
 *
 * ⭐ المعادلة: أي حد يقرا الداتابيز (backup مسروق، SQL injection،
 * زميل عنده صلاحية logs) **مش** يقدر يسجّل دخول بحساب حد تاني.
 * التوكن مش موجود عنده.
 *
 * ⭐ **ليه SHA-256 لـ64 byte كافي؟** لأن المدخل **مش قابل للتخمين**:
 * 2^512 احتمال. مش زي bcrypt اللي محتاج `password_hash` كامل.
 * ومفيش pepper محتاجينه لأن الـtoken **مش** سر طويل الأجل — هو
 * عمره ٧٢ ساعة ومرة واحدة.
 *
 * @see \App\Services\InvitationService
 * @see \Tests\Feature\PrivilegeEscalationTest
 */
class AccountInvitationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    private const NEW_PASSWORD = 'qwertyuiop12ZX!';

    private function inviter(): User
    {
        return $this->makeUserWithRole('admin', ['users.invite', 'users.roles.assign']);
    }

    /**
     * ⭐⭐ صلاحية `users.invite` لازم تكون في الكتالوج.
     *
     * ⭐ تحطّها في `RESTRICTED_ROLES` هنا مش في `RoleIsolationTest`
     * لأن الاختبار ده بيغطّي **سلوك** الصلاحية (مين يقدر يدعي
     * مين)، والـ`RoleIsolationTest` بيغطّي **المصفوفة**.
     */
    public function test_the_invite_permission_exists_in_the_catalog(): void
    {
        $this->seed(\Database\Seeders\SeedRolesPermissions::class);

        $names = collect(\Database\Seeders\SeedRolesPermissions::permissions())
            ->pluck('name')
            ->all();

        $this->assertContains(
            'users.invite',
            $names,
            'صلاحية `users.invite` مش في الكتالوج — ومحدش هيقدر يدعي حد'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ① الإنشاء
    // ═══════════════════════════════════════════════════════════════

    public function test_an_inviter_can_create_an_invitation(): void
    {
        Notification::fake();

        $inviter = $this->inviter();

        $r = $this->postJsonAs('/api/invitations', [
            'email' => 'new@example.test',
            'role_id' => DB::table('roles')->where('name', 'teacher')->value('id'),
        ], $inviter);

        $r->assertCreated();

        $this->assertDatabaseHas('invitations', [
            'email' => 'new@example.test',
            'invited_by' => $inviter->id,
            'accepted_at' => null,
        ]);
    }

    /**
     * ⭐⭐⭐ **التوكن الخام مش بيتخزّن. ده أهم assertion في الملف.**
     *
     * ⭐ بنقرا الصف من الداتابيز — مش من رد الـAPI — عشان نثبت إن
     * المتسجل فعلاً مش صريح.
     */
    public function test_the_token_is_never_stored_in_plain_text(): void
    {
        Notification::fake();

        $plain = app(InvitationService::class)->create(
            ['email' => 'new@example.test'],
            $this->inviter(),
        );

        $row = DB::table('invitations')->where('email', 'new@example.test')->first();

        $this->assertNotSame(
            $plain,
            $row->token_hash,
            '⭐⭐⭐ التوكن الخام متسجل — أي حد يقرا الداتابيز يسجّل دخول بحساب حد'
        );

        $this->assertSame(
            hash('sha256', $plain),
            $row->token_hash,
            'المسجل مش SHA-256 للتوكن'
        );

        // ⭐ وطول الـhash = 64 حرف hex
        $this->assertSame(64, strlen($row->token_hash));
    }

    /**
     * ⭐⭐⭐ التوكن = **٥١٢ بت عشوائية، مطبوعة**.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه «مطبوع» شرط، مش رفاهية
     *
     * ⭐ أول نسخة رجّعت `random_bytes(64)` **خام**. النتيجة كانت
     * **500 على كل رد**:
     *
     *     Malformed UTF-8 characters, possibly incorrectly encoded
     *
     * ⭐ لأن `json_encode` بيرمي على أول بايت مش UTF-8.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ وليه **base64url** مش base64 العادي
     *
     * ⭐ النسخة التانية استخدمت base64 العادي، واختبار الإيميل
     * فشل: التوكن اللي طلع في الرابط كان `abc%2Bdef%2Fghi%3D%3D`
     * — مش نفس التوكن، لأن `+` و `/` و `=` محتاجين encoding.
     *
     * ⭐ ودي مش مسألة شكلية: الواجهة بتقرا التوكن من `?token=`، فلو
     * نسيت `decodeURIComponent` هترفض **دعوة صحيحة**. والاختبار
     * ده بيحرس `rawurlencode($token) === $token` — يعني التوكن
     * **جاهز** للـURL بلا أي معالجة.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ وليه `random_bytes` مش `Str::random`
     *
     * ⭐ `Str::random` بيولّد من **أبجدية مختارة** — ≈ 2^380 بت.
     * `random_bytes(64)` = **٥١٢ بت مباشرة** من الـCSPRNG.
     *
     * ⭐ الترميز (encoding) مش تشفير ومش hash — الـentropy زي ما هي.
     *
     * @see \App\Services\InvitationService::generateToken
     */
    public function test_the_token_is_512_bits_of_true_randomness_printed_as_ascii(): void
    {
        Notification::fake();

        $service = app(InvitationService::class);
        $inviter = $this->inviter();

        $first = $service->create(['email' => 'a@example.test'], $inviter);
        $second = $service->create(['email' => 'b@example.test'], $inviter);

        // ⭐⭐Entropy: 64 **byte** عشوائي = ٥١٢ بت.
        $this->assertSame(64, strlen((string) $this->decodeToken($first)), 'التوكن مش 64 بايت عشوائي');
        $this->assertSame(64, strlen((string) $this->decodeToken($second)));

        // ⭐⭐ **مطبوع** — يخلي الـJSON والإيميل شغالين
        $this->assertMatchesRegularExpression(
            '/\A[A-Za-z0-9_-]+\z/',
            $first,
            'التوكن فيه بايت مش مطبوع — هيطلع 500 في الـJSON ويفشل في الرابط'
        );

        // ⭐⭐⭐ **آمن في الـURL بلا معالجة** — أهم assertion في
        // الملف، لأنه الشرط اللي اتكسّر مرتين.
        $this->assertSame(
            $first,
            rawurlencode($first),
            'التوكن محتاج URL-encoding — الواجهة هترفض دعوة صحيحة'
        );

        // ⭐ والـRNG شغال
        $this->assertNotSame($first, $second, 'التوكنات بتتكرر — الـRNG مش شغال');
    }

    /**
     * ⭐⭐⭐ التشفير العكسي للـbase64url — عشان نثبت الـ٥١٢ بت.
     *
     * ⭐ `base64_decode` في PHP بيشتغل على مدخل من غير padding
     * (`64 % 3 = 1` فآخر quartet بيتكوّن من حرفين بس).
     */
    private function decodeToken(string $token): string|false
    {
        return base64_decode(strtr($token, '-_', '+/'), true);
    }

    /**
     * ⭐ صلاحية ٧٢ ساعة — ومعناها **من moment الإنشاء**.
     */
    public function test_the_invitation_lasts_seventy_two_hours(): void
    {
        Notification::fake();

        $service = app(InvitationService::class);

        $service->create(['email' => 'a@example.test'], $this->inviter());

        $row = DB::table('invitations')->where('email', 'a@example.test')->first();

        $hours = now()->diffInHours($row->expires_at, false);

        $this->assertGreaterThanOrEqual(71, $hours, "الدعوة بتنتهي بعد {$hours} ساعة");
        $this->assertLessThanOrEqual(73, $hours, "الدعوة بتنتهي بعد {$hours} ساعة");
    }

    // ═══════════════════════════════════════════════════════════════
    // ② القبول — الـacceptance
    // ═══════════════════════════════════════════════════════════════

    public function test_a_valid_token_can_be_accepted(): void
    {
        Notification::fake();

        $token = app(InvitationService::class)->create(
            ['email' => 'new@example.test'],
            $this->inviter(),
        );

        $r = $this->postJson('/api/invitations/accept', [
            'token' => $token,
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ]);

        $r->assertOk();

        $user = User::where('email', 'new@example.test')->first();

        $this->assertNotNull($user, 'مافيش حساب اتعمل');
        $this->assertSame('active', $user->status);
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertNotNull($user->password_changed_at);

        $this->assertDatabaseHas('invitations', [
            'email' => 'new@example.test',
        ]);
        $this->assertNotNull(
            DB::table('invitations')->where('email', 'new@example.test')->value('accepted_at'),
            'الدعوة ما اتسجلش أنها اتقبلت'
        );
    }

    /**
     * ⭐⭐⭐ **الاستخدام التاني = 410.**
     *
     * ⭐ ده الـacceptance بتاع الـPR بالحرف.
     *
     * ⭐ **ليه 410 مش 403؟** التوكن «راح» — مش «محرم». و410
     * بيقول للعميل إن إعادة المحاولة **مش هتفيد**، فميقدرش
     * يعمل retry loop. 403 هيدفعه يجرّب تاني طول اليوم.
     *
     * ⭐ و**نفس الكود** للتوكن المجهول: مافيش فايدة نرجّع 404
     * لتوكن مش موجود — ده بيكشف إن التوكنات **كانت** موجودة.
     * 410 لكل حاجة بتاعت الدعوة = مافيش تسريب.
     */
    public function test_using_the_same_token_twice_is_gone(): void
    {
        Notification::fake();

        $token = app(InvitationService::class)->create(
            ['email' => 'new@example.test'],
            $this->inviter(),
        );

        $payload = [
            'token' => $token,
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ];

        $this->postJson('/api/invitations/accept', $payload)->assertOk();

        $second = $this->postJson('/api/invitations/accept', $payload);

        $second->assertStatus(410, 'التوكن اتقبل مرتين — الحساب قابل للإعادة');
    }

    /**
     * ⭐⭐⭐ **منتهية = 410.** الـacceptance بتاع الـPR.
     */
    public function test_an_expired_invitation_is_gone(): void
    {
        Notification::fake();

        $token = app(InvitationService::class)->create(
            ['email' => 'new@example.test'],
            $this->inviter(),
        );

        // ⭐ نمضي بالوقت — `Carbon::setTestNow` أأمن من `sleep()`
        DB::table('invitations')->update([
            'expires_at' => now()->subMinute(),
        ]);

        $r = $this->postJson('/api/invitations/accept', [
            'token' => $token,
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ]);

        $r->assertStatus(410);

        $this->assertNull(
            User::where('email', 'new@example.test')->first(),
            '⬜حساب اتعمل من دعوة منتهية'
        );
    }

    /**
     * ⭐ توكن **مش موجود أصلاً** = نفس الكود — مافيش تسريب.
     */
    public function test_an_unknown_token_is_also_gone(): void
    {
        $r = $this->postJson('/api/invitations/accept', [
            'token' => str_repeat('z', 88),
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ]);

        $r->assertStatus(410);
    }

    /**
     * ⭐⭐ الـ`accept` **عام** — مافيش جلسة، وده منطقي: اللي بيقبّل
     * لسه مالوش حساب.
     *
     * ⭐ ومقيّد بـ`throttle` —urfaces فضاء.
     */
    public function test_the_accept_endpoint_is_public_and_throttled(): void
    {
        $r = $this->postJson('/api/invitations/accept', [
            'token' => 'x',
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ]);

        // ⭐ مش 401/403 — يعني عام فعلاً. اللي بيقبّل لسه مالوش حساب.
        $this->assertNotContains(
            $r->status(),
            [401, 403],
            'الـaccept endpoint محمي بجلسة — بس اللي بيقبّل مالوش حساب أصلاً'
        );

        // ⭐ والـthrottle موجود فعلاً —urfaces فضاء مفتوح
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($r) => str_contains($r->uri(), 'invitations/accept'));

        $this->assertNotNull($route, 'مافيش راو للقبول');

        $this->assertNotEmpty(
            array_filter(
                $route->gatherMiddleware(),
                fn ($m) => is_string($m) && str_contains($m, 'throttle')
            ),
            'الـaccept endpoint من غير throttle — حد يقدر يجرّب مليون توكن'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ سياسة كلمة المرور
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ كلمة المرور بتخصد بالسياسة القوية.
     */
    public function test_a_weak_password_is_refused(): void
    {
        Notification::fake();

        $token = app(InvitationService::class)->create(
            ['email' => 'new@example.test'],
            $this->inviter(),
        );

        foreach (['short1!A', 'aaaaaaaaaaaa', 'qwertyuiop12'] as $weak) {
            $r = $this->postJson('/api/invitations/accept', [
                'token' => $token,
                'password' => $weak,
                'password_confirmation' => $weak,
            ]);

            $r->assertStatus(422, "كلمة المرور الضعيفة «{$weak}» اتقبلت");
        }

        // ⭐ والدعوة لسه صالحة — كلمة ضعيفة مش بتبطلها
        $this->assertNull(
            DB::table('invitations')->where('email', 'new@example.test')->value('accepted_at'),
            'كلمة مرور ضعيفة بتبطل الدعوة — لازم المستخدم يقدر يجرّب تاني'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ مين يقدر يدعي مين
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **مين يقدر يدعي مين — نفس قاعدة تسليم الصلاحيات.**
     *
     * ⭐ محدش يدعي حد بدور أعلى منه.
     *
     * ⚠️⚠️ **الفخ اللي الاختبار الأول دخل فيه فعلاً:**
     *
     * ⭐ أول نسخة كان بتعمل
     * `DB::table('roles')->where('name','admin')->value('id')` — بس
     * `makeUserWithRole` بيعمل **الدور اللي طلبته بس** على الـfly،
     * فمافيش صف `admin`، والنتيجة `null`، والـ`role_id` بعت `null`،
     * و`InvitationService::create()` بتعمل
     * `if (! empty($data['role_id']))` — **فالفحص اتخطّى بالكامل**
     * والرد رجع **201**.
     *
     * ⭐ درس مهم: أي assertion على `role_id` من جدول الأدوار لازم
     * assert إنه **مش null الأول**. صفر صلاحية مش اختبار.
     */
    public function test_nobody_can_invite_someone_into_the_admin_role(): void
    {
        Notification::fake();

        $supervisor = $this->makeUserWithRole('supervisor', [
            'users.invite', 'users.roles.assign', 'students.view',
        ]);

        $adminRole = $this->makeRole('admin', ['finance.movements.reverse']);

        $r = $this->postJsonAs('/api/invitations', [
            'email' => 'sneaky@example.test',
            'role_id' => $adminRole->id,
        ], $supervisor);

        $r->assertForbidden('مشرف قدر يدعي حد بدور admin — تصعيد');

        $this->assertDatabaseMissing('invitations', ['email' => 'sneaky@example.test']);
    }

    /**
     * ⭐⭐⭐ **والقاعدة الأعمّ: ما تسلّمش اللي مش ماسكه.**
     *
     * ⭐ الاختبار فوق بيضرب في `isAdminRole()` — حماية **اسمية**
     * على اسم الدور.
     *
     * ⭐ وده **مش** الحماية الحقيقية. الحماية الحقيقية إن الدور
     * الموقَّع فيه صلاحية **ميكسهاش** مObama. وده اللي
     * `permissionsActorLacks()` بيفحصه.
     *
     * ⭐ الاختبار ده بيستهدف الحالة اللي `isAdminRole` **مش**
     * بيلقطها: دور `principal` (مش admin) فيه صلاحية مالية.
     *
     * ⭐⭐ وده اللي بيقفل **التصعيد التدريجي**: لو حد يقدر يدعي
     * حد دوراً فيه صلاحية واحدة ناقصة، يقدر يبني عليه في اليوم
     * التاني دور فيه تلات ناقصة — كل خطوة لوحدها «قانونية».
     */
    public function test_nobody_can_deliver_a_permission_they_do_not_hold_through_any_role(): void
    {
        Notification::fake();

        $supervisor = $this->makeUserWithRole('supervisor', [
            'users.invite', 'users.roles.assign', 'students.view',
        ]);

        // ⭐ دور **مش** admin — فـ`isAdminRole()` مش هيلقطه
        $principal = $this->makeRole('principal', ['finance.movements.reverse']);

        $this->assertNotNull(
            $principal->permissions->firstWhere('name', 'finance.movements.reverse'),
            'الـfixture مابيش.MakeRole لازم يربط الصلاحية بالدور فعلاً'
        );

        $r = $this->postJsonAs('/api/invitations', [
            'email' => 'sneaky@example.test',
            'role_id' => $principal->id,
        ], $supervisor);

        $r->assertForbidden('مشرف قدر يسلّم صلاحية مالية هو مش ماسكها');

        $this->assertDatabaseMissing('invitations', ['email' => 'sneaky@example.test']);
    }

    /**
     * ⭐ المشرف يقدر يدعي بدور **أقل** منه — وده الوضع الصح.
     *
     * ⭐ نفس الـrole والصلاحيات بالظبط، فالقاعدة consented.
     * الاختبار ده هو اللي بيحرس إن القاعدة **مش** متشددة لدرجة
     * إنها تكسر الاستخدام الطبيعي.
     */
    public function test_an_inviter_can_deliver_a_role_whose_permissions_they_hold(): void
    {
        Notification::fake();

        $inviter = $this->makeUserWithRole('admin', [
            'users.invite', 'users.roles.assign', 'students.view',
        ]);

        // ⭐ نفس الصلاحيات اللي الـinviter ماسكها — بس الدور مختلف
        $teacher = $this->makeRole('teacher', ['students.view']);

        $this->postJsonAs('/api/invitations', [
            'email' => 'new@example.test',
            'role_id' => $teacher->id,
        ], $inviter)->assertCreated();
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ الإلغاء وإعادة الإرسال
    // ═══════════════════════════════════════════════════════════════

    // ⭐⭐ `makeRole()` بقى في `tests/BuildsFixtures.php` — كان
    /**
     * ⭐⭐⭐ الإلغاء بيبطّل التوكن فورًا.
     *
     * ⭐ الإلغاء **حذف الصف** — مش `accepted_at` ولا حالة. لو
     * التوكن فضل في الجدول بعد الإلغاء، حد يقدر يقرا `id`
     * ويتأكد إن الدعوة كانت موجودة.
     */
    public function test_an_inviter_can_cancel_an_invitation(): void
    {
        Notification::fake();

        $inviter = $this->inviter();

        $token = app(InvitationService::class)->create(
            ['email' => 'new@example.test'],
            $inviter,
        );

        $id = DB::table('invitations')->where('email', 'new@example.test')->value('id');

        $this->deleteJsonAs("/api/invitations/{$id}", $inviter)->assertOk();

        $r = $this->postJson('/api/invitations/accept', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $r->assertStatus(410, 'التوكن الملغي لسه شغال');
    }

    /**
     * ⭐⭐⭐ إعادة الإرسال بتدّي **توكن جديد** وتبطل القديم.
     *
     * ⭐ لو الإرسال الجديد رجّع نفس التوكن، فإلغاؤه بيبطل
     * الدعوة الجديدة كمان.
     *
     * ⭐ والتوكن الجديد بيتقرا من **رد الـAPI** — وده بالظبط
     * الاستعمال الوحيد المسموح. `InvitationService` **مالهاش**
     * دالة ترجّع توكن من الداتابيز، ومفيش حاجة **ممكن** تعملها.
     */
    public function test_resending_invalidates_the_old_token(): void
    {
        Notification::fake();

        $inviter = $this->inviter();
        $service = app(InvitationService::class);

        $old = $service->create(['email' => 'new@example.test'], $inviter);

        $id = DB::table('invitations')->where('email', 'new@example.test')->value('id');

        $r = $this->postJsonAs("/api/invitations/{$id}/resend", [], $inviter);

        $r->assertOk();

        $fresh = $r->json('token');

        $this->assertNotEmpty($fresh, 'الرد مافيهوش توكن جديد — الـresend مش بيعمل حاجة');
        $this->assertNotSame(
            $old,
            $fresh,
            '⭐ إعادة الإرسال رجّعت نفس التوكن — إلغاء الدعوة الجديدة هتبطل القديمة كمان'
        );

        // ⭐⚠️ **والمhash في الداتابيز اتغيّر فعلاً** — مش بس الرد
        $this->assertSame(
            hash('sha256', $fresh),
            DB::table('invitations')->find($id)->token_hash,
            'التوكن الجديد رجع في الرد بس ما اتخزّنش — الدعوة مش هتشتغل'
        );

        // ⭐ القديم مبقاش صالح
        $this->postJson('/api/invitations/accept', [
            'token' => $old,
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ])->assertStatus(410);
    }

    /**
     * ⭐⭐⭐ **دعوة جديدة للإيميل نفسه بتبطل القديمة.**
     *
     * ⭐ من غير كده ممكن يكون في ٣ روابط حيّة لنفس الحساب، وأقدم
     * واحدة فيهم تبقى هي اللي اشتغلت.
     */
    public function test_a_new_invitation_invalidates_older_ones_for_the_same_email(): void
    {
        Notification::fake();

        $service = app(InvitationService::class);
        $inviter = $this->inviter();

        $first = $service->create(['email' => 'new@example.test'], $inviter);

        // ⭐ نعملها مرتين عشان نأكد إن مش أول واحدة بس
        $service->create(['email' => 'new@example.test'], $inviter);
        $third = $service->create(['email' => 'new@example.test'], $inviter);

        $this->postJson('/api/invitations/accept', [
            'token' => $first,
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ])->assertStatus(410, 'الدعوة الأولى لسه شغالة بعد ما عملنا تالت واحدة');

        // ⭐ والتالتة هي اللي شغالة
        $this->postJson('/api/invitations/accept', [
            'token' => $third,
            'password' => 'qwertyuiop12ZX!',
            'password_confirmation' => 'qwertyuiop12ZX!',
        ])->assertOk();
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ الإيميل
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ الإيميل بيتسلّم **بالعربي** و**فيه التوكن**.
     *
     * ⭐ مش «إشعار اتبعت» — ده لا يكفي. الدعوة **مالها قيمة** غير
     * إن الرابط وصل لصاحبه. فلازم نفحص إن التوكن **جوّه** فعلاً.
     *
     * ⭐ والـ`MAIL_MAILER=log` في `.env.testing` — فبدون
     * `Notification::fake()` مش هنقدر نقرا المحتوى.
     */
    public function test_the_invitation_email_is_arabic_and_carries_the_token(): void
    {
        Notification::fake();

        $token = app(InvitationService::class)->create(
            ['email' => 'new@example.test'],
            $this->inviter(),
        );

        // ⭐ التاريخ **من صف الدعوة نفسه** مش `now()->addDays(3)`
        // ⭐ ودي مش مسألة شكلية: الواجهة بتقرا التوكن من `?token=`، فلو
        $expiresLabel = \App\Models\Invitation::query()
            ->where('email', 'new@example.test')
            ->firstOrFail()
            ->expires_at
            ->translatedFormat('l j F Y');

        // ⭐⭐ **`assertSentOnDemand` مش `assertSentTo`** — الدعوة
        // بتروح لـ`Notification::route('mail', $email)` أي
        // `AnonymousNotifiable`، لأن المدعو لسه مالوش حساب.
        //
        // ⭐ و`assertSentTo` مع class name بيرمي
        // `get_class(): Argument #1 must be of type object, string given`.
        Notification::assertSentOnDemand(
            InvitationMail::class,
            // ⭐⚠️ أول باراميتر لازم **typed** — `NotificationFake`
            // بيعمل reflection عليه عشان يفلتر. `function ($n)`
            // بيرمي `The first parameter of the given Closure is
            // missing a type hint`.
            function (InvitationMail $notification, array $channels, $notifiable) use ($token, $expiresLabel) {
                // ⭐⭐ `toMail()` مش `render()` — الـ`Notification` الأساس
                // مالوش `render()`. الـHTML بيطلع من الـ`MailMessage`
                // اللي `toMail()` بيرجّعها.
                $rendered = (string) $notification->toMail($notifiable)->render();

                // ⭐⭐ التوكن موجود — من غيره الدعوة بلا قيمة
                $this->assertStringContainsString(
                    $token,
                    $rendered,
                    'التوكن مش في الإيميل — الدعوة بلا قيمة',
                );

                // ⭐⭐ والإيميل بيتسلّم **للمدعو** — مش لحد تاني.
                // إيميل غلط = الدعوة ضايعة وصاحبها مش هيعرف.
                //
                // ⭐ `Notification::route('mail', $x)` بيخزّن
                // `['mail' => $x]` — **نص**، مش مصفوفة.
                $this->assertSame(
                    ['mail' => 'new@example.test'],
                    $notifiable->routes,
                    'الإيميل مش متبعت للإيميل اللي اتدعى'
                );

                // ⭐ بالعربي — التطبيق كله عربي
                $this->assertMatchesRegularExpression(
                    '/[\x{0600}-\x{06FF}]/u',
                    $rendered,
                    'الإيميل مش عربي',
                );

                // ⭐ وفيه تاريخ الانتهاء — عشان المستخدم يعرف ليه
                $this->assertStringContainsString(
                    $expiresLabel,
                    $rendered,
                    'الإيميل مافيهش تاريخ انتهاء',
                );

                // ⭐⚠️ والقناة **mail بس** — لو الـnotification اتخزّن
                // في جدول `notifications` كان التوكن هيمشي معاه
                // صريح.
                $this->assertSame(
                    ['mail'],
                    $notification->via(new AnonymousNotifiable),
                    'في قناة غير mail — التوكن ممكن يتخزّن صريح في جدول'
                );

                return true;
            }
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑦ استبدال تسليم كلمة المرور في `EmployeeController`
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **المشكلة اللي الـPR ده بيقفلها في الكود الحقيقي.**
     *
     * ═══════════════════════════════════════════════════════════════
     * قبل
     *
     *     'password' => Hash::make(Str::password(20)),
     *     'must_change_password' => true,
     *
     * ⭐ الحساب بيتعمل، بس **محدش عارف كلمة المرور**. فالموظف
     * الجديد واقف قدام صفحة «اختار كلمة مرور» وما يقدرش يدخلها،
     * والمدير ماعرفش يسلّمه حاجة.
     *
     * ═══════════════════════════════════════════════════════════════
     * بعد
     *
     * ⭐ `status = 'invited'` + **دعوة**. كلمة المرور **هي** اللي
     * الموظف يختارها، من رابط بينتهي.
     *
     * ⚠️ ملاحظة: كلمة المرور العشوائية **بتفضل موجودة** في
     * `users.password` — العمود `NOT NULL` وملفه bcrypt مش فاضي
     * مينفعش. الفرق إنها **مالهاش طريق للدخول**:
     *   ① `status='invited'` → `AuthController` بيمنع Login
     *   ② محدش بيقراها من أي مكان
     *   ③ والقبول بيكتب فوقها فوراً
     */
    public function test_creating_an_employee_with_an_account_creates_an_invitation(): void
    {
        Notification::fake();

        $actor = $this->makeUserWithRole('hr', ['students.update', 'users.invite', 'users.roles.assign']);

        $r = $this->postJsonAs('/api/employees', [
            'name' => 'موظف جديد',
            'email' => 'new.employee@example.test',
            'phone' => '01000000001',
            'create_account' => true,
        ], $actor);

        $r->assertCreated();

        $user = User::where('email', 'new.employee@example.test')->first();

        $this->assertNotNull($user, 'مافيش حساب اتعمل');
        $this->assertSame('invited', $user->status, 'الحساب لازم يبقى `invited` مش `active`');
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertNull($user->password_changed_at, 'حساب مدعو مينفعش يكون له تاريخ تغيير كلمة');

        $employeeId = $r->json('id');

        $this->assertDatabaseHas('invitations', [
            'email' => 'new.employee@example.test',
            // ⭐⭐ الربط بالملف — من غيره الحساب بيتفعّل بس الـ
            // employee يفضل `user_id = null` ومفيش حد يوصله.
            'profile_type' => 'employee',
            'profile_id' => $employeeId,
            'accepted_at' => null,
        ]);

        // ⭐ والموظف مرتبط بالحساب فعلاً
        $this->assertSame(
            $user->id,
            DB::table('employees')->where('id', $employeeId)->value('user_id'),
        );
    }

    /**
     * ⭐⭐⭐ الحساب المدعو **مش يقدر يسجّل دخول** قبل ما يقبل —
     * **وكلمة المرور الصح**.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه بنحط كلمة مرور **صح** في الاختبار
     *
     * ⭐ `AuthController` بيرجّع **422** لحالتين: كلمة مرور غلط،
     * وحساب مش `active`. فلو اختبرنا برده، الاختبار هيعدّي حتى لو
     * `status='invited'` اتشال من الكود خالص — لأن كلمة المرور
     * الغلط لوحدها بتدي نفس الرد.
     *
     * ⭐ فبنحط كلمة المرور الصح: لو الرد لسه مرفوض، يبقى ده **فحص
     * الـ`status`** هو اللي شغّال مش المقارنة.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐ و **422 مش 403** مقصود: رد موحّد لكل حالات الدخول الفاشل،
     * عشان محدش يفرق بين «الحساب موجود بس موقّف» و«مفيش حساب».
     * لو رجعنا 403 للحساب الموقوف وكنا رجعنا 422 لغيره، الرد
     * بيقول للمهاجم إن الحساب **موجود**.
     *
     * ⭐(Test#3 بيكمّل الصورة: **نفس** كلمة المرور بعد القبول
     * بتسجّل دخول بنجاح — فالفرق هو `status` مش كلمة المرور.)
     */
    public function test_an_invited_account_cannot_log_in_before_accepting(): void
    {
        Notification::fake();

        $actor = $this->makeUserWithRole('hr', ['students.update', 'users.invite', 'users.roles.assign']);

        $this->postJsonAs('/api/employees', [
            'name' => 'موظف جديد',
            'email' => 'new.employee@example.test',
            'phone' => '01000000002',
            'create_account' => true,
        ], $actor)->assertCreated();

        $user = User::where('email', 'new.employee@example.test')->first();

        // ⭐ كلمة مرور **صح** — عشان نختبر فحص `status` لوحده
        $user->forceFill(['password' => Hash::make(self::NEW_PASSWORD)])->save();

        $r = $this->postJson('/api/auth/login', [
            'email' => 'new.employee@example.test',
            'password' => self::NEW_PASSWORD,
            'device_name' => 'test',
        ]);

        $r->assertStatus(422);

        // ⭐⭐⭐ **الدليل الحقيقي: سبب الرفض في `audit_logs`.**
        //
        // ⚠️⚠️ ليه مش بنفحص الرسالة؟
        //
        // `TranslateValidationErrors` بياخد أي رسالة **مش معروفة**
        // ويرجّع `في حقل مش صحيح` — فرسالة `AuthController`
        // «الحساب غير نشط — كلّم إدارة الأكاديمية» بتتحوّل لكده،
        // زيها زي «بيانات الدخول غير صحيحة». **اتنين بيطلعوا نفس
        // النص.** (سلوك موجود من قبل الـPR ده، مش من تغييره.)
        //
        // ⭐ فمفيش حاجة يقدر يفرّق من الرد. لكن `LoginRateLimiter`
        // بيسجّل `reason` في `audit_logs` — ودي الرسالة الحقيقية.
        $logged = DB::table('audit_logs')
            ->where('action', 'login_failed')
            ->latest('id')
            ->first();

        $this->assertNotNull($logged, 'محاولة الدخول ما اتسجلتش خالص');

        $this->assertStringContainsString(
            'inactive_account',
            json_encode($logged) ?: '',
            'رفض بسبب كلمة المرور أو مش مسجّلش خالص — فحص حالة الحساب مش شغّال'
        );

        // ⭐⭐ والأهم: **مفيش** تسجيل `bad_credentials` — كلمة المرور
        // صح والدخول اترفض بالحالة، مش بالمقارنة.
        //
        // ⚠️ بنفحص على `json_encode($row)` زي `LoginHardeningTest`
        // بالظبط — الـ`details` عمود json، والـ`like` عليه مش
        // مضمون يشتغل على SQLite.
        $this->assertStringNotContainsString(
            'bad_credentials',
            json_encode(DB::table('audit_logs')->where('action', 'login_failed')->get()) ?: '',
            'رفض بسبب كلمة المرور — الاختبار مش بيختبر فحص الحالة'
        );
    }

    /**
     * ⭐⭐⭐ **الدورة كاملة**: إنشاء ← دعوة ← قبول ← الحساب بيشتغل.
     *
     * ⭐ ده الـ`provisionUser()` فرع «حساب موجود مسبقًا» — وهو
     * اللي بيتنفّذ في reality، لأن الـemployee بيعمل الحساب وقت
     * الإنشاء والدعوة بتوصل بعدين.
     *
     * ⭐ ⚠️ وده المسار اللي **محدش** بيختبره لو الاختبار بيبدأ
     * بـ`create()` على إيميل جديد بس.
     */
    public function test_an_invited_employee_activates_by_accepting_the_invitation(): void
    {
        Notification::fake();

        $actor = $this->makeUserWithRole('hr', [
            'students.update', 'users.invite', 'users.roles.assign', 'students.view',
        ]);
        $teacherRole = $this->makeRole('teacher', ['students.view']);

        $r = $this->postJsonAs('/api/employees', [
            'name' => 'موظف جديد',
            'email' => 'new.employee@example.test',
            'phone' => '01000000003',
            'create_account' => true,
            'role_id' => $teacherRole->id,
        ], $actor);

        $r->assertCreated();

        $userId = User::where('email', 'new.employee@example.test')->value('id');

        // ⭐ الدور اتعيّن وقت الإنشاء — مش وقت القبول
        $this->assertDatabaseHas('model_has_roles', [
            'model_id' => $userId,
            'model_type' => User::class,
        ]);

        // ⭐ التوكن بيتقرا من **رابط الرد** — وده الاستعمال الوحيد
        // المسموح. مش من الداتابيز (متخزّن مش خام)، ومش من
        // notification accessor (مفيش واحد).
        //
        // ⭐ ودي ميزة حقيقية مش تذوق: المدير اللي مش فاضي يبعت
        // إيميل بينسخ الرابط ويبعتّه واتساب.
        $acceptUrl = $r->json('invitation.accept_url');

        $this->assertNotEmpty($acceptUrl, 'الرد مافيهوش رابط الدعوة');

        parse_str((string) parse_url($acceptUrl, PHP_URL_QUERY), $query);

        $token = $query['token'] ?? null;

        $this->assertNotEmpty($token, 'رابط الدعوة مافيهوش توكن');

        $this->postJson('/api/invitations/accept', [
            'token' => $token,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $user = User::where('email', 'new.employee@example.test')->first();

        $this->assertSame('active', $user->status, 'الحساب ميفعّلش بعد القبول');
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertNotNull($user->password_changed_at);

        // ⭐⭐ الـhash الجديد هو اللي اتخزّن فعلاً — مش بس الـstatus
        // اللي اتغيّر.
        $this->assertTrue(
            Hash::check(self::NEW_PASSWORD, $user->password),
            'كلمة المرور الجديدة مش هي اللي اتخزّنت'
        );

        // ⭐⭐⭐ **نفس** كلمة المرور اللي كان الدخول مرفوض بيها في
        // `test_an_invited_account_cannot_log_in_before_accepting`.
        // الفرق الوحيد اللي اتغيّر هو `status`.
        //
        // ⭐ ده اللي بيحوّل الـ422 والـ200 من «رقمين مختلفين» لـ
        // **دليل** إن فحص `status` هو اللي شغّال.
        $this->postJson('/api/auth/login', [
            'email' => 'new.employee@example.test',
            'password' => self::NEW_PASSWORD,
            'device_name' => 'test',
        ])->assertOk();
    }

    /**
     * ⭐⭐ تعيين دور لموظف **مالوش حساب** — بيعمل دعوة برضه.
     *
     * ⭐ المسار ده في `EmployeeController::update`، وهو نفس المشكلة:
     * كان بيعمل حساب بكلمة مرور عشوائية محدش بيعرفها.
     */
    public function test_assigning_a_role_to_an_accountless_employee_also_invites(): void
    {
        Notification::fake();

        $actor = $this->makeUserWithRole('hr', [
            'students.update', 'users.invite', 'users.roles.assign', 'students.view',
        ]);

        $employee = $this->makeEmployee(['email' => 'promoted@example.test', 'user_id' => null]);
        $teacherRole = $this->makeRole('teacher', ['students.view']);

        $this->putJsonAs("/api/employees/{$employee->id}", [
            'role_id' => $teacherRole->id,
        ], $actor)->assertOk();

        $this->assertDatabaseHas('invitations', [
            'email' => 'promoted@example.test',
            'profile_type' => 'employee',
            'profile_id' => $employee->id,
            'accepted_at' => null,
        ]);

        $this->assertSame(
            'invited',
            User::where('email', 'promoted@example.test')->value('status'),
            'الحساب اللي اتعمل بالتعيين لازم يبقى مدعو مش شغال'
        );
    }
}
