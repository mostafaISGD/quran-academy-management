<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Services\ParentChildScope;

/**
 * ⭐⭐⭐ **سياسة الفواتير (PR 1.8).**
 *
 * - موظف (بلا بروفايلات) → غير مقيّد؛ الصلاحيات المالية هي الحكم.
 * - ولي أمر → فاتورة ابنه فقط، وبشرط `can_view_finance`.
 * - طالب → فواتيره (دفتر شؤونه المالية الخاصة به).
 * - معلم → لا يرى فواتير الطلبة إطلاقًا.
 *
 * `pay` = القدرة على الدفع (محمولة من `can_pay`): محفوظة لوجهات
 * الدفع الأبوية (PR 4.x) وتم تحجبها بالفعل من السياسة.
 */
class InvoicePolicy
{
    public function view(User $user, Invoice $invoice): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return true;
        }

        if (! in_array($invoice->student_id, $ids, true)) {
            return false;
        }

        $profile = $user->relationLoaded('student') ? $user->student : $user->student()->first();

        if ($profile !== null) {
            return true;
        }

        return ParentChildScope::canViewFinance($user, $invoice->student_id);
    }

    public function pay(User $user, Invoice $invoice): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return true;
        }

        return in_array($invoice->student_id, $ids, true)
            && ParentChildScope::canPay($user, $invoice->student_id);
    }
}
