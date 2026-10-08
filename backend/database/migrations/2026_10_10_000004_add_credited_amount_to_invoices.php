<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ `credited_amount` على الفاتورة.
 *
 * ⭐费用花提醒: `balance_due = total - paid_amount - credited_amount`
 *
 * يعني لو فاتورة بـ500 واطّبقنا 200 رصيد دائن، المتبقي=300.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('credited_amount', 12, 2)->default(0)->after('paid_amount');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('credited_amount');
        });
    }
};
