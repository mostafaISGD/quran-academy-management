<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐⭐ **PR 4.1 — تسليم الإشعار (كل قناة خارجية = صف هنا).**
 *
 * ⭐⭐ **القاعدة الذهبية — ثابتة ولا نغاها طالما أثبتها
 * الاختبار**: `sent_at` لا يُكتب إلا بعد إرسال فعلي.
 * وضع `sent` بلا دليل تسليم = علة PR القديمة (معناه ترجع
 * الاختبارات في وجهك).
 *
 * سلة الحالات:
 *
 * - `queued` — على الطابور أو بانتظار نافذة (ساعات الهدوء).
 * - `sent`   — مرّ عبر مزود مع `external_id` وساعة `sent_at`.
 * - `failed` — خرج من محاولة بفشل، والنص موجز في `error`،
 *   و`attempts` يشرح عدد العمليات.
 */
class NotificationDelivery extends Model
{
    protected $fillable = [
        'organization_id', 'notification_id', 'user_id', 'student_id',
        'event', 'channel', 'payload', 'status', 'attempts',
        'provider', 'external_id', 'error', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'sent_at' => 'datetime',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function notification()
    {
        return $this->belongsTo(Notification::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
