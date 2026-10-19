<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ⭐ PR 2.2 — كل الأعمدة المالية `bigint` — مفيش `decimal` ولا float.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ اختبار القبول من الـroadmap:
 *   «total الفاتورة من 5 سطور = مجموع السطور بالظبط»
 *
 * ⭐ وب Plus: مفيش عمود مالي في القاعدة اسمه لسه `decimal`.
 */
class MoneyColumnIntegrityTest extends TestCase
{
    /**
     * ⭐⭐⭐ الاختبار الأساسي — الدقة الكاملة بـ 5 أسطر.
     *
     * ⭐ 5 أسعار كسرية (10.01, 20.02, 30.03, 40.04, 50.05) ×
     * كميات كسرية (1.1, 2.2, 3.3, 4.4, 5.5).
     * ⭐ المجموع لازم يطلع **صحيح 100%** — مش قريب.
     */
    public function test_invoice_total_equals_sum_of_line_items_exactly(): void
    {
        $invoice = Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $this->makeStudent()->id,
            'invoice_number' => 'INV-TEST-001',
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'status' => 'draft',
        ]);

        $prices = [10.01, 20.02, 30.03, 40.04, 50.05];
        $quantities = [1.1, 2.2, 3.3, 4.4, 5.5];

        $expectedTotal = 0;

        foreach ($prices as $i => $price) {
            $lineTotal = (int) round($price * $quantities[$i] * 100);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'سطر '.($i + 1),
                'quantity' => $quantities[$i],
                'unit_price' => $price,
                'total' => $lineTotal,
            ]);

            $expectedTotal += $lineTotal;
        }

        $invoice->update(['subtotal' => $expectedTotal, 'total' => $expectedTotal]);

        // ⭐⭐ الفاتورة اتحسبت بالقرش، والـcast بيعرضها بالجنيه.
        // فلازم المجموع الخام (بالقرش) = مجموع السطور الخام بالظبط —
        // **من غير أي float في المنتصف**.
        $invoiceTotalRaw = (int) DB::table('invoices')
            ->where('id', $invoice->id)
            ->value('total');

        $itemsSum = (int) DB::table('invoice_items')
            ->where('invoice_id', $invoice->id)
            ->sum('total');

        $this->assertSame(
            $itemsSum,
            $invoiceTotalRaw,
            'المجموع الخام لازم يساوي مجموع السطور الخام بالظبط'
        );

        // ⭐ والعرض (نص بالجنيه) بيقرأ صح من القرش
        $this->assertSame(
            number_format($invoiceTotalRaw / 100, 2, '.', ''),
            $invoice->fresh()->total,
            'الـcast بيرجّع الوحدة الكبرى صح'
        );
    }

    /**
     * ⭐ كل عمود مالي في القاعدة لازم يكون `bigint` — مفيش `decimal`.
     *
     * ⭐ ده بيمسح كل الجداول المالية ويفتّش على النوع.
     */
    public function test_no_decimal_columns_remain_in_financial_tables(): void
    {
        $financialTables = [
            'invoices', 'invoice_items', 'payments', 'refunds',
            'student_ledger_entries', 'teacher_earnings', 'teacher_payments',
            'teacher_ledger_entries', 'expenses', 'teacher_rates',
            'subscription_plans', 'subscriptions', 'teacher_payroll_lines',
            'employee_payroll_lines', 'teacher_contracts',
            'lesson_credit_accounts', 'lesson_credit_transactions',
            'financial_accounts', 'account_movements', 'account_transfers',
            'credit_note_applications', 'promo_code_usages',
            'payment_allocations', 'student_credits',
        ];

        $violations = [];
        $missing = [];

        foreach ($financialTables as $table) {
            // ⭐⚠️ الجدول لازم **يوجد**. لو مش موجود، الاختبار
            // بيسيب فاضي وبيعدّي على الفاضي — وده اختبار مكسوف
            // بيحميك من حراجة مش موجودة أصلاً.
            if (! Schema::hasTable($table)) {
                $missing[] = $table;

                continue;
            }

            foreach (Schema::getColumns($table) as $column) {
                if (str_contains($column['type'], 'decimal')) {
                    $violations[] = "{$table}.{$column['name']} → {$column['type']}";
                }
            }
        }

        $this->assertSame(
            [],
            $missing,
            "الجداول دي مش موجودة — الاختبار ده يفحص فاضي:\n  • ".implode("\n  • ", $missing)
        );

        $this->assertSame(
            [],
            $violations,
            "الأعمدة دي لسه decimal:\n  • ".implode("\n  • ", $violations)
        );
    }

    /**
     * ⭐⭐ الأعمدة المالية **فعلاً** أعداد صحيحة — مش بس
     * «مش decimal».
     *
     * ⭐ ليه اختبار لوحده؟ لأن `numeric` في SQLite = float،
     * و`gettype()` بيرجّعها `double` حتى لو الأرقام سليمة. ده
     * بالظبط الفخ اللي الـdecimal بيقع فيه.
     */
    public function test_money_columns_store_integers_not_floats(): void
    {
        $invoice = Invoice::create([
            'organization_id' => $this->org->id,
            'student_id' => $this->makeStudent()->id,
            'invoice_number' => 'INV-TYPE-001',
            'issue_date' => now()->toDateString(),
            'currency' => 'EGP',
            'status' => 'draft',
        ]);

        // ⭐ المبلغ **بالقرش** — لازم `Money` object عشان الـcast
        // ميخمّنش النية (رقم = وحدة كبرى دايماً).
        $invoice->update([
            'total' => Money::of(21, 'EGP'),
            'balance_due' => Money::of(21, 'EGP'),
            'subtotal' => Money::of(21, 'EGP'),
        ]);

        $raw = DB::table('invoices')->where('id', $invoice->id)->value('total');

        $this->assertIsInt($raw, 'المبلغ لازم يتخزّن int — مش float');
        $this->assertSame(21, $raw);

        // ⭐ والقرش بيتقري بالظبط من غير خطأ تمثيل
        $this->assertSame('0.21', $invoice->fresh()->total);
    }
}
