<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('teacher_id')->constrained('teachers')->cascadeOnDelete();
            $table->foreignId('earning_id')->nullable()->constrained('teacher_earnings')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('teacher_payments')->nullOnDelete();
            $table->enum('type', ['lesson_earning', 'monthly_salary', 'bonus', 'deduction', 'advance', 'payment', 'adjustment']);
            $table->decimal('debit', 12, 2)->default(0);
            $table->decimal('credit', 12, 2)->default(0);
            $table->decimal('balance_after', 12, 2);
            $table->char('currency', 3);
            $table->string('description')->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            $table->index(['teacher_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_ledger_entries');
    }
};
