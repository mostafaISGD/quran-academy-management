<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * حساب دخول لولي الأمر.
 *
 * ليش عمود جديد؟ مفيش أي طريقة تانية نعرف بيها إن المستخدم ده ولي
 * أمر: teachers ليها teachers.user_id، بس مفيش حاجة مقابلها في أولياء
 * الأمر — والعمود user_id في جدول parents بيفضل null.
 *
 * بدون عمود كده، أي حساب جديد هيتعامل معاه الـ layout على إنه أدمن
 * ويفتح لوحة التحكم ويشوف كل الطلاب والمالية.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_parent')->default(false)->after('status');
            $table->index('is_parent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_parent');
            $table->dropIndex(['is_parent']);
        });
    }
};
