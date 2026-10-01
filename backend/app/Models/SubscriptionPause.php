<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPause extends Model
{
    protected $fillable = [
        'subscription_id', 'start_date', 'end_date', 'days',
        'reason', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
