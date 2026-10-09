<?php

namespace App\Models;

use App\Casts\Piastres;
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
            'amount' => Piastres::class,
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }
}
