<?php

namespace App\Services;

use App\Casts\MoneyCast;
use App\Models\AccountMovement;
use App\Models\FinanceAlert;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\StudentLedgerEntry;
use App\Models\TeacherEarning;
use App\Models\TeacherLedgerEntry;

/**
 * PR 2.9 - التسوية المالية (`finance:reconcile`).
 *
 * الأرقام المحفوظة بتتلف (paid_amount, balance_after, balance)
 * والدليل بتاعها موجود جنبها — التسوية بتقارن الثانية
 * بياسميها وتكتب التنبيه في `finance_alerts` للإدارة.
 *
 * المقارنات (وحدها كوافية مع المسار):
 *
 *   (أ) **الفاوترة مقابل توزيعاتها** — `paid_amount` لازم
 *       يساوي مجموع التوزيعات النشطة. أي تعديل خارج
 *       `InvoiceService::recalculateFromAllocations()` بيظهر.
 *
 *   (ب) **دفتر الطالب** — حاجتين:
 *         1) الداخلي: آخر `balance_after` (لكل عمولة) يساوي
 *            Σمدين − Σدائن. أي سطر مزروع أو معدّل بيكسر
 *            التسلسل ويقف هنا.
 *         2) المصدر: Σدائن من نوع `payment` يساوي مجموع
 *            الدفعات، وΣمدين من نوع `invoice` يساوي مجموع
 *            الفواتير، لكل (طالب، عمولة).
 *
 *   (ج) **الحركات مقابل مصادرها** — لكل دفعة مكتملة: صافي
 *       حركاتها (دخل + عمولة) يساوي المبلغ. يهم الدخول
 *       المزروع من خارج الخدمات أو الدفعة اللي مسجلة
 *       بحركة مفقودة.
 *
 *   (د) **أرباح المعلم** — أرباح معتمدة (`approved`/`paid`)
 *       لازم يكون ليها سطر في دفتر المعلم. لسه مفيش مسار
 *       يكتب أرباح (PR 2.10) — القاعدة دي بتحصل معناها
 *       بمجرد ما يبقى فيه مسار: اعتمد ربح من غير دفتر = تنبيه.
 *
 * ⭐ مفيش هدف ياخد دوبليكنت: لو تنبيه بنفس
 * (kind, entity_type, entity_id) مفتوح بالفعل، السطر
 * بيتجاهل. يدوي إغلاق (`resolved_at`) بيسمح بتنبيه جديد
 * لو الانحراف رجع تاني.
 */
class FinanceReconcileService
{
    private const TOLERANCE = 0.009;

    /**
     * تنفيذ التسوية — بطالم المؤسسات أو لحدة واحدة.
     *
     * @return array<string, int> عدد التنبيهات الجديدة لكل مقارنة
     */
    public function reconcile(?int $organizationId = null): array
    {
        $orgIds = $organizationId
            ? [$organizationId]
            : Organization::query()->pluck('id')->all();

        $counts = [
            'invoice_allocation_drift' => 0,
            'student_ledger_self_drift' => 0,
            'student_ledger_source_drift' => 0,
            'account_movement_drift' => 0,
            'teacher_ledger_drift' => 0,
        ];

        foreach ($orgIds as $orgId) {
            $counts['invoice_allocation_drift'] += $this->reconcileInvoices($orgId);
            $counts['student_ledger_self_drift'] += $this->reconcileStudentLedgerSelf($orgId);
            $counts['student_ledger_source_drift'] += $this->reconcileStudentLedgerSources($orgId);
            $counts['account_movement_drift'] += $this->reconcilePaymentMovements($orgId);
            $counts['teacher_ledger_drift'] += $this->reconcileTeacherLedgers($orgId);
        }

        return array_filter($counts);
    }

    // ============================================================
    // (أ) الفاتورة مقابل توزيعاتها
    // ============================================================

    private function reconcileInvoices(int $organizationId): int
    {
        $new = 0;

        Invoice::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->chunk(100, function ($invoices) use (&$new) {
                foreach ($invoices as $invoice) {
                    /** ⭐ التوزيعات النشطة بعمولة الفاتورة */
                    $allocated = (float) MoneyCast::fromAggregate(
                        $invoice->allocations()->active()->sum('amount'),
                    );

                    if (abs($allocated - (float) $invoice->paid_amount) <= self::TOLERANCE) {
                        continue;
                    }

                    $currency = $invoice->currency;

                    $created = $this->alertIfNew(
                        'invoice_allocation_drift',
                        'invoice',
                        $invoice->id,
                        $invoice->organization_id,
                        $currency,
                        '[فاتورة '.$invoice->invoice_number.'] المدفوع المحفوظ '
                            .$invoice->paid_amount.' لا يطابق مجموع التوزيعات النشطة '.$allocated,
                        [
                            'stored_paid_amount' => (float) $invoice->paid_amount,
                            'sum_active_allocations' => $allocated,
                            'currency' => $currency,
                        ],
                    );

                    if ($created) {
                        $new++;
                    }
                }
            });

        return $new;
    }

    // ============================================================
    // (ب1) الدفتر داخلياً: التتابع التجميعي
    // ============================================================

    /**
     * لكل (طالب، عمولة): آخر `balance_after` = Σمدين − Σدائن.
     */
    private function reconcileStudentLedgerSelf(int $organizationId): int
    {
        $new = 0;

        $currencies = StudentLedgerEntry::query()
            ->join('students', 'students.id', '=', 'student_ledger_entries.student_id')
            ->where('students.organization_id', $organizationId)
            ->selectRaw('student_ledger_entries.student_id, student_ledger_entries.currency')
            ->distinct()
            ->get();

        foreach ($currencies as $pair) {
            $last = StudentLedgerEntry::query()
                ->where('student_id', $pair->student_id)
                ->where('currency', $pair->currency)
                ->orderByDesc('id')
                ->first();

            // ⭐ استعلام فريش من غير ترتيب — تكرار `orderBy` على
            // الاستعلام نفسه بيتنتج `ORDER BY id ASC, id DESC`
            // واول سطر بيرجع (زي ما الاختبار مسك)
            $net = (float) MoneyCast::fromAggregate(
                StudentLedgerEntry::query()
                    ->where('student_id', $pair->student_id)
                    ->where('currency', $pair->currency)
                    ->selectRaw('COALESCE(SUM(debit) - SUM(credit), 0) AS net')
                    ->value('net'),
            );

            if (abs($net - (float) $last->balance_after) <= self::TOLERANCE) {
                continue;
            }

            $created = $this->alertIfNew(
                'student_ledger_self_drift',
                'student',
                $pair->student_id,
                $organizationId,
                $pair->currency,
                '[كشف حساب الطالب #'.$pair->student_id.'] الرصيد الأخير للعملة '.$pair->currency
                    .' يساوي '.$last->balance_after.' والمجموع بالسطور = '.$net,
                [
                    'last_balance_after' => (float) $last->balance_after,
                    'sum_debit_minus_credit' => $net,
                    'currency' => $pair->currency,
                ],
            );

            if ($created) {
                $new++;
            }
        }

        return $new;
    }

    // ============================================================
    // (ب2) الدفتر مقابل المصادر: الفواتير والدفعات
    // ============================================================

    private function reconcileStudentLedgerSources(int $organizationId): int
    {
        $new = 0;

        $pairs = StudentLedgerEntry::query()
            ->join('students', 'students.id', '=', 'student_ledger_entries.student_id')
            ->where('students.organization_id', $organizationId)
            ->selectRaw('student_ledger_entries.student_id, student_ledger_entries.currency')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $studentId = (int) $pair->student_id;
            $currency = $pair->currency;

            $expectedInvoices = (float) MoneyCast::fromAggregate(
                Invoice::query()
                    ->where('student_id', $studentId)
                    ->where('currency', $currency)
                    ->where('status', '!=', 'voided')
                    ->sum('total'),
            );

            $actualInvoices = (float) MoneyCast::fromAggregate(
                StudentLedgerEntry::query()
                    ->where('student_id', $studentId)
                    ->where('currency', $currency)
                    ->where('type', 'invoice')
                    ->sum('debit'),
            );

            $expectedPayments = (float) MoneyCast::fromAggregate(
                Payment::query()
                    ->where('student_id', $studentId)
                    ->where('currency', $currency)
                    ->where('status', 'completed')
                    ->sum('amount'),
            );

            $actualPayments = (float) MoneyCast::fromAggregate(
                StudentLedgerEntry::query()
                    ->where('student_id', $studentId)
                    ->where('currency', $currency)
                    ->where('type', 'payment')
                    ->sum('credit'),
            );

            $invoiceDrift = $expectedInvoices - $actualInvoices;
            $paymentDrift = $expectedPayments - $actualPayments;

            if (abs($invoiceDrift) <= self::TOLERANCE && abs($paymentDrift) <= self::TOLERANCE) {
                continue;
            }

            $created = $this->alertIfNew(
                'student_ledger_source_drift',
                'student',
                $studentId,
                $organizationId,
                $currency,
                '[الطالب #'.$studentId.'] كشف الحساب لا يطابق مصادره للعملة '.$currency
                    .' (فواتير: '.$expectedInvoices.' مقابل '.$actualInvoices
                    .', دفعات: '.$expectedPayments.' مقابل '.$actualPayments.')',
                [
                    'invoices_total' => $expectedInvoices,
                    'ledger_invoice_debits' => $actualInvoices,
                    'payments_total' => $expectedPayments,
                    'ledger_payment_credits' => $actualPayments,
                    'currency' => $currency,
                ],
            );

            if ($created) {
                $new++;
            }
        }

        return $new;
    }

    // ============================================================
    // (ج) الحركات مقابل دفعاتها
    // ============================================================

    private function reconcilePaymentMovements(int $organizationId): int
    {
        $new = 0;

        Payment::query()
            ->where('organization_id', $organizationId)
            ->where('status', 'completed')
            ->orderBy('id')
            ->chunk(100, function ($payments) use (&$new) {
                foreach ($payments as $payment) {
                    $expectedIncome = (float) ($payment->net_amount ?: $payment->amount);

                    /** ⭐ صافي الحركات (دخل − خارج) — العكوس بتلغي نفسها */
                    $actualIncome = $this->postedNet(
                        'payment',
                        $payment->id,
                    );

                    if (abs($actualIncome - $expectedIncome) <= self::TOLERANCE) {
                        continue;
                    }

                    $created = $this->alertIfNew(
                        'account_movement_drift',
                        'payment',
                        $payment->id,
                        $payment->organization_id,
                        $payment->currency,
                        '[الدفعة #'.$payment->id.'] إيراد الحركة '
                            .$actualIncome.' لا يطابق المبلغ الواصل '.$expectedIncome,
                        [
                            'expected_income' => $expectedIncome,
                            'actual_income' => $actualIncome,
                            'currency' => $payment->currency,
                        ],
                    );

                    if ($created) {
                        $new++;
                    }
                }
            });

        return $new;
    }

    /**
     * صافي الحركات المرتبطة بمرجع (دخل ناقص خارج) — posted فقط.
     */
    private function postedNet(string $referenceType, int $referenceId): float
    {
        return (float) MoneyCast::fromAggregate(
            AccountMovement::query()
                ->where('reference_type', $referenceType)
                ->where('reference_id', $referenceId)
                ->where('status', 'posted')
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN direction = \'in\' THEN amount ELSE -amount END), 0) AS net'
                )
                ->value('net'),
        );
    }

    // ============================================================
    // (د) أرباح المعلم مقابل دفتره
    // ============================================================

    private function reconcileTeacherLedgers(int $organizationId): int
    {
        $new = 0;

        $pairs = TeacherEarning::query()
            ->join('teachers', 'teachers.id', '=', 'teacher_earnings.teacher_id')
            ->where('teachers.organization_id', $organizationId)
            ->whereIn('teacher_earnings.status', ['approved', 'paid'])
            ->selectRaw('teacher_earnings.teacher_id, teacher_earnings.currency')
            ->distinct()
            ->get();

        foreach ($pairs as $pair) {
            $teacherId = (int) $pair->teacher_id;
            $currency = $pair->currency;

            $expected = (float) MoneyCast::fromAggregate(
                TeacherEarning::query()
                    ->where('teacher_id', $teacherId)
                    ->where('currency', $currency)
                    ->whereIn('status', ['approved', 'paid'])
                    ->sum('amount'),
            );

            $actual = (float) MoneyCast::fromAggregate(
                TeacherLedgerEntry::query()
                    ->where('teacher_id', $teacherId)
                    ->where('currency', $currency)
                    ->where('type', 'lesson_earning')
                    ->sum('credit'),
            );

            if (abs($expected - $actual) <= self::TOLERANCE) {
                continue;
            }

            $created = $this->alertIfNew(
                'teacher_ledger_drift',
                'teacher',
                $teacherId,
                $organizationId,
                $currency,
                '[المعلم #'.$teacherId.'] أرباحه المعتمدة لعمولة '.$currency
                    .' = '.$expected.' ودفتره = '.$actual,
                [
                    'approved_earnings' => $expected,
                    'ledger_lesson_earning_credits' => $actual,
                    'currency' => $currency,
                ],
            );

            if ($created) {
                $new++;
            }
        }

        return $new;
    }

    // ============================================================
    // التنبيهات — بدون تكرار
    // ============================================================

    /**
     * تنبيه واحد مفتوح لكل (نوع، كيان). المفتوح بيتجاهل.
     *
     * @return bool هل اتعمل سطر جديد؟
     */
    private function alertIfNew(
        string $kind,
        string $entityType,
        int $entityId,
        ?int $organizationId,
        ?string $currency,
        string $message,
        array $details,
    ): bool {
        $exists = FinanceAlert::query()
            ->where('kind', $kind)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->open()
            ->exists();

        if ($exists) {
            return false;
        }

        FinanceAlert::create([
            'organization_id' => $organizationId ?? 1,
            'severity' => 'warning',
            'kind' => $kind,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'currency' => $currency,
            'message' => $message,
            'details' => $details,
        ]);

        return true;
    }
}
