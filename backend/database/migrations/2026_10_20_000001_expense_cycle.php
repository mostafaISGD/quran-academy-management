<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2.12 — دورة المصروفات الكاملة.
 *
 * ① دورة الحالة: `pending` (المحفوظة قديمًا عهدتها باسم
 *    Submitted) → `draft`/`submitted`/`approved`/`rejected`/
 *    `paid`. الصفوف القديمة `pending` تتحوّل `submitted`.
 *
 * ② علاقة صريحة بـ: الحساب المالي، صفة من يقبض (payee)،
 *    والاعتماد والصرف (من ومتأسس).
 *
 * ③ المرفقات في جدول مستقل — تخزين خاص (مجلد بعيد عن
 *    `public`) والتنزيل برابط موقّع مؤقت.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ⭐ التحويل قبل إعادة تعريف الـenum — القيمة القديمة
        // خارج النطاق الجديد.
        DB::table('expenses')->where('status', 'pending')->update(['status' => 'submitted']);

        // ⚠️ الترتيب يحكم: `->change()` على SQLite بيعمل **إعادة بناء
        // للجدول** من شكل اللحظة — يعني أي عمود اتضاف قبله بيتضيّع
        // (الفخ رقم 2 بعد الـindexes في ميجريشن 2.10). فتغيير
        // الـenum خلق، والأعمدة كلها بعد.
        Schema::table('expenses', function (Blueprint $table) {
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'paid'])
                ->default('submitted')
                ->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            // ⭐ الحساب المصروف منه — اختياري (الافتراضي resolveAccount
            // وقت الصرف لو فاضي)
            $table->foreignId('account_id')->nullable()->constrained('financial_accounts')->nullOnDelete();

            // ⭐ من بيقبض الفلوس
            $table->string('payee')->nullable();

            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();

            // ⭐ `notes` كانت في `$fillable` في الموديل من اليوم
            // الأول مش في الجدول — فأي مصروف بـ notes كان بيسقّط
            // 500 صامت. عمودها بيتضاف هنا.
            $table->text('notes')->nullable();
        });

        Schema::create('expense_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expense_id')->constrained('expenses')->cascadeOnDelete();
            // ⭐ مسار نسبي فوق القرص المحلي الخاصة — مش public
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_attachments');

        // ⭐ جزئي: نرجّع الاسم قبل تضييق الـenum — draft/submitted →
        // pending، وpaid → approved (في صف تضيع لصف الاعتماد نفسه).
        DB::table('expenses')->whereIn('status', ['draft', 'submitted'])->update(['status' => 'pending']);
        DB::table('expenses')->where('status', 'paid')->update(['status' => 'approved']);

        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            // ⚠️ SQLite مش بيقبل `DROP COLUMN` لعمود جوه FK
            // (account_id) — فالنزول بيعمل **إعادة بناء يدوي** بنفس
            // شكل ميجريشن 07/01 الأصلي.
            Schema::rename('expenses', 'expenses_rebuild_backup');

            Schema::create('expenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained('organizations')->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('category_id')->constrained('expense_categories')->cascadeOnDelete();
                $table->decimal('amount', 12, 2);
                $table->char('currency', 3);
                $table->date('expense_date');
                $table->string('description');
                $table->string('payment_method', 50)->nullable();
                $table->string('reference')->nullable();
                $table->foreignId('created_by')->constrained('users');
                $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
                $table->timestamps();
                $table->softDeletes();
            });

            DB::statement(
                'INSERT INTO expenses (id, organization_id, branch_id, category_id, amount, currency, expense_date, '
                .'description, payment_method, reference, created_by, status, created_at, updated_at, deleted_at) '
                .'SELECT id, organization_id, branch_id, category_id, amount, currency, expense_date, '
                .'description, payment_method, reference, created_by, status, created_at, updated_at, deleted_at '
                .'FROM expenses_rebuild_backup',
            );

            Schema::drop('expenses_rebuild_backup');

            return;
        }

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'payee', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'notes']);

            $table->enum('status', ['pending', 'approved', 'rejected'])
                ->default('pending')
                ->change();
        });
    }
};
