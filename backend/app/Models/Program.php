<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Program extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'slug', 'description', 'status',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function levels()
    {
        return $this->hasMany(Level::class)->orderBy('sort_order');
    }

    public function subscriptionPlans()
    {
        return $this->hasMany(SubscriptionPlan::class);
    }
}
