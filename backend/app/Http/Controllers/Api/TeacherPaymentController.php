<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherPayment;
use Illuminate\Http\Request;

class TeacherPaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = TeacherPayment::query()->with(['teacher', 'payrollPeriod'])
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payroll_period_id'), fn ($q) => $q->where('payroll_period_id', $request->string('payroll_period_id')))
            ->orderByDesc('paid_at');

        $countsQuery = TeacherPayment::query()
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('payroll_period_id'), fn ($q) => $q->where('payroll_period_id', $request->string('payroll_period_id')));

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['status'], ['amount']);
    }
}