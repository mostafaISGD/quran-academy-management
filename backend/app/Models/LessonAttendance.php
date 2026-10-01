<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonAttendance extends Model
{
    protected $table = 'lesson_attendance';

    protected $fillable = [
        'lesson_id', 'student_id', 'status', 'late_minutes',
        'marked_at', 'marked_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['marked_at' => 'datetime'];
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function markedBy()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }
}
