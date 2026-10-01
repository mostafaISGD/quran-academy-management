<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProgressRecord extends Model
{
    protected $fillable = [
        'student_id', 'lesson_id', 'teacher_id', 'program_id', 'level_id',
        'category', 'score', 'notes', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'recorded_at' => 'datetime'];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }
}
