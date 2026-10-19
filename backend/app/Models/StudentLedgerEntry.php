<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class StudentLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'student_id', 'invoice_id', 'payment_id', 'type', 'debit', 'credit',
        'balance_after', 'currency', 'description', 'reference_type',
        'reference_id', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'debit' => MoneyCast::class,
            'credit' => MoneyCast::class,
            'balance_after' => MoneyCast::class,
            'created_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
