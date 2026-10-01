<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('name');
            $table->enum('billing_type', ['monthly', 'per_lesson', 'custom']);
            $table->decimal('price', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('lessons_count')->nullable();
            $table->unsignedSmallInteger('lesson_duration_minutes')->default(30);
            $table->unsignedSmallInteger('duration_days')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
