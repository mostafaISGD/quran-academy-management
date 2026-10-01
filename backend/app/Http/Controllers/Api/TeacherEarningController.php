<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherEarning;
use Illuminate\Http\Request;

class TeacherEarningController extends Controller
{
    public function index(Request $request)
    {
        $query = TeacherEarning::query()->with(['teacher', 'lesson', 'rate', 'contract'])
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->where('earning_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('earning_date', '<=', $request->string('to')))
            ->orderByDesc('earning_date');

        $countsQuery = TeacherEarning::query()
            ->when($request->filled('teacher_id'), fn ($q) => $q->where('teacher_id', $request->string('teacher_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('from'), fn ($q) => $q->where('earning_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('earning_date', '<=', $request->string('to')));

        return $this->paginatedWithCounts($query, $countsQuery, $request, ['status'], ['amount']);
    }
}