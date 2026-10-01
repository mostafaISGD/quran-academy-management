<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_attendance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('status', ['present', 'absent', 'late', 'excused']);
            $table->unsignedSmallInteger('late_minutes')->nullable();
            $table->timestamp('marked_at');
            $table->foreignId('marked_by')->constrained('users');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_attendance');
    }
};
