<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ عمودين ناقصين في `sessions` — كان الـdriver مكسور.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ المشكلة
 *
 * الـmigration بتاع PR 1.1 عمل الجدول بـ4 أعمدة: `id`,
 * `user_id`, `payload`, `last_activity`.
 *
 * ⭐ بس `Illuminate\Session\DatabaseSessionHandler::write()` في
 * Laravel 11 بيعمل update بـ**5** أعمدة:
 *
 *     update "sessions" set
 *       "payload"   = ?,
 *       "last_activity" = ?,
 *       "user_id"   = ?,
 *       "ip_address"    = ?,   ← ⭐ الناقص
 *       "user_agent"    = ?    ← ⭐ الناقص
 *     where "id" = ?
 *
 * ⭐ النتيجة على أي بيئة بـ`SESSION_DRIVER=database`:
 *
 *     SQLSTATE[HY000]: General error: 1 no such column: ip_address
 *
 * ⭐ ⭐⭐ **مش وقت أول login — وقت *كل* login.** يعني الـ500
 * بتفصل مع **كل** مستخدم، وكل session مش بتتكتب.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه محدش شافه
 *
 * ⭐ ① `.env.testing` فيه `SESSION_DRIVER=array` — فالاختبارات
 *    **ما بتكتبش** صف في الجدول خالص.
 *
 * ⭐ ② `.env` المحلي بيستخدم `file`.
 *
 * ⭐ ③ `SessionAuthenticationTest` بيفحص إن الجدول **موجود**
 *    (`Schema::hasTable`) — بس مافيش اختبار **بيكتب فيه**.
 *
 * ⭐ `hasTable` بيجاوب سؤال جدول وبيسكت عن سؤال الأعمدة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه الـعمودين `nullable`
 *
 * ⭐ عشان **مش** نكسر الجلسات الموجودة. العمود الناقص في
 * `INSERT` بيدي `NULL` مش error — فالجلسات اللي شغّالة دلوقتي
 * هتفضل شغّالة بعد النشر.
 *
 * ⭐ ليش nullable أصلاً؟ لأن `read()` بيرجّع `ip_address` و
 * `user_agent` وبيحطّهم في `Request` — والقيمة الفاضية معناها
 * «مش متسجّل» مش «فاضي».
 *
        // ⭐ الحارس: الـmigration اللي قبله بيعمل `hasTable` guard،
        // وممكن الجدول يكون موجود من نسخة قديمة. العمودين
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⭐ الحارس: الـmigration اللي قبله بيعمل `hasTable` guard،
        // ⭐ الحارس: الـmigration اللي قبله بيعمل `hasTable` guard،
        // وممكن الجدول يكون موجود من نسخة قديمة من المشروع.
        if (! Schema::hasTable('sessions')) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('sessions', 'ip_address')) {
                // ⭐ 45 = `inet` في MySQL، ويقبل `127.0.0.1`
                // (4 bytes) و `::1` (16 bytes).
                $table->string('ip_address', 45)->nullable()->after('last_activity');
            }

            if (! Schema::hasColumn('sessions', 'user_agent')) {
                // ⭐ `text` مش `string` — الـUser-Agent المعمول
                // بيطول (متصفح على لينكس بيوصل 200+ حرف)،
                // و`varchar 255` بيقطعه في النص.
                $table->text('user_agent')->nullable()->after('ip_address');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sessions')) {
            return;
        }

        Schema::table('sessions', function (Blueprint $table) {
            // ⭐⚠️ **العمودين بيتشالوا بـ`DROP`** — مفيش
            // ⭐⚠️ **العمودان بيتشالا بـ`dropColumn`** — مفيش
            // `dropColumn` على SQLite قبل Laravel 11.
            //
            // وبلا قصد: العمودين ميكتبهم Laravel لوحده من
            // `DatabaseSessionHandler`. حذفهم معناه أي بيئة
            // بترجع لـdriver تاني (مثلاً `redis`) مش هتتأثر.
            $columns = [];

            if (Schema::hasColumn('sessions', 'user_agent')) {
                $columns[] = 'user_agent';
            }

            if (Schema::hasColumn('sessions', 'ip_address')) {
                $columns[] = 'ip_address';
            }

            foreach ($columns as $column) {
                $table->dropColumn($column);
            }
        });
    }
};
