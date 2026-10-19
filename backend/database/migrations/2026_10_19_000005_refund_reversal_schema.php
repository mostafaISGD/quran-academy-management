<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2.8 - قاعدة الـ"استرداد/عكس".
 *
 * 1) `payments.status` + `reversed`:
 *
 *    دفعة **خاطئة** بتفسرب "عكس" — من غير حذف ولا voided
 *    (voided معناها "استردت"، وreversed معناها "سجل خطأ
 *    وصحح عكسياً"). فرق يفسره تقرير في آخر السنة.
 *
 * 2) `refunds`:
 *    - `mode` — نقدي vs رصيد دائن (credit_note). زوج مختلف
 *      بتأثير٪ مختلف: نقدي = حركة من الحساب؛ credit_note =
 *      student_credits (خصم المشتركة القادمة).
 *    - `approved_by / approved_at` — الموافقة التانية
 *      للمبالغ فوق السقف (`finance.refund_approval_threshold`).
 *    - `status` + `pending_approval` — "المبلغ معلق موافقة"
 *      الحقيقة تخزين reality.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('status', ['pending', 'completed', 'failed', 'voided', 'reversed'])
                ->default('completed')
                ->change();
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->enum('status', ['pending', 'pending_approval', 'processed', 'failed'])
                ->default('pending')
                ->change();

            $table->enum('mode', ['cash', 'credit_note'])->default('cash');

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->enum('status', ['pending', 'completed', 'failed', 'voided'])
                ->default('completed')
                ->change();
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);

            $table->enum('status', ['pending', 'processed', 'failed'])
                ->default('pending')
                ->change();
            $table->dropColumn(['mode', 'approved_by', 'approved_at']);
        });
    }
};
