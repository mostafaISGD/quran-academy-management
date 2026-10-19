<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⭐ PR 2.6 — عدّاد تسلسلي للأرقام.
 *
 * ⭐ المفتاح بيضم السنة: `invoice:2026`.
 *
 * @see \App\Services\InvoiceService::nextNumber
 */
class NumberSequence extends Model
{
    protected $fillable = [
        'key',
        'last_value',
    ];

    protected function casts(): array
    {
        return [
            'last_value' => 'integer',
        ];
    }
}