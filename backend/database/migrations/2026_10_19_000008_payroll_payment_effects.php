<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2.11 — الصرف يكتب كل آثاره المالية.
 *
 * ① أنواع دفتر المعلم تكمل: `settlement` (تسوية) و
 *    `correction` (تصحيح — حركات عكسية فقط). الباقي موجود.
 *
 * ② `teacher_payments` ما كانش فيه حماية تكرار: فهرس فريد
 *    جزئي (معلم، فترة) — سطر مرتب واحد بيتصرف مرة واحدة.
 *    الصفوف اللي فترتها فاضية (دفعات يدوية خارج الفترات)
 *    مش داخل الفهرس.
 */
return new class extends Migration
{
    private const INDEX = 'unq_teacher_payments_period_once';

    public function up(): void
    {
        Schema::table('teacher_ledger_entries', function (Blueprint $table) {
            $table->enum('type', [
                'lesson_earning', 'monthly_salary', 'bonus', 'deduction',
                'advance', 'payment', 'adjustment', 'settlement', 'correction',
            ])->change();
        });

        Schema::table('teacher_payments', function (Blueprint $table) {
            $table->index(['teacher_id', 'payroll_period_id'], self::INDEX . '_lookup');
        });

        DB::statement(
            'CREATE UNIQUE INDEX ' . self::INDEX .
            " ON teacher_payments (teacher_id, payroll_period_id) WHERE payroll_period_id IS NOT NULL"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX ' . self::INDEX);

        Schema::table('teacher_payments', function (Blueprint $table) {
            $table->dropIndex(self::INDEX . '_lookup');
        });

        Schema::table('teacher_ledger_entries', function (Blueprint $table) {
            $table->enum('type', [
                'lesson_earning', 'monthly_salary', 'bonus', 'deduction',
                'advance', 'payment', 'adjustment',
            ])->change();
        });
    }
};
