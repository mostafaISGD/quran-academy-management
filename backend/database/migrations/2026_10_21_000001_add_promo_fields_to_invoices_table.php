<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2.14 — الفاتورة بتوثّق «كام فضل بعد الخصم».
 *
 * قبلها الفاتورة كانت بتحمل `subtotal`/`discount`/`total` بس — يعني
 * «السعر الأصلي على القائمة» ومين الكود اللي اتطبق وقنباسب الخصم،
 * مكانش ليهم مكان. المواصفة بتطلب:
 *
 * ⭐ `list_amount` — السعر الأصلي (قبل أي خصم) — **بالقشوس زي
 *    باقي أعمدة الفاتورة** (ميجريشن 2.1); بيفضل ثابت حتى لو سعر
 *    الخطة اتغيّر: «تتبّع تكلفة الخصومات من غير ما نلمس السعر».
 * ⭐ `discount_reason` — سبب الخصم (يدوي أو ترويجي).
 * ⭐ `promo_code_id` — الكود الترويجي المطبق على الفاتورة.
 *
 * ⚠️ على SQLite العمود بتاع FK مينفعش DROP COLUMN عليه — الـ`down()`
 * بيعيد بناء الجدول يدوياً (نفس درس PR 2.12)، والفهارس بأسماء
 * مؤقتة لعُطل تعارض أسامي الفهارس القديمة اللي لسه على الجدول
 * المسمى tmp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->bigInteger('list_amount')->default(0)->after('subtotal');
            $table->string('discount_reason', 255)->nullable()->after('discount');
            $table->foreignId('promo_code_id')->nullable()->after('discount_reason')
                ->constrained('promo_codes')->nullOnDelete();
        });

        // ⭐ الباكفيل للبيانات القائمة: السعر الأصلي هو الـsubtotal،
        // والكود بيتاخد من سطر الاستخدام في جدول الاستخدامات.
        DB::statement('UPDATE invoices SET list_amount = subtotal');
        DB::statement(
            'UPDATE invoices SET promo_code_id = '
            .'(SELECT u.promo_code_id FROM promo_code_usages u '
            .'WHERE u.invoice_id = invoices.id LIMIT 1)'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            $this->sqliteRebuild();

            return;
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['promo_code_id']);
            $table->dropColumn(['list_amount', 'discount_reason', 'promo_code_id']);
        });
    }

    /**
     * إطة إعادة بناء على SQLite — الشكل = حركة التنفيذ **قبل** هذه
     * الهجرة (بعد ميجريشن القرش 2.1: المبالغ bigint،status enum
     * بـCHECK تلقائي من Blueprint).
     */
    private function sqliteRebuild(): void
    {
        Schema::rename('invoices', 'invoices_promo_tmp');

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('parents')->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('invoice_number', 100)->unique('invoices_invoice_number_r_unique');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('total');
            $table->bigInteger('paid_amount')->default(0);
            $table->bigInteger('balance_due');
            $table->bigInteger('credited_amount')->default(0);
            $table->string('currency', 3);
            $table->enum('status', ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'void'])->default('draft');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['student_id', 'status'], 'invoices_student_id_status_r_index');
            $table->index('invoice_number', 'invoices_invoice_number_r_index');
        });

        DB::statement(
            'INSERT INTO invoices
            (id, organization_id, branch_id, student_id, parent_id, subscription_id,
             invoice_number, issue_date, due_date, subtotal, discount, tax, total,
             paid_amount, balance_due, credited_amount, currency, status, notes,
             created_at, updated_at, deleted_at)
            SELECT id, organization_id, branch_id, student_id, parent_id, subscription_id,
             invoice_number, issue_date, due_date, subtotal, discount, tax, total,
             paid_amount, balance_due, credited_amount, currency, status, notes,
             created_at, updated_at, deleted_at
            FROM invoices_promo_tmp'
        );

        Schema::dropIfExists('invoices_promo_tmp');
    }
};
