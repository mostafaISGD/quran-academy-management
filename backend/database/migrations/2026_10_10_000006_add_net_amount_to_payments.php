<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ `net_amount` — المبلغ الواصل الفعلي بعد العمولة.
 *
 * ⭐ معناه: الطالب دفع 1000، بس بوابة الدفع خصمت 30 عمولة،
 * وفتحسابنا دخل 970 فقط.
 *
 * فالدفعة `amount` = 1000 (اللي الطالب دفعها)،
 * `net_amount` = 970 (اللي فتحسابنا استفاد منه).
 *
 * الفرق (30) بيتسجل كمصروف عمولة في دفتر الحسابات — علشان
 * «صافي التشغيل» يفضل حقيقي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('net_amount', 12, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('net_amount');
        });
    }
};
