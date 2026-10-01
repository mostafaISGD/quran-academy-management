<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonReschedule extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'lesson_id', 'old_start', 'old_end', 'new_start', 'new_end',
        'reason', 'changed_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_start' => 'datetime',
            'old_end' => 'datetime',
            'new_start' => 'datetime',
            'new_end' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
