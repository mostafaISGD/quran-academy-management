<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->foreignId('parent_lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->enum('lesson_type', ['regular', 'trial', 'makeup', 'extra', 'free', 'assessment'])->default('regular');
            $table->timestamp('scheduled_start_at');
            $table->timestamp('scheduled_end_at');
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->enum('meeting_provider', ['zoom', 'google_meet', 'other'])->nullable();
            $table->text('meeting_url')->nullable();
            $table->string('meeting_id', 100)->nullable();
            $table->string('meeting_password', 50)->nullable();
            $table->enum('status', [
                'scheduled', 'confirmed', 'in_progress', 'completed',
                'cancelled', 'student_absent', 'teacher_absent', 'technical_issue', 'rescheduled',
            ])->default('scheduled');
            $table->string('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['teacher_id', 'scheduled_start_at', 'scheduled_end_at']);
            $table->index(['student_id', 'scheduled_start_at']);
            $table->index('parent_lesson_id');
            $table->index('lesson_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lessons');
    }
};
