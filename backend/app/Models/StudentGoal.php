<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentGoal extends Model
{
    protected $fillable = [
        'student_id', 'program_id', 'title', 'description',
        'target_value', 'current_value', 'unit', 'start_date', 'target_date',
        'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'target_value' => 'decimal:2',
            'current_value' => 'decimal:2',
            'start_date' => 'date',
            'target_date' => 'date',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}
