<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **PR 3.1 — سجل تحويلات حالة الحصة.**
 *
 * سجل **إضافة فقط** (append-only): `updated_at` مش موجود — القيد
 * على السجلات المالية (القاعدة 7) بيقول «السجل المالي ما
 * بيتعدّلش ولا بيتحذف»؛ وسجل الحالات نفس المنطق: القرار اللي
 * اتخذ ما بترجعش.
 */
class LessonStatusHistory extends Model
{
    /**
     * ⭐ اسم الجدول صراحةً: Eloquent كان هيرجّع
     * `lesson_status_histories` (جمع "History" بالشكل ده) —
     * والجدول اسمه مفرد.
     */
    protected $table = 'lesson_status_history';

    /** ⭐ السجل إضافة فقط — مفيش updated_at. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'lesson_id', 'from_status', 'to_status', 'actor_id', 'reason',
    ];

    protected function casts(): array
    {
        return [
            'lesson_id' => 'integer',
            'actor_id' => 'integer',
        ];
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
