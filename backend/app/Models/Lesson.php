<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'branch_id', 'student_id', 'teacher_id',
        'subscription_id', 'program_id', 'level_id', 'parent_lesson_id',
        'lesson_type', 'scheduled_start_at', 'scheduled_end_at', 'actual_start', 'actual_end',
        'duration_minutes', 'meeting_provider', 'meeting_url', 'meeting_id',
        'meeting_password', 'status', 'cancellation_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start_at' => 'datetime',
            'scheduled_end_at' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
        ];
    }

    public function student() { return $this->belongsTo(Student::class); }
    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function subscription() { return $this->belongsTo(Subscription::class); }
    public function program() { return $this->belongsTo(Program::class); }
    public function level() { return $this->belongsTo(Level::class); }
    public function parentLesson() { return $this->belongsTo(Lesson::class, 'parent_lesson_id'); }
    public function makeupLessons() { return $this->hasMany(Lesson::class, 'parent_lesson_id'); }
    public function attendance() { return $this->hasOne(LessonAttendance::class); }
    public function reschedules() { return $this->hasMany(LessonReschedule::class); }
    public function memorizationRecords() { return $this->hasMany(MemorizationRecord::class); }
    public function progressRecords() { return $this->hasMany(ProgressRecord::class); }
    public function creditTransactions() { return $this->hasMany(LessonCreditTransaction::class); }

    public function isMakeup(): bool { return $this->lesson_type === 'makeup'; }
    public function isTrial(): bool { return $this->lesson_type === 'trial'; }

    public function scopeBlockingTeacherTime(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['cancelled']);
    }
}
