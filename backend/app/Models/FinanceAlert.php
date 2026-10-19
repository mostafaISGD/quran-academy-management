<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceAlert extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id', 'severity', 'kind', 'entity_type', 'entity_id',
        'currency', 'message', 'details', 'resolved_at', 'resolved_by',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected $attributes = [
        'severity' => 'warning',
    ];

    /** ⭐ التنبيهات المفتوحة — التسوية ما بتكررش فاتها مفتوح */
    public function scopeOpen($query)
    {
        return $query->whereNull('resolved_at');
    }
}
