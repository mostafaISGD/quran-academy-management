<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'student_id', 'parent_id', 'invoice_id',
        'amount', 'fee_amount', 'currency', 'payment_method', 'transaction_reference',
        'status', 'paid_at', 'received_by', 'account_id', 'notes', 'net_amount',
        'idempotency_key',
    ];

    /**
     * ⭐⭐ قيم افتراضية **في الموديل** مش بس في قاعدة البيانات.
     *
     * ⭐ **اللي حصل بسبب إن دي كانت ناقصة:**
     *
     * `Payment::create([...])` من غير `status` بيحفظ السطر
     * `completed` في الـ DB (لأن الـ migration عامل `default`)،
     * بس `$payment->status` في الذاكرة بيبقى **`null`**.
     *
     * وأول ما الـ ledger اتربط بالدفعات، الكود اللي بيقول
     * «سجّل الحركة بس لو الدفعة `completed`» كان شغال **غلط**:
     * `$payment->status` كان `null` فمابيسجلش والا.
     * **ولا ولا دفعة في الداتا كانت هتتسجل في الدفتر** — من غير
     * أي رسالة خطأ، بس الأرقام غلط.
     *
     * والسبب إن SQLite (و MySQL كمان) **مايرجعش** قيمة العمود
     * الافتراضية للصف اللي اتعمل دلوقتي.
     *
     * نفس الملاحظة في `WaitingListEntry` و `GroupClass`.
     *
     * @see \App\Http\Controllers\Api\PaymentController::store
     */
    protected $attributes = [
        'status' => 'completed',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'net_amount' => MoneyCast::class,
            'fee_amount' => MoneyCast::class,
            'paid_at' => 'datetime',
        ];
    }

    /** ⭐ PR 2.7 — الحساب اللي دخلت فيه الفلوس (محفوظ على الدفعة) */
    public function account()
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function student() { return $this->belongsTo(Student::class); }
    public function parent() { return $this->belongsTo(ParentModel::class, 'parent_id'); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function refunds() { return $this->hasMany(Refund::class); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by'); }

    /**
     * ⭐ توزيعات الدفعة على الفواتير — المصدر الوحيد لمعرفة إيه
     * اللي غطّته الدفعة وإيه لسه.
     */
    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    /**   مجموع ما اتوزّع من الدفعة (النشط فقط) */
    public function allocatedAmount(): float
    {
        // ⭐ `sum()` بالقرش — لازم `fromDatabase`
        return (float) MoneyCast::fromAggregate(
            $this->allocations()->active()->sum('amount')
        );
    }

    /**   المتبقي من الدفعة اللي لسه ما اتغطى على فاتورة */
    public function unallocatedAmount(): float
    {
        return max(0, (float) $this->amount - $this->allocatedAmount());
    }

    public function totalRefunded(): float
    {
        // ⭐ `sum()` بالقرش — لازم `fromDatabase`
        return (float) MoneyCast::fromAggregate(
            $this->refunds()->where('status', 'processed')->sum('amount')
        );
    }

    public function isFullyRefunded(): bool
    {
        return $this->totalRefunded() >= (float) $this->amount;
    }
}
