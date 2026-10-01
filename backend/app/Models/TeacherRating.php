<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherRating extends Model
{
    protected $fillable = [
        'teacher_id', 'student_id', 'parent_id', 'rating', 'comment',
    ];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function student() { return $this->belongsTo(Student::class); }
    public function parent() { return $this->belongsTo(ParentModel::class); }
}
