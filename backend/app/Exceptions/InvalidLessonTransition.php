<?php

namespace App\Exceptions;

use App\Enums\LessonStatus;
use Exception;

/**
 * ⭐⭐⭐ **PR 3.1 — تحويل حالة غير مسموح.**
 *
 * ⭐ **422 مش 403**: الرفض هنا من **قاعدة** (جدول التحويلات)
 * لا من الصلاحية — الطلب كان مسموحاً أصلاً بس التحويل نفسه
 * مخالف. و**مش 500**: القاعدة قاعدة بيانات — حالة معلومة.
 */
class InvalidLessonTransition extends Exception
{
    public function __construct(
        public readonly LessonStatus $from,
        public readonly LessonStatus $to,
    ) {
        parent::__construct('تحويل حالة الحصة غير مسموح');
    }

    public function render()
    {
        return response()->json([
            'message' => "مش مسموح تتحوّل الحصة من «{$this->from->label()}» إلى «{$this->to->label()}»",
            'from' => $this->from->value,
            'to' => $this->to->value,
            'allowed' => array_map(
                static fn (LessonStatus $status): string => $status->value,
                $this->from->allowedTransitions(),
            ),
        ], 422);
    }
}
