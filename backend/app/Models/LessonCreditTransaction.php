<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LessonCreditTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'credit_account_id', 'student_id', 'subscription_id', 'lesson_id',
        'type', 'quantity', 'balance_after', 'reason', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function creditAccount()
    {
        return $this->belongsTo(LessonCreditAccount::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }
}
