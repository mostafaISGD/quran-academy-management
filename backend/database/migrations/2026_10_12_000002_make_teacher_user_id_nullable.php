<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ `teachers.user_id` يبقى **اختياري**.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * `TeacherController::store` كان بيعمل:
 *
 *     $data['user_id'] = $request->user()->id;
 *
 * يعني المعلم الجديد بيتربط بحساب **الأدمن اللي عمله**، مش بحسابه
 * هو. والنتيجة العملية كانت: حساب المدير نفسه بقى مربوط بسجل
 * `teachers` — والواجهة كانت بتحسبه معلم وبتوديه `/my-schedule`
 * بدل لوحة التحكم.
 *
 * (الإصلاح الجزئي اللي كان موجود في `AuthController::profile` كان
 * بيخفي الأعراض، مش السبب.)
 *
 * ═══════════════════════════════════════════════════════════════
 * ليه الـ migration لازم
 *
 * العمود كان `foreignId('user_id')->constrained('users')` — أي **مش**
 * nullable. فلو غيرنا السطر في الـ controller بس، الـ insert هيفشل
 * بـ NOT NULL violation.
 *
 * ⭐ الـ الربط الصح للحساب بييجي بالدعوة (PR 1.4)، فلو المعلم لسه
 * معندوش دعوة مقبولة يبقى `null` — وده الوضع الطبيعي، مش نقص.
 *
 * ═══════════════════════════════════════════════════════════════
 * قرار مقصود: `cascadeOnDelete` → `nullOnDelete`
 *
 * كان `cascadeOnDelete` — أي حد حذف حساب المستخدم كان **بيحذف سجل
 * المعلم كله** ومعاه كل الحصص والتقييمات والمستحقات والجدول.
 *
 * ده كان غلط حتى والعمود إجباري (المفروض وجود الـ user يحمي
 * السجل). دلوقتي بقى اختياري، فـ cascade معناها: «امسح الربط
 * الاختياري ده وامسح كمان المعلم وسنه كامل». ده **فقدان بيانات
 * صامت** — أخطر من المشكلة الأصلية.
 *
 * `nullOnDelete` = «سيب الربط فاضي، واحتفظ بالبيانات». وده
 * المطلوب معمارياً: المستخدم بيتأرشف (PR 1.5) مش بيتحذف، ولو
 * اتحذف بالخطأ البيانات المالية والمرتبطة لازم تفضل موجودة.
 *
 * @see \App\Http\Controllers\Api\TeacherController::store
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);

            $table->foreignId('user_id')
                ->nullable()
                ->change();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        /**
         * ⭐ الرجوع بيحتاج كل معلم يكون مربوط بحساب.
         *
         * العمود رجع NOT NULL، فلو فيه معلم بـ `user_id = NULL`
         * مش هينفع نرجّعه من غير ما نقرّر إيه حسابه — والاختيار
         * (نسكب حساب / نحذف المعلم) قرار بيانات مش قرار هندسة.
         *
         * فبنقول بوضوح بدل ما نعمل rollback ناقص ونبني عليه.
         */
        $orphans = DB::table('teachers')->whereNull('user_id')->count();

        if ($orphans > 0) {
            throw new RuntimeException(
                "في {$orphans} معلم من غير حساب مستخدم (user_id = NULL)، "
                .'فمش ينفع نرجّع العمود لـ NOT NULL. اربط كل معلم بحساب '
                .'أو احذفه قبل الـ rollback.'
            );
        }

        Schema::table('teachers', function (Blueprint $table) {
            $table->dropForeign(['user_id']);

            $table->foreignId('user_id')
                ->nullable(false)
                ->change();

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });
    }
};