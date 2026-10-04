<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * مستوى/مرحلة داخل البرنامج.
 *
 * الجدول موجود من الأول (٣٠ مستوى على ٦ برامج) — كان ناقص بس和管理
 * وواجهة ليه.
 */
class Level extends Model
{
    protected $fillable = [
        'program_id', 'name', 'code', 'sort_order', 'description', 'status',
    ];

    public function program() { return $this->belongsTo(Program::class); }

    /** الحصص اللي اتعملت في المستوى ده */
    public function lessons() { return $this->hasMany(Lesson::class); }
}
