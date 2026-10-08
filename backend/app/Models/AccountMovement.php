<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐ حركة واحدة — **المصدر الوحيد للحقيقة المالية**.
 *
 * كل قرش داخل الأكاديمية أو بيخرج منها بيتسجل هنا كسطر واحد.
 * الرصيد مافيش رقم في جدول خ��ان — بيتحسب من الحركات دي.
 *
 * ⭐ **القاعدة ١: لا تعديل على السجلات المالية التاريخية.**
 * عشان كده الجدول ده **مالوش** `deleted_at` — السطر ما يتمسحش
 * أبداً. التصحيح = **سطر عكسي** جديد في `reversalOf()`.
 */
class AccountMovement extends Model
{
    /**
     * ⚠️⚠️ **مفيش `SoftDeletes` عن قصد.**
     *
     * كل الجداول المالية في المشروع بتستخدم `softDeletes`. بس
     * الحركة دي استثناء: السجل المالي **التاريخي** ما يتمسحش.
     * لو اتمسح، الرصيد جاahوقت الصيانة هيتغيّر من غير ما حد
     * يعرف — وده عكس القاعدة ١ بالظبط.
     *
     * التصحيح = سطر عكسي.
     */
    protected $fillable = [
        'organization_id', 'financial_account_id', 'direction', 'category',
        'amount', 'currency', 'reference_type', 'reference_id',
        'movement_date', 'status', 'reverses_movement_id',
        'account_transfer_id', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'movement_date' => 'date',
        ];
    }

    // ============================================================
    // العلاقات
    // ============================================================

    public function account()
    {
        return $this->belongsTo(FinancialAccount::class, 'financial_account_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transfer()
    {
        return $this->belongsTo(AccountTransfer::class);
    }

    /** ⭐ الحركة الأصلية اللي دي بتعكسها */
    public function reverses()
    {
        return $this->belongsTo(AccountMovement::class, 'reverses_movement_id');
    }

    /** ⭐ الحركات اللي عكستها دي */
    public function reversedBy()
    {
        return $this->hasMany(AccountMovement::class, 'reverses_movement_id');
    }

    // ============================================================
    // العكس
    // ============================================================

    /**
     * ⭐⭐ عكس حركة — **بسطرين**، ومش بلمس الأصلي خالص.
     *
     * ⚠️⚠️ **التصميم ده مقصود ومتعمّد:**
     *
     * القاعدة ١: «لا تعديل على السجلات المالية التاريخية. أي
     * تصحيح يتم بسجل عكسي ثم سجل جديد».
     *
     * فأصلا... **ما بيتكتبش فيه ولا حاجة**. ولا مبلغه، ولا
     * تاريخه، ولا `status` بتاعه. السطر الجديد هو اللي بيتعمل،
     * بنفس المبلغ والاتجاه **المضاد**، وبيتبعت للأصل في
     * `reverses_movement_id`.
     *
     * ⭐ والنتيجة إن الرصيد **بيطبّع لوحده**: ‎+1000 (الأصلي)
     * ناقص ‎1000 (العكسي) = صفر. من غير أي استثناء في معادلة
     * الرصيد، ومن غير ما نلمس تاريخ.
     *
     * ⚠️ **اللي اتغيّر في التصميم الأول:** كنت بنعلّم
     * `status = reversed` على الأصل، وده كان غلط من اتنين:
     *   ١. **بيخالف القاعدة ١** — ده تعديل على سجل تاريخي.
     *   ٢. **الحساب كان غلط** — معادلة الرصيد بتستبعد المعكوس،
     *      فالأصلي (المستبعد) والعكسي (المشمول) = ناقص 1000 بدل
     *      صفر. الاختبار `reversing_a_movement…` مسك ده.
     *
     * @param  string  $reason  سبب العكس — **إلزامي**. من غيره
     *                       مفيش حد يفهم بعدين ليه اتعكست.
     */
    public function reversalOf(string $reason, ?int $userId = null): self
    {
        // ⭐ ممنوع عكس حركة معكوسة — هيتعمل cancel for cancel
        if ($this->hasReversal()) {
            throw new \LogicException('الحركة دي متعكوسة أصلاً');
        }

        return static::create([
            'organization_id' => $this->organization_id,
            'financial_account_id' => $this->financial_account_id,
            // ⭐ الاتجاه المضاد — دي الحيلة كلها
            'direction' => $this->direction === 'in' ? 'out' : 'in',
            'category' => $this->category,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            // ⭐ تاريخ العكس = **يوم العكس**، مش يوم الحركة الأصلية.
            // عشان التقرير بيقول «اتصحح امبارح» بوضوح.
            'movement_date' => now()->toDateString(),
            'status' => 'posted',
            'reverses_movement_id' => $this->id,
            'created_by' => $userId ?? auth()->id(),
            'notes' => 'عكس: '.$reason,
        ]);
    }

    /**
     * ⭐ اتعكست؟ — **بالسطر العكسي**، مش بـ `status`.
     *
     * ⚠️ السبب: لو حسبناها من `status` كان لازم نعدّل الأصل،
     * وده بيخالف القاعدة ١. فالمصدر الوحيد هو **وجود** السطر
     * العكسي.
     */
    public function isReversed(): bool
    {
        return $this->hasReversal();
    }

    /** ⭐ هل ليها سطر عكسي؟ — المصدر الوحيد للحقيقة هنا */
    public function hasReversal(): bool
    {
        return $this->reversedBy()->exists();
    }

    /** ⭐ سطر العكس (لو موجود) */
    public function reversal(): ?self
    {
        return $this->reversedBy()->first();
    }

    // ============================================================
    // القفل
    // ============================================================

    /**
     * ⭐ الفترة دي مقفولة؟
     *
     * ⭐ العكس **بيخضع للقفل** زي أي كتابة تانية — manager
     * بس هو اللي يفتح.
     */
    public function isLockedOn(): bool
    {
        $date = (string) $this->movement_date;

        return PeriodLock::query()
            ->where(function ($q) use ($date) {
                $q->where('scope', 'all')
                    ->orWhere(function ($q2) use ($date) {
                        $q2->where('scope', 'account')
                            ->where('financial_account_id', $this->financial_account_id);
                    });
            })
            ->whereDate('period_start', '<=', $date)
            ->whereDate('period_end', '>=', $date)
            ->exists();
    }
}