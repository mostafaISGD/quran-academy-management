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
            // ⭐ الحساب اللي المصروف طلع منه — اختياري
            'financial_account_id' => 'nullable|integer',
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

        $expense = Expense::create($data);

        /**
         * ⭐⭐ المصروف = فلوس **هتخرج** — بس مش دلوقتي.
         *
         * ⚠️ الـ `status` بيتقفل على `pending` دايماً
         * (اعتماد المصروف من صفحة المصروفات)، ومفيش endpoint
         * اعتماد لسه. فلو سجّلنا الحركة `posted` كان هيبقى
         * **مصروف متقفل وانتهى** — ومينفعش نرجّعه.
         *
         * ⭐ فبنسجّلها `pending`: **موجودة وواضحة** في الدفتر،
         * بس **مش بتأثر على الرصيد**. ولما يتبنى endpoint الاعتماد،
         * السطر ده هو اللي هيتحوّل `posted`.
         *
         * @see \App\Services\FinancialLedgerService::pendingToPosted
         */
        $ledger = app(\App\Services\FinancialLedgerService::class);

        [$account, $pickReason] = $ledger->resolveAccount(
            $request->user()->organization_id,
            $data['financial_account_id'] ?? null,
            $ledger->accountTypeForPaymentMethod((string) ($data['payment_method'] ?? '')),
        );

        $movement = null;
        $warning = null;

        if ($account) {
            $res = $ledger->recordFor(
                account: $account,
                direction: 'out',
                category: 'expense',
                amount: (float) $expense->amount,
                referenceType: 'expense',
                referenceId: $expense->id,
                movementDate: (string) $expense->expense_date,
                notes: $expense->description,
                user: $request->user(),
                // ⭐ مش معتمدة لسه — ما بتدخلش الرصيد
                status: 'pending',
            );
            $movement = $res['movement'];
            $warning = $res['warning'];
        } else {
            $warning = 'مفيش حساب مالي نشط — الحركة المالية ماتسجلتش';
        }

        return response()->json([
            ...$expense->load('category')->toArray(),
            'ledger' => [
                'recorded' => $movement !== null,
                'movement_id' => $movement?->id,
                // ⭐ الأدمن يشوف «مسجّلة بس لسه معتمدة؟» من هنا
                'posted' => $movement !== null && $movement->status === 'posted',
                'account_id' => $account?->id,
                'account_name' => $account?->name,
                'account_pick' => $pickReason,
            ],
            'warning' => $warning,
        ], 201);
    }
}
