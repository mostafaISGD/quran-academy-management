<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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