<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سطر مرتب موظف في فترة.
 *
 * ده **الاحتساب** مش الدفع. `status` بتتحرك:
 *   draft    → اتحسب، لسه مالوش حد راجعه
 *   approved → اتراجع واعتمد
 *   paid     → اتدفع
 *
 * ⭐ `hours` و `hourly_rate` لقطات وقت الاحتساب. لو عدّلنا حضور
 * الشهر أو غيّرنا سعر الموظف بعدها، السطر ده ما بيتغيرش — وده اللي
 * بيخلّي المرتب المتدفع ما يتغيرش في نص الشهر.
 */
class EmployeePayrollLine extends Model
{
    protected $fillable = [
        'organization_id', 'payroll_period_id', 'employee_id',
        'hours', 'hourly_rate', 'amount', 'currency',
        'days_present', 'period_start', 'period_end',
        'status', 'payment_method', 'reference', 'paid_at', 'processed_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'float',
            'hourly_rate' => 'float',
            'amount' => 'float',
            'period_start' => 'date',
            'period_end' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function period()
    {
        return $this->belongsTo(PayrollPeriod::class, 'payroll_period_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    // ===== حالات =====

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'draft' => 'مسودّة',
            'approved' => 'معتمد',
            'paid' => 'مدفوع',
            default => '—',
        };
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * ⚠️ ده **الاحتساب الوحيد** لساعات المرتب في النظام.
     *
     * لو ما كتبناش الـ amount (أو hours) من الحضور،
     * الـ DB بتخزن لقطة والـ UI بتعرض القيمة دي.
     *
     * `hourly_rate` null = الموظف بيشتغل بالشهر الثابت، فمش داخل
     * في احتساب الساعات أصلاً. وده اللي بيخلي الشاشة تعرضه
     * «بشهري» بدل ما يكون صفر.
     */
    public static function computeAmount(float $hours, ?float $rate): float
    {
        if ($rate === null) {
            return 0.0;
        }

        return round($hours * $rate, 2);
    }
}