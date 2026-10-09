<?php

namespace App\Models;

use App\Casts\Piastres;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ تحويل بين حسابين.
 *
 * ⭐⭐ **دي النقطة اللي الخطة سمّتها «سد الثغرة»:**
 *
 * التحويل **مش إيراد ومش مصروف**. 10,000 من البنك لخزينة
 * الأكاديمية معناها «فلوس اتنقلت من مكان لمكان» — **مش** «دخل
 * الشركة 10,000».
 *
 * فلو سجلناه كـ `income` كان التقرير هيقول «الإيراد زاد
 * 10,000» وهو كذب. عشان كده التحويل = **حركتين**:
 *
 *   • `out` من الحساب الأول
 *   • `in` على الحساب التاني
 *
 * وبنربطهم بـ `account_movements.account_transfer_id`.
 */
class AccountTransfer extends Model
{
    protected $fillable = [
        'organization_id', 'from_account_id', 'to_account_id',
        'amount', 'currency', 'exchange_rate', 'target_currency',
        'status', 'transfer_date', 'reference', 'created_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => Piastres::class,
            'exchange_rate' => 'decimal:6',
            'transfer_date' => 'date',
        ];
    }

    public function fromAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'from_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(FinancialAccount::class, 'to_account_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** ⭐ الحركتين — صادر ووارد */
    public function movements()
    {
        return $this->hasMany(AccountMovement::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * ⭐ المبلغ المستلم في العملة لو التحويل بين عملتين.
     *
     * القاعدة ٣: سعر الصرف بيتسجل مع العملية ولا نعيد حساب
     * بأثر رجعي.
     */
    public function receivedAmount(): float
    {
        if (! $this->exchange_rate) {
            return (float) $this->amount;
        }

        return round((float) $this->amount * (float) $this->exchange_rate, 2);
    }
}