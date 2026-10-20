<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class PromoCodeUsage extends Model
{
    // ⭐ الجدول فيه `applied_at` بس — ماعندوش من `created_at/updated_at`
    public $timestamps = false;

    protected $fillable = [
        'organization_id', 'promo_code_id', 'invoice_id', 'student_id',
        'discount_amount', 'original_amount', 'final_amount', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount' => MoneyCast::class,
            'original_amount' => MoneyCast::class,
            'final_amount' => MoneyCast::class,
            'applied_at' => 'datetime',
        ];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function promoCode() { return $this->belongsTo(PromoCode::class); }
    public function invoice() { return $this->belongsTo(Invoice::class); }
    public function student() { return $this->belongsTo(Student::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}