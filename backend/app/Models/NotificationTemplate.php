<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **PR 4.1 — قالب الإشعار: النص العربي لكل (حدث × قناة).**
 *
 * الكتل داخل القالب تحمل عناصر إحلال بصيغة `:key` — الـ
 * `NotificationService` يعملها من بيانات الحدث قبل الجملة.
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'organization_id', 'event', 'channel', 'language',
        'title_template', 'body_template',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
