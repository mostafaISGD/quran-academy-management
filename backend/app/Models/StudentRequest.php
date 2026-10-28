<?php

namespace App\Models;

use App\Enums\StudentRequestStatus;
use App\Enums\StudentRequestType;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐⭐ **طلب ولي أمر (PR 4.5).**
 *
 * ⭐ السجل بيحفظ **مين طلب إيه وليه**، والقرار ومين أخده. التطبيق
 * الفعلي مش هنا — الموافقة قرار مسجَّل، والتغيير بيمر على الخدمات
 * الإدارية (`LessonService` / `SubscriptionPauseService`).
 */
class StudentRequest extends Model
{
    protected $fillable = [
        'organization_id', 'student_id', 'parent_id', 'lesson_id',
        'subscription_id', 'type', 'status', 'requested_date',
        'reason', 'admin_notes', 'reviewed_by', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => StudentRequestType::class,
            'status' => StudentRequestStatus::class,
            'requested_date' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function parent()
    {
        return $this->belongsTo(ParentModel::class, 'parent_id');
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }

    public function reviewedBy()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
