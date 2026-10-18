<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ **أعلام الربط بين ولي الأمر وابنه (PR 1.8).**
 *
 * الـroadmap (PR 1.8): `student_parents` يضيف
 * `can_view_finance` و `can_view_progress` و `can_request_changes`،
 * و **يفرض اللي موجود من اليوم الأول** (`can_manage`, `can_pay`)
 * واللذين لا أحد كان يقرأهما.
 *
 * القيم الافتراضية **مقصودة**:
 * - `can_view_finance` = true: البورتال الحالي بيعرض الديون
 *   لكل ولي — الافتراضي يحفظ السلوك الحالي، والإدارة تُغلقه
 *   لكل ولي على حدة.
 * - `can_view_progress` = true: نفس المنطق.
 * - `can_request_changes` = false: طلبات ولي الأمر (تعليق أو
 *   تغيير حصة) تعمل في PR 4.5 — العمود يُلقى من دي؛ **بدون
 *   معنى** قبل ظهور البورتال.
 *
 * down(): حذف الأعمدة واحد واحد عشان SQLite ما يعيد الجدول
 * إلا مرة (قيد أعمدة قديمة).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_parents', function (Blueprint $table) {
            $table->boolean('can_view_finance')->default(true);
        });

        Schema::table('student_parents', function (Blueprint $table) {
            $table->boolean('can_view_progress')->default(true);
        });

        Schema::table('student_parents', function (Blueprint $table) {
            $table->boolean('can_request_changes')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('student_parents', function (Blueprint $table) {
            $table->dropColumn('can_request_changes');
        });

        Schema::table('student_parents', function (Blueprint $table) {
            $table->dropColumn('can_view_progress');
        });

        Schema::table('student_parents', function (Blueprint $table) {
            $table->dropColumn('can_view_finance');
        });
    }
};
