<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index()
    {
        $employees = User::whereHas('roles', fn ($q) => $q->where('name', '!=', 'teacher'))
            ->whereHas('roles', fn ($q) => $q->where('name', '!=', 'student'))
            ->get();

        return response()->json(['data' => $employees->map(fn ($e) => [
            'id' => $e->id,
            'name' => $e->name,
            'email' => $e->email,
            'phone' => $e->phone,
            'job_title' => $e->job_title ?? 'موظف',
            'department' => $e->department ?? 'عام',
            'status' => $e->status,
        ])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'phone' => 'nullable|string',
            'job_title' => 'nullable|string',
            'department' => 'nullable|string',
            'status' => 'nullable|active|inactive|on_leave',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['password'] = bcrypt('password');

        $employee = User::create($data);

        return response()->json(['id' => $employee->id, 'name' => $employee->name, 'email' => $employee->email, 'phone' => $employee->phone, 'job_title' => $employee->job_title ?? 'موظف', 'department' => $employee->department ?? 'عام', 'status' => $employee->status], 201);
    }
}
