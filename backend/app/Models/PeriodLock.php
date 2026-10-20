<?php

namespace App\Models;

use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ قفل فترة — «بعد ما الشهر اتقفل، ما حدش يكتب فيه».
 *
 * يغطي: كل الحسابات، أو حساب واحد، أو مرتبات المعلمين،
 * أو المصروفات.
 *
 * ⚠️ **مين يفتح الفترة؟** قرار الإدارة: **المدير بس**
 * (`finance.periods.unlock`).
 */
class PeriodLock extends Model
{
    protected $fillable = [
        'organization_id', 'scope', 'financial_account_id',
        'period_start', 'period_end', 'label', 'reason',
        'locked_by', 'locked_at', 'unlocked_by', 'unlocked_at', 'unlock_reason',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'locked_at' => 'datetime',
            'unlocked_at' => 'datetime',
        ];
    }

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function lockedBy()
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    public function unlockedBy()
    {
        return $this->belongsTo(User::class, 'unlocked_by');
    }

    /** ⭐ لسه مقفولة؟ */
    public function isActive(): bool
    {
        return $this->unlocked_at === null;
    }

    /** ⭐ النطاقات المسموحة — PR 2.13 يضيف `invoices` و `payments`. */
    public const SCOPES = ['all', 'account', 'invoices', 'payments', 'expenses', 'payroll'];

    /**
     * ⭐ التاريخ ده واقع جوه الفترة دي؟
     *
     * **شامل الطرفين** — شهر مارس = ١ → ٣١، فلو يوم ٣١ مسدول
     * يبقى مقفول فعلاً.
     */
    public function covers($date): bool
    {
        $date = (string) $date;

        return $date >= (string) $this->period_start
            && $date <= (string) $this->period_end;
    }

    /**
     * ⭐⭐ PR 2.13 — **البوابة الواحدة** لفحص أي كتابة مالية.
     *
     * قبل كده الفحص كان منقسم: `FinancialLedgerService::assertNotLocked`
     * كان بيشوف أقفال `all`/`account` بس، وعمليات زي المدفوعات
     * والمصروفات كانت بتعدّي في فترة مقفولة من غير أي رفض —
     * الدفعة بتتحفظ والحركة بتضيع مع تحذير.
     *
     * القاعدة دلوقتي: أي كتابة مالية بتتأكد إن **فترتها مفتوحة**
     * على النطاق الخاص بيها:
     *
     * - قفل `all` → بيمنع كل حاجة.
     * - قفل على نطاق وظيفي (`payments`/`invoices`/`expenses`/`payroll`)
     *   → بيمنع عمليات النطاق ده بس.
     * - قفل `account` → بيمنع الكتابة على الحساب ده بالذات
     *   (بيلزم تمرير الحساب).
     *
     * @param  string|null  $scope  نطاق العملية الوظيفي
     *                        (`payments`/`invoices`/`expenses`/`payroll`)
     *                        أو null = فحص الحساب/الكل بس.
     * @param  FinancialAccount|null  $account  الحساب المتأثر —
     *                        لو موجود، أقفال `account` بتتمسكه برضه.
     *
     * @throws ValidationException (HTTP 422) لو الفترة مقفولة
     */
    public static function assertOpen(string $date, ?string $scope = null, ?FinancialAccount $account = null): void
    {
        $locked = static::findBlocking($date, $scope, $account);

        if ($locked !== null) {
            throw ValidationException::withMessages([
                'period' => 'الفترة دي مقفولة ('.$locked->label.')'
                    .($locked->reason ? ' — السبب: '.$locked->reason : ''),
            ]);
        }
    }

    /**
     * مين قفل واحد (لو موجود) بيمنع الكتابة دي؟
     *
     * مفيد الفحص قبل القرار — بدون رمي استثناء.
     */
    public static function findBlocking(
        string $date,
        ?string $scope = null,
        ?FinancialAccount $account = null,
    ): ?self {
        return static::query()
            ->whereNull('unlocked_at')
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->where(function ($q) use ($scope, $account) {
                $q->where('scope', 'all');

                if ($scope !== null && $scope !== 'all') {
                    $q->orWhere('scope', $scope);
                }

                if ($account !== null) {
                    $q->orWhere(function ($q2) use ($account) {
                        $q2->where('scope', 'account')
                            ->where('financial_account_id', $account->id);
                    });
                }
            })
            ->first();
    }

    /**
     * ⭐ قفل شهر (أول → آخر).
     *
     * عشان ما نكتبش `period_end` بنفسك وتغلط في شهر فبراير.
     */
    public static function monthRange(string $month): array
    {
        $start = \Illuminate\Support\Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        return [$start->toDateString(), $end->toDateString()];
    }

    /**
     * ⭐ قفل نطاق تاريخ.
     *
     * @return static
     */
    public static function lock(
        int $organizationId,
        string $start,
        string $end,
        string $scope = 'all',
        ?int $accountId = null,
        ?string $reason = null,
        ?int $userId = null,
    ): self {
        return static::create([
            'organization_id' => $organizationId,
            'scope' => $scope,
            'financial_account_id' => $accountId,
            'period_start' => $start,
            'period_end' => $end,
            'label' => 'قفل '.substr($start, 0, 7),
            'reason' => $reason,
            'locked_by' => $userId ?? auth()->id(),
            'locked_at' => now(),
        ]);
    }

    /**
     * ⭐ فتح — **المدير بس** (الصلاحية بيتأكد عليها في الـ controller).
     *
     * ⚠️ بنقفل **سطر جديد** مش بنمسح القفل. السبب: «مين فتح
     * الفترة دي وإمتى» — معلومة محاسبية لازم تفضل محفوظة.
     */
    public function unlock(string $reason, ?int $userId = null): self
    {
        if (! $this->isActive()) {
            return $this;
        }

        $this->update([
            'unlocked_by' => $userId ?? auth()->id(),
            'unlocked_at' => now(),
            'unlock_reason' => $reason,
        ]);

        return $this;
    }
}