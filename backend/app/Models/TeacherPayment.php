<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherPayment extends Model
{
    protected $fillable = [
        'teacher_id', 'payroll_period_id', 'amount', 'currency',
        'payment_method', 'reference', 'paid_at', 'status', 'processed_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function payrollPeriod() { return $this->belongsTo(PayrollPeriod::class); }
    public function processedBy() { return $this->belongsTo(User::class, 'processed_by'); }
}
