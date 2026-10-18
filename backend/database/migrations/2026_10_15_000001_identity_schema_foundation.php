<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ أساس الهوية — المخطط اللي فات كل حاجة قبل كده.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⚠️⚠️ «add» في الخارطة مش معناها «مفيش»
 *
 * 🚫 **موجود أصلاً — متكرّرش تعريفه:**
 *
 *   • `users.must_change_password`   ← PR 0.4
 *   • `users.password_changed_at`    ← PR 0.4
 *   • `students.user_id`             ← موجودة
 *   • `employees.user_id`            ← موجودة
 *   • `teachers.user_id`             ← موجودة (و بقت nullable في PR 0.2)
 *   • `parents.user_id`              ← موجودة
 *
 * ⭐ كل واحدة فيهم موجودة لأن الخارطة كانت مكتوبة من مراجعة
 * Sprint 1، والمشروع اتقدم من ساعتها. **عدّنا اللي موجود قبل ما
 * نكتب سطر واحد.**
 *
 * 🚿 **المفقود فعلاً — وده اللي الـmigration ده بيعمله:**
 *
 *   ① `status`: `invited` + `archived`
 *   ② قيد تفرّد على `email` و `phone`
 *   ③ قيد تفرّد على `user_id` في كل جدول ملف شخصي
 *   ④ جدول `invitations`
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ② الإيميل — **ثغرة كانت موجودة**
 *
 * `AuthController::login` بيعمل:
 *
 *     User::where('email', $email)->first();
 *
 * ⭐ مافيش `organization_id` في الفلتر. والقيد الموجود كان:
 *
 *     $table->unique(['organization_id', 'email']);
 *
 * ⭐⭐ ده كان يسمح **بنفس الإيميل في أكاديميتين**. والنتيجة على
 * الدخول: `first()` بيرجّع الحساب اللي طلع أول — وممكن يكون حساب
 * حد تاني خالص، في أكاديمية تانية، بدل الحساب اللي صاحب الإيميل.
 *
 * ⭐ الـschema ما كانتش بتمنع ده — الـschema كانت بتسمح بيه.
 *
 * ⚠️ **قرار تصميمي: التفرّد عام مش لكل مؤسسة.**
 *
 * البديل كان نضيف `where('organization_id', …)` لكل استعلام
 * مستخدم — يعني كل استعلام لازم يمر على المؤسسة. وده غلط: الـ
 * login بييجي من مستخدم **مالوش مؤسسة بعد**، هو بيدخل بإيميله
 * بس والمنطقة بتتحدد بعد الدخول.
 *
 * فاخترنا إن `email` يبقى **فريد عالمياً**. الأكاديمية منصة
 * واحدة، والمستخدم إنسان واحد — مش حساب في كل مؤسسة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ `NULL` مش متعارض — وده مقصود
 *
 * في SQL، `NULL != NULL` جوه قيد التفرّد. يعني أكتر من حساب من
 * غير موبايل **عادي ومش بيكسر**. ولو استبدلنا `null` بسلسلة
 * فاضية لكسرنا كل حساب مالهوش موبايل.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ ③ `user_id` فريد في كل ملف شخصي
 *
 * من غير القيد: ممكن تحط نفس الـ`user_id` في **اتنين** سجلات
 * teachers. وعندها:
 *
 *   • `authorizeOwnership()` شغال بالعلاقة، فـ`/teachers/{id}`
 *     بتاع أنهي سجل؟ الـrelation بترجّع **الأول**.
 *   • `AuthController::profile` بيرجّع `teacher` واحد — والتاني
 *     مش موجود في **أي** رد. سجل بلا واجهة: موجود في الداتابيز
 *     ومحدش يقدر يشوفه أو يعدّله.
 *
 * ⭐ القيد بيبقى على `user_id` نفسه، وبbbsمح `null` للسجلات اللي
 * مالهاش حساب — وده مقصود: معلم من غير حساب دخول سجل صالح
 * (PR 0.2 سمّى ده «عملاء»).
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ④ `invitations` — مش مستقبلي
 *
 * الحسابات دلوقتي بتتعمل لكل الناس بكلمة مرور واحدة. PR 0.4
 * قفل النتيجة (`must_change_password`) بس **ما غيّرش آلية
 * الإنشاء**. الجدول ده هو اللي بيخلّي PR 1.4 ممكن.
 *
 * ⚠️ **العمود اسمه `token_hash` مش `token`** — والاسم نفسه هو
 * التوثيق. التوكن 64 byte raw، والمتسجل SHA-256 hex = 128 حرف.
 * لو رجعنا لـ`token`، أي حد يقرا الجدول يقدر يسجّل دخول بحسابات
 * الناس.
 *
 * @see \Tests\Feature\IdentitySchemaTest
 */
return new class extends Migration
{
    /**
     * ⭐⭐ جداول الملف الشخصي اللي ليها حساب.
     *
     * ⭐ المفتاح = اسم الفهرس اللي Laravel بيعمله لـ`unique('user_id')`.
     */
    private const PROFILE_LINKS = [
        'students' => 'students_user_id_unique',
        'employees' => 'employees_user_id_unique',
        'teachers' => 'teachers_user_id_unique',
        'parents' => 'parents_user_id_unique',
    ];

    /** ⭐ الحالات المتاحة للحساب + اللي بنضيفها */
    private const STATUSES = ['invited', 'active', 'suspended', 'archived', 'inactive'];

    /** ⭐⭐ الحالة قبل الـmigration — دي اللي بيرجعلها الـ`down()` */
    private const STATUSES_ORIGINAL = ['active', 'suspended', 'inactive'];

    public function up(): void
    {
        $this->replaceStatuses(self::STATUSES);
        $this->uniqueIdentifiers();
        $this->uniqueProfileLinks();
        $this->createInvitations();
    }

    // ═══════════════════════════════════════════════════════════════
    // ① حالة الحساب
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ ليه `enum` مش `string`.
     *
     * ⚠️ الاعتراض المعتاد على `enum` هو «صعب نضيف قيمة». بس ده
     * **أصغر enum في المشروع** (٤ قيم)، وبيبقى شرط في كتل.
     * توسيعه عملية واحدة، والبديل (`string`) يعني validation في كل
     * مكان.
     *
     * ⭐ `inactive` **بنتركه**: `EnsureAccountActive` و
     * `AuthController::login` بيعاملوا أي قيمة غير `active`
     * كـ«مش داخل». حذفه كان هيكسر أي حساب عليه.
     *
     * ⭐⭐ `allowed_values` بتظهر في Postgres و MySQL بس. في
     * SQLite الـenum بيتجاهل **تماماً** — فمافيش فايدة من مقارنتها
     * في الاختبار على SQLite. الحل إن `change()` لو اشتغل =
     * تمام، والاختبار بيثبت القيم بتتقبل فعلياً.
     */
    private function replaceStatuses(array $allowed): void
    {
        if ($this->statusAlready($allowed)) {
            return;
        }

        Schema::table('users', function (Blueprint $table) use ($allowed) {
            $table->enum('status', $allowed)->default('active')->change();
        });
    }

    /** @param string[] $allowed */
    private function statusAlready(array $allowed): bool
    {
        $column = collect(Schema::getColumns('users'))->firstWhere('name', 'status');

        $current = $column['allowed_values'] ?? null;

        // ⭐ SQLite: مافيش قائمة قيم، فنعتبرها «محتاجة تغيير» و
        // ننفّذ `change()` — لو مش هيلاقي error يبقى تمام.
        if ($current === null) {
            return false;
        }

        return array_values(array_diff($allowed, array_map('strval', $current))) === [];
    }

    // ═══════════════════════════════════════════════════════════════
    // ② تفرّد المعرّفات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ **القيد المركّب القديم بيفضل مكانه.**
     *
     * القيد الجديد `users_email_unique` **أقوى** — بيغطي كل اللي
     * القديم بيغطيه وأكثر. والقديم بقى **زائد**.
     *
     * ⭐ بصراحة سيبه سببين: الفهرسة الحقيقية مافية، وإزالته في نفس
     * الـmigration معناها لو الـ`down()` فشل النص هنبقى في حالة
     * نصف-متجددة. سيبه.
     */
    private function uniqueIdentifiers(): void
    {
        foreach (['email', 'phone'] as $column) {
            if ($this->hasIndex('users', "users_{$column}_unique")) {
                continue;
            }

            Schema::table('users', function (Blueprint $table) use ($column) {
                $table->unique($column);
            });
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ تفرّد الربط بالملف الشخصي
    // ═══════════════════════════════════════════════════════════════

    private function uniqueProfileLinks(): void
    {
        foreach (self::PROFILE_LINKS as $table => $index) {
            $this->addProfileIndex($table, $index);
        }
    }

    private function addProfileIndex(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        if ($this->hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ جدول الدعوات
    // ═══════════════════════════════════════════════════════════════

    private function createInvitations(): void
    {
        if (Schema::hasTable('invitations')) {
            return;
        }

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();

            // ⭐ الإيميل بتاع الدعوة — مش لازم يكون حساب لسه.
            // **هو** اللي بيتدعى.
            $table->string('email');

            // ⭐⭐ 128 = hex لـSHA-256. التوكن الأصلي 64 bytes raw.
            $table->char('token_hash', 128);

            // ⭐ الحساب اللي المفروض يتعمل من الدعوة.
            $table->string('profile_type', 32)->nullable();
            $table->unsignedBigInteger('profile_id')->nullable();

            // ⭐ الدور اللي هيتسلّم. `null` = من غير دور.
            //
            // ⚠️ `nullOnDelete` مش `restrict` عن قصد: لو الدور
            // اتشال، الدعوة تبقى بلا دور — وده **أأمن** من إنها
            // تبقى معلّقة وتمنع إعادة استخدام الاسم.
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();

            // ⭐ مين بعت الدعوة. **`restrictOnDelete`** عن قصد:
            // دعوة بلا مرسل مالهاش معنى — مينfigure يلتزم بيها؟
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();

            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();

            $table->timestamps();

            // ⭐ البحث: «دعوة الإيميل ده اتبعت؟»
            $table->index(['email', 'expires_at']);

            // ⭐ و«لسه مفتوحة؟» — الـpartial index مش متاح في كل
            // الـengines، فعمود عادي كفاية.
            $table->index('accepted_at');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // Helpers
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐ الفهرس موجود؟
     *
     * ⭐ `Schema::getIndexes()` موجود من Laravel 11.2 ومطبَّق على
     * Postgres و MySQL و SQLite — فمافيش داعي لـSQL مكتوب بإيد.
     *
     * ⚠️ أول نسخة من الـmigration ده كانت بتكتب `PRAGMA index_list`
     * و`information_schema` و`pg_indexes` جوّه نفس الدالة. تلات
     * مصادر للحقيقة برجوع مختلف، والاختبارات كلها بتمشي على
     * SQLite بس فمش هتكتشف إن Postgres مش شغّال.
     *
     * ⭐ الجدول مش موجود = `false` مش error. ممكن يكون لسه ما
     * اتعملش لو حد شغّل الـmigration لوحده.
     */
    private function hasIndex(string $table, string $index): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $found) {
            if (($found['name'] ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    // ═══════════════════════════════════════════════════════════════
    // down()
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ الرجوع لورا — **من غير ما نمسح بيانات**.
     *
     * ⚠️⚠️ **اللي بيتشال:**
     *   • جدول `invitations` — كله مفيش فيه بيانات ثابتة
     *   • القيود — القيود مفيهاش صفوف بتتحطّ في `down()`
     *
     * ⚠️ **اللي ما بيتشالش:**
     *   • أي صف في أي جدول. `down()` بيشيل قيود وجدول دعوات،
     *     مش سجلات.
     *
     * ⭐ لو الـ`down()` مسح صفوف، أي `migrate:rollback` في بيئة
     * فيها بيانات حقيقية = فقدان حسابات ومرتبات. عشان كده
     * الاختبار بيبني بيانات قبل الـ`down()` ويطلب إنها لسه موجودة
     * بعد الـ`up()`.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitations');

        foreach (['users_phone_unique', 'users_email_unique'] as $index) {
            $column = str_replace('users_', '', $index);
            $column = str_replace('_unique', '', $column);

            if ($this->hasIndex('users', $index)) {
                Schema::table('users', function (Blueprint $table) use ($column) {
                    // ⭐ `dropUnique` بياخد **الأعمدة** مش الاسم
                    $table->dropUnique([$column]);
                });
            }
        }

        foreach (array_reverse(self::PROFILE_LINKS) as $table => $index) {
            $this->dropProfileIndex($table, $index);
        }

        $this->replaceStatuses(self::STATUSES_ORIGINAL);
    }

    private function dropProfileIndex(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'user_id')) {
            return;
        }

        if (! $this->hasIndex($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};