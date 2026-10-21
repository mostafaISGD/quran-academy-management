<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.1 — سجل تحويلات حالة الحصة.**
 *
 * ⭐ **ليه السجل؟** عشان سؤال «مين غيّر حالة الحصة وإزاي وإمتى»
 * يبقى له إجابة بعد شهور — مش من الذاكرة. كل سطر هنا: من، إلى،
 * الفاعل، السبب.
 *
 * ⭐ **إضافة فقط**: مفيش `updated_at` — السجل بيتسجَّل ومحدش
 * بيلمسه (نفس منطق السجلات المالية: التصحيح قيد جديد مش
 * تعديل).
 *
 * ⚠️ `actor_id` بـ`nullOnDelete` — المستخدم بيتمسح من النظام
 * والسجل يفضل (السجل تاريخ، مش ملكية).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained('lessons')->cascadeOnDelete();
            $table->string('from_status', 32);
            $table->string('to_status', 32);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();

            // ⭐ الاستعلام المتوقع: «تاريخ حصة واحدة» — والفهارس
            // في Postgres بيتبني لوحدها من الـFK، لكن SQLite لأ.
            $table->index(['lesson_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_status_history');
    }
};
