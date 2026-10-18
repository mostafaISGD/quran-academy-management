<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * ⭐⭐⭐ دعوة — الحساب الجديد بيتعمل بيها.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐⭐⭐ القاعدة الوحيدة المهمة
 *
 * `token_hash` هو **SHA-256** لتوكن 64 byte عشوائي. **التوكن
 * الخام** بيرجع مرة واحدة بس — في رد الـAPI أو في الإيميل — ومش
 * بيتخزّن في **أي** مكان.
 *
 * ⭐ المعادلة: أي حد يقرا الداتابيز (backup، SQL injection، زميل
 * عنده صلاحية logs) **مش** يقدر يسجّل دخول بحساب حد تاني. التوكن
 * مش عنده.
 *
 * ⭐ **ليه SHA-256 لـ64 byte كافي** ومش bcrypt؟
 *
 *   • المدخل **غير قابل للتخمين** أصلاً: 2^512 احتمال. تخمين
 *     مستحيل رياضياً، فالـhash السريع مافيش ميزة خاطئة عليه.
 *   • التوكن عمره ٧٢ ساعة ومرة واحدة — فمافيش قلق من تخمين
 *     بطيء.
 *   • و`hash_equals` بيدي مقارنة **ثابتة الزمن**.
 *
 * ⭐ `password_hash` (bcrypt/argon) هو للـ**كلمات المرور** اللي
 * المستخدم إنسان اختارها وقابلة للتخمين. التوكن هنا تختاره الآلة.
 *
 * @see \App\Services\InvitationService
 */
class Invitation extends Model
{
    protected $fillable = [
        'email',
        'token_hash',
        'profile_type',
        'profile_id',
        'role_id',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(\Spatie\Permission\Models\Role::class);
    }

    /**
     * ⭐ الملف اللي اتدعى صاحبو.
     *
     * ⭐ `profile_id` **من غير FK على الجدول** — عن قصد: الـ
     * `profile_type` بيحدد الجدول، والداتابيز مش بتعرف تعمل
     * polymorphic FK. و`profile_id` فاضي = دعوة لحساب **مش**
     * مربوط بملف بعد (زي معلم جديد: الملف بيتعمل مع الحساب).
     */
    public function profile(): MorphTo
    {
        return $this->morphTo('profile', 'profile_type', 'profile_id');
    }

    // ═══════════════════════════════════════════════════════════════
    // الحالات
    // ═══════════════════════════════════════════════════════════════

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isUsable(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }

    // ═══════════════════════════════════════════════════════════════
    // Scopes
    // ═══════════════════════════════════════════════════════════════

    public function scopeUsable(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * ⭐⭐⭐ **التجزئة مش على `token_hash` في الـquery.**
     *
     * ⭐ ليه؟ لأن `where('token_hash', $input)` بتقارن نص عادي.
     * والـDB بتعمل المقارنة سطر سطر وبتوقف عند أول اختلاف — فده
     * **timing oracle**: مهاجم بيقيس وقت الرد يقدر يستنتج كم
     * حرف متطابق.
     *
     * ⭐ الحل: **بنجزّئ في PHP** (`InvitationService::hashToken`) وبنقارن
     * بـ`hash_equals`. المدخل مش قابل للتخمين أصلاً فده مش
     * ألترا-حذر — بس صح.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at');
    }
}
