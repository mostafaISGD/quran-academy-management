<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherEarning extends Model
{
    protected $fillable = [
        'teacher_id', 'lesson_id', 'contract_id', 'rate_id',
        'amount', 'currency', 'earning_date', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'earning_date' => 'date'];
    }

    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function lesson() { return $this->belongsTo(Lesson::class); }
    public function contract() { return $this->belongsTo(TeacherContract::class); }
    public function rate() { return $this->belongsTo(TeacherRate::class); }
}
