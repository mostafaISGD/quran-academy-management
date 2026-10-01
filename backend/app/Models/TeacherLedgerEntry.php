<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'teacher_id', 'earning_id', 'payment_id', 'type', 'debit', 'credit',
        'balance_after', 'currency', 'description', 'reference_type',
        'reference_id', 'created_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function earning() { return $this->belongsTo(TeacherEarning::class); }
    public function payment() { return $this->belongsTo(TeacherPayment::class); }
}
