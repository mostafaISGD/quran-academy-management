<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'account_movements' => ['amount'],
        'account_transfers' => ['amount'],
        'credit_note_applications' => ['amount'],
        'employee_payroll_lines' => ['hourly_rate', 'amount'],
        'employees' => ['hourly_rate'],
        'expenses' => ['amount'],
        'financial_accounts' => ['opening_balance'],
        'invoice_items' => ['unit_price', 'total'],
        'invoices' => ['subtotal', 'discount', 'tax', 'total', 'paid_amount', 'balance_due', 'credited_amount'],
        'lesson_credit_accounts' => ['current_balance'],
        'lesson_credit_transactions' => ['balance_after'],
        'payment_allocations' => ['amount'],
        'payments' => ['amount', 'net_amount'],
        'promo_code_usages' => ['discount_amount', 'original_amount', 'final_amount'],
        'refunds' => ['amount'],
        'student_credits' => ['total', 'used', 'refunded'],
        'student_ledger_entries' => ['debit', 'credit', 'balance_after'],
        'subscription_plans' => ['price'],
        'subscriptions' => ['price'],
        'teacher_contracts' => ['monthly_salary'],
        'teacher_earnings' => ['amount'],
        'teacher_ledger_entries' => ['debit', 'credit', 'balance_after'],
        'teacher_payments' => ['amount'],
        'teacher_payroll_lines' => ['rate_snapshot', 'amount'],
        'teacher_rates' => ['amount'],
    ];

    public function up(): void
    {
        $this->convert('bigint', 'round(%s::numeric * 100)::bigint');
    }

    public function down(): void
    {
        $this->convert('numeric(20, 2)', '%s::numeric / 100');
    }

    private function convert(string $type, string $expression): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $grammar = DB::connection()->getSchemaGrammar();
            $name = $grammar->wrapTable($table);
            $definitions = array_column(Schema::getColumns($table), null, 'name');
            foreach (array_intersect($columns, array_keys($definitions)) as $column) {
                $quoted = $grammar->wrap($column);
                $default = $definitions[$column]['default'];
                DB::statement("ALTER TABLE {$name} ALTER COLUMN {$quoted} DROP DEFAULT");
                $using = sprintf($expression, $quoted);
                DB::statement("ALTER TABLE {$name} ALTER COLUMN {$quoted} TYPE {$type} USING {$using}");
                if ($default !== null) {
                    $value = sprintf($expression, "({$default})");
                    DB::statement("ALTER TABLE {$name} ALTER COLUMN {$quoted} SET DEFAULT {$value}");
                }
            }
        }
    }
};
