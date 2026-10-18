<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ PR 1.12 — رمز دخول حصة لطالب مالوش حساب (تحت 13).
 *
 * @see \App\Services\LessonJoinTokenService
 */
class LessonJoinToken extends Model
{
    protected $fillable = [
        'lesson_id',
        'student_id',
        'token_hash',
        'pin',
        'expires_at',
        'used_at',
        'used_by',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Lesson, LessonJoinToken> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<Student, LessonJoinToken> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && ! $this->expires_at->isPast();
    }
}