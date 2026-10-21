<?php

namespace App\Services;

use App\Exceptions\InsufficientLessonCredit;
use App\Models\Lesson;
use App\Models\LessonCreditAccount;
use App\Models\LessonCreditTransaction;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐⭐ **PR 3.3 — استهلاك رصيد الحصص.**
 *
 * ══════════════════════════════════════════════════════════════
 * ⭐ **المصدر للحقيقة** = `lesson_credit_transactions`.
 *
 * `lesson_credit_accounts.current_balance` **كاش متزامن** — نحسبه
 * من الحركات ونكتبه، عشان التقارير والبوابة يقرؤوا بسرعة. لو
 * الكاش اتفرق عن الحركات، الحركات هي اللي صح.
 *
 * ⭐ **ليه الحساب ما بينقصش قبل PR ده؟** لأن ما كان في حد
 * بيخصم. الرصيد اللي ولي الأمر بيشوفه كان دايماً الرصيد الأولي
 * (8 مثلاً) مهما اتّصلت حصص — رقم كاذب. دلوقتي كل حصة بتخلص
 * عادي **بتخصم حصة واحدة** (حسب لقطة السياسة على الحصة نفسها).
 *
 * ⭐ **السحب (overdraft) ممنوع افتراضياً**: `Setting
 * lesson_credit_overdraft_allowed`. لما يكون ممنوع، ما ينفعش
 * الحصة تتخصم أكتر من الرصيد — الطلب **يرجع 422** والعملية
 * كلها (الحالة + السجل) بترجع زي ما كانت (نفس الـtransaction).
 */
final class LessonCreditService
{
    /** ⭐ مفتاح الإعداد: السحب المسموح ولا لأ. */
    public const OVERDRAFT_SETTING = 'lesson_credit_overdraft_allowed';

    /**
     * ⭐ **الرصيد المشتق** — مجموع كميات الحركات.
     *
     * @return float
     */
    public function balance(int $accountId): float
    {
        return (float) LessonCreditTransaction::where('credit_account_id', $accountId)->sum('quantity');
    }

    /**
     * ⭐ رصيد اشتراك معيّن (الحساب النشط) — للبوابة والتقارير.
     */
    public function balanceForSubscription(int $subscriptionId): ?float
    {
        $account = LessonCreditAccount::where('subscription_id', $subscriptionId)
            ->where('status', 'active')
            ->orderByDesc('id')
            ->first();

        if ($account === null) {
            return null;
        }

        return $this->balance($account->id);
    }

    /**
     * ⭐ **استهلاك حصة** — يخصم واحدة من رصيد الطالب.
     *
     * @param Lesson $lesson الحصة اللي اتقبلت (لازم يكون ليها لقطة سياسة)
     * @param User|null $actor اللي عمل الحركة (تسجيل)
     *
     * @throws InsufficientLessonCredit رصيد الطالب ما يكفيش والسحب ممنوع
     */
    public function consume(Lesson $lesson, ?User $actor = null): ?LessonCreditTransaction
    {
        $account = $this->accountFor($lesson);

        // ⭐ مفيش حساب رصيد (حصة من غير اشتراك) — مفيش خصم.
        if ($account === null) {
            return null;
        }

        $snapshot = $lesson->policy_snapshot;

        // ⭐ القرار من **لقطة السياسة على الحصة نفسها** — فالتعديل
        // بعدين ما بيغيّرش التاريخ.
        if (! ($snapshot['deduct_credit'] ?? false)) {
            return null;
        }

        return DB::transaction(function () use ($account, $lesson, $actor): LessonCreditTransaction {
            $locked = LessonCreditAccount::query()->lockForUpdate()->find($account->id);

            $balance = $this->balance($locked->id);

            if ($balance < 1 && ! $this->overdraftAllowed($lesson->organization_id)) {
                throw new InsufficientLessonCredit($balance);
            }

            $newBalance = $balance - 1;

            $transaction = LessonCreditTransaction::create([
                'credit_account_id' => $locked->id,
                'student_id' => $locked->student_id,
                'subscription_id' => $locked->subscription_id,
                'lesson_id' => $lesson->id,
                'type' => 'lesson_used',
                'quantity' => -1,
                'balance_after' => $newBalance,
                'reason' => 'استهلاك حصة',
                'created_by' => $actor?->id,
                'created_at' => now(),
            ]);

            $this->syncBalance($locked, $newBalance);

            return $transaction;
        });
    }

    /**
     * ⭐ **إرجاع حصة** — لو اتخصمت بالغلط (تصحيح إداري).
     *
     * ⭐ إرجاع مش تعديل: سطر **جديد** في السجل (القاعدة 7 — السجل
     * المالي ما بيتعدّلش).
     */
    /**
     * ⭐⭐⭐ **PR 3.4 — الإرجاع بيحقق: يرجّع الحصة بس لو كانت
     * اتخصمت.
     *
     * ⭐ قبل PR ده كان `refund` بيزيد 1 **دايماً** — يعني تصحيح
     * على حصة ما اتخصمتش كان بيدي الطالب حصة زيادة. دلوقتي
     * الخدمة بتشوف: في حركة `lesson_used` لنفس الحصة؟ لو لأ ⇒
     * مفيش إرجاع (بلا أثر).
     */
    public function refund(Lesson $lesson, ?User $actor = null, ?string $reason = null): ?LessonCreditTransaction
    {
        $account = $this->accountFor($lesson);

        if ($account === null) {
            return null;
        }

        $consumed = LessonCreditTransaction::where('credit_account_id', $account->id)
            ->where('lesson_id', $lesson->id)
            ->where('type', 'lesson_used')
            ->exists();

        // ⭐ مفيش خصم ⇒ مفيش إرجاع (التصحيح ما يقدرش يدي زيادة).
        if (! $consumed) {
            return null;
        }

        return DB::transaction(function () use ($account, $lesson, $actor, $reason): LessonCreditTransaction {
            $locked = LessonCreditAccount::query()->lockForUpdate()->find($account->id);

            $newBalance = $this->balance($locked->id) + 1;

            $transaction = LessonCreditTransaction::create([
                'credit_account_id' => $locked->id,
                'student_id' => $locked->student_id,
                'subscription_id' => $locked->subscription_id,
                'lesson_id' => $lesson->id,
                'type' => 'refund',
                'quantity' => 1,
                'balance_after' => $newBalance,
                'reason' => $reason ?? 'إرجاع حصة',
                'created_by' => $actor?->id,
                'created_at' => now(),
            ]);

            $this->syncBalance($locked, $newBalance);

            return $transaction;
        });
    }

    /**
     * ⭐ الحساب النشط للطالب (عن طريق اشتراك الحصة).
     *
     * ⭐ `depleted` (خلص) **برجع** مع `active` — لأن الحساب
     * الخالص لسه بيستقبل حركات (لو السحب مسموح، أو لو فيه
     * إرجاع لاحق).
     */
    public function accountFor(Lesson $lesson): ?LessonCreditAccount
    {
        if ($lesson->subscription_id === null) {
            return null;
        }

        return LessonCreditAccount::where('subscription_id', $lesson->subscription_id)
            ->where('student_id', $lesson->student_id)
            ->whereIn('status', ['active', 'depleted'])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * ⭐ السحب المسموح؟ (افتراضياً **لأ**).
     */
    public function overdraftAllowed(?int $organizationId): bool
    {
        return (bool) Setting::get(self::OVERDRAFT_SETTING, false, $organizationId);
    }

    /**
     * ⭐ مزامنة الكاش (`current_balance`) مع الحركات.
     */
    private function syncBalance(LessonCreditAccount $account, float $balance): void
    {
        $account->forceFill([
            'current_balance' => $balance,
            'status' => $balance <= 0 ? 'depleted' : 'active',
        ])->save();
    }
}
