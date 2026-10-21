<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;
use App\Services\ParentChildScope;

/**
 * ⭐⭐⭐ **سياسة الدفعات — PR 2.17 (إيصال PDF أولاً).**
 *
 * الدفعة مالهاش JSON route عام للفتحة للعائلة (فيه `/payments/{id}`
 * للموظفين بـpermission)، لكن **الإيصال PDF** عائلي تماماً مثل
 * الفاتورة: `viewPdf` هو الحكم للمسار الأهلي:
 *
 * - موظف مالي (`finance.payments.view` + بلا هوامش) → مسموح.
 * - طالب → إيصالات دفعاته فقط (بروفايله هو، مثل `view` في
 *   سياسة الفواتير).
 * - ولي أمر → ابنه فقط، وبشرط `can_view_finance`.
 * - معلم → لا يرى إيصالات ابداً (رفض).
 * - ولي آخر → 403 (قبول المواصفة الصريح).
 */
class PaymentPolicy
{
    public function viewPdf(User $user, Payment $payment): bool
    {
        // ⭐ دفعة بلا طالب مرتبط؟ التسجيل المطلوب خارج فويته —
        // الموظف المالي فقط يقدر يدخل هنا.
        if ($payment->student_id === null) {
            return $user->hasPermissionTo('finance.payments.view')
                && ParentChildScope::allowedStudentIds($user) === null;
        }

        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return $user->hasPermissionTo('finance.payments.view');
        }

        if (! in_array($payment->student_id, $ids, true)) {
            return false;
        }

        $profile = $user->relationLoaded('student') ? $user->student : $user->student()->first();

        if ($profile !== null) {
            return true;
        }

        return ParentChildScope::canViewFinance($user, $payment->student_id);
    }
}
