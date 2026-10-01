<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'student_id', 'parent_id', 'invoice_id',
        'amount', 'currency', 'payment_method', 'transaction_reference',
        'status', 'paid_at', 'received_by', 'notes',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    public function student() { return $this->belongsTo(Student::class); }
    public function parent() { return $this->belongsTo(ParentModel::class, 'parent_id'); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function refunds() { return $this->hasMany(Refund::class); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by'); }

    public function totalRefunded(): float
    {
        return (float) $this->refunds()->where('status', 'processed')->sum('amount');
    }

    public function isFullyRefunded(): bool
    {
        return $this->totalRefunded() >= (float) $this->amount;
    }
}
