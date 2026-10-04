<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use SoftDeletes;

    protected $appends = ['full_name'];

    protected $fillable = [
        'organization_id', 'branch_id', 'user_id', 'teacher_code', 'display_name',
        'avatar_url', 'phone', 'email', 'country_code', 'timezone', 'specialization',
        'qualifications', 'years_of_experience', 'languages', 'bio',
        'status', 'joined_at', 'notes',
    ];

    protected function casts(): array
    {
        return ['joined_at' => 'date'];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function contracts() { return $this->hasMany(TeacherContract::class); }
    public function getFullNameAttribute(): string { return $this->display_name; }
    public function activeContract() { return $this->hasOne(TeacherContract::class)->where('status', 'active')->latestOfMany(); }
    public function rates() { return $this->hasMany(TeacherRate::class); }
    public function currentRate() { return $this->hasOne(TeacherRate::class)->where('effective_from', '<=', now())->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()))->latestOfMany(); }
    public function lessons() { return $this->hasMany(Lesson::class); }
    public function earnings() { return $this->hasMany(TeacherEarning::class); }
    public function payments() { return $this->hasMany(TeacherPayment::class); }
    public function ledgerEntries() { return $this->hasMany(TeacherLedgerEntry::class); }
    public function ratings() { return $this->hasMany(TeacherRating::class); }
    public function averageRating() { return $this->ratings()->avg('rating'); }
    public function ratingsCount() { return $this->ratings()->count(); }

    /** البرامج اللي المعلم مسجّل إنه يدرّسها */
    public function programs()
    {
        return $this->belongsToMany(Program::class, 'program_teacher')
            ->withPivot(['is_primary', 'rate_multiplier', 'notes'])
            ->withTimestamps();
    }
}
