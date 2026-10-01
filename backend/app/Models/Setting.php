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

    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::where('key', $key)->first();
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
