<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::query()->with('category')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->string('category_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_date', '<=', $request->string('to')))
            ->orderByDesc('expense_date');

        $countsQuery = Expense::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->string('category_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('expense_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('expense_date', '<=', $request->string('to')));

        return $this->paginatedWithCounts(
            $query,
            $countsQuery,
            $request,
            ['status', 'category_id'],
            ['amount'],
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'expense_date' => 'required|date',
            'description' => 'required|string',
            'payment_method' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'pending';

        return response()->json(Expense::create($data), 201);
    }
}
