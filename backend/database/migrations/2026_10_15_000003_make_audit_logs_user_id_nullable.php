<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ `audit_logs.user_id` بقى `nullable` — ومحاولة الدخول
 * لإيميل مجهول كانت بتعمل **500**.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة
 *
 * `LoginRateLimiter::logFailure` بيسجّل **كل** محاولة فاشلة —
 * وده مقصود. بس لما الإيميل مالوش حساب، كان بيحطّ:
 *
 *     $this->userIdFor($email) ?: 0
 *
 * ⭐ `audit_logs.user_id` عنده **FK** على `users`، و`0` مش
 * مستخدم موجود:
 *
 *     SQLSTATE[23000]: FOREIGN KEY constraint failed
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ الأثر كان مزدوج
 *
 * ⭐ ① **500 بدل 422.** أي حد يكتب إيميل عشوائي في صفحة الدخول
 *    بياخد خطأ سيرفر. مافيش session، مافيش تحقق — الـinsert
 *    بيفشل قبل أي حاجة تانية.
 *
 * ⭐ ② ⭐⭐ **والأخطر: الحدث ما كانش بيتسجّل خالص.** يعني
 *    إيميلات بتتخمّن (أدقDnsBruteForce، أو_password reuse)
 *    كانت **بتختفي من `audit_logs`**. أحسن حالة اختراب في
 *    النظام، والـmonitoring مش شايفها.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه الـFK غلط هنا أصلاً
 *
 * ⭐ `audit_logs` بتوثّق **من عمل إيه**. بس الـauth بالذات
 * بتتعامل مع ناس **لسه مالهمش حساب** — محاولة الدخول الفاشلة
 * هي الحدث اللي **بيبقى فيه «مين» مجهول بالتصميم**.
 *
 * ⭐ الـFK بيقول «كل سطر لازم يخصّ حد». ده غلط لصفوف الـauth.
 * فبنعمله `nullable` — مش بنلغي الـFK، الـFK **سلامة بيانات
 * مهمة** لكل الـaudit التاني.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه `null` مش `0`
 *
 * ⭐ `0` كان attempt Previous موجود أول الـPR ده — كان اختيار
 * صامت عشان الـعمود `NOT NULL`. رقم `0` في عمود FK هو
 * **قيمة مزيّفة**: بيوحي إن في مستخدم رقم 0، وأي query بيبني
 * على `user_id = 0` بيرجع غلط.
 *
 * ⭐ `NULL` معناه بدقة: «الفاعل مش معروف» — وده واقع.
 *
 * ⚠️ **على البيانات الموجودة**: لو كان في سطر بـ`user_id = 0`
 * (كان الـinsert بيفشل، فمافيش أصلاً — لكن لو بيئة تانية
 * كانت الـFK متساهلة) الـmigration **مش هيمسح** السطر. لازم
 * تشيك يدوي قبل الإنتاج.
 *
 * @see \App\Services\LoginRateLimiter::logFailure
 * @see \Tests\Feature\LoginHardeningTest::test_a_login_attempt_for_an_unknown_email_is_422_not_500
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        // ⭐ `change()` محتاج `doctrine/dbal` في Laravel <11.
        // المشروع على 11.56 فبيشتغل بـSQL مباشر — بس **بيلفّ
        // الجدول كله** على SQLite، فبناخد نسخة قبلها.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')
                ->nullable()
                ->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        // ⭐⚠️ **مش رجوع آمن.** السطر اللي `user_id` فيه فاضي
        // مش هيقبل `NOT NULL` تاني.
        //
        // فبنمسح السطور اللي مالهاش فاعل معروف **قبل** الرجوع.
        // دي بيانات **فقدناها** — but دي النتيجة الوحيدة
        // المتسقة: سطر «مين حاول» من غير «مين» مش بيعمل حاجة.
        //
        // ⚠️ ولازم يعدّي بحذر: لو في أي سطر تاني يعتمد على
        // السطور دي، لازم يتشال قبل النشر.
        DB::table('audit_logs')->whereNull('user_id')->delete();

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
