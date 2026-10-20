<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    /** دورة حياة المصروف: `submitted` → `approved`/`rejected` → `paid`. */
    public const STATUSES = ['draft', 'submitted', 'approved', 'rejected', 'paid'];

    protected $fillable = [
        'organization_id', 'branch_id', 'category_id', 'amount', 'currency',
        'expense_date', 'description', 'payment_method', 'reference',
        'created_by', 'status', 'notes',
        // PR 2.12 — دورة المصروفات
        'payee', 'account_id', 'approved_by', 'approved_at', 'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'expense_date' => 'date',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function category() { return $this->belongsTo(ExpenseCategory::class); }
    public function account() { return $this->belongsTo(FinancialAccount::class, 'account_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
    public function payer() { return $this->belongsTo(User::class, 'paid_by'); }
    public function attachments() { return $this->hasMany(ExpenseAttachment::class); }
}
