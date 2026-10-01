<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Student extends Model
{
    use SoftDeletes;

    protected $appends = ['full_name'];

    protected $fillable = [
        'organization_id', 'branch_id', 'user_id', 'lead_id', 'student_code',
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'gender',
        'country_code', 'timezone', 'phone', 'email', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'date'];
    }

    public function organization() { return $this->belongsTo(Organization::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function lead() { return $this->belongsTo(Lead::class); }
    public function parents() { return $this->belongsToMany(ParentModel::class, 'student_parents', 'student_id', 'parent_id')->withPivot(['relationship', 'is_primary', 'can_manage', 'can_pay', 'can_receive_notifications'])->withTimestamps(); }
    public function phones() { return $this->hasMany(StudentPhone::class); }
    public function subscriptions() { return $this->hasMany(Subscription::class); }
    public function activeSubscription() { return $this->hasOne(Subscription::class)->where('status', 'active')->latestOfMany(); }
    public function lessons() { return $this->hasMany(Lesson::class); }
    public function creditAccounts() { return $this->hasMany(LessonCreditAccount::class); }
    public function goals() { return $this->hasMany(StudentGoal::class); }
    public function invoices() { return $this->hasMany(Invoice::class); }
    public function payments() { return $this->hasMany(Payment::class); }
    public function ledgerEntries() { return $this->hasMany(StudentLedgerEntry::class); }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->middle_name} {$this->last_name}");
    }
}
