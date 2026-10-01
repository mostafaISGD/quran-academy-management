<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'plan_id', 'program_id', 'teacher_id',
        'start_date', 'end_date', 'billing_type', 'price', 'currency',
        'lesson_duration_minutes', 'lessons_included', 'status', 'auto_renew', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'start_date' => 'date',
            'end_date' => 'date',
            'auto_renew' => 'boolean',
        ];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function student() { return $this->belongsTo(Student::class); }
    public function plan() { return $this->belongsTo(SubscriptionPlan::class, 'plan_id'); }
    public function program() { return $this->belongsTo(Program::class); }
    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function pauses() { return $this->hasMany(SubscriptionPause::class); }
    public function lessons() { return $this->hasMany(Lesson::class); }
    public function creditAccounts() { return $this->hasMany(LessonCreditAccount::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isExpired(): bool
    {
        return $this->end_date !== null && $this->end_date->isPast();
    }
}
