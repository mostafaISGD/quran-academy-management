<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ توزيع دفعة على فاتورة.
 *
 * ⭐ **الطلب هنا:** دفعة واحدة ممكن تغطي فواتير كتير (€400 على
 * فاتورتين)، وفاتورة واحدة ممكن تبقى مدفوعة على فترات
 * (100 كل شهر). فلازم التوزيع يكون سطر منفصل.
 *
 * ⭐ **والأهم:** من غير السطر ده الدفتر لا يعرف "الدفعة جابت
 * إيه لأي فاتورة" — ودي المعلومة اللي محاسب بيطلبها أول ما
 * يراجع.
 *
 * ⭐ `status`=`reversed` يعني إن التوزيع اتلغي (مثلاً عكس
 * الدفعة). فما بنشطب السطر — بنعلّمه `reversed`، يبقى
 * الـ Audit Trail كامل.
 */
class PaymentAllocation extends Model
{
    protected $fillable = [
        'organization_id', 'payment_id', 'invoice_id', 'amount', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => MoneyCast::class];
    }

    public function organization() { return $this->belongsTo(Organization::class); }

    public function payment() { return $this->belongsTo(Payment::class); }

    public function invoice() { return $this->belongsTo(Invoice::class); }

    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    /**
     * ⭐ التوزيعات النشطة فقط — اللي بتحسب في المدفوع.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
