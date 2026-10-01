<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->enum('rate_type', ['per_lesson', 'monthly']);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();

            $table->index(['teacher_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_rates');
    }
};
