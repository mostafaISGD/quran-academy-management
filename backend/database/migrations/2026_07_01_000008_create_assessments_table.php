<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('students')->nullOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->timestamp('scheduled_at');
            $table->unsignedTinyInteger('reading_score')->nullable();
            $table->unsignedTinyInteger('tajweed_score')->nullable();
            $table->unsignedTinyInteger('memorization_score')->nullable();
            $table->string('recommended_level')->nullable();
            $table->text('notes')->nullable();
            $table->enum('result', ['ready_to_subscribe', 'needs_follow_up', 'not_suitable'])->nullable();
            $table->timestamps();

            $table->index(['lead_id', 'student_id']);
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessments');
    }
};
