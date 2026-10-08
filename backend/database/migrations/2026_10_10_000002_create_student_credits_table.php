<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐⭐ `student_credits` — الرصيد الدائن.
 *
 * ⭐ **الفرق بين الاسترداد النقدي والرصيد الدائن:**
 *
 * الطالب دفع ١٠٠٠، لغى الاشتراك، ولسه فيه حصص مدفوعة.
 * قرار الإدارة: استرداد نقدي **أو** رصيد دائن يُخصم من
 * اشتراك قادم.
 *
 * `student_credits` بتسجل الرصيد الدائن. كل سطر بيقول:
 * "الطالب ده عنده X ج.م رصيد، منها Y استُهلك، وZ استُرد نقداً".
 *
 * ⚠️ مابنشطبش السطر لو الرصيد اتصفّر — بنغيّر الـ `status`
 * لـ `depleted`، عشان تاريخ الطالب يفضل موجود.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();

            // ⭐ العملة — الرصيد بالعملة نفسها
            $table->string('currency', 3);

            $table->decimal('total', 12, 2);
            $table->decimal('used', 12, 2)->default(0);
            $table->decimal('refunded', 12, 2)->default(0);

            $table->string('reason', 255);

            // ⭐ مصدر الرصيد: overpayment, manual, credit_note
            $table->string('source_type', 50)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            // active / depleted / refunded
            $table->string('status', 20)->default('active');

            $table->date('expires_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'status']);
            $table->index(['organization_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_credits');
    }
};
