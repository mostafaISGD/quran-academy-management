<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الموظفون — هوية الشخص داخل الأكاديمية.
 *
 * ليش جدول لوحده ومش على users؟ لأن الموظف هو هوية إدارية (وظيفة، قسم،
 * مدير مباشر، حالة توظيف) بينما حساب الدخول شيء منفصل. ممكن يبقى عند
 * الموظف حساب دخول وممكن يبقى من غيره — زي teachers.user_id و
 * parents.user_id بالظبط.
 *
 * job_title و department نص حر مش enum: الأكاديمية ممكن توظف "مسؤول
 * متابعة" أو "مصمم" من غير ما نعدل الداتابيز.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            // حساب دخول اختياري — الموظف ممكن يبقى من غير حساب
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // ===== البيانات الشخصية =====
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('country_code', 5)->nullable();
            $table->string('email')->nullable();
            $table->string('gender', 10)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('nationality')->nullable();
            $table->string('address')->nullable();
            $table->string('photo_url')->nullable();

            // ===== بيانات العمل =====
            $table->string('job_title')->nullable();
            $table->string('department')->nullable();
            $table->enum('employment_type', ['full_time', 'part_time', 'contract', 'volunteer'])->default('full_time');
            // المدير المباشر — موظف تاني في نفس الجدول
            $table->foreignId('manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('joined_at')->nullable();
            $table->enum('status', ['active', 'inactive', 'on_leave'])->default('active');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
