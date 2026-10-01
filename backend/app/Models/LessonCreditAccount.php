<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonCreditAccount extends Model
{
    protected $fillable = [
        'student_id', 'subscription_id', 'credit_type', 'current_balance',
        'expires_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'expires_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function transactions()
    {
        return $this->hasMany(LessonCreditTransaction::class);
    }
}
