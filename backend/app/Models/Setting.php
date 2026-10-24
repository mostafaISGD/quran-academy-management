<?php

namespace App\Models;

use App\Casts\SettingValue;
use App\Services\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐⭐ **PR 3.12 — جدول الإعدادات: كتابة مصرّفة وسجل نسخ.**
 *
 * ⭐ `set` دلوقتي:
 *
 * - **ضمن معامل قاعدة واحدة** (`DB::transaction`) — الكتابة
 *   وسجل النسخ مع بعض.
 * - **سجل نسخ** (`setting_versions`): قديم/جديد/فَاعِل — ولن
 *   يسجّل نسخة لو القيمة نفسها (كتابة Idempotent).
 * - **إبطال cache** — الخدمة تقرأ من السجل مع cache؛ الكتابة
 *   بتُسقطه (`Settings::forget`).
 *
 * ⭐ **وما اختلفت القراءة عن القديم**: الـ cast قد كان `array` — أي
 * قيمة نصية (`EGP`) تقرأ `json_decode('EGP')` = **null**!
 * الآن الـ cast المسَؤول `App\Casts\SettingValue` — نص = نص،
 * رقم = رقم، مصفوفة = مصفوفة.
 */
class Setting extends Model
{
    protected $fillable = ['organization_id', 'key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => SettingValue::class,
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public static function get(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        /**
         * ⭐ القراءة **مؤسسية** — الجدول مؤسسِيًا بالكامل (العمود
         * `organization_id` NOT NULL)، فمؤسسة null تقرأ مفيش صف.
         */
        $query = static::query()->where('key', $key);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $setting = $query->first();

        return $setting?->value ?? $default;
    }

    /**
     * ⭐ الكتابة + سجل نسخ (قديم/جديد/فَاعِل) + إبطال cache —
     * ضمن معامل قاعدة واحدة.
     *
     * ⭐ `organizationId` **إلزامي**: الجدول مؤسسِيًا بالكامل،
     * فالكتابة بلا مؤسسة = خطأ برمجي (ما نخِن شيا قيمة مشتركة).
     *
     * @param  int|null  $actorId  المستخدم الحاصل التغيير — للسجل.
     * @param  bool  $logVersion  السجلّ الإفتراضي true — يُنْكَر
     *                             false من الرجوع (rollback) حتى
     *                             سلسلة النسخ تبقى نظيفة.
     */
    public static function set(
        string $key,
        mixed $value,
        ?int $organizationId = null,
        ?int $actorId = null,
        bool $logVersion = true,
    ): void {
        if ($organizationId === null) {
            throw new \InvalidArgumentException('كتابة إعداد تحتاج مؤسسة — الجدول مؤسِّسي بالكامل');
        }

        DB::transaction(function () use ($key, $value, $organizationId, $actorId, $logVersion) {
            $row = static::query()
                ->where('key', $key)
                ->where('organization_id', $organizationId)
                ->first();

            $previous = $row?->value;

            // ⭐ نفس القيمة = مفيش نسخة جديدة ولا كتابة
            // (idempotent — cache ما يتأثر والتاريخ ما يتمليش
            // سطور مكرّرة).
            if ($row !== null && self::sameEncoded($previous, $value)) {
                return;
            }

            static::updateOrCreate(
                ['key' => $key, 'organization_id' => $organizationId],
                ['value' => $value],
            );

            if ($logVersion) {
                SettingVersion::create([
                    'organization_id' => $organizationId,
                    'key' => $key,
                    'old_value' => $previous,
                    'new_value' => $value,
                    'actor_id' => $actorId,
                ]);
            }

            Settings::forget($key, $organizationId);
        });
    }

    /**
     * ⭐ حذف صف **مؤسسة واحدة فقط** — القديم كان بيمسح أكاديمية
     * تانية (بلا نطاق مؤسسة) لمفتاح واحد. + نسخة (قديم → null).
     *
     * @return bool هل اتحذف صف فعلًا؟
     */
    public static function forget(string $key, int $organizationId, ?int $actorId = null): bool
    {
        return (bool) DB::transaction(function () use ($key, $organizationId, $actorId) {
            $row = static::query()
                ->where('key', $key)
                ->where('organization_id', $organizationId)
                ->first();

            if ($row === null) {
                return false;
            }

            SettingVersion::create([
                'organization_id' => $organizationId,
                'key' => $key,
                'old_value' => $row->value,
                'new_value' => null,
                'actor_id' => $actorId,
            ]);

            $row->delete();
            Settings::forget($key, $organizationId);

            return true;
        });
    }

    /**
     * ⭐ مقارنة قنونية للقيمتين: بواسطة json الترميز (حتى
     * مصفوفة 5 و'5' ما يخصهُس وا لا أثر جارِ).
     */
    private static function sameEncoded(mixed $previous, mixed $value): bool
    {
        $encode = function (mixed $v) {
            if (is_array($v)) {
                return json_encode(array_values($v), JSON_UNESCAPED_UNICODE);
            }

            if (is_bool($v)) {
                return json_encode($v);
            }

            if ($v === null) {
                return '';
            }

            return (string) $v;
        };

        return $encode($previous) === $encode($value);
    }
}
