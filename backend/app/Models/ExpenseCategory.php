<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    protected $fillable = ['organization_id', 'name', 'slug', 'description', 'status'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function expenses() { return $this->hasMany(Expense::class); }
}
