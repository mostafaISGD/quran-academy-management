<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ الباقة على **المجموعة نفسها** — مش على كل طالب لوحده.
 *
 * السبب: اشتراك المجموعة **شهري** (قرار ثابت). فلو كل طالب
 * بيدفع باقة مختلفة، الحساب في آخر الشهر بيطلع مش متفق.
 *
 * فالمجموعة بتاخد **باقة واحدة**، وأي طالب يدخل بياخد نفس
 * الباقة + فاتورة على سعرها.
 *
 * ⚠️ `nullable` عن قصد: مجموعات قديمة من غير باقة — اللي بيدخلوا
 * بياخدوا اشتراك من باقة البرنامج الشهرية زي ما كانوا قبل كده،
 * ومش هنكسر حاجة موجودة.
 *
 * ⭐ تغيير الباقة **مقفول** لحد ما اشتراكات المجموعة تخلص
 * وفواتيرها تتسدّد — شوف `GroupEnrollmentService::packageBlockersFor`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_classes', function (Blueprint $table) {
            $table->foreignId('package_id')->nullable()->after('capacity')
                ->constrained('subscription_plans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('group_classes', function (Blueprint $table) {
            $table->dropForeign(['package_id']);
            $table->dropColumn('package_id');
        });
    }
};