<?php

namespace App\Exceptions;

use App\Models\Holiday;
use Carbon\Carbon;
use Exception;

/**
 * ⭐⭐⭐ **PR 3.6 — الحصة في يوم عطلة.**
 *
 * ⭐ **422 مش 409**: الطلب نفسه صح (الموعد متاح أصلاً) بس
 * **اليوم ده عطلة** — فالرفض من **قاعدة التقويم** لا من تعارض.
 */
class LessonOnHoliday extends Exception
{
    public function __construct(
        public readonly Holiday $holiday,
        public readonly Carbon $date,
    ) {
        parent::__construct('الحصة في يوم عطلة');
    }

    public function render()
    {
        return response()->json([
            'message' => "«{$this->date->toDateString()}» يوم عطلة ({$this->holiday->name}) — مفيش حجز في العطلات",
            'holiday' => [
                'name' => $this->holiday->name,
                'date' => $this->holiday->date?->toDateString(),
                'type' => $this->holiday->type,
            ],
        ], 422);
    }
}
