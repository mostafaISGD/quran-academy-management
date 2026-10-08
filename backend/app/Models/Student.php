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

    /**
     * ⭐ عضوياته في **المجموعات**.
     *
     * السبب:Student مالوش حالة «في مجموعة» — الحالة في
     * `group_members.status`. من غير العلاقة دي، صفحة الطلاب
     * مش هتعرف مين «طالب مجموعة» ومين خاص.
     *
     * ⚠️ `hasMany` مش `belongsToMany` — العضوية عندها تاريخ
     * (دخل/خرج)، فالسطر بيفضل بعد ما يخرج.
     */
    public function groupMemberships() { return $this->hasMany(GroupMember::class); }

    /**
     * ⭐ العضويات **النشطة** بس — اللي بتظهر كشارة في صفحة
     * الطلاب وفي شاشة النقل.
     *
     * منفصلة عن `groupMemberships` عن قصد: دي بتطلع كل
     * التاريخ (دخل وخرج)، والدي محتاجين النشط بس. لازم نلخبط
     * نحسبهم غلط — «طالب في مجموعة» لازم ما يكونش اللي خرج
     * من شهر.
     */
    public function activeGroupMemberships() { return $this->hasMany(GroupMember::class)->where('status', 'active'); }
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
