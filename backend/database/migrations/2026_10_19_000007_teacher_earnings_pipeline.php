<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2.10 — خط أنابيب أرباح المعلمين.
 *
 * ① `rate_snapshot` — لقطة السعر والعقد لحظة الحصة: السعر
 *    بيتغيّر لاحقاً والمستحق القديم بيحتفظ بالقيمة اللي اتقفل
 *    عليها. (الحقل هو الضامن إن العرض للمعلّم مش بيعاد يقرأ
 *    `teacher_rates` مرة تانية.)
 *
 * ② `approved_by`/`approved_at` — من اعتمد المستحق وامتحنه
 *    (قرار مالي يحتاج تدقيق بجانب `audit_logs`).
 *
 * ③ `reversed` — حالة مستحق انعكس (مسار الإلغاء جاي مع
 *    إعادة تدوير الحصص). الفهرس الفريد الجزئي يسمح بسطور
 *    `reversed` لنفس الحصة، ويمنع باقي الحالات من التكرار —
 *    «مستحق واحد لكل حصة عايشة».
 *
 * ⚠️ الترتيب مهم على SQLite: تعديل enum بيعيد بناء الجدول
 * (وبكده بينساخ الفهارس اللي اتعملت قبله يدوياً) — فالفهرس
 * الفريد بيتعمل **بعد** إعادة البناء.
 */
return new class extends Migration
{
    private const INDEX = 'unq_teacher_earnings_lesson_active';

    public function up(): void
    {
        Schema::table('teacher_earnings', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'paid', 'reversed', 'cancelled'])
                ->default('pending')
                ->change();

            $table->json('rate_snapshot')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });

        DB::statement(
            "CREATE UNIQUE INDEX ".self::INDEX.
            " ON teacher_earnings (lesson_id) WHERE status <> 'reversed'"
        );
    }

    public function down(): void
    {
        // صيغة عامة تشتغل على SQLite وPostgreSQL
        DB::statement('DROP INDEX '.self::INDEX);

        Schema::table('teacher_earnings', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);

            $table->enum('status', ['pending', 'approved', 'paid', 'cancelled'])
                ->default('pending')
                ->change();

            $table->dropColumn(['rate_snapshot', 'approved_by', 'approved_at']);
        });
    }
};
