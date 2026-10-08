<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditNoteApplication extends Model
{
    protected $fillable = [
        'organization_id', 'student_credit_id', 'invoice_id',
        'amount', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected $attributes = ['status' => 'active'];

    public function studentCredit() { return $this->belongsTo(StudentCredit::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function createdBy() { return $this->belongsTo(User::class, 'created_by'); }

    public function scopeActive($query) { return $query->where('status', 'active'); }
}
