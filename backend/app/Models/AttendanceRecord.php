<?php

namespace App\Models;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * حضور موظف في يوم — بالساعات المرنة.
 *
 * سجل واحد لكل (موظف + يوم) — الـ unique في الجدول بيضمن كده،
 * فالتسجيل مرتين على نفس اليوم بيعمل تحديث مش سطر تاني.
 *
 * ⭐ `worked_hours` هو **مصدر الأجر** — الأدمن بيكتبه بنفسه.
 * مفيش اشتقاق من وقت دخول وخروج، لأن مفيش دوام ثابت نقيس منه.
 * وده السبب إن مفيش نسخة ثانية من الحساب في الـ frontend: السيرفر
 * بيقرر، والشاشة بتعرض.
 */
class AttendanceRecord extends Model
{
    protected $fillable = [
        'organization_id', 'employee_id', 'date',
        'status', 'check_in', 'check_out',
        'worked_hours', 'notes', 'marked_by',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'worked_hours' => 'float',
        ];
    }

    /**
     * التاريخ بيتخزّن نضيف (Y-m-d) مش مع الوقت.
     *
     * من غير ده SQLite بيخزّنه `2026-11-18 00:00:00`، وأي حد يقرأه
     * raw ويحطه في URL بيكسر المسار بسبب المسافة.
     */
    protected function serializeDate(DateTimeInterface $date): string
    {
        return $date->format('Y-m-d');
    }

    public function employee() { return $this->belongsTo(Employee::class); }
    public function markedBy() { return $this->belongsTo(User::class, 'marked_by'); }

    // ===== تسميات =====

    public static function statusLabel(?string $status): string
    {
        return match ($status) {
            'present' => 'حاضر',
            'absent' => 'غائب',
            'late' => 'متأخر',
            'on_leave' => 'إجازة',
            'half_day' => 'نصف يوم',
            default => 'لم يُسجّل',
        };
    }

    /** الحالة considered = الموظف موجود فعلياً (حاضر/متأخر/نص يوم) */
    public function isCountedAsWorking(): bool
    {
        return in_array($this->status, ['present', 'late', 'half_day'], true);
    }
}
