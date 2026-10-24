<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐⭐⭐ **PR 3.11 — سجل الدخول للحصة.**
 *
 * سطر واحد لكل (حصة، مشارك) — القيد الفريد زي القبول:
 * «الدخول بيتسجَّل مرة واحدة لكل مشارك». الزيارة التانية لنفس
 * الرمز تعيد التوجيه بس (ما بتعملش سطر جديد).
 *
 * البَاغُثر: هده الصفوف هى مصدر «اقتراح الحضور» من البوابة —
 * الحضور بندعي بس, والسجل بيقول مين دخل فعلًا.
 */
class LessonParticipant extends Model
{
    protected $fillable = [
        'lesson_id',
        'student_id',
        'token_id',
        'source',
        'joined_at',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    /** @return BelongsTo<Lesson, self> */
    public function lesson() { return $this->belongsTo(Lesson::class); }

    /** @return BelongsTo<Student, self> */
    public function student() { return $this->belongsTo(Student::class); }

    /** @return BelongsTo<LessonJoinToken, self> */
    public function token() { return $this->belongsTo(LessonJoinToken::class, 'token_id'); }
}
