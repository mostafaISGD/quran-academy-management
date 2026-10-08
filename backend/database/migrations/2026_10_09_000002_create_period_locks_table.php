<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ قفل الفترات — «بعد ما الشهر اتقفل، ما حدش يكتب فيه».
 *
 * ⭐ **المشكلة اللي بتحلها:**
 *
 * دلوقتي لو مرتبات شهر مارس اتعتمد، يقدر حد يفتح شهر مارس
 * تاني ويسجّل مصروف جديد فيه — من غير ما يبان. وكشف المرتبات
 * اللي اتقفل ممكن يتغير رقمه، والأرقام اللي اتفقنا عليها في
 * اجتماع لإدارة تطلع غلط.
 *
 * ⭐ `period_locks` بيقفل **أي فترة زمنية** (شهر، ربع سنة،
 * يوم واحد) لكل وحدة (كل الحسابات، أو حساب واحد بس، أو مرتبات
 * المعلمين بس).
 *
 * ⚠️ مين يفتح الفترة المقفلة؟ قرار الإدارة: **المدير بس**.
 * دي في الـ permission: `finance.periods.unlock`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('period_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();

            /**
             * ⭐ الوحدة — «حاجة واحدة بتتقفل» مش «كل حاجة».
             *
             * `all` كل الحسابات (الافتراضي — أدق حاجة)
             * `account` حساب واحد بس
             * `payroll` مرتبات المعلمين
             * `expenses` المصروفات
             */
            $table->enum('scope', ['all', 'account', 'payroll', 'expenses'])
                ->default('all');

            /** ⭐ للحساب الواحد بس — `scope = account` */
            $table->foreignId('financial_account_id')->nullable()
                ->constrained('financial_accounts')->cascadeOnDelete();

            /**
             * ⭐ الفترة من/لـ — **شاملة الطرفين**.
             *
             * شهر مارس = `2026-03-01` → `2026-03-31`.
             * استعلام «الفترة دي مقفولة؟» = تداخل (overlap):
             * `start <= period_end AND end >= period_start`.
             */
            $table->date('period_start');
            $table->date('period_end');

            $table->string('label', 120)->nullable();
            $table->text('reason')->nullable();

            // ⭐ مين قفل ومين فتح — обя�� traceability
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();

            // ⭐ فتح — لو فتح (المدير بس)
            $table->foreignId('unlocked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('unlocked_at')->nullable();
            $table->text('unlock_reason')->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'period_start', 'period_end']);
            $table->index(['financial_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('period_locks');
    }
};