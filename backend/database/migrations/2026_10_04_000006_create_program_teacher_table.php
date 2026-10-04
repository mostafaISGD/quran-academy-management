<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ربط المعلم بالبرنامج.
 *
 * معناه: «هذا المعلم يقدر يدرّس هذا البرنامج» — تعريف، مش جدول عمل.
 * ليش ده لازم؟ ل قبل كده مفيش أي مكان يقول إيه البرامج اللي المعلم
 * بيحضّرها. فيظهر في كل شاشة لوPrograms، وكل واحد بيخمّن — وبتعمل
 * مطابقة بين اللي مكتوب واللي بيحصل فعلاً في الحصص.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_teacher', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();

            // المعلم الأساسي للبرنامج ده — بيظهر في الواجهة الأول
            $table->boolean('is_primary')->default(false);

            // performance rating متاحة للبرنامج ده (0.5 - 2 مثلاً)
            $table->decimal('rate_multiplier', 4, 2)->default(1.00);

            $table->text('notes')->nullable();
            $table->timestamps();

            // المعلم يقدر يدرّس البرنامج مرة واحدة بس
            $table->unique(['program_id', 'teacher_id']);
            $table->index(['teacher_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_teacher');
    }
};
