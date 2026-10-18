<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐⭐ جدول الجلسات — **كان ناقصاً بتاتاً**.
 *
 * ═══════════════════════════════════════════════════════════════
 * المشكلة
 *
 * `config/session.php:21` بيقول:
 *
 *     'driver' => env('SESSION_DRIVER', 'database'),
 *
 * و`.env.example` كمان كان بيقول `SESSION_DRIVER=database` — بس
 * مافيش **migration** للجدول ده. و78 migration كانت في المشروع،
 * مافيش فيهم السطر `create('sessions', …)`.
 *
 * ⭐ النتيجة: أي بيئة بتشغّل تسجيل دخول بالـcookie من غير Redis
 * كانت هتفصل بـ `no such table: sessions` **وقت أول طلب** —
 * مش وقت `artisan migrate`، فمحدش بيشوفه لحد ما حد يعمل
 * تسجيل دخول في الإنتاج.
 *
 * ⭐ وملاحظة: `php artisan session:table` بيعمل migration، بس
 * المشروع ده بيشتغل بـ78 migration مكتوبة بإيد — والجدول ده
 * جزء أساسي من الـauth، مش حاجة اختيارية.
 *
 * ⚠️ **ليه هنضيفه لو `.env.example` بقى `redis`؟**
 *
 * لأن `config/session.php` بيفترض `database` كـfallback، وأي حد
 * بيشغّل المشروع من غير `.env` كامل هيقع فيه. وكمان
 * `SESSION_DRIVER=file` بيحفظ في `storage/framework/sessions`
 * — لو حد ضيّر الملفات، الجلسات بتتمسح. الجدول بيدي المسار
 * الآمن موجود من غير Redis.
 *
 * @see \Tests\Feature\SessionAuthenticationTest::test_the_sessions_table_exists
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⭐ متكرّرة في Laravel 11 بتعمل guard. لو حد شغّل
        // `session:table` كمان، من غير guardMigration التانية
        // هترمي «table already exists» وتوقّف كل الهجرات.
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            // ⭐ مفتاح الجلسة. **مشفّر** وقت النقل في الكوكي
            // (`EncryptCookies`) ومخزّن صريح هنا.
            $table->string('id')->primary();

            // ⭐ مين الجلسة دي. فاضي لو الزائر لسه مادخلش.
            $table->foreignId('user_id')->nullable()->index();

            // ⭐ محتوى الجلسة، **مشفّر** بـlaravel (`Crypt`).
            $table->text('payload');

            // ⭐ آخر مرة شُغلت. بيتمسح منها الجلسات المنتهية.
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};