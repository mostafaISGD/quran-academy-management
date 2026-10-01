<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SubscriptionPlan extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'program_id', 'name', 'billing_type', 'price',
        'currency', 'lessons_count', 'lesson_duration_minutes', 'duration_days',
        'status', 'description',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }
}
