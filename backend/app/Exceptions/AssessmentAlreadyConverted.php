<?php

namespace App\Exceptions;

use App\Models\Assessment;
use Exception;

/**
 * ⭐⭐⭐ **PR 3.9 — التقييم اتحوّل قبل كده.**
 *
 * ⭐ **422**: الطلب مش غلط — هو **مكرّر** (التقييم ده ليه طالب
 * بالفعل). والقيد الفريد على `assessments.student_id` (في
 * الميجريشن) هو **الضمان الأخير** لو اتزحم طلبين.
 */
class AssessmentAlreadyConverted extends Exception
{
    public function __construct(public readonly Assessment $assessment)
    {
        parent::__construct('التقييم ده اتحوّل قبل كده');
    }

    public function render()
    {
        return response()->json([
            'message' => 'التقييم ده اتحوّل بالفعل — التحويل مرتين مش مسموح',
            'student_id' => $this->assessment->student_id,
            'subscription_id' => $this->assessment->converted_subscription_id,
        ], 422);
    }
}
