<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Level extends Model
{
    protected $fillable = [
        'program_id', 'name', 'code', 'sort_order', 'description', 'status',
    ];

    public function program()
    {
        return $this->belongsTo(Program::class);
    }
}
