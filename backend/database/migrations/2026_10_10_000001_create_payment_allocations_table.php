<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⭐ `payment_allocations` — التوزيع الوحيد اللي بيتحدد منه المدفوع.
 *
 * ⭐ **القاعدة 5 في الخطة:** «لا نخزّن المدفوع والمتبقي على الفاتورة
 * كأرقام مستقلة؛ تتحسب من المدفوعات المخصصة».
 *
 * يعني الفاتورة ما عندهاش `paid_amount` كعمود حقيقي — ده
 * **cache** بيتحسب من عدد توزيعات الدفعات النشطة.
 *
 * والتوزيع النشط هو اللي بيقول «الدفعة دي غطّت 500 من الفاتورة
 * دي». فلو الدفعة اتعكست أو تم استردادها، نعلّم التوزيع
 * `reversed` — ما نمحوش السطر (قاعدة 1: لا تعديل على السجلات
 * المالية، والعكسي هو الحل).
 *
 * ⭐ ليه `reversed` بدل `DELETE`؟
 *
 * لأن تاريخ «الدفعة دي كانت مخصصة للفاتورة دي» معلومة محاسبية —
 * بتقول «حصل إنه تم تخصيص، بعدين التوزيع اتلغي». المسطرة بتفضل
 * ليراجعها المدير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 12, 2);

            /**
             * ⭐ الحالة: `active` يعني التوزيع محسوب في المدفوع.
             * `reversed` يعني الاتفاق اتلغي (استرداد أو عكس دفعة).
             */
            $table->enum('status', ['active', 'reversed'])->default('active');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // ⭐ أي حد كتب التوزيع — عشان نعرف من غيّر التخصيص
            $table->timestamps();

            $table->index(['invoice_id', 'status']);
            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
