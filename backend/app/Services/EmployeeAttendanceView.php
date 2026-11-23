<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;

class EmployeeAttendanceView
{
    public function month(Employee $employee, ?string $selectedMonth): array
    {
        $month = $selectedMonth !== null
            ? Carbon::createFromFormat('!Y-m', $selectedMonth)->startOfMonth()
            : now()->startOfMonth();

        $end = $month->copy()->endOfMonth();

        $records = AttendanceRecord::where('employee_id', $employee->id)
            ->where('organization_id', $employee->organization_id)
            ->whereBetween('date', [$month->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn ($r) => $r->date->toDateString());

        $days = [];
        $cursor = $month->copy();

        while ($cursor->lte($end)) {
            $key = $cursor->toDateString();
            $r = $records->get($key);

            $days[] = [
                'date' => $key,
                'day' => $cursor->day,
                'weekday' => $cursor->dayOfWeek,
                'is_friday' => $cursor->dayOfWeek === Carbon::FRIDAY,
                'future' => $cursor->isFuture(),
                'record' => $r ? [
                    'status' => $r->status,
                    'check_in' => $r->check_in,
                    'check_out' => $r->check_out,
                    'worked_hours' => (float) $r->worked_hours,
                ] : null,
            ];

            $cursor->addDay();
        }

        return [
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
            ],
            'month' => $month->format('Y-m'),
            'days' => $days,
            'stats' => $this->monthStats($records),
        ];
    }

    /**
     * ملخص شهر لموظف: كام يوم شغال وكام غايب.
     * الأساس هنا لو حبيت تحسب منه المرتب.
     */
    private function monthStats($records): array
    {
        $byStatus = [];
        $workedHours = 0.0;

        foreach ($records as $r) {
            $byStatus[$r->status] = ($byStatus[$r->status] ?? 0) + 1;
            $workedHours += (float) $r->worked_hours;
        }

        $present = ($byStatus['present'] ?? 0)
            + ($byStatus['late'] ?? 0)
            + ($byStatus['half_day'] ?? 0);

        $absent = $byStatus['absent'] ?? 0;
        $leave = $byStatus['on_leave'] ?? 0;

        return [
            'by_status' => $byStatus,
            'present' => $present,
            'absent' => $absent,
            'on_leave' => $leave,
            'unrecorded' => 0,
            'worked_hours' => round($workedHours, 2),
            // النسبة من الأيام المسجّلة بس — مش من أيام الشهر كلها
            'attendance_rate' => ($present + $absent + $leave) > 0
                ? round($present / ($present + $absent + $leave) * 100, 1)
                : null,
        ];
    }
}
