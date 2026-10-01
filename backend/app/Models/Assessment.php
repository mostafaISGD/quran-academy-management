<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Assessment extends Model
{
    protected $fillable = [
        'lead_id', 'student_id', 'teacher_id', 'scheduled_at',
        'reading_score', 'tajweed_score', 'memorization_score',
        'recommended_level', 'notes', 'result',
    ];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime'];
    }

    public function lead() { return $this->belongsTo(Lead::class); }
    public function student() { return $this->belongsTo(Student::class); }
    public function teacher() { return $this->belongsTo(Teacher::class); }
}
