<?php

namespace App\Exceptions;

use App\Models\TeacherEarning;
use Exception;

/**
 * ⭐⭐⭐ **PR 3.4 — المستحق اتصرف خلاص.**
 *
 * ⭐ **422**: التصحيح مرفوض لأن عكس مستحق **مدفوع** محتاج قيد
 * عكسي في دفتر المعلم (`teacher_ledger_entries`) — وده شغل
 * الدفتر مش خط أنابيب الإتمام. فبنقول مش بدل ما نعمل نصف
 * عكس.
 */
class EarningAlreadyPaid extends Exception
{
    public function __construct(public readonly TeacherEarning $earning)
    {
        parent::__construct('المستحق اتصرف خلاص');
    }

    public function render()
    {
        return response()->json([
            'message' => 'مستحق المعلم ده اتصرف فعلاً — تصحيحه محتاج قيد عكسي في دفتر المعلم، مش من هنا',
            'earning_id' => $this->earning->id,
            'earning_status' => $this->earning->status,
        ], 422);
    }
}
