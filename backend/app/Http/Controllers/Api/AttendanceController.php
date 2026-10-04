<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * الحضور والانصراف — كله على الأدمن.
 *
 * مفيش نظام موافقات ولا وردات: الأدمن بيفتح يوم ويمسح لكل موظف.
 * Screen واحد: GET يعرض اليوم كله، POST يسجّل الكل مرة واحدة.
 */
class AttendanceController extends Controller
{
    /**
     * يوم واحد: كل الموظفين النشطين + تسجيلاتهم (إن وُجدت).
     *
     * الـ left join بتجيب السجلات الموجودة بس — الـ employees اللي
     * مالهمش سطر لسه null فيسجّلهم الأدمن.
     */
    public function day(Request $request)
    {
        $date = Carbon::parse($request->input('date') ?? today())->startOfDay();

        $records = DB::table('attendance_records')
            ->whereDate('date', $date->toDateString())
            ->get()
            ->keyBy('employee_id');

        // الموظفين اللي ليهم دوام — المتطوعين مالهمش حضور
        $employees = Employee::query()
            ->whereIn('status', ['active', 'on_leave'])
            ->whereNotIn('employment_type', ['volunteer'])
            ->orderBy('department')
            ->orderBy('name')
            ->get();

        $rows = $employees->map(function ($employee) use ($records, $date) {
            $r = $records->get($employee->id);

            return [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'job_title' => $employee->job_title,
                    'department' => $employee->department,
                    'employment_type' => $employee->employment_type,
                ],
                'record' => $r ? [
                    'id' => $r->id,
                    'status' => $r->status,
                    'check_in' => $r->check_in,
                    'check_out' => $r->check_out,
                    'late_minutes' => (int) $r->late_minutes,
                    'worked_hours' => (float) $r->worked_hours,
                    'notes' => $r->notes,
                ] : null,
            ];
        });

        return response()->json([
            'date' => $date->toDateString(),
            'weekday' => $date->translatedFormat('l'),
            'is_friday' => $date->dayOfWeek === Carbon::FRIDAY,
            'summary' => $this->summarize($records, $employees->count()),
            'rows' => $rows,
        ]);
    }

    /**
     * تسجيل حضور ليوم — كل الموظفين مرة واحدة.
     *
     * Body: { date, records: [{ employee_id, status, check_in, ... }] }
     *
     * استخدمنا upsert واحد على (employee_id, date) — التسجيل مرتين
     * بيحدّث مش يضيف سطر تاني.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date',
            'records' => 'required|array|min:1',
            'records.*.employee_id' => 'required|exists:employees,id',
            'records.*.status' => 'required|in:present,absent,late,on_leave,half_day',
            'records.*.check_in' => 'nullable|date_format:H:i',
            'records.*.check_out' => 'nullable|date_format:H:i',
            'records.*.notes' => 'nullable|string',
        ]);

        $date = Carbon::parse($data['date'])->startOfDay();
        $markedBy = $request->user()->id;
        $organizationId = $request->user()->organization_id ?? 1;

        $saved = 0;

        DB::transaction(function () use ($data, $date, $markedBy, $organizationId, &$saved) {
            foreach ($data['records'] as $row) {
                $checkIn = $row['check_in'] ?? null;
                $checkOut = $row['check_out'] ?? null;

                $workedHours = $this->hoursBetween($checkIn, $checkOut, $row['status']);

                $lateMinutes = (int) ($row['late_minutes'] ?? 0);
                if ($row['status'] === 'late' && $lateMinutes === 0) {
                    $lateMinutes = 15;
                }

                $existing = AttendanceRecord::where('employee_id', $row['employee_id'])
                    ->whereDate('date', $date->toDateString())
                    ->first();

                $payload = [
                    'status' => $row['status'],
                    'check_in' => $checkIn,
                    'check_out' => $checkOut,
                    'late_minutes' => $lateMinutes,
                    'worked_hours' => $workedHours,
                    'notes' => $row['notes'] ?? null,
                    'marked_by' => $markedBy,
                    'updated_at' => now(),
                ];

                if ($existing) {
                    $existing->update($payload);
                } else {
                    AttendanceRecord::create($payload + [
                        'organization_id' => $organizationId,
                        'employee_id' => $row['employee_id'],
                        'date' => $date->toDateString(),
                        'created_at' => now(),
                    ]);
                }

                $saved++;
            }
        });

        app(\App\Services\AuditLogService::class)->log(
            'create', 'attendance', 0,
            null,
            ['date' => $date->toDateString(), 'marked' => $saved],
            $request,
        );

        return response()->json([
            'message' => "تم تسجيل {$saved} موظف",
            'date' => $date->toDateString(),
            'saved' => $saved,
        ]);
    }

    /**
     * شهر كامل موظف واحد — شبكة أيام × حالات.
     * عشان الأدمن يشوف شهره في شاشة واحدة.
     */
    public function month(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'month' => 'nullable|date_format:Y-m',
        ]);

        $month = isset($data['month'])
            ? Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth()
            : now()->startOfMonth();

        $end = $month->copy()->endOfMonth();

        $records = AttendanceRecord::where('employee_id', $employee->id)
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

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'name' => $employee->name,
                'job_title' => $employee->job_title,
            ],
            'month' => $month->format('Y-m'),
            'days' => $days,
            'stats' => $this->monthStats($records),
        ]);
    }

    /**
     * ملخص شهر لموظف: كام يوم شغال وكام غايب.
     * الأساس هنا لو حبيت تحسب منه المرتب.
     */
    public function monthStats($records): array
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

    /** إحصائيات اليوم الحالي */
    private function summarize($records, int $totalEmployees): array
    {
        $byStatus = [];
        $marked = 0;

        foreach ($records as $r) {
            $byStatus[$r->status] = ($byStatus[$r->status] ?? 0) + 1;
            $marked++;
        }

        return [
            'total' => $totalEmployees,
            'marked' => $marked,
            'unmarked' => max(0, $totalEmployees - $marked),
            'by_status' => $byStatus,
        ];
    }

    /**
     * الساعات بين وقتين.
     * نص اليوم = ٤ ساعات (نص الدوام).
     */
    private function hoursBetween(?string $in, ?string $out, string $status): float
    {
        if ($status === 'half_day') {
            return 4.0;
        }

        if ($status !== 'present' && $status !== 'late') {
            return 0.0;
        }

        if (!$in || !$out) {
            return 0.0;
        }

        [$ih, $im] = array_map('intval', explode(':', $in));
        [$oh, $om] = array_map('intval', explode(':', $out));

        $start = ($ih * 60) + $im;
        $end = ($oh * 60) + $om;

        // وردية تعدّت نص الليل — بنعتبرها يوم تاني
        if ($end < $start) {
            $end += 24 * 60;
        }

        return round(($end - $start) / 60, 2);
    }
}
