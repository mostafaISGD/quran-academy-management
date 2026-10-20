<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PR 2.13 — الأقفال المالية بنطاقات وظيفية.
 *
 * قبل كده القفل كان بيمس تلات وحدات بس:
 * `all` (كل حاجة) · `account` (حساب واحد) · `payroll` · `expenses`.
 *
 * ⭐ ده ناقص: محاسب يقدر يقفل «المرتبات بس» ويفتح tapping الفواتير.
 * المواصفة بتطلب نطاقات موضفة: **المدفوعات** و**الإستردادات**
 * (scope payments) و**الفواتير** (scope invoices) — عشان القفل
 * يفصل العمليات المالية عن بعضها: قفل المدفوعات مش بيمنع
 * إصدار فاتورة جديدة، وقفل الفواتير مش بيمنع تحصيل دفعة.
 *
 * القيم الجديدة: `invoices` و `payments`.
 * القديمة بتفضل شغالة زي ما هي — backwards compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('period_locks', function (Blueprint $table) {
            // ⭐ ما نضيفش أعمدة جنب ->change() — على SQLite الـchange
            // بيعيد بناء الجدول وأي عملية قبل بيضيع (درس PR 2.12).
            $table->enum('scope', ['all', 'account', 'invoices', 'payments', 'expenses', 'payroll'])
                ->default('all')
                ->change();
        });
    }

    public function down(): void
    {
        // ⭐ النطاقات الجديدة غير موجودة في الـenum القديم —
        // بنرجّع الصفوف لـ`all` قبل ما نرجّع التعريف، وإلا الـCHECK
        // هيرفضها.
        DB::table('period_locks')
            ->whereIn('scope', ['invoices', 'payments'])
            ->update(['scope' => 'all']);

        Schema::table('period_locks', function (Blueprint $table) {
            $table->enum('scope', ['all', 'account', 'payroll', 'expenses'])
                ->default('all')
                ->change();
        });
    }
};
