<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['organization_id', 'key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public static function get(string $key, mixed $default = null, ?int $organizationId = null): mixed
    {
        /**
         * ⭐ PR 2.8 — القراءة **مؤسسية** (لو البعت org): settings
         * جدول مشترك، وقيمة مؤسسة لاختيارية تهد إلى مؤسسة تانية.
         */
        $query = static::query()->where('key', $key);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        $setting = $query->first();

        return $setting?->value ?? $default;
    }

    public static function set(string $key, mixed $value, ?int $organizationId = null): void
    {
        static::updateOrCreate(
            ['key' => $key, 'organization_id' => $organizationId],
            ['value' => $value]
        );
    }
}
