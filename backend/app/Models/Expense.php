<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Expense extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'branch_id', 'category_id', 'amount', 'currency',
        'expense_date', 'description', 'payment_method', 'reference',
        'created_by', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date'];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function category() { return $this->belongsTo(ExpenseCategory::class); }
}
