<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $fillable = [
        'name', 'slug', 'legal_name', 'email', 'phone',
        'default_currency', 'default_timezone', 'status',
    ];
}
