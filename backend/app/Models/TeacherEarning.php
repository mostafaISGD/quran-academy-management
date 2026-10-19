<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class TeacherEarning extends Model
{
    protected $fillable = [
        'teacher_id', 'lesson_id', 'contract_id', 'rate_id',
        'amount', 'currency', 'earning_date', 'status', 'notes',
        'rate_snapshot', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'earning_date' => 'date',
            // ⭐ PR 2.10 — لقطة السعر والعقد لحظة الحصة
            'rate_snapshot' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * من اعتمد — الصلاحية `payroll.earnings.approve` والقرار
     * بيتقفل في `approved_at` و`audit_logs`.
     */
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }

    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function lesson() { return $this->belongsTo(Lesson::class); }
    public function contract() { return $this->belongsTo(TeacherContract::class); }
    public function rate() { return $this->belongsTo(TeacherRate::class); }
}
