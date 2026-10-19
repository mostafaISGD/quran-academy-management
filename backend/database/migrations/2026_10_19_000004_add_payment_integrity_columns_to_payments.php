<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ PR 2.7 — أعمدة نزاهة الدفعات.
 *
 * ═══════════════════════════════════════════════════════════════
 * ① `account_id` — الحساب اللي دخلت فيه الفلوس.
 *
 * قبلها الحساب كان بيتحدد وقت الحركة المالية وبس — الدفعة
 * نفسها ماتسجلش عليه. فسؤال «الدفعة دي دخلت أنهي حساب»
 * كان محتاج استنتاج من `account_movements` — ولو الحركة
 * ماتسجلتش (فترة مقفولة)، الجواب بيضيع خالص.
 *
 * ② `fee_amount` — العمولة محفوظة صراحةً.
 *
 * قبلها كانت **محسوبة تغياً**: `amount - net_amount`.
 * لو `net_amount` اتصحح لاحقاً أو اتسجل غلط، العمولة
 * بتتغيّر كمان — تسجيل مالي بيتحسب مش محفوظ.
 *
 * ③ `idempotency_key` — مفتاح منع التكرار.
 *
 * ⭐⭐ السيناريو الحقيقي: العميل داس «ادفع» والنت ضعف،
 * المتصفح بيتعاد الطلب، والمحاسب داس مرتين مش فاكر.
 * النتيجة قبلها: **دفعتين 1000** على فاتورة 1000 —
 * وتانية بتتحول رصيد دائن بلا سبب واضح.
 *
 * ⭐ الـunique **مؤسسي**: `organization_id + idempotency_key`
 * — مؤسستين ممكن يستخدموا نفس الـkey عادي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('account_id')
                ->nullable()
                ->after('received_by')
                ->constrained('financial_accounts')
                ->nullOnDelete();

            $table->unsignedBigInteger('fee_amount')->default(0)->after('amount');

            $table->string('idempotency_key', 64)
                ->nullable()
                ->after('transaction_reference');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['organization_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'idempotency_key']);
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'fee_amount', 'idempotency_key']);
        });
    }
};
