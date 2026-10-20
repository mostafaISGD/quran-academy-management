<?php

namespace App\Services;

use App\Models\AccountMovement;
use App\Models\Expense;
use App\Models\PeriodLock;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * PR 2.12 — دورة حياة المصروف: اعتماد / رفض / صرف.
 *
 * ═══════════════════════════════════════════════════════════
 * القواعد:
 *
 * ① الحركة المالية **وقت الصرف بس** — مش وقت الإنشاء ولا
 *    وقت الاعتماد. الاعتماد قرار إداري (الأزمة لسه ما خرجتش)،
 *    الصرف هو اللي الفلوس بتعوز منه فعلاً.
 *
 * ② المنشئ ما يقدرش يعتمد مصروفه بنفسه — منفصلة الإنشاء عن
 *    المراجعة على مستوى الشخص، مش على مستوى الصلاحية فقط.
 *
 * ③ كل مرحلة جوه transaction مع `lockForUpdate` على صف
 *    المصروف — أي محاولة مزدوجة (دفع مرتين مثلاً) بتقرأ
 *    الحالة الجديدة بعد القفل فبترفض.
 */
class ExpenseService
{
    public function __construct(
        private FinancialLedgerService $ledger,
        private AuditLogService $audit,
    ) {}

    /**
     * اعتماد مصروف مُرسَل.
     *
     * @return Expense  المصروف بعد الاعتماد
     */
    public function approve(Expense $expense, User $approver): Expense
    {
        return \DB::transaction(function () use ($expense, $approver) {
            /** @var Expense $fresh */
            $fresh = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($fresh->created_by === $approver->id) {
                abort(403, 'لا يمكن لمنشئ المصروف أن يعتمده بنفسه');
            }

            if ($fresh->status !== 'submitted') {
                $this->guardStatus($fresh, 'submitted', 'الاعتماد');
            }

            $old = $fresh->only(['status', 'approved_by', 'approved_at']);
            $fresh->forceFill([
                'status' => 'approved',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ])->save();

            $this->audit->log('update', 'expense', $fresh->id, $old, $fresh->only(['status', 'approved_by', 'approved_at']), userId: $approver->id);

            return $fresh;
        });
    }

    /**
     * رفض مصروف مُرسَل — قرار إداري مالي حسّاس → `audit_logs`.
     *
     * ⭐ الرفض مسموح لمنشئ المصروف نفسه (يعني «سحب الطلب»)،
     * والقيود الحقيقية على **الاعتماد** فقط (قاعدة 2).
     */
    public function reject(Expense $expense, User $user, ?string $reason = null): Expense
    {
        return \DB::transaction(function () use ($expense, $user, $reason) {
            /** @var Expense $fresh */
            $fresh = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($fresh->status !== 'submitted') {
                $this->guardStatus($fresh, 'submitted', 'الرفض');
            }

            $old = $fresh->only('status');
            $fresh->forceFill(['status' => 'rejected'])->save();

            $this->audit->log(
                'update',
                'expense',
                $fresh->id,
                $old,
                array_merge($fresh->only('status'), ['reject_reason' => $reason]),
                userId: $user->id,
            );

            return $fresh;
        });
    }

    /**
     * صرف مصروف معتمد — هنا الحركة المالية بتتسجل `posted`.
     *
     * @param  int|null  $requestedAccountId  حساب حدده الصرّاف (يغلب
     *                                       الحساب المخزّن على المصروف)
     * @return array{expense: Expense, movement: ?AccountMovement, warning: ?string, account: ?\App\Models\FinancialAccount, account_pick: string}
     */
    public function pay(Expense $expense, User $payer, ?int $requestedAccountId = null): array
    {
        $result = \DB::transaction(function () use ($expense, $payer, $requestedAccountId) {
            /** @var Expense $fresh */
            $fresh = Expense::query()->lockForUpdate()->findOrFail($expense->id);

            if ($fresh->status !== 'approved') {
                $this->guardStatus($fresh, 'approved', 'الصرف');
            }

            $organizationId = $fresh->organization_id;

            // ⭐ الحساب: طلب الصرّاف ← الحساب المخزّن ← الافتراضي
            [$account, $pick] = $this->ledger->resolveAccount(
                $organizationId,
                $requestedAccountId,
                $this->ledger->accountTypeForPaymentMethod((string) ($fresh->payment_method ?? '')),
            );

            $movement = null;
            $warning = null;

            // ⭐⭐ PR 2.13 — الصرف في فترة مقفولة = مرفوض 422.
            //
            // قبل كده (سلوك 2.11/2.12): الصرف كان بيكمّل والحركة
            // بتفشل بصمت مع تحذير — «صرف مكتمل من غير حركة مالية»
            // ثغرة تسوية. دلوقتي: قفل `expenses`/`all` بيمنع الصرف
            // أصلاً، وقفل `account` على الحساب المختار بيمنعه برضه.
            PeriodLock::assertOpen(now()->toDateString(), 'expenses', $account);

            if ($account) {
                $res = $this->ledger->recordFor(
                    account: $account,
                    direction: 'out',
                    category: 'expense',
                    amount: (float) $fresh->amount,
                    referenceType: 'expense',
                    referenceId: $fresh->id,
                    movementDate: now()->toDateString(),
                    notes: $fresh->description,
                    user: $payer,
                );
                $movement = $res['movement'];
                $warning = $res['warning'];
            } else {
                $warning = 'لا يوجد حساب مالي نشط — تم الصرف بدون حركة مالية';
            }

            $old = $fresh->only(['status', 'paid_by', 'paid_at']);
            $fresh->forceFill([
                'status' => 'paid',
                'paid_by' => $payer->id,
                'paid_at' => now(),
            ])->save();

            $this->audit->log('update', 'expense', $fresh->id, $old, $fresh->only(['status', 'paid_by', 'paid_at']), userId: $payer->id);

            return [$fresh, $movement, $warning, $account, $pick];
        });

        return ['expense' => $result[0], 'movement' => $result[1], 'warning' => $result[2], 'account' => $result[3], 'account_pick' => $result[4]];
    }

    /** رسالة 422 عربية واضحة لما المرحلة متتطلب الحالة دي. */
    private function guardStatus(Expense $expense, string $required, string $verb): void
    {
        throw ValidationException::withMessages([
            'status' => "لا يمكن تنفيذ {$verb} — حالة المصروف الحالية: {$expense->status} والمطلوبة: {$required}",
        ]);
    }
}
