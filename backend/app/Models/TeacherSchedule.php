<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TeacherSchedule extends Model
{
    /** 0 = الأحد … 6 = السبت (نفس dayOfWeek في Carbon) */
    public const WEEKDAYS = [
        0 => 'الأحد',
        1 => 'الإثنين',
        2 => 'الثلاثاء',
        3 => 'الأربعاء',
        4 => 'الخميس',
        5 => 'الجمعة',
        6 => 'السبت',
    ];

    public const KINDS = [
        'academy' => 'أكاديمية',
        'external' => 'خارجي',
        'leave' => 'إجازة',
        'personal' => 'شخصي',
    ];

    protected $fillable = [
        'teacher_id', 'weekday', 'starts_at', 'ends_at',
        'kind', 'title', 'notes', 'is_recurring', 'specific_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_recurring' => 'boolean',
            'specific_date' => 'date',
        ];
    }

    protected $appends = ['weekday_label', 'kind_label'];

    public function teacher() { return $this->belongsTo(Teacher::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    public function getWeekdayLabelAttribute(): string
    {
        return self::WEEKDAYS[$this->weekday] ?? '—';
    }

    public function getKindLabelAttribute(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /**
     * هل الوقت ده واقع جوه الفترة دي؟
     *
     * المقارنة كلها بالدقائق من منتصف الليل، والفترة بتعدي midnight
     * (23:00 → 01:00) بتتخيّل ended بتعدّي 1440 عشان المقارنة تظبط.
     */
    public function overlaps(string $fromTime, string $toTime): bool
    {
        $start = $this->toMinutes($this->starts_at);
        $end = $this->toMinutes($this->ends_at);
        $from = $this->toMinutes($fromTime);
        $to = $this->toMinutes($toTime);

        // فترة بتعدي منتصف الليل
        $wraps = $end <= $start;
        if ($wraps) {
            $end += 24 * 60;
            // والفترة المطلوبة كمان بتعدي midnight
            if ($to <= $from) $to += 24 * 60;
        }

        return $from < $end && $to > $start;
    }

    /** عدد الدقائق من منتصف الليل حتى الوقت المعطى */
    private function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return ($h * 60) + $m;
    }

    /**
     * المدة بالدقائق. لو عدّى منتصف الليل (23:00 → 01:00) رجّع 120 لا -1320.
     */
    public function durationMinutes(): int
    {
        $start = $this->toMinutes($this->starts_at);
        $end = $this->toMinutes($this->ends_at);

        return $end > $start ? $end - $start : ($end + (24 * 60)) - $start;
    }
}
