<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class StudentCredit extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'currency',
        'total', 'used', 'refunded',
        'reason', 'source_type', 'source_id',
        'status', 'expires_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total' => MoneyCast::class,
            'used' => MoneyCast::class,
            'refunded' => MoneyCast::class,
            'expires_at' => 'date',
        ];
    }

    protected $attributes = [
        'used' => 0,
        'refunded' => 0,
        'status' => 'active',
    ];

    public function student() { return $this->belongsTo(Student::class); }
    public function applications() { return $this->hasMany(CreditNoteApplication::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    /**   الرصيد المتاح = الإجمالي - المستهلك - المسترد */
    public function balance(): float
    {
        return max(0, (float) $this->total - (float) $this->used - (float) $this->refunded);
    }

    /**   هل الرصيد متوفر للاستخدام؟ */
    public function isAvailable(): bool
    {
        return $this->status === 'active'
            && $this->balance() > 0
            && (! $this->expires_at || $this->expires_at->isFuture());
    }
}
