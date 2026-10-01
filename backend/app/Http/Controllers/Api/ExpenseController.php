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
            'payment_method' => 'nullable|string|max:50',
            'category_id' => 'nullable|exists:expense_categories,id',
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        // category_id إجباري في الجدول — لو مش مبعوت، خود أول فئة
        if (empty($data['category_id'])) {
            $firstCategory = \App\Models\ExpenseCategory::query()->first();
            if (!$firstCategory) {
                return response()->json(['error' => 'مفيش فئات مصروفات — أضف فئة الأول'], 422);
            }
            $data['category_id'] = $firstCategory->id;
        }

        $data['organization_id'] = $request->user()->organization_id;
        $data['branch_id'] = $request->input('branch_id');
        $data['currency'] = $request->input('currency', 'EGP');
        $data['created_by'] = $request->user()->id;
        $data['status'] = $request->input('status', 'pending');
        if ($data['status'] !== 'pending') {
            $data['status'] = 'pending'; // اعتماد المصروف بيحصل من صفحة المصروفات
        }

        return response()->json(Expense::create($data)->load('category'), 201);
    }
}
