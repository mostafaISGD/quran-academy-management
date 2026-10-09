<?php

namespace App\Models;

use App\Casts\Piastres;
use Illuminate\Database\Eloquent\Model;

class TeacherPayment extends Model
{
    protected $fillable = [
        'teacher_id', 'payroll_period_id', 'amount', 'currency',
        'payment_method', 'reference', 'paid_at', 'status', 'processed_by',
    ];

    /**
     * ⭐ قيم افتراضية **في الموديل** — انظر `Payment` للتفصيل.
     *
     * السبب إن SQLite مايرجعش قيمة العمود الافتراضية للصف الجديد.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return ['amount' => Piastres::class, 'paid_at' => 'datetime'];
    }

    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function payrollPeriod() { return $this->belongsTo(PayrollPeriod::class); }
    public function processedBy() { return $this->belongsTo(User::class, 'processed_by'); }
}
