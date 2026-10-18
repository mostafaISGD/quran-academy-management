<?php

namespace App\Services;

use App\Exceptions\InvitationGoneException;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationMail;
use App\Services\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * ⭐⭐⭐ الدعوات — الحساب بيتعمل بدعوة مش بكلمة مرور مُسلَّمة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة: التوكن الخام **مش بيتخزّن**
 *
 *     $plain = random_bytes(64);              // ⚠️ الخام
 *     $hash  = hash('sha256', $plain);        // ✅ اللي بيتخزّن
 *
 * ⭐ `$plain` بيرجع من `create()` / `resend()` **مرة واحدة** —
 * بيروح للإيميل أو للرد. وبعد كده بيختفي.
 *
 * ⭐ المعادلة: أي حد يقرا الداتابيز **مش** يقدر يسجّل دخول بحساب
 * حد تاني. التوكن مش عنده.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ لماذا `random_bytes(64)` مش `Str::random(64)`
 *
 * `Str::random` بتولّد من **حروف مختارة** (a-z, A-Z, 0-9) —
 * المساحة ≈ 62^64 ≈ 2^380 بت.
 * `random_bytes(64)` = 512 بت **مباشرة** من الـCSPRNG.
 *
 * ⭐ ⚠️ الأهم: `Str::random` في Laravel 11 **مش** CSPRNG
 * بحت في كل الأحوال. الفرق هنا مش أكاديمي بس.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ لماذا SHA-256 مش bcrypt
 *
 * bcrypt مصمم للـ**كلمات المرور**: مدخل بشري **قابل للتخمين**
 * (أسماء، تواريخ، «123456»). من هنا الـhash بطيء والمكوّن
 * `{cost}` قابل للضبط.
 *
 * ⭐ التوكن هنا **اختاره CSPRNG** — 2^512 احتمال، والتخمين
 * مستحيل رياضياً مش بطيء بس. فالسريع مافيش ميزة خاطئة عليه،
 * والمقارنة الثابتة الزمن (`hash_equals`) هي اللي بتهم.
 *
 * ⭐ والـpepper مش محتاجينه لأن التوكن **مش** سر طويل الأجل —
 * عمره ٧٢ ساعة ومرة واحدة.
 *
 * @see \Tests\Feature\AccountInvitationTest
 */
class InvitationService
{
    /** ⭐⭐ 64 **byte** — مش 64 حرف. المساحة 512 بت. */
    private const TOKEN_BYTES = 64;

    /** ⭐⭐ ٧٢ ساعة = ٣ أيام. */
    private const VALID_HOURS = 72;

    /**
     * ⭐⭐ بتجزّئ التوكن الخام.
     *
     * ⭐ الدالة دي **بتتصل من نقطتين بس**: الإنشاء، والبحث عند
     * القبول. لو أي حد نسي يعمل تجزئة قبل مقارنة، يبقى يcompares
     * خام بـخام.
     */
    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    // ═══════════════════════════════════════════════════════════════
    // ① الإنشاء
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ تعمل دعوة — وترجّع **التوكن الخام** (مرة واحدة بس).
     *
     * @param  array{email: string, role_id?: int|null, profile_type?: string|null, profile_id?: int|null, name?: string|null}  $data
     * @return string التوكن الخام — **متخزّنش** في أي مكان تاني
     */
    public function create(array $data, User $actor): string
    {
        // ⭐⭐⭐ التزويد: **دا اللي بيمنع التصعيد.**
        //
        // `RoleGrantService::assertCanAssignRole` بيفرض إن **كل**
        // صلاحية في الدور اللي بتسلّمه تكون موجودة عندك أنت. من
        // غيرها، أي حد عنده `users.invite` يقدر يدعي حد بدور
        // المدير.
        //
        // ⭐ وده **أقوى** من فحص `users.roles.assign`: الفرق بين
        // «عندك صلاحية» و«عندك كل حاجة في الدور» — التانية
        // بتخلي التصعيد التدريجي مستحيل.
        if (! empty($data['role_id'])) {
            app(RoleGrantService::class)->assertCanAssignRole(
                $actor,
                \Spatie\Permission\Models\Role::findOrFail($data['role_id']),
            );
        }

        $plain = $this->generateToken();
        $email = mb_strtolower(trim($data['email']));

        return DB::transaction(function () use ($data, $actor, $plain, $email) {
            // ⭐⭐⭐ أي دعوة **قديمة** لنفس الإيميل بتتقفل.
            //
            // ⭐ من غير كده ممكن يكون في ٣ روابط حيّة لنفس الحساب،
            // وأقدم واحدة فيهم تبقى هي اللي اشتغلت — والمستخدم
            // بيتفجأ «أنا عملت كذا دخول».
            //
            // ⭐ `accepted_at` مش بنمسحه — الدعوة المقبولة **تاريخ**،
            // دي بتتأرشف. بنقفل الـpending بس.
            $superseded = Invitation::pending()
                ->where('email', $email)
                ->get();

            foreach ($superseded as $old) {
                $old->delete();
            }

            $invitation = Invitation::create([
                'email' => $email,
                'token_hash' => self::hashToken($plain),
                'profile_type' => $data['profile_type'] ?? null,
                'profile_id' => $data['profile_id'] ?? null,
                'role_id' => $data['role_id'] ?? null,
                'invited_by' => $actor->id,
                'expires_at' => now()->addHours(self::VALID_HOURS),
            ]);

            app(AuditLogService::class)->log(
                'invitation_created',
                'invitation',
                $invitation->id,
                null,
                ['email' => $email, 'superseded' => $superseded->count()],
                request(),
                $actor->id,
            );

            // ⭐ الإيميل **برّه** الـtransaction.
            //
            // ⚠️ ليه؟ لأن `MAIL_MAILER` ممكن يكون SMTP بياخد
            // ثانية. لو بعثنا جوّه الـtransaction وفشل الإرسال، الـ
            // rollback بيرمي الدعوة كلها — والمستخدم قال «وصلني
            // إشعار إن الدعوة اتشالت» وده أسوأ من إشعار ضايع.
            //
            // ⭐ والأسوأ: لو الـmail علق ٣٠ ثانية، الـDB transaction
            // هيفضل **مفتوح** والع lads كله متوقف.
            $this->sendMail($invitation, $plain, $data['name'] ?? null);

            return $plain;
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // ② القبول
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ قبول الدعوة: يتحقق، يعمل الحساب، **مرة واحدة بس**.
     *
     * ⭐⚠️ **`lockForUpdate` مش اختياري هنا.**
     *
     * الطلب **عام ومحدود بـthrottle** — يعني مهاجم يقدر يبعت
     * طلبين بالظبط في نفس اللحظة (أو طلبين من جهازين).
     *
     * بدون القفل:
     *   ١. طلب A: `whereNull('accepted_at')` → مفيش
     *   ٢. طلب B: `whereNull('accepted_at')` → مفيش (لسه)
     *   ٣. A يحطّ كلمة مرور1
     *   ٤. B يحطّ كلمة مرور2 ← **الطلب الأخير بيكسب**
     *
     * فمستخدم شرس يقدر يفرض كلمته **على حساب حد تاني** لمجرد إنه
     * فمستخدم شرس يقدر يفرض كلمته **على حساب حد تاني** لمجرد إنه
     *
     * @return User الحساب اللي اتعمل (أو اتفعّل)
     */
    public function accept(string $plainToken, string $password): User
    {
        $hash = self::hashToken($plainToken);

        return DB::transaction(function () use ($hash, $password) {
            $invitation = Invitation::where('token_hash', $hash)->lockForUpdate()->first();

            // ⭐⭐ مش موجود = نفس رد المنتهية. التوكن **غير قابل
            // للتخمين** (2^512)، فالتمييز هنا مافيش ليه فايدة
            // ومافيش ضرر — بس الرد الموحّد أبسط.
            if (! $invitation) {
                throw InvitationGoneException::of(InvitationGoneException::UNKNOWN);
            }

            if ($invitation->isAccepted()) {
                throw InvitationGoneException::of(InvitationGoneException::ALREADY_ACCEPTED);
            }

            if ($invitation->isExpired()) {
                throw InvitationGoneException::of(InvitationGoneException::EXPIRED);
            }

            $user = $this->provisionUser($invitation, $password);

            $invitation->update(['accepted_at' => now()]);

            app(AuditLogService::class)->log(
                'invitation_accepted',
                'user',
                (int) $user->id,
                null,
                ['invitation_id' => $invitation->id, 'email' => $invitation->email],
                request(),
                (int) $user->id,
            );

            return $user;
        });
    }

    /**
     * ⭐⭐ الحساب: **يعمل** جديد، أو **يفعّل** واحد `invited`.
     *
     * ⭐⭐ الحالتين لازم، والحالة الأولى هي اللي بتشتغل في الـproduction:
     *
     *   • **`invited`** — `EmployeeController` بيعمل الحساب وقت إنشاء
     *     الموظف (`status = 'invited'`)، والدعوة بتوصل **بعدين**.
     *     فحتى آخر لحظة، الحساب موجود بس مفيش حد يقدر يدخل بيه.
     *
     *   • **جديد** — `POST /api/invitations` لإيميل مالوش حساب بعد.
     *
     * ⭐ والفرق مش تجميلي: الحالة الأولى بتحافظ على
     * `employees.user_id` اللي اتربط وقت الإنشاء، فالقبول مالوش
     * دور غير تفعيل الحساب.
     */
    private function provisionUser(Invitation $invitation, string $password): User
    {
        $existing = User::where('email', $invitation->email)->first();

        if ($existing) {
            $existing->forceFill([
                'password' => Hash::make($password),
                // ⭐ بعد ما اختار كلمة المرور، **مفيش** سبب نجبّره
                // يغيّرها تاني.
                'must_change_password' => false,
                'password_changed_at' => now(),
                // ⭐⭐ الـ`status` بيتحرّك من `invited` → `active`.
                // لو كان `suspended` أو `archived` مش بنلمسه —
                // ده قرار إداري مش نتيجة قبول دعوة.
                'status' => $existing->status === 'invited' ? 'active' : $existing->status,
            ])->save();

            $this->assignRole($existing, $invitation);
            $this->linkProfile($existing, $invitation);

            return $existing->fresh();
        }

        $user = User::create([
            // ⭐ `auth()->user()` **فاضي دايمًا** — الراوت عام.
            // فلو المدعو مالوش حساب، مين الـorg؟
            //
            // ⭐ بنقع على أول أكاديمية في الجدول. الدعوات بتتعمل من
            // جوه أكاديمية (المدیر شاف الـorg بتاعه في الإيميل)،
            // والشيء الوحيد الناقص هو إن الـ`invitations` مافيش
            // عمود `organization_id`.
            //
            // ⚠️ ده حد معروف لتثبيت الـorg على أول صف. مقبول
            // مع أكاديمية واحدة بس — ولازم يتقفل بإضافة عمود
            // `organization_id` على `invitations` قبل أي نشر
            // بأكثر من أكاديمية. راجع PR 1.5.
            'organization_id' => User::query()->value('organization_id') ?? 1,
            // ⭐ الاسم placeholder — الإيميل لحد ما المستخدم يغيّره.
            //
            // ⚠️ الحساب اللي ليه **ملف** (موظف/معلم/أهل) مالوش
            // المشكلة دي: `linkProfile()` بيربطه بالملف اللي اسمه
            // صحيح. ده بس للحساب المجرّد من غير ملف.
            'name' => $invitation->email,
            'email' => $invitation->email,
            'password' => Hash::make($password),
            'status' => 'active',
            'must_change_password' => false,
            'password_changed_at' => now(),
            'timezone' => 'Africa/Cairo',
            'locale' => 'ar',
        ]);

        $this->assignRole($user, $invitation);
        $this->linkProfile($user, $invitation);

        return $user->fresh();
    }

    private function assignRole(User $user, Invitation $invitation): void
    {
        if (! $invitation->role_id) {
            return;
        }

        $role = \Spatie\Permission\Models\Role::find($invitation->role_id);

        if ($role) {
            $user->assignRole($role);
        }
    }

    /**
     * ⭐ ربط الحساب بالملف الشخصي اللي اتدعى صاحبو.
     *
     * ⭐ `profile_type` فاضي = حساب **مش** مربوط بملف (مثلاً
     * موظف جديد لسه ملفه مش اتعمل). ساعتها مافيش شغل.
     */
    private function linkProfile(User $user, Invitation $invitation): void
    {
        if (! $invitation->profile_type || ! $invitation->profile_id) {
            return;
        }

        $map = [
            'teacher' => \App\Models\Teacher::class,
            'student' => \App\Models\Student::class,
            'employee' => \App\Models\Employee::class,
            'parent' => \App\Models\ParentModel::class,
        ];

        $modelClass = $map[$invitation->profile_type] ?? null;

        if (! $modelClass || ! class_exists($modelClass)) {
            return;
        }

        $modelClass::whereKey($invitation->profile_id)->update(['user_id' => $user->id]);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الإلغاء وإعادة الإرسال
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ الإلغاء **حذف الصف** — مش `accepted_at` ولا `status`.
     *
     * ⭐ ليه الحذف؟ لأن التوكن لو فضل في الجدول بعد الإلغاء، حد
     * يقدر يقرا `id` ويتأكد إن الدعوة كانت موجودة. والحذف
     * بيمسح دليلها كله.
     *
     * ⭐ والدعوة **المقبولة** ما بتتشالشش** — دي تاريخ.
     */
    public function cancel(Invitation $invitation, User $actor): void
    {
        if ($invitation->isAccepted()) {
            throw InvitationGoneException::of(InvitationGoneException::ALREADY_ACCEPTED);
        }

        app(RoleGrantService::class)->assertCanAssignRole(
            $actor,
            $invitation->role ?? new \Spatie\Permission\Models\Role,
        );

        $invitation->delete();

        app(AuditLogService::class)->log(
            'invitation_cancelled',
            'invitation',
            $invitation->id,
            null,
            ['email' => $invitation->email],
            request(),
            $actor->id,
        );
    }

    /**
     * ⭐⭐ إعادة الإرسال = **توكن جديد + صلاحية جديدة**.
     *
     * ⭐ ليما نرجّع نفس التوكن؟ لأن الإلغاء بيبطل الصف — فإلغاء
     * الدعوة الجديدة هيبطل القديمة كمان، والمستخدم يتخبط.
     */
    public function resend(Invitation $invitation, User $actor): string
    {
        if ($invitation->isAccepted()) {
            throw InvitationGoneException::of(InvitationGoneException::ALREADY_ACCEPTED);
        }

        $plain = $this->generateToken();

        $invitation->update([
            'token_hash' => self::hashToken($plain),
            'expires_at' => now()->addHours(self::VALID_HOURS),
        ]);

        app(AuditLogService::class)->log(
            'invitation_resent',
            'invitation',
            $invitation->id,
            null,
            ['email' => $invitation->email],
            request(),
            $actor->id,
        );

        $this->sendMail($invitation->fresh(), $plain);

        return $plain;
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════
    /**
     * ⭐⭐⭐ ٥١٢ بت عشوائية — **مطبوعة و آمنة في الـURL**.
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ ليه `random_bytes` مباشرة **مش** هتشتغل
     *
     * ⭐ `random_bytes(64)` بيرجّع **bytes خام** — arbitrary binary.
     * وده مش صالح للاستخدام في:
     *
     *   • **JSON** — الـ`json_encode` بيرمي
     *     `Malformed UTF-8 characters`. ⚠️ ده **اللي حصل فعلاً**
     *     في أول نسخة: الرد كان 500 والسبب سطر واحد فيه بايت
     *     مش UTF-8.
     *   • **URL** — بايتيّات مش printable بتتلف
     *   • **إيميل** — نفس المشكلة
     *
     * ═══════════════════════════════════════════════════════════════
     * ⭐⭐⭐ وليه **base64url** مش base64 العادي
     *
     * ⭐ الـbase64 العادي بيطلّع `+` و `/` و `=`:
     *
     *     ?token=abc+def/ghi==
     *
     * ⭐ `urlencode` بتاخدهم لـ`%2B` و `%2F` و `%3D`، والتوكن اللي
     * طلع في الإيميل **مش** نفس اللي في الرد بـ`assertStringContains`
     * — والاختبار ده كشفها.
     *
     * ⭐ الأسوأ من اللي-test: الواجهة بتقرا التوكن من `?token=`، ولو
     * حد نسي `decodeURIComponent` فيقارن `%2B` بـ`+` و**يرفض
     * دعوة صحيحة**. الـbase64url بيشيل المصيبة كلها: التوكن بيمشي
     * في الـURL **زي ما هو** حرف بحرف.
     *
     * ⭐ `+`→`-` و `/`→`_` والـpadding `=` بيتشال. النتيجة **٨٦
     * حرف** من أبجدية `[A-Za-z0-9_-]` — آمنة في query، في path،
     * في JSON، وفي الإيميل.
     *
     * ⭐⚠️ **الـentropy مش متغيّرة.** الترميز (encoding) مش
     * تشفير (encryption) ولا hash. نفس الـ٥١٢ بت.
     *
     * ⭐⚠️ `=` بيتشال عشان `=` بيترجم لـ`%3D` في بعض الـcontexts،
     * والـpadding مش جزء من الـentropy أصلاً.
     *
     * @see \Tests\Feature\AccountInvitationTest::test_the_token_is_512_bits_of_true_randomness_printed_as_ascii
     */
    private function generateToken(): string
    {
        // ⭐ `random_bytes` **بيرمي استثناء** لو مافيش مصدر
        // عشوائي — وده **صح**. البديل الرخيص (توكن أصفار أو
        // `uniqid()`) هيبقى **أسوأ من الفشل** لأنه بيبقى token
        // *متنبّأ*.
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * ⭐⭐ إرسال الإيميل — **برّه** أي transaction.
     */
    private function sendMail(Invitation $invitation, string $plain, ?string $name = null): void
    {
        try {
            Notification::route('mail', $invitation->email)
                ->notify(new InvitationMail($invitation, $plain, $name));

            Log::info('دعوة اتبعتت', [
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'expires_at' => (string) $invitation->expires_at,
            ]);
        } catch (\Throwable $e) {
            // ⭐ⓘ **مش بنرمي.** الدعوة اتعملت فعلاً؛ لو الإرسال
            // فشل، المستخدم يقدر يطلب «إعادة إرسال» — ودي عملية
            // ⭐ تاني تسجّلواحدة — مستقلة.
            //
            // ⚠️ الرمي هنا كان هيعمل rollback للدعوة كلها، فالمستخدم
            // ياخد 500 و**ميفهمش** إن الدعوة اتعملت. أسوأ.
            //
            // ⭐ والخطأ بيتسجّل بالتفاصيل — ومفيش **توكن** في اللوج.
            Log::error('فشل إرسال دعوة', [
                'invitation_id' => $invitation->id,
                'email' => $invitation->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
