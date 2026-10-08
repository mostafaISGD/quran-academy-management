<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ `lesson_policies` — سياسة استهلاك الحصة.
 *
 * ⭐ **قبل الجدول ده:** لو الطالب غاب، هل الحصة تتحسب عليه؟
 * لو المعلم غاب، هل يتقاضى أجره؟ دي كانت أسئلة بلا إجابة في
 * الكود — والخلافات المالية كلها بتيجي من هنا.
 *
 * ⭐ صف واحد لكل أكاديمية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // ⭐ غياب الطالب
            $table->boolean('student_absence_consumes_lesson')->default(false);
            $table->boolean('student_absence_teacher_paid')->default(false);

            // ⭐ غياب المعلم — الطالب ما يخسرش، والمعلم ما يتقاضاش
            $table->boolean('teacher_absence_consumes_lesson')->default(false);
            $table->boolean('teacher_absence_teacher_paid')->default(false);

            // ⭐ الحصة التعويضية
            $table->boolean('makeup_consumes_lesson')->default(true);
            $table->boolean('makeup_teacher_paid')->default(true);

            // ⭐ حدود التعويض
            $table->integer('max_makeup_per_month')->default(4);
            $table->integer('max_makeup_days')->default(30);

            // ⭐ هل الحصة التعويضية تصرف من نفس رصيد الباقة؟
            // لو true، التعويض بيتخصم من رصيد الطالب الأصلي.
            // لو false، التعويض "مجاني" من رصيد خاص.
            $table->boolean('makeup_deducts_from_package')->default(true);

            $table->timestamps();

            $table->unique('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_policies');
    }
};
