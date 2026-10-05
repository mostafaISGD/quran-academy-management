<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرتبات الموظفين بالساعات.
 *
 * ⭐ الأجر = الساعات المسجّلة في `attendance_records` × سعر الساعة.
 * مفيش معادلات متعددة — نظام واحد بس (فريلانس بالساعات).
 *
 * التصميم:
 * - `employee_payroll_lines` — سطر لكل (موظف + فترة). ده مش الدفع،
 *   ده **الاحتساب**. فالمراجع لسه مفتوحة والتعديل سهل.
 * - الدفع (`paid` + `paid_at` + `payment_method`) بيحصل بعد الاعتماد
 *   بس. سطر تاني لنفس الفترة غير مسموح (unique) — عشان ما يحصلش
 *   double-pay.
 *
 * `hours` و `hourly_rate` **لقطات** (snapshots) وقت الاحتساب:
 * لو غيّرنا سعر الموظف أو عدّلنا حضور الشهر اللي فات، مرتبه اللي
 * اتدفع ما يتغيرش. ده مهم جداً في المرتبات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();

            // ===== لقطات وقت الاحتساب =====
            // الساعات كما كانت لحظة الاحتساب (مش استعلام مباشر على
            // الحضور) — لوAttendance اتعدل، المرتب يتعدّلش.
            $table->decimal('hours', 8, 2)->default(0);
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('EGP');

            // كم يوم اتسجل عليه حضور في الفترة (للمراجعة)
            $table->unsignedSmallInteger('days_present')->default(0);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->string('payment_method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // ⭐ سطر واحد لكل (موظف + فترة) — يمنع الدفع المزدوج
            $table->unique(['payroll_period_id', 'employee_id'], 'employee_payroll_unique');
            $table->index(['payroll_period_id', 'status']);
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_payroll_lines');
    }
};