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
                'scheduled_start_at' => $this->conflict->scheduled_start->toIso8601String(),
                'scheduled_end_at' => $this->conflict->scheduled_end->toIso8601String(),
                'status' => $this->conflict->status,
            ],
        ], 409);
    }
}
