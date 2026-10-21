<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ **PR 3.7 — المنطقة الزمنية للحصة.**
 *
 * ⭐ **القاعدة**: `APP_TIMEZONE=UTC` — وكل `scheduled_*_at`
 * **مخزّن UTC**. بس السلسلة المتكررة اتولّدت بـCarbon في **UTC**
 * ⇒ ساعة الحصة المحلية كانت **بتتحرك** ساعة مع التوقيت الصيفي
 * (في لندن مثلاً: 16:00 بتبقى 15:00 بعد التغير).
 *
 * ⭐ **الحل**: كل حصة من سلسلة متكررة بتحفظ:
 *
 * - `timezone` — المنطقة التي **اتولّدت فيها** (مثل
 *   `Europe/London`).
 * - `local_start_time` — الساعة المحلية الأصلية (16:00).
 *
 * ⇒ إعادة التوليد/العرض بياخدوا «الساعة المحلية» ويحوّلوها للمنطقة
 * المطلوبة ⇒ الساعة المحلية **ثابتة** عبر التوقيت الصيفي.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // ⭐ المنطقة التي اتولّدت فيها الحصة (null = UTC).
            $table->string('timezone', 64)->nullable()->after('duration_minutes');

            // ⭐ الساعة المحلية الأصلية (HH:MM:SS).
            $table->time('local_start_time')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['timezone', 'local_start_time']);
        });
    }
};
