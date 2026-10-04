<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes, HasRoles;

    protected $fillable = [
        'organization_id', 'name', 'email', 'phone', 'password',
        'timezone', 'locale', 'status', 'is_parent', 'email_verified_at', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'student', 'teacher', 'parentProfile',
    ];

    /**
     * Prevent circular references during debugging/backtrace generation.
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_parent' => 'boolean',
        ];
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function student()
    {
        // return $this->hasOne(Student::class)->without('user');
        return $this->hasOne(Student::class);
    }

    public function teacher()
    {
        // return $this->hasOne(Teacher::class)->without('user');
        return $this->hasOne(Teacher::class);
    }

    public function parentProfile()
    {
        // return $this->hasOne(ParentModel::class)->without('user');
        return $this->hasOne(ParentModel::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
