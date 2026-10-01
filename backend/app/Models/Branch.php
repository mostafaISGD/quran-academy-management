<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'code', 'timezone', 'currency',
        'phone', 'address', 'status',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
