<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherRate extends Model
{
    protected $fillable = [
        'teacher_id', 'rate_type', 'amount', 'currency',
        'duration_minutes', 'effective_from', 'effective_to',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
