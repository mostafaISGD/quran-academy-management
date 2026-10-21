<?php

namespace App\Exceptions;

use Carbon\Carbon;
use Exception;

/**
 * ⭐⭐⭐ **PR 3.6 — المعلم مش متاح في الوقت ده.**
 *
 * ⭐ الحظر من **جدول المعلم** (`teacher_schedules`): شغل خارجي أو
 * إجازة أو التزام شخصي — والوقت ده **مش متاح** أصلاً.
 */
class TeacherUnavailable extends Exception
{
    public function __construct(
        public readonly int|string $teacherId,
        public readonly Carbon $date,
    ) {
        parent::__construct('المعلم غير متاح في هذا التوقيت');
    }

    public function render()
    {
        return response()->json([
            'message' => "المعلم مش متاح في هذا التوقيت ({$this->date->toDateString()}) — فيه التزام مسجَّل في جدوله",
        ], 422);
    }
}
