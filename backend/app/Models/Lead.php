<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'branch_id', 'full_name', 'phone', 'email',
        'country_code', 'student_age', 'interested_program_id', 'source',
        'assigned_staff_id', 'status', 'notes',
    ];

    public function organization() { return $this->belongsTo(Organization::class); }
    public function branch() { return $this->belongsTo(Branch::class); }
    public function program() { return $this->belongsTo(Program::class, 'interested_program_id'); }
    public function assignedStaff() { return $this->belongsTo(User::class, 'assigned_staff_id'); }
    public function assessments() { return $this->hasMany(Assessment::class); }
}
