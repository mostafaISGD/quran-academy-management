<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class Refund extends Model
{
    protected $fillable = [
        'payment_id', 'invoice_id', 'amount', 'currency', 'reason',
        'method', 'mode', 'status', 'processed_by', 'processed_at',
        'approved_by', 'approved_at',
    ];

    /**
     * ⭐ قيم افتراضية **في الموديل** — انظر `Payment` للتفصيل.
     *
     * السبب إن SQLite مايرجعش قيمة العمود الافتراضية للصف الجديد.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'processed_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    /** ⭐⭐ من طلب الاسترداد (اللي ممنوع يوافق بعدين) */
    public function requester()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    /** ⭐⭐ من اعتمد الاسترداد (الموافقة التانية) */
    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function processedBy()
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
