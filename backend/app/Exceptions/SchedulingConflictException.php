<?php

namespace App\Exceptions;

use App\Models\Lesson;
use Exception;

class SchedulingConflictException extends Exception
{
    public function __construct(public readonly Lesson $conflict)
    {
        parent::__construct('تعارض في جدول المعلم');
    }

    public function render()
    {
        return response()->json([
            'message' => 'المعلم لديه حصة أخرى في هذا التوقيت',
            'conflicting_lesson' => [
                'id' => $this->conflict->id,
                // ⭐ الأسماء الصحيحة: `scheduled_start_at` /
                // `scheduled_end_at` — كان هنا `scheduled_start`
                // (اسم مش موجود على الموديل) ⇒ المسار كله (409)
                // كان بيقع 500 بسبب `toIso8601String()` على null.
                'scheduled_start_at' => $this->conflict->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $this->conflict->scheduled_end_at?->toIso8601String(),
                'status' => $this->conflict->status,
            ],
        ], 409);
    }
}
