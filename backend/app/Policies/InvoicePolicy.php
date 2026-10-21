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

    /**
     * ⭐⭐⭐ **PR 2.17 — وثيقة PDF للفاتورة** — الحكم الأشد تحديدًا
     * من `view` لأن المسار **بلا `permission:`** (ولي الأمر يصله،
     * والبوابة الوحيدة هي البوابة هذه):
     *
     * - الموظف المالي: بلا هوامش + صلاحية العرض المالية
     *   (`finance.payments.view`). «بلا هوامش» مهم — موظف مرتبط
     *   ببروفايلات يبقى محكومًا بهامشه شرعًا على المستند.
     * - ولي أمر: ابنه فقط + `can_view_finance` (نفس البوابة
     *   في `view`).
     * - طالب: إيصاله بنفسه (`view` يسمح).
     * - غير ذلك (معلم، بلا صلاحية مالية، ولي آخر) → 403.
     */
    public function viewPdf(User $user, Invoice $invoice): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return $user->hasPermissionTo('finance.payments.view');
        }

        return $this->view($user, $invoice);
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
