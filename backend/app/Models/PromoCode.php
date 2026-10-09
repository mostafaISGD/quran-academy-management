<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromoCode extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'code', 'name', 'description',
        'type', 'value',
        'max_uses', 'used_count', 'max_uses_per_student',
        'starts_at', 'expires_at',
        'applies_to', 'program_id', 'category',
        'new_students_only', 'is_active', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'max_uses_per_student' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'new_students_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function program() { return $this->belongsTo(Program::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function usages() { return $this->hasMany(PromoCodeUsage::class); }

    /** ⭐ هل الكود صالح للاستخدام دلوقتي؟ */
    public function isValidNow(): bool
    {
        if (! $this->is_active) return false;
        if ($this->starts_at && $this->starts_at->isFuture()) return false;
        if ($this->expires_at && $this->expires_at->isPast()) return false;
        if ($this->max_uses && $this->used_count >= $this->max_uses) return false;
        return true;
    }

    /** ⭐ هل الكود ينطبق على الفاتورة/الطالب دي؟ */
    public function appliesTo(Invoice $invoice): bool
    {
        if (! $this->isValidNow()) return false;

        // نطاق التطبيق
        if ($this->applies_to === 'program' && $this->program_id !== $invoice->program_id) {
            return false;
        }
        if ($this->applies_to === 'category') {
            $plan = $invoice->subscription?->plan;
            if (! $plan || $plan->category !== $this->category) return false;
        }

        // طلاب جدد فقط
        if ($this->new_students_only) {
            $hasPrevious = Invoice::where('student_id', $invoice->student_id)
                ->where('id', '!=', $invoice->id)
                ->exists();
            if ($hasPrevious) return false;
        }

        return true;
    }

    /** ⭐ حساب قيمة الخصم */
    public function calculateDiscount(float $amount): float
    {
        return $this->type === 'percent'
            ? round($amount * $this->value / 100, 2)
            : min($this->value, $amount);
    }

    /** ⭐ هل الطالب استخدم الكود قبل كده؟ */
    public function hasStudentUsed(int $studentId): int
    {
        return $this->usages()->where('student_id', $studentId)->count();
    }

    /** ⭐ هل الطالب يقدر يستخدم الكود تاني؟ */
    public function canStudentUse(int $studentId): bool
    {
        return $this->hasStudentUsed($studentId) < $this->max_uses_per_student;
    }

    /** ⭐ زيادة العداد */
    public function incrementUsage(): void
    {
        $this->increment('used_count');
    }
}