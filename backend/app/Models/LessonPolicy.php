<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐سياسة استهلاك الحصة.
 *
 * ⭐ كل أكاديمية صف واحد. السياسات قابلة للديبت من خلال
 * API عشان محدش يعبث في الداتابيز.
 */
class LessonPolicy extends Model
{
    protected $fillable = [
        'organization_id',
        'student_absence_consumes_lesson',
        'student_absence_teacher_paid',
        'teacher_absence_consumes_lesson',
        'teacher_absence_teacher_paid',
        'makeup_consumes_lesson',
        'makeup_teacher_paid',
        'max_makeup_per_month',
        'max_makeup_days',
        'makeup_deducts_from_package',
    ];

    protected function casts(): array
    {
        return [
            'student_absence_consumes_lesson' => 'boolean',
            'student_absence_teacher_paid' => 'boolean',
            'teacher_absence_consumes_lesson' => 'boolean',
            'teacher_absence_teacher_paid' => 'boolean',
            'makeup_consumes_lesson' => 'boolean',
            'makeup_teacher_paid' => 'boolean',
            'makeup_deducts_from_package' => 'boolean',
            'max_makeup_per_month' => 'integer',
            'max_makeup_days' => 'integer',
        ];
    }

    /**
     * ⭐ الحصول على السياسة الحالية — لو مفيش، نرجع الافتراضي.
     */
    public static function current(?int $organizationId = null): self
    {
        $orgId = $organizationId ?? (auth()->user()?->organization_id ?? 1);

        return static::firstOrCreate(
            ['organization_id' => $orgId],
            [
                'student_absence_consumes_lesson' => false,
                'student_absence_teacher_paid' => false,
                'teacher_absence_consumes_lesson' => false,
                'teacher_absence_teacher_paid' => false,
                'makeup_consumes_lesson' => true,
                'makeup_teacher_paid' => true,
                'max_makeup_per_month' => 4,
                'max_makeup_days' => 30,
                'makeup_deducts_from_package' => true,
            ],
        );
    }
}
