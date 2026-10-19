<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ PR 2.6 — تسلسلات الترقيم.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه جدول؟
 *
 * لأن الترقيم المتسلسل محتاج **عدّاد مشترك**. والعدّاد لازم
 * يبقى في القاعدة (مش في الذاكرة) عشان:
 *
 *   ① طلبين جوه نفس اللحظة → نفس الرقم = تضارب
 *   ② السيرفر اتعاد تشغيله → العدّاد رجع صفر
 *   ③ أكتر من سيرفر → كل واحد عدّاد لوحده
 *
 * ⭐ **من غير فجوات** يعني: لو فاتورة اتلغت، الرقم **مش
 * بيرجع يُستخدم**. و`last_value` بيتقفل بـ`lockForUpdate` جوه
 * transaction — فمفيش حدين بياخدوا نفس الرقم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_sequences', function (Blueprint $table) {
            $table->id();

            // ⭐ `invoice:2026` — المفتاح بيضم السنة عشان العدّاد
            // يبدأ من 1 كل سنة (الفواتير بتترقّم جوه السنة).
            $table->string('key', 100);

            $table->unsignedBigInteger('last_value')->default(0);

            $table->timestamps();

            // ⭐ الـunique هنا هو **الحماية الحقيقية**. من غيرها
            // طلبين متزامنين بياخدوا نفس الرقم.
            $table->unique('key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_sequences');
    }
};