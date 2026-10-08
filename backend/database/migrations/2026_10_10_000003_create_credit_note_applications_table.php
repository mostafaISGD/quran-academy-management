<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ `credit_note_applications` — الرصيد الدائن بيتطبق على فاتورة.
 *
 * ⭐ كل تطبيق خصم جزء من الرصيد الدائن على فاتورة. الفاتورة
 * `credited_amount` بتزيد، والرصيد الدائن `used` بيزيد.
 *
 * ⭐ نفس القرار اللي في `payment_allocations`: السطر immutable.
 * لرفض التطبيق أو عكسه، بنعمل سجل عكسي — مابنمسحش.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_credit_id')->constrained('student_credits')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['student_credit_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_note_applications');
    }
};
