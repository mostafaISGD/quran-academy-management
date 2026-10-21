<?php

namespace App\Exceptions;

use App\Models\Lesson;
use Exception;

/**
 * ⭐⭐⭐ **تعارض في الجدولة (PR 3.6).**
 *
 * ⭐ **422 مش 409**: المواصفة بتقول «حجز في وقت متعارض = 422»
 * — لأن الطلب **مش غلط في صيغته**، هو فقط **مش ممكن في هذا
 * الوقت** (زي ما أي حجز مستحيل بيترد 422 مش 500).
 *
 * ⭐ **`reason`** يفرّق: `teacher` (المعلم مشغول) أو `student`
 * (الطالب عنده حصة تانية) — عشان الرسالة تكون واضحة للعميل.
 */
class SchedulingConflictException extends Exception
{
    public function __construct(
        public readonly Lesson $conflict,
        public readonly string $reason = 'teacher',
    ) {
        parent::__construct('تعارض في جدول المعلم');
    }

    public function render()
    {
        return response()->json([
            'message' => $this->reason === 'student'
                ? 'ال الطالب عنده حصة تانية في نفس التوقيت'
                : 'المعلم لديه حصة أخرى في هذا التوقيت',
            'conflicting_lesson' => [
                'id' => $this->conflict->id,
                // ⭐ الأسماء الصحيحة: `scheduled_start_at` /
                // `scheduled_end_at` — كان هنا `scheduled_start`
                // (اسم مش موجود على الموديل) ⇒ المسار كله (409/422)
                // كان بيقع 500 بسبب `toIso8601String()` على null.
                'scheduled_start_at' => $this->conflict->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $this->conflict->scheduled_end_at?->toIso8601String(),
                'status' => $this->conflict->status,
            ],
            'reason' => $this->reason,
        ], 422);
    }
}
