<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.5 — الحضور يدرّج النتيجة (نموذج الحصة الجماعية).**
 *
 * ⭐ **اللي كان ناقص:** `lessons.student_id` كان **NOT NULL** ⇒
 * الحصة=row واحد=طالب واحد، و`lesson_attendance` كان عليه
 * `unique(lesson_id)` ⇒ **سطر حضور واحد للحصة**. فمفيش مكان
 * لـ«حصة جماعية بـ5 طلاب» أصلاً.
 *
 * ① `lessons.student_id` بقى **nullable** (الحصة الفردية زي
 *    ما هي؛ والجماعية سطر واحد بلا طالب مفرد).
 * ② `lessons.lesson_type` اتضافت قيمة **`group`**.
 * ③ جدول **`lesson_students`** — طلاب الحصة الجماعية.
 * ④ `lesson_attendance` — الـunique بقى **(lesson_id, student_id)**
 *    فالحضور **لكل طالب** (مش سطر واحد للحصة).
 *
 * ⚠️ **ليه migration جديدة مش تعديل قديمة:** القاعدة 15 —migration
 * جديد لكل تغيير.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ① طالب الحصة يبقى اختياري (للحصة الجماعية).
        Schema::table('lessons', function (Blueprint $table) {
            $table->foreignId('student_id')->nullable()->change();
        });

        // ② نوع الحصة: `group` للحصة الجماعية.
        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('lesson_type', [
                'regular', 'trial', 'makeup', 'extra', 'free', 'assessment', 'group',
            ])->change();
        });

        // ③ طلاب الحصة الجماعية.
        Schema::create('lesson_students', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lesson_id', 'student_id']);
        });

        // ④ الحضور لكل طالب.
        Schema::table('lesson_attendance', function (Blueprint $table) {
            $table->dropUnique(['lesson_id']);
            $table->unique(['lesson_id', 'student_id'], 'lesson_attendance_lesson_student_unique');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_attendance', function (Blueprint $table) {
            $table->dropUnique('lesson_attendance_lesson_student_unique');
            $table->unique(['lesson_id']);
        });

        Schema::dropIfExists('lesson_students');

        Schema::table('lessons', function (Blueprint $table) {
            $table->enum('lesson_type', [
                'regular', 'trial', 'makeup', 'extra', 'free', 'assessment',
            ])->change();

            $table->foreignId('student_id')->nullable(false)->change();
        });
    }
};
