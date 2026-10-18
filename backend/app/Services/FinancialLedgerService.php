<?php

namespace App\Services;

use App\Models\AccountMovement;
use App\Models\AccountTransfer;
use App\Models\FinancialAccount;
use App\Models\PeriodLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐ دفتر الحركات المالية — **البوابة الوحيدة** لكل حركة فلوس.
 *
 * ⭐ **ليه خدمة ولي مش models observers؟**
 *
 * ١. عشان نعرف **إمتى** نسجّل. مش كل كتابة على `payments` معناها
 *    فلوس دخلت — كلمة `status = pending` مش تحصيل.
 * ٢. عشان نقدر **نمنع** الكتابة (قفل الفترة) — observer بيسجّل
 *    بس مش بيسأل.
 * ٣. عشان القاعدة ٣ (سعر الصرف وقت العملية) محتاجة تكون في
 *    نفس اللحظة.
 *
 * ⭐⭐ **قرار المبالغ — نقطة التحويل لسه واحدة:**
 *
 * كل المبالغ بتتحوّل لـ `decimal` هنا **قبل** ما تتخزّن. فلو
 * بعدين حوّلنا الجداول كلها لـ `bigint` (قرش) زي الخطة، هيبقى
 * التعديل **في ملف واحد** — مش في كل صفحة وكل تقرير.
 *
 * @see \App\Models\ExchangeRate::convert
 */
class FinancialLedgerService
{
    /**
     * ⭐⭐ تسجيل حركة واحدة.
     *
     * @param  string  $category  income | expense | teacher_payment | refund
     *                          | transfer_in | transfer_out | fee | adjustment
     * @param  string  $direction in | out
     *
     * @throws \RuntimeException  لو الفترة مقفولة
     */
    public function record(
        FinancialAccount $account,
        string $direction,
        string $category,
        float $amount,
        ?string $movementDate = null,
        ?string $currency = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?string $notes = null,
        ?int $userId = null,
        string $status = 'posted',
        ?int $transferId = null,
    ): AccountMovement {
        $date = $movementDate ?? now()->toDateString();

        // ⭐⭐ القفل — قبل أي كتابة
        $this->assertNotLocked($account, $date);

        /**
         * ⚠️ ⚠️ **نقطة التحويل للمبالغ.**
         *
         * هنا بالظبط — مش في الـ controller، مش في الـ model.
         * كل فلوس بتعدّي من السطر ده. فلو غيّرنا النوع،
         * نغيّره هنا في **سطر واحد**.
         */
        $normalizedAmount = $this->normalizeAmount($amount);

        return AccountMovement::create([
            'organization_id' => $account->organization_id,
            'financial_account_id' => $account->id,
            'direction' => $direction,
            'category' => $category,
            // ⚠️ **دايماً موجب** — الاتجاه منفصل في `direction`.
            // مبلغ سالب في دفتر الحركات = أول خطأ بيطلّع نتيجة غلط.
            'amount' => abs($normalizedAmount),
            'currency' => $currency ?? $account->currency,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'movement_date' => $date,
            'status' => $status,
            'account_transfer_id' => $transferId,
            'created_by' => $userId ?? auth()->id(),
            'notes' => $notes,
        ]);
    }

    /**
     * ⭐ تسجيل **تحصيل** — فلوس دخلت من طالب.
     */
    public function recordIncome(
        FinancialAccount $account,
        float $amount,
        string $referenceType,
        int $referenceId,
        ?string $movementDate = null,
        ?string $notes = null,
        ?User $user = null,
    ): AccountMovement {
        return $this->record(
            account: $account,
            direction: 'in',
            category: 'income',
            amount: $amount,
            movementDate: $movementDate,
            referenceType: $referenceType,
            referenceId: $referenceId,
            notes: $notes,
            userId: $user?->id,
        );
    }

    /**
     * ⭐ تسجيل **مصروف** — فلوس خرجت.
     */
    public function recordExpense(
        FinancialAccount $account,
        float $amount,
        string $referenceType,
        int $referenceId,
        ?string $movementDate = null,
        ?string $notes = null,
        ?User $user = null,
    ): AccountMovement {
        return $this->record(
            account: $account,
            direction: 'out',
            category: 'expense',
            amount: $amount,
            movementDate: $movementDate,
            referenceType: $referenceType,
            referenceId: $referenceId,
            notes: $notes,
            userId: $user?->id,
        );
    }

    /**
     * ⭐ تسجيل **دفعة لمعلم**.
     *
     * ⭐ مش `expense` عادي — الفئة مختلفة عشان التقرير يقدر
     * يفرق بين «المصروفات» و«مستحقات المعلمين».
     */
    public function recordTeacherPayment(
        FinancialAccount $account,
        float $amount,
        string $referenceType,
        int $referenceId,
        ?string $movementDate = null,
        ?string $notes = null,
        ?User $user = null,
    ): AccountMovement {
        return $this->record(
            account: $account,
            direction: 'out',
            category: 'teacher_payment',
            amount: $amount,
            movementDate: $movementDate,
            referenceType: $referenceType,
            referenceId: $referenceId,
            notes: $notes,
            userId: $user?->id,
        );
    }

    // ============================================================
    // الربط مع Flows الموجودة
    // ============================================================

    /**
     * ⭐ نوع الحساب المناسب لطريقة الدفع.
     *
     * ⭐ السبب: لو حد دفع بـ InstaPay، الفلوس دخلت **محفظة** مش
     * الخزينة النقدية. من غير ده رصيد الخزينة النقدية هيبقى غلط
     * والـ gateway مش هيبان خالص.
     *
     * ⚠️ `cash` بيرجع `null` — لأن الخزينة هي **الافتراضي** تاني،
     * والباقي بيروح لحسابه. فمفيش داعي نعمل lookup.
     *
     * @return string|null  نوع الحساب، أو `null` للافتراضي
     */
    public function accountTypeForPaymentMethod(string $method): ?string
    {
        return match ($method) {
            'bank_transfer' => 'bank',
            'wallet' => 'wallet',
            'online_payment', 'card' => 'gateway',
            // ⭐ `cash` و `other` → الافتراضي
            default => null,
        };
    }

    /**
     * ⭐ اعتماد حركة معلّقة — `pending` → `posted`.
     *
     * ⭐ دي اللحظة اللي فيها المصروف **بيطلع فعلاً من الخزينة**.
     *
     * ⚠️ ليش الـ `status` بيتغيّر هنا بساعته وما بينفعش يتغيّر
     * بعد كده: **`posted` معناه «الفلوس خرجت»**. لو رجعنا لحد
     * بعدين غيّرناه، يبقى عندنا تاريخ بيقول «الفلوس خرجت» و«ما
     * خرجتش» في نفس الوقت — وده عكس قاعدة الحساب الأساسية.
     * فبعد الاعتماد، التصحيح = **عكس** مش تعديل.
     *
     * @throws \RuntimeException  لو الفترة مقفولة
     */
    public function pendingToPosted(AccountMovement $movement, ?User $user = null): AccountMovement
    {
        if ($movement->status === 'posted') {
            // ⭐ idempotent — الاعتماد مرتين مابرتميش. Admissions نفس
            // الفكرة: زرار الدبل-كليك مايرميش 500.
            return $movement;
        }

        $account = $movement->account;

        // ⭐ القفل بيتفحص **يوم الاعتماد** — هو سجلFinancialلي
        // بيحصل فيه، مش تاريخ المصروف الأصلي
        $this->assertNotLocked($account, now()->toDateString());

        $movement->update(['status' => 'posted']);

        return $movement->fresh();
    }

    /**
     * ⭐⭐ تسجيل حركة **من_flow موجود** — بتفشل **بصوت عالي**.
     *
     * ⭐ **ليه مش بنستخدم `record()` مباشرة:**
     *
     * ① **مافيش حساب** — لو مفيش ولا حساب نشط، الحركة مش
     *    تتسجل. وده **مش** معناه نكمل كأن مفيش حاجة: بنرجّع
     *    `[movement => null, warning => ...]` والـ API بيقول
     *    بالحرف. فلوس مااتسجلتش لازم حد يعرف.
     *
     * ② **الفترة مقفولة** — دي بترمي exception زي الأول، لأن
     *    التحصيل في شهر مقفول لازم **يفشل** (مش يتحفظ السطر
     *    من غير حركة مالية ويفصل فلوس عن رصيدها).
     *
     * @return array{movement: AccountMovement|null, warning: string|null}
     */
    public function recordFor(
        FinancialAccount $account,
        string $direction,
        string $category,
        float $amount,
        string $referenceType,
        int $referenceId,
        string $movementDate,
        ?string $notes = null,
        ?User $user = null,
        string $status = 'posted',
    ): array {
        try {
            $movement = $this->record(
                account: $account,
                direction: $direction,
                category: $category,
                amount: $amount,
                movementDate: $movementDate,
                referenceType: $referenceType,
                referenceId: $referenceId,
                notes: $notes,
                userId: $user?->id,
                status: $status,
            );

            return ['movement' => $movement, 'warning' => null];
        } catch (\RuntimeException $e) {
            /**
             * ⭐ **الفترة المقفولة مابترميش فوق.**
             *
             * السبب: دي حالة **طبيعية** («الشهر مقفل»)، مش خطأ.
             * فلو رميناها، الدفع كله بيقع بسبب قفل، والعميل
             * واقف في البوابة.
             *
             * بدل كده: الدفعة **بتتحفظ** عادي، بس من غير حركة
             * مالية، وبنرجّع تحذير. الأدمن يشوف «دفعة من غير
             * حركة مالية» ويفتح الفترة أو يعمل التسوية.
             */
            return ['movement' => null, 'warning' => $e->getMessage()];
        }
    }

    /**
     * ⭐ تسجيل **استرداد** لطالب.
     *
     * قرار الإدارة: الاسترداد **نقدي** (مش رصيد دائن).
     * فلو القرار ده اتغيّر، سطر الاسترداد زي ما هو — الفرق بس في
     * `reference_type` اللي بيبقى `credit_note` بدل `refund`.
     */
    public function recordRefund(
        FinancialAccount $account,
        float $amount,
        string $referenceType,
        int $referenceId,
        ?string $movementDate = null,
        ?string $notes = null,
        ?User $user = null,
    ): AccountMovement {
        return $this->record(
            account: $account,
            direction: 'out',
            category: 'refund',
            amount: $amount,
            movementDate: $movementDate,
            referenceType: $referenceType,
            referenceId: $referenceId,
            notes: $notes,
            userId: $user?->id,
        );
    }

    /**
     * ⭐⭐⭐ تحويل بين حسابين — **حركتين مرتبطتين**.
     *
     * ⭐⭐ دي النقطة اللي الخطة سمّتها «سد الثغرة»:
     * التحويل **مش إيراد ومش مصروف**. فلو سجلناه كده كان التقرير
     * هيقول «الدخل زاد» وهو مجرد فلوس بتتحرك.
     *
     * فلو الحسابين **عملتين مختلفين**: بنسجّل سعر الصرف الفعلي
     * (القاعدة ٣: ولا نعيد حساب بأثر رجعي)، والوارد ببالعملة
     * التانية بسعر اليوم.
     *
     * @throws \RuntimeException  لو الحساب مش كفاية أو الفترة مقفولة
     */
    public function transfer(
        FinancialAccount $from,
        FinancialAccount $to,
        float $amount,
        ?string $transferDate = null,
        ?string $notes = null,
        ?User $user = null,
        string $reference = null,
    ): AccountTransfer {
        $date = $transferDate ?? now()->toDateString();

        // ⭐ القفل على **الاتنين** — التحويل بيحرك في حسابين
        $this->assertNotLocked($from, $date);
        $this->assertNotLocked($to, $date);

        $amount = $this->normalizeAmount($amount);

        // ⭐ الحساب مش كفاية — مستحيل نعمل تحويل من رصيد سالب
        if ($from->balance() < $amount) {
            throw new \RuntimeException(
                'رصيد «'.$from->name.'» مش مكفي للتحويل'
            );
        }

        return DB::transaction(function () use ($from, $to, $amount, $date, $notes, $user, $reference) {
            $sameCurrency = $from->currency === $to->currency;

            /** ⭐ سعر الصرف الفعلي — بيتسجل مع العملية */
            $rate = null;
            $targetCurrency = null;

            if (! $sameCurrency) {
                $rate = \App\Models\ExchangeRate::rateFor($from->currency, $to->currency, $date);
                $targetCurrency = $to->currency;
            }

            $transfer = AccountTransfer::create([
                'organization_id' => $from->organization_id,
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => $amount,
                'currency' => $from->currency,
                'exchange_rate' => $rate,
                'target_currency' => $targetCurrency,
                'status' => 'completed',
                'transfer_date' => $date,
                'reference' => $reference,
                'created_by' => $user?->id ?? auth()->id(),
                'notes' => $notes,
            ]);

            // ⭐ الصادر — بالحركة الأولى
            $this->record(
                account: $from,
                direction: 'out',
                category: 'transfer_out',
                amount: $amount,
                movementDate: $date,
                currency: $from->currency,
                referenceType: 'account_transfer',
                referenceId: $transfer->id,
                notes: $notes,
                userId: $user?->id,
                transferId: $transfer->id,
            );

            // ⭐ الوارد — بالحركة التانية، وبعملة التحويل لو مختلفة
            $received = $rate !== null
                ? round($amount * (float) $rate, 2)
                : $amount;

            $this->record(
                account: $to,
                direction: 'in',
                category: 'transfer_in',
                amount: $received,
                movementDate: $date,
                currency: $to->currency,
                referenceType: 'account_transfer',
                referenceId: $transfer->id,
                notes: $notes,
                userId: $user?->id,
                transferId: $transfer->id,
            );

            return $transfer;
        });
    }

    /**
     * ⭐⭐ عكس حركة — **المدير بس**.
     *
     * ⭐ الحركة الأصلية **ما بتتلمسش** — بيتعمل سطر عكسي جديد
     * بنفس المبلغ والاتجاه المضاد، والرصيد بيطبّع لوحده
     * (+1000 ناقص 1000 = صفر). القاعدة ١: «أي تصحيح يتم بسجل
     * عكسي ثم سجل جديد».
     *
     * ⚠️ القفل بيشتغل هنا كمان — يعني العكس مش هينفع في فترة
     * مقفولة. ده **مقصود**: القفل موجود عشان ما نغيّرش أرقام
     * اتفقنا عليها، والعكس بيغيّر أرقام.
     */
    public function reverse(
        AccountMovement $movement,
        string $reason,
        ?User $user = null,
    ): AccountMovement {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('سبب العكس إجباري');
        }

        // ⭐ ممنوع العكس مرتين — cancel for cancel
        if ($movement->hasReversal()) {
            throw new \LogicException('الحركة دي متعكوسة أصلاً');
        }

        $account = $movement->account;

        // ⭐ القفل بتاريخ **الحركة الأصلية** — هي اللي بترجع
        $this->assertNotLocked($account, $movement->movement_date->toDateString());

        return $movement->reversalOf($reason, $user?->id);
    }

    // ============================================================
    // القفل
    // ============================================================

    /**
     * ⭐⭐ يمنع الكتابة في فترة مقفلة.
     *
     * @throws \RuntimeException
     */
    public function assertNotLocked(FinancialAccount $account, $date): void
    {
        $locked = PeriodLock::query()
            ->whereNull('unlocked_at')
            ->where(function ($q) use ($account) {
                $q->where('scope', 'all')
                    ->orWhere(function ($q2) use ($account) {
                        $q2->where('scope', 'account')
                            ->where('financial_account_id', $account->id);
                    });
            })
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->first();

        if ($locked) {
            throw new \RuntimeException(
                'الفترة دي مقفولة ('.$locked->label.')'
                .($locked->reason ? ' — السبب: '.$locked->reason : '')
            );
        }
    }

    /**
     * ⭐ هل التاريخ ده مقفول؟ (للفحص قبل العرض)
     */
    public function isLocked(FinancialAccount $account, $date): bool
    {
        try {
            $this->assertNotLocked($account, $date);

            return false;
        } catch (\RuntimeException) {
            return true;
        }
    }

    // ============================================================
    // التحويل — نقطة واحدة بس في النظام كله
    // ============================================================

    /**
     * ⭐⭐ **نقطة التحويل للمبالغ — مكانها الوحيد.**
     *
     * لو الخطة اتنفّذت وتحوّلنا لـ `bigint` (قرش)، التغيير هنا
     * في **سطر واحد**:
     *
     * ```php
     * return (int) round($amount * 100);
     * ```
     *
     * وبعدها كل صفحة العرض بتقسم على 100 في `lib/format`.
     * سطرين الإجمالي، مش ٣٠٠٠ سطر في التقارير.
     *
     * @see \App\Services\FinancialLedgerService::record
     */
    private function normalizeAmount(float $amount): float
    {
        return round($amount, 2);
    }

    /**
     * ⭐ حساب **الحساب الافتراضي** للأكاديمية.
     *
     * ⚠️ ليش بنعمل ده: لحد ما الإدارة تعمل حساباتها، أي تحصيل
     * لازم يروح **مكان**. لو ملقيناش حساب، نعمل «الخزينة
     * الرئيسية» أوتوماتيك — أحسن من إن الفلوس تضيع.
     *
     * ⭐ رجع `null` يعني مفيش ولا حساب نشط — وساعتها
     * `resolveAccount()` بيرجع `null` والمتحرّك **مش بيتسجل**،
     * والـ API بيرجع تحذير واضح. الفلوس ماتضيعش صامتة.
     */
    public function defaultAccount(int $organizationId): ?FinancialAccount
    {
        return FinancialAccount::where('organization_id', $organizationId)
            ->where('status', 'active')
            ->orderBy('id')
            ->first();
    }

    /**
     * ⭐⭐ أي حساب تختاره الحركة؟
     *
     * الترتيب:
     *  ① الحساب اللي **الكود حدّده** — مثلاً «محفظة فودافون كاش»
     *     عشان طريقة الدفع محفظة.
     *  ② الحساب اللي **اليوزر اختاره** (`financial_account_id`
     *     في الطلب).
     *  ③ الحساب الافتراضي.
     *
     * @return array{0: FinancialAccount|null, 1: string}  [الحساب، سبب الرجوع]
     */
    public function resolveAccount(
        int $organizationId,
        ?int $requestedId = null,
        ?string $byType = null,
    ): array {
        if ($requestedId) {
            $account = FinancialAccount::where('organization_id', $organizationId)
                ->where('id', $requestedId)
                ->where('status', 'active')
                ->first();

            if ($account) {
                return [$account, 'requested'];
            }

            return [null, 'requested_missing'];
        }

        if ($byType) {
            $account = FinancialAccount::where('organization_id', $organizationId)
                ->where('type', $byType)
                ->where('status', 'active')
                ->orderBy('id')
                ->first();

            if ($account) {
                return [$account, 'by_type'];
            }
        }

        return [$this->defaultAccount($organizationId), 'default'];
    }
}