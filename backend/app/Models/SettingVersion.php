<?php

namespace App\Models;

use App\Casts\SettingValue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐⭐⭐ **PR 3.12 — نسخة إعداد (للرجوع والتاريخ).**
 *
 * كل تعديل على إعداد بكتب صف هنا لما **القيمة تغيّر فعلًا**
 * (راجع `Setting::set` — كتابة idempotent بلا نسخة).
 *
 * ⭐ `old_value`/`new_value` محفوظين بالكَست ذات اللي بدو
 * (`App\Casts\SettingValue`) حتى قيمة `EGP` النصية وما تشب.
 *
 * ⭐ **الرجوع للمستخدم**: `POST /settings/{key}/rollback` —
 * بيدوّر على النسخة اللي أنتِجت القيمة الحالية (new_value ==
 * الحالي) وبيرجّع `old_value` (قد تكون null = الافتراضي).
 */
class SettingVersion extends Model
{
    protected $fillable = [
        'organization_id',
        'key',
        'old_value',
        'new_value',
        'actor_id',
    ];

    protected function casts(): array
    {
        return [
            'old_value' => SettingValue::class,
            'new_value' => SettingValue::class,
        ];
    }

    /** @return BelongsTo<Organization, self> */
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, self> */
    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
