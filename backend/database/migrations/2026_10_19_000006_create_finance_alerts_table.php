<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2.9 - تقييبات التسوية المالية.
 *
 * انحراف مكتشوف = سطر هنا مش كلام في اللوج. الصف مؤسسي، وكإندر
 * قابلة للإغلاق (`resolved_at`) عشان التسوية اليومية ما تعيدش
 * نفس التنبيه المفتوح مفتوحاً جديداً كل يوم.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->enum('severity', ['info', 'warning', 'error'])->default('warning');
            // ما هو الانحراف: invoice_allocation / student_ledger_self /
            // student_ledger_source / account_movement / teacher_ledger
            $table->string('kind', 60);
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('message');
            $table->json('details')->nullable();
            $table->timestamp('resolved_at')->nullable();
            // من أغلق التنبيه — التسوية مش بتغلقه أبداً
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');

            // التسوية اليومية بتسأل «في تنبيه مفتوح بنفس النوع
            // والكيان؟» — الفهرس ده هو سبب عدم التكرار
            $table->index(['kind', 'entity_type', 'entity_id', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_alerts');
    }
};
