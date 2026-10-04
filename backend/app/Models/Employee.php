<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * موظف — هوية الشخص داخل الأكاديمية.
 *
 * الفصل المهم: الموظف هو السجل الإداري (وظيفة، قسم، مدير، حالة)،
 * بينما حساب الدخول والصلاحيات شيء منفصل مرتبط بـ user_id.
 */
class Employee extends Model
{
    use SoftDeletes;

    /**
     * الخصائص المحسوبة لازم تكون في $appends عشان تظهر في JSON.
     * من غيرها الـ accessor شغال جوه PHP بس مش بيترجم في الرد —
     * فالدور والصلاحيات كانت هتطلع فاضية في الواجهة.
     */
    protected $appends = ['full_name', 'role', 'role_label', 'account_status', 'last_login_at', 'has_account'];

    /**
     * بنحمّل الأدوار مع الموظف عشان الـ accessors اللي بتقرأ منها
     * (role / role_label) ما تعملش query لكل صف في القائمة.
     */
    protected $with = ['user.roles'];

    protected $fillable = [
        'organization_id', 'user_id',
        'name', 'phone', 'country_code', 'email',
        'gender', 'date_of_birth', 'nationality', 'address', 'photo_url',
        'job_title', 'department', 'employment_type', 'manager_id',
        'joined_at', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joined_at' => 'date',
        ];
    }

    // ===== العلاقات =====

    public function organization() { return $this->belongsTo(Organization::class); }

    /** حساب الدخول المرتبط — ممكن يكون null */
    public function user() { return $this->belongsTo(User::class); }

    /** المدير المباشر */
    public function manager() { return $this->belongsTo(Employee::class, 'manager_id'); }

    /** المرؤوسين */
    public function subordinates() { return $this->hasMany(Employee::class, 'manager_id'); }

    /** سجل النشاط — عبر حساب الدخول */
    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class, 'user_id', 'user_id');
    }

    // ===== خصائص مساعدة =====

    public function getFullNameAttribute(): string
    {
        return $this->name;
    }

    /** الدور في النظام (من حساب الدخول) */
    public function getRoleAttribute(): ?string
    {
        return $this->user?->roles->first()?->name;
    }

    /** اسم الدور بالعربي للعرض */
    public function getRoleLabelAttribute(): string
    {
        return match ($this->role) {
            'admin' => 'مدير النظام',
            'teacher' => 'معلم',
            'parent' => 'ولي أمر',
            'accountant' => 'محاسب',
            'supervisor' => 'مشرف',
            'receptionist' => 'موظف استقبال',
            null => 'بدون دور',
            default => $this->role,
        };
    }

    /** هل الموظف عنده حساب دخول أصلاً؟ */
    public function getHasAccountAttribute(): bool
    {
        return $this->user_id !== null;
    }

    /** حالة حساب الدخول لو موجود */
    public function getAccountStatusAttribute(): ?string
    {
        return $this->user?->status;
    }

    /** آخر دخول — من حساب الدخول */
    public function getLastLoginAtAttribute()
    {
        return $this->user?->last_login_at;
    }
}
