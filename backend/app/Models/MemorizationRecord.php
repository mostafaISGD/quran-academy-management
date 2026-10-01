<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemorizationRecord extends Model
{
    protected $fillable = [
        'student_id', 'lesson_id', 'teacher_id', 'surah_id',
        'from_ayah', 'to_ayah', 'quality', 'notes', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
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

    public function surah()
    {
        return $this->belongsTo(QuranSurah::class, 'surah_id');
    }
}
