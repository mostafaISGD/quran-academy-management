<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePayrollLine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EmployeePortalView
{
    public function salaries(Employee $employee): LengthAwarePaginator
    {
        return EmployeePayrollLine::where('employee_id', $employee->id)
            ->where('organization_id', $employee->organization_id)
            ->whereIn('status', ['approved', 'paid'])
            ->with('period:id,start_date,end_date')
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->paginate(12)
            ->through(fn (EmployeePayrollLine $line) => [
                'id' => $line->id,
                'period_start' => ($line->period_start ?? $line->period->start_date)->toDateString(),
                'period_end' => ($line->period_end ?? $line->period->end_date)->toDateString(),
                'amount' => $line->amount,
                'currency' => $line->currency,
                'status' => $line->status,
                'paid_at' => $line->paid_at?->toDateString(),
            ]);
    }
}
