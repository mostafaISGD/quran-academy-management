<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuranSurah extends Model
{
    protected $fillable = ['number', 'name_ar', 'name_en', 'ayah_count', 'revelation_type'];
}
