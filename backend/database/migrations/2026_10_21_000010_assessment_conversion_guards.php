<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.9 — حماية التحويل وربط الحصة التجريبية.**
 *
 * ⭐ **قبل PR ده** التحويل (`AssessmentController::convert`) كان:
 *
 * - بينشئ **طالب** جديد كل مرة ⇒ التحويل مرتين = **طالبان**
 *   لنفس الـlead (العنوان: «من غير طلاب مكرّرين»).
 * - **مش ذرّي** ⇒ فشل في النص بيسيب طالب بلا اشتراك (أو فاتورة
 *   بلا دفع).
 * - كان **بيتجاوز** الخدمات (فاتورة/دفع يدويان) ⇒ مفيش حساب
 *   رصيد ولا حركة مالية.
 *
 * ⭐ دلوقتي:
 *
 * ① `assessments.lesson_id` — رابط **الحصة التجريبية** اللي
 *    `store` بيعملها (الحصة بتتسجَّل من غير طالب — `student_id`
 *    بقى nullable في PR 3.5).
 * ② `assessments.converted_at` + `converted_subscription_id` —
 *    سجل التحويل (متى وإيه الاشتراك).
 * ③ **`unique(assessments.student_id)`** — الحماية على مستوى
 *    القاعدة: التقييم الواحد ما يقدرش يبقى ليه أكتر من طالب.
 *    (`student_id` nullable ⇒ عدّة تقييمات من غير تحويل مسموحة.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->timestamp('converted_at')->nullable();
            $table->foreignId('converted_subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            // ⭐ التقييم الواحد = طالب واحد (حماية القاعدة).
            $table->unique('student_id');
        });
    }

    public function down(): void
    {
        Schema::table('assessments', function (Blueprint $table) {
            $table->dropUnique(['student_id']);
            $table->dropConstrainedForeignId('lesson_id');
            $table->dropConstrainedForeignId('converted_subscription_id');
            $table->dropColumn('converted_at');
        });
    }
};
