<?php

namespace App\Models;

use App\Casts\Piastres;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ⭐⭐ خزينة أو حساب — «فين الفلوس».
 *
 * مثال: خزينة نقدية، حساب البنك، محفظة فودافون كاش، بوابة دفع.
 *
 * ⭐ **القاعدة الذهبية:** رصيد الحساب **بيتحسب** من
 * `opening_balance + مجموع account_movements` — **مش** رقم
 * مخزّن بيتعدّل بإيد.
 *
 * السبب: لو الرصيد رقم مستقل، أي تصحيح على حركة قديمة هيميل
 * الرصيد ومش هنعرف. والطريقة دي بتخلّي كل خطأ **يتبان** في
 * الحركة نفسها (audit trail كامل).
 */
class FinancialAccount extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'type', 'currency',
        'opening_balance', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => Piastres::class,
        ];
    }

    // ============================================================
    // العلاقات
    // ============================================================

    /** ⭐ كل الحركات — دي اللي بتعمل الرصيد */
    public function movements()
    {
        return $this->hasMany(AccountMovement::class);
    }

    /** ⭐ الحركات اللي **بعدت** بس — المستخدمة في الرصيد */
    public function postedMovements()
    {
        return $this->hasMany(AccountMovement::class)->where('status', 'posted');
    }

    /** الفترات المقفولة على الحساب ده بس */
    public function locks()
    {
        return $this->hasMany(PeriodLock::class);
    }

    // ============================================================
    // الرصيد
    // ============================================================

    /**
     * ⭐⭐ الرصيد الحالي — **محسوب، مش مخزّن**.
     *
     * = الرصيد الافتتاحي + مجموع الداخل − مجموع الخارج
     *
     * ⚠️ **الحركات المعكوسة بتُستبعد** (`status = reversed`) — لأن
     * العكس بيسجّل **سطرين**: الأصلي + السطر العكسي المضاد. لو
     * حسبنا الاثنين هنلاقي الرصيد رجع لمكانه، وده مش المطلوب.
     *
     * ملاحظة: `sum` بياخد الحركات كلها على خط واحد
     * (`amount * direction` كـ CASE) عشان ما نعملش استعلامين.
     *
     * @param  \Carbon\Carbon|string|null  $from  فلتر من تاريخ (للتقارير)
     * @param  \Carbon\Carbon|string|null  $to    فلتر لآخر تاريخ
     */
    public function balance($from = null, $to = null): float
    {
        $query = $this->movements()->where('status', 'posted');

        if ($from !== null) {
            $query->where('movement_date', '>=', $from);
        }
        if ($to !== null) {
            $query->where('movement_date', '<=', $to);
        }

        /**
         * ⭐⭐ `$net` **بالقرش** — الـ `SUM` بيتخطّى الـ cast.
         *
         * ⚠️ و`$this->opening_balance` **بالجنيه** — لأنه قراءة
         * خاصية وبيتعدّي على `Piastres::get`.
         *
         * فجمعهم من غير تحويل = خلط وحدتين = **غلط 100×**:
         *     1000.00 (جنيه) + 50000 (قرش) = 51000
         *
         * فبنمرّر `SUM` على `fromDatabase()` الأول.
         *
         * @see \App\Casts\Piastres::fromDatabase
         */
        $net = Piastres::fromDatabase(
            $query
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN direction = '."'in'".' THEN amount ELSE -amount END), 0) AS net'
                )
                ->value('net')
        );

        return round((float) $this->opening_balance + (float) $net, 2);
    }

    /**
     * إجمالي الداخل في فترة.
     */
    public function totalIn($from = null, $to = null): float
    {
        // ⭐ `sum()` بيرجّع قرش — لازم `fromDatabase`
        return (float) Piastres::fromDatabase(
            $this->movements()
                ->where('status', 'posted')
                ->where('direction', 'in')
                ->when($from, fn ($q) => $q->where('movement_date', '>=', $from))
                ->when($to, fn ($q) => $q->where('movement_date', '<=', $to))
                ->sum('amount')
        );
    }

    /**
     * إجمالي الخارج في فترة.
     */
    public function totalOut($from = null, $to = null): float
    {
        // ⭐ `sum()` بيرجّع قرش — لازم `fromDatabase`
        return (float) Piastres::fromDatabase(
            $this->movements()
                ->where('status', 'posted')
                ->where('direction', 'out')
                ->when($from, fn ($q) => $q->where('movement_date', '>=', $from))
                ->when($to, fn ($q) => $q->where('movement_date', '<=', $to))
                ->sum('amount')
        );
    }

    // ============================================================
    // القفل
    // ============================================================

    /**
     * ⭐ هل الفترة دي مقفولة؟
     *
     * الفحص بـ **تداخل** مش بالتساوي: قفل شهر كامل يغطي
     * الـcheck لكل يوم جواه، والعكس كمان.
     *
     * @param  string  $date  التاريخ اللي عايزين نكتب فيه
     */
    public function isLockedOn($date): bool
    {
        $date = (string) $date;

        return PeriodLock::query()
            ->where(function ($q) use ($date) {
                $q->where('scope', 'all')
                    // ⭐ الحساب ده بس اتقفل
                    ->orWhere(function ($q2) use ($date) {
                        $q2->where('scope', 'account')
                            ->where('financial_account_id', $this->id);
                    });
            })
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();
    }
}