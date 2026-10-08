<?php

namespace Tests\Feature;

use App\Models\FinancialAccount;
use Tests\TestCase;

/**
 * ⭐⭐ تصدير التقارير في شكل CSV/Excel.
 *
 * ⭐ الاختبارات دي بتقفل:
 * ① الحساب بيصدّر CSV مختلفاً (Content-Disposition: attachment)
 * ② الهيدرات بالعربي
 * ③ BOM موجود في أول الـ response (ضروري لـ Excel)
 */
class ExportTest extends TestCase
{
    public function test_account_balances_exports_csv_with_arabic_headers(): void
    {
        $admin = $this->makeUserWithRole('admin', [
            'finance.view', 'finance.movements', 'finance.accounts',
            'payments.view',
        ]);

        $this->makeAccount(['name' => 'خزينة ١٠٠', 'opening_balance' => 100]);

        $r = $this->getJsonAs('/api/exports/account-balances', $admin);

        $r->assertOk();
        $r->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $r->getContent();

        // ⭐ أول حرفين = BOM
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('الحساب', $content);
        $this->assertStringContainsString('الرصيد', $content);
        $this->assertStringContainsString('خزينة', $content);
    }

    public function test_account_movements_export_returns_data(): void
    {
        $admin = $this->makeUserWithRole('admin', [
            'finance.view', 'finance.movements', 'finance.accounts',
        ]);

        $account = $this->makeAccount(['name' => 'بنك']);
        $this->makeMovement($account, ['direction' => 'in', 'category' => 'income', 'amount' => 500]);

        $r = $this->getJsonAs('/api/exports/account-movements', $admin);

        $r->assertOk();
        $content = $r->getContent();

        $this->assertStringContainsString('الاتجاه', $content);
        $this->assertStringContainsString('داخل', $content);
    }

    public function test_non_finance_user_cannot_export(): void
    {
        // ⭐ """
        $accountant = $this->makeUserWithRole('accountant', [
            'finance.view', 'finance.movements', 'finance.accounts',
        ]);

        // الحساب عنده finance.accounts → يقدر يصدر أرصدة
        $this->getJsonAs('/api/exports/account-balances', $accountant)->assertOk();

        // لكن من غير finance.accounts → 403
        $noFinance = $this->makeUserWithRole('teacher', []);
        $this->getJsonAs('/api/exports/account-balances', $noFinance)->assertForbidden();
    }
}
