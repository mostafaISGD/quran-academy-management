<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            
            $table->string('code', 50)->unique(); // الكود اللي بيدخله المستخدم
            $table->string('name', 100); // اسم العرض للعرض في الواجهة
            $table->text('description')->nullable();
            
            // نوع الخصم
            $table->enum('type', ['percent', 'fixed']); // percent = نسبة، fixed = مبلغ ثابت
            $table->decimal('value', 10, 2); // القيمة: 20 لـ 20%، أو 100 لـ 100 ج.م
            
            // القيود
            $table->unsignedInteger('max_uses')->nullable(); // إجمالي عدد الاستخدامات (null = لا حد)
            $table->unsignedInteger('used_count')->default(0); // عداد الاستخدامات الفعلي
            $table->unsignedInteger('max_uses_per_student')->default(1); // حد الاستخدام لكل طالب
            
            // الصلاحية الزمنية
            $table->timestamp('starts_at')->nullable(); // nullable = فوراً
            $table->timestamp('expires_at')->nullable(); // nullable = لا تنتهي
            
            // النطاق
            $table->enum('applies_to', ['all', 'program', 'category'])->default('all');
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete(); // لو applies_to = program
            $table->enum('category', ['traditional', 'golden', 'group', 'single'])->nullable(); // لو applies_to = category
            
            // قيود إضافية
            $table->boolean('new_students_only')->default(false); // طلاب جدد بس؟
            $table->boolean('is_active')->default(true);
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['organization_id', 'is_active']);
            $table->index(['code', 'organization_id']);
            $table->index(['starts_at', 'expires_at']);
        });

        Schema::create('promo_code_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            
            $table->decimal('discount_amount', 12, 2); // قيمة الخصم اللي اتطبقت
            $table->decimal('original_amount', 12, 2); // المبلغ الأصلي قبل الخصم
            $table->decimal('final_amount', 12, 2); // المبلغ بعد الخصم
            
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->useCurrent();
            
            $table->index(['promo_code_id', 'student_id']);
            $table->index(['invoice_id']);
            $table->unique(['promo_code_id', 'invoice_id']); // كود واحد لكل فاتورة
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promo_code_usages');
        Schema::dropIfExists('promo_codes');
    }
};