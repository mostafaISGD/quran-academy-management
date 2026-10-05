<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سطر مرتب معلم في فترة.
 *
 * نفس دورة `EmployeePayrollLine` بالظبط: مسودّة → معتمد → مدفوع.
 *
 * ⭐ `rate_snapshot` لقطة: لو سعر المعلم اتغيّر، السطر
 * المعتمد مايتغيرش — لأنه مستحق اتراجع فعلاً.
 */
class TeacherPayrollLine extends Model
{
    protected $fillable = [
        'organization_id', 'payroll_period_id', 'teacher_id',
        'lessons_count', 'hours', 'rate_snapshot', 'amount', 'currency',
        'period_start', 'period_end',
        'status', 'payment_method', 'reference', 'paid_at', 'processed_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'lessons_count' => 'integer',
            'hours' => 'float',
            'rate_snapshot' => 'float',
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

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

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
     * ⭐ الاحتساب الوحيد لمرتب المعلم.
     *
     * المعلمين نظامهم مختلف عن الموظفين:
     *   - موظف = ساعات × سعر
     *   - معلم = حصص × سعر (أو راتب شهري ثابت)
     *
     * ⚠️ `hours` هنا **مش جزء من المعادلة** للمعلم بالحصة.
     * كان مكتوب `($lessons * $rate) + ($hours * $rate)` — وده غلط:
     * لو مافيش حصص، `lessons = 0` فسقطنا على `hours × rate`،
     * يعني نطّقنا **منطق الموظفين** على المعلم وخدعناه نفس الرقم.
     * والواقع: المعلم بالحصة مش بيتقاس بالساعات أصلاً.
     */
    public static function computeAmount(
        int $lessons,
        ?float $rate,
        ?string $rateType,
    ): float {
        if ($rate === null) {
            return 0.0;
        }

        // راتب شهري ثابت — الرقم جاي من السعر نفسه
        if ($rateType === 'monthly') {
            return round($rate, 2);
        }

        return round($lessons * $rate, 2);
    }
}