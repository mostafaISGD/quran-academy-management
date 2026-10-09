<?php

namespace App\Models;

use App\Casts\Piastres;
use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'description', 'item_type', 'quantity',
        'unit_price', 'total', 'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => Piastres::class,
            'total' => Piastres::class,
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
