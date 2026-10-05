<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مرتبات المعلمين — نفس دورة مرتبات الموظفين بالظبط.
 *
 * ⭐ ليه جدول جديد ولسه في `teacher_earnings`؟
 *
 * `teacher_earnings` = **المستحق** (بيحسب لوحده من كل حصة، ١١٧
 * سطر). ده مش هيبقى هيتدفع مباشرة، لأنه:
 *   - مش في period → مين يقول إن ده مستحق شهر مين
 *   - مفيش snapshot → لو سعر المعلم اتغيّر، كل التاريخ يتغيّر
 *   - مفيش حالة «معتمد» مربوطة بمرحلة صرف
 *
 * فبنعمل `teacher_payroll_lines` = **الاحتساب للفترة**، زي
 * `employee_payroll_lines` بالظبط. الأرقام بتتقفل عند الاعتماد.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('payroll_period_id')->constrained('payroll_periods')->cascadeOnDelete();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();

            // ===== لقطات =====
            $table->unsignedInteger('lessons_count')->default(0);
            $table->decimal('hours', 8, 2)->default(0);
            $table->decimal('rate_snapshot', 10, 2)->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->char('currency', 3)->default('EGP');

            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();

            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->string('payment_method', 50)->nullable();
            $table->string('reference', 100)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            // ⭐ سطر واحد لكل (معلم + فترة)
            $table->unique(['payroll_period_id', 'teacher_id'], 'teacher_payroll_unique');
            $table->index(['payroll_period_id', 'status']);
            $table->index('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_payroll_lines');
    }
};