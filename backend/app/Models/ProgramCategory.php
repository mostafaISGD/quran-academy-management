<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * تصنيف برنامج (النوع، المجال، الفئة، المستوى...).
 *
 * جدول مش enum: الأكاديمية بتضيف تصنيفات جديدة من غير تعديل كود.
 */
class ProgramCategory extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'slug', 'icon', 'sort_order', 'description',
    ];

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'program_category')
            ->withPivot('label')
            ->withTimestamps();
    }
}
