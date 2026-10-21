<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **العطلة (PR 3.6).**
 *
 * ⭐ `official` — عطلة رسمية تخص كل الأكاديميات
 * (`organization_id = null`). `academy` — إجازة الأكاديمية.
 *
 * ⭐ **ليه جدول مستقل مش `settings`؟** لأن العطلة **تقويم**
 * (تاريخ له معنى مدرسي/قانوني) — والجدول بيخلّيها قابلة
 * للاستعلام والتصدير والعرض، لا مجرد نص.
 */
class Holiday extends Model
{
    protected $fillable = [
        'date', 'name', 'type', 'organization_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    /**
     * ⭐ هل التاريخ ده عطلة (للمؤسسة دي)؟
     */
    public static function findFor(string $date, ?int $organizationId = null): ?self
    {
        return static::query()
            ->whereDate('date', $date)
            ->where(function ($query) use ($organizationId) {
                $query->where('type', 'official')
                    ->orWhere(function ($q) use ($organizationId) {
                        $q->where('type', 'academy')
                            ->where('organization_id', $organizationId);
                    });
            })
            ->orderByRaw("case type when 'official' then 0 else 1 end")
            ->first();
    }
}
