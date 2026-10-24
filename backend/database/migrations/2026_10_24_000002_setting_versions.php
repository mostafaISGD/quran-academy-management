<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.12 — سجل نسخ الإعدادات.**
 *
 * ⭐ **قبل PR ده** تغيير إعداد (من المستخدم أو بالكود) ما كانش
 * فيه مصدر يعيد تكوين القيمة السابقة — غلطة واحدة كانت تدمر
 * قاعدة تجارية (نافذة الانتهاء مثلًا) بلا رجعة.
 *
 * ⭐ دلوقتي: كل تعديل يُسجّل هنا (قديم/جديد/فَاعِل) ضمن معامل
 * واحد مع الكتابة + نطاق المؤسسة — والرجوع عبر
 * `POST /api/settings/{key}/rollback`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setting_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->string('key', 100);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();

            /** ⭐ مين غيّر — يقدر يكنون نظام (import/seed). */
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // ⭐ الأكثر نسخة لكل (مؤسسة، مفتاح) — وكلها هسي هيفسِر.
            $table->index(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setting_versions');
    }
};
