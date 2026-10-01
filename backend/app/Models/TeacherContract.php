<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherContract extends Model
{
    protected $fillable = [
        'teacher_id', 'contract_type', 'start_date', 'end_date',
        'monthly_salary', 'currency', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'monthly_salary' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
