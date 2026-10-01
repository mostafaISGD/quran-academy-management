<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_phones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->string('phone_number', 20);
            $table->boolean('is_personal')->default(true);
            $table->boolean('is_parent')->default(false);
            $table->boolean('is_whatsapp')->default(false);
            $table->boolean('is_call')->default(true);
            $table->boolean('is_primary')->default(false);
            $table->string('parent_name')->nullable();
            $table->string('parent_relationship')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'is_primary']);
            $table->unique(['student_id', 'phone_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_phones');
    }
};
