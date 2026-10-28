<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ **طلبات ولي الأمر (PR 4.5).**
 *
 * ⭐ ولي الأمر بيطلب تغيير (إلغاء حصة / تأجيلها / تعليق الاشتراك)،
 * والإدارة بتراجع. الطلب **سجل** مش إجراء: الموافقة بتسجّل القرار
 * بس، والتطبيق الفعلي بيفضل على المسارات الإدارية المراجَعة
 * (`LessonService` / `SubscriptionPauseService`).
 *
 * ⭐ `organization_id` مشتق من الطالب — عشان صندوق الإدارة يتقرا
 * بمؤسسة واحدة، ومفيش طلب يتسرّب لمؤسسة تانية.
 *
 * ⭐ `parent_id` و`reviewed_by` بـ`nullOnDelete`: حذف حساب مش
 * بيمسح تاريخ الطلب (نفس مبدأ السجلات المالية).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('parents')->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();

            // cancel | postpone | suspend
            $table->string('type', 20);
            // pending | approved | rejected | withdrawn
            $table->string('status', 20)->default('pending');

            // ⭐ التأجيل بيحتاج التاريخ المطلوب؛ الإلغاء والتعليق لأ.
            $table->date('requested_date')->nullable();
            $table->text('reason');
            $table->text('admin_notes')->nullable();

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // ⭐ صندوق الإدارة = (مؤسسة، حالة) — أهم استعلام.
            $table->index(['organization_id', 'status']);
            $table->index(['student_id', 'status']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_requests');
    }
};
