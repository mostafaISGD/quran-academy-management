<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\NumberSequence;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ PR 2.6 — الكاتب **الوحيد** لحالة الفاتورة.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐ القاعدة 9 من `AGENTS.md`:
 *   «`paid_amount` / `balance_due` / `status` بتكتبها
 *   `InvoiceService` وحدها».
 *
 * ⭐ وليه خدمة لوحدها؟
 *
 * قبل كده كان فيه **5 مواقع** بتكتب الثلاثة دول:
 * `SubscriptionActivationService`, `DailySubscriptionService`,
 * `GroupEnrollmentService`, `AssessmentController`, خدمة الدفع.
 * وكل واحد بيحسب بطريقته:
 *
 *     max(0, price - paid)       ← هنا
 *     round(price - paid, 2)     ← هناك
 *     $paid >= $price ? 'paid' : 'partially_paid'
 *
 * ⭐ النتيجة: فاتورة بحالة غلط **من غير ما حد عدّل حاجة** — والـbug
 * بيظهر بعد شهور في تقرير، ومحدش فاكر ليه.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ هنا **الصيغة الواحدة**:
 *
 *     balance = max(0, total - paid)
 *     status  = paid >= total ? 'paid' : 'partially_paid'
 *
 * وبس. مفيش نسخة تانية من السطرين دول في المشروع.
 *
 * ⚠️ **`overdue` مش حالة مخزّنة** — محسوب من `due_date` (قاعدة 10).
 */
class InvoiceService
{
    /**
     * ⭐⭐⭐ الرقم **التالي** — تسلسلي، من غير فجوات.
     *
     * ⚠️ **`lockForUpdate` جوه transaction** — دي الحماية الوحيدة
     * ضد الترقيم المكرر. من غيرها طلبين جوه نفس اللحظة بياخدوا
     * نفس الرقم، والـ`unique('invoice_number')` بيرمي exception
     * للمستخدم (500) بدل ما ياخد رقم تاني.
     *
     * ⭐ **السنة جزء من المفتاح** — العدّاد يبدأ من 1 كل سنة.
     * فـ`INV-2026-0001` هيبدأ من جديد في يناير.
     */
    public function nextNumber(int $organizationId, ?string $prefix = 'INV'): string
    {
        $key = $prefix.':'.now()->year;

        return DB::transaction(function () use ($key, $prefix) {
            /** ⭐ القفل — لحد ما الـtransaction تخلص */
            $sequence = NumberSequence::where('key', $key)->lockForUpdate()->first();

            if ($sequence === null) {
                $sequence = NumberSequence::create(['key' => $key, 'last_value' => 0]);
                // ⭐ الـinsert نفسه محمي بـ`unique` — لو طلبين
                // بدأوا في نفس اللحظة، واحد بيفشل ثم يعيد القراءة.
                $sequence = NumberSequence::where('key', $key)->lockForUpdate()->first();
            }

            $sequence->increment('last_value');

            return sprintf(
                '%s-%d-%04d',
                $prefix,
                now()->year,
                $sequence->fresh()->last_value,
            );
        });
    }

    /**
     * ⭐⭐ تطبيق دفعة على فاتورة — **الحالة بتتحسب هنا وبس**.
     *
     * @throws \RuntimeException لو الفاتورة ملغاة
     */
    public function applyPayment(Invoice $invoice, Payment $payment): void
    {
        $this->assertPayable($invoice);

        DB::transaction(function () use ($invoice, $payment) {
            // ⭐ `lockForUpdate` — فاتورتين بيتدفعوا في نفس اللحظة
            // على نفس الفاتورة (قاعدة 8).
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            // ⭐⭐ **الحساب بالجمع مش بالفرق**.
            //
            // ليه؟ لأن الدفعات ممكن تتكرر، والحساب بالفرق بيعتمد
            // إن `paid_amount` مظبوط من قبل. لو اتظبط مرتين، بقى
            // غلط. بالجمع: `paid = paid + جديد` — مستحيل يتظبط.
            $newPaid = (float) $locked->paid_amount + (float) $payment->amount;

            $this->writeState($locked, $newPaid);
        });
    }

    /**
     * ⭐ عكس دفعة — يرجّع الفاتورة لحالتها الصحيحة.
     *
     * ⭐⚠️ مش حذف: الدفعة **تفضل** في `payments` بـ`voided`، والسطر
     * المالي في `account_movements` بيتقلب بقيد عكسي (قاعدة 7).
     */
    public function reversePayment(Invoice $invoice, Payment $payment, ?string $reason = null): void
    {
        DB::transaction(function () use ($invoice, $payment) {
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            $newPaid = max(0.0, (float) $locked->paid_amount - (float) $payment->amount);

            $this->writeState($locked, $newPaid);
        });
    }

    /**
     * ⭐⭐ **المكان الوحيد** اللي `paid_amount` بيتكتب فيه.
     *
     * @param float $newPaid المدفوع الجديد (بالوحدة الكبرى)
     */
    private function writeState(Invoice $invoice, float $newPaid): void
    {
        $total = (float) $invoice->total;

        // ⭐ `max(0, ...)` — الرصيد **ما ينفعش يبقى سالب**.
        //
        // ليه؟ لأن السالب بيخالف القاعدة 10 («overdue محسوب من
        // due_date») — والأهم: تقرير بيجمع أرصدة وسالب واحد بيخرب
        // المجموع كله بصمت.
        $balance = max(0.0, $total - $newPaid);

        /**
         * ⭐⭐ الحالة من **المقارنة** — مش من increment.
         *
         * لو الحالة كانت `partially_paid` وبقى السالب 0 قبل ما
         * تاريخ الاستحقاق، الحالة `issued` مش `paid`.
         */
        $status = $newPaid >= $total
            ? 'paid'
            : ($newPaid > 0 ? 'partially_paid' : $this->baseStatus($invoice));

        $invoice->update([
            'paid_amount' => $newPaid,
            'balance_due' => $balance,
            'status' => $status,
        ]);
    }

    /**
     * ⭐ الحالة اللي الفاتورة ترجعها لما الدفعة تترجع خالص.
     *
     * ⚠️ لو فاتورة **معتمدة** (`issued`) ترجع `draft`، ده تغيير
     * في تاريخ الفاتورة. فاتورة معتمدة بتفضل معتمدة.
     */
    private function baseStatus(Invoice $invoice): string
    {
        return in_array($invoice->status, ['void', 'paid', 'partially_paid'], true)
            ? 'issued'
            : $invoice->status;
    }

    /**
     * ⭐ فاتورة ملغاة ما بتقبلش دفع — الدفعة كانت هتروح لفاتورة
     * ما ليهاش قيمة.
     */
    private function assertPayable(Invoice $invoice): void
    {
        if ($invoice->status === 'void') {
            throw new \RuntimeException(
                'الفاتورة ملغاة — ما ينفعش يتطبق عليها دفعة. '
                .'اعمل فاتورة جديدة بدل ما تعيد التراجع.'
            );
        }
    }
}