<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.13 — مفتاح فريد لجزء الحفظ.**
 *
 * ⭐ **قبل PR ده** إعادة إرسال نفس الجزء (الطالب + الحصة + السورة
 * + نطاق الآيات) كانت تضيف صفاً مكرراً لكل مرة — سجل الحفظ
 * كان يتضخم بنفس الجزء من غير أي معنى.
 *
 * ⭐ مفتاح فريد: (student_id, lesson_id, surah_id,
 * from_ayah, to_ayah) — منع التكرار دلوقتي محكوم على مستوى
 * القاعدة نفسها (defense in depth) مضاف لعملية upsert في
 * الـcontroller.
 *
 * ⭐ ملاحظة: `lesson_id` قابل للـnull — والفهارس الفريدة مش
 * بتحسب NULL MySQL/SQLite، فالصفوف اللي بلا حصة مش بتتعارض
 * (شكل التدوين الخارجي بilla حصة ما يتعارضش).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memorization_records', function (Blueprint $table) {
            $table->unique(
                ['student_id', 'lesson_id', 'surah_id', 'from_ayah', 'to_ayah'],
                'memorization_segment_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('memorization_records', function (Blueprint $table) {
            $table->dropUnique('memorization_segment_unique');
        });
    }
};
