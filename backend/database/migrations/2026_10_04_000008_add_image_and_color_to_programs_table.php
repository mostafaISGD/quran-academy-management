<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * صورة/أيقونة البرنامج.
 *
 * البرنامج كيان ليه وصف وصورة — الجدول القديم كان بيضيّع ده.
 * صورة البروفايل مش مطلوبة، أيقونة أو لوجو كفاية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('description');
            // لون للبطاقة — بديل للصورة لو مفيش واحدة
            $table->string('color', 7)->default('#2563eb')->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'color']);
        });
    }
};
