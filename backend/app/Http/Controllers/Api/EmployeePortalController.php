<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Services\EmployeeAttendanceView;
use App\Services\EmployeePortalView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeePortalController extends Controller
{
    public function attendance(Request $request, EmployeeAttendanceView $attendance): JsonResponse
    {
        $employee = $this->employee($request);
        $data = $request->validate(['month' => 'nullable|date_format:Y-m']);

        return response()->json($attendance->month($employee, $data['month'] ?? null));
    }

    public function salaries(Request $request, EmployeePortalView $portal): JsonResponse
    {
        return response()->json($portal->salaries($this->employee($request)));
    }

    private function employee(Request $request): Employee
    {
        $user = $request->user();
        $employee = $user->employee()->where('organization_id', $user->organization_id)->first();
        abort_if($employee === null, 404, 'لا يوجد ملف موظف مرتبط بهذا الحساب.');

        return $employee;
    }
}
