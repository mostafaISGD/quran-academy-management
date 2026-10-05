<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollPeriod extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'start_date', 'end_date',
        'status', 'finalized_at', 'finalized_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'finalized_at' => 'datetime',
        ];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function teacherPayments() { return $this->hasMany(TeacherPayment::class); }

    /** سطور مرتبات الموظفين في الفترة — نفس الفترة تتشاركها مع المعلمين */
    public function employeeLines() { return $this->hasMany(EmployeePayrollLine::class); }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
