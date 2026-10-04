<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ربط معلم ببرنامج — «المعلم ده يقدر يدرّس البرنامج ده».
 *
 * تعريف مش جدول عمل: الجدول بيتبنى من teacher_schedules + lessons.
 */
class ProgramTeacher extends Model
{
    protected $table = 'program_teacher';

    protected $fillable = [
        'program_id', 'teacher_id', 'is_primary', 'rate_multiplier', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'rate_multiplier' => 'float',
        ];
    }

    public function program() { return $this->belongsTo(Program::class); }
    public function teacher() { return $this->belongsTo(Teacher::class); }
}
