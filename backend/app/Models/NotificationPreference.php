<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **PR 4.1 — تفضيل المستخدم لكل قناة.**
 *
 * ⭐ بلا صف = القناة **مفعّلة** افتراضياً. الصف الموجود بـ
 * `is_enabled` = false يمنع بناء تسليماتها للمستخدم إطلاقاً —
 * مافيش «هتّبعتلك ورغمكراك تكرهها».
 */
class NotificationPreference extends Model
{
    protected $fillable = [
        'organization_id', 'user_id', 'channel', 'is_enabled',
    ];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
