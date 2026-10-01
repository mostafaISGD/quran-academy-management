<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ParentModel extends Model
{
    use SoftDeletes;

    protected $table = 'parents';

    protected $fillable = [
        'organization_id', 'user_id', 'name', 'phone', 'alternate_phone',
        'email', 'country_code', 'preferred_language', 'notes', 'status',
    ];

    protected $appends = ['relationships'];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function students() {
        return $this->belongsToMany(Student::class, 'student_parents', 'parent_id', 'student_id')
            ->withPivot(['relationship', 'is_primary', 'can_manage', 'can_pay', 'can_receive_notifications'])
            ->withTimestamps();
    }
    public function payments() { return $this->hasMany(Payment::class); }

    /** كل صلات القرابة المميّزة — مفيدة للفلترة والعرض */
    public function relationshipList(): array
    {
        return $this->students
            ->pluck('pivot.relationship')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function getRelationshipsAttribute(): array
    {
        return $this->relationshipList();
    }
}
