<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'user_id', 'student_id', 'lesson_id', 'event_type', 'channel', 'payload', 'sent_at', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    /** ⭐ PR 3.4 — الإشعار مربوط بالحصة. */
    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
