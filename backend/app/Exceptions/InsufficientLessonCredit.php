<?php

namespace App\Exceptions;

use Exception;

/**
 * ⭐⭐⭐ **PR 3.3 — رصيد الحصص ما يكفيش.**
 *
 * ⭐ **422 مش 403**: الرفض من **رصيد** (الطالب خلصت حصصه)،
 * مش من صلاحية. والعملية كلها (تغيير الحالة + السجل) بترجع
 * زي ما كانت — عشان كمان `LessonService::transition` شغال في
 * نفس الـtransaction.
 *
 * ⭐ **السحب ممنوع افتراضياً** — لو الأكاديمية سمحت بالسحب
 * (إعداد `lesson_credit_overdraft_allowed`) ما بيحصلش رفض.
 */
class InsufficientLessonCredit extends Exception
{
    public function __construct(public readonly float $balance)
    {
        parent::__construct('رصيد حصص الطالب غير كافٍ');
    }

    public function render()
    {
        return response()->json([
            'message' => 'رصيد حصص الطالب خلص — مفيش حصة تانية تُخصم',
            'balance' => $this->balance,
        ], 422);
    }
}
