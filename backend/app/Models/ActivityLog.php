<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **سجل النشاط (PR 3.4).**
 *
 * ⭐ **الفرق عن `audit_logs`**: الـaudit سجل **إداري** (من غيّر
 * إيه في الداتابيز)؛ وسجل النشاط **تشغيلي**: «الحصة اتقبلت»،
 * «الحصة اتصححت»، «المعلم اتسجّل»… — بيتسجَّل مع كل إجراء مهم
 * عشان السؤال «إيه اللي حصل؟» يبقى له إجابة.
 *
 * ⭐ **إضافة فقط**: مفيش `updated_at` — السجل بيتسجَّل ومحدش
 * بيلمسه (نفس منطق السجلات المالية).
 */
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'user_id', 'subject_type', 'subject_id',
        'action', 'description', 'meta', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'user_id' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
