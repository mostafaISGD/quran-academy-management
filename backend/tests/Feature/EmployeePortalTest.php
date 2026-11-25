<?php

namespace Tests\Feature;

use App\Models\EmployeePayrollLine;
use App\Support\Money;
use Tests\TestCase;

class EmployeePortalTest extends TestCase
{
    public function test_employee_can_only_read_their_own_attendance_and_published_salary(): void
    {
        $user = $this->makeUserWithRole('employee', ['employee.portal']);
        $employee = $this->makeEmployee(['user_id' => $user->id]);
        $other = $this->makeEmployee();
        $this->makeAttendance($employee, '2026-03-02');
        $this->makeAttendance($other, '2026-03-03');
        $period = $this->makePeriod('2026-03-01', '2026-03-31');
        $draftPeriod = $this->makePeriod('2026-04-01', '2026-04-30');

        foreach ([[$employee, $period, 'paid'], [$other, $period, 'approved'], [$employee, $draftPeriod, 'draft']] as [$profile, $payroll, $status]) {
            EmployeePayrollLine::create([
                'organization_id' => $this->org->id,
                'employee_id' => $profile->id,
                'payroll_period_id' => $payroll->id,
                'period_start' => $payroll->start_date,
                'period_end' => $payroll->end_date,
                'currency' => 'KWD',
                'amount' => Money::of(12345, 'KWD'),
                'status' => $status,
            ]);
        }

        $this->getJsonAs("/api/employee/attendance?month=2026-03&employee_id={$other->id}", $user)
            ->assertOk()
            ->assertJsonPath('employee.id', $employee->id)
            ->assertJsonPath('days.1.record.status', 'present')
            ->assertJsonPath('days.2.record', null);
        $this->getJsonAs("/api/employee/salaries?employee_id={$other->id}", $user)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.amount', '12.345')
            ->assertJsonPath('data.0.status', 'paid');
        $this->getJsonAs("/api/payroll/periods/{$period->id}/report", $user)->assertForbidden();
        $this->getJsonAs("/api/employees/{$other->id}/attendance-month", $user)->assertForbidden();
    }

    public function test_employee_portal_requires_permission_and_a_linked_profile(): void
    {
        $unlinked = $this->makeUserWithRole('employee', ['employee.portal']);
        $this->getJsonAs('/api/employee/attendance', $unlinked)->assertNotFound();
        $withoutPermission = $this->makeUser();
        $this->makeEmployee(['user_id' => $withoutPermission->id]);
        $this->getJsonAs('/api/employee/salaries', $withoutPermission)->assertForbidden();
    }
}
