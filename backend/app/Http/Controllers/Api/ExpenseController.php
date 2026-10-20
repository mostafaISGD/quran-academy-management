<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Services\ExpenseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function __construct(private ExpenseService $service) {}

    public function index(Request $request)
    {
        $query = Expense::query()->with(['category', 'attachments'])
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
            // ⭐ PR 2.12: الفئة إجبارية — مفيش خلفية أوتوماتيكية خفية،
            // والتفتيش محصور بفئات المنشأة نفسها.
            'category_id' => [
                'required',
                Rule::exists('expense_categories', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            'reference' => 'nullable|string',
            'notes' => 'nullable|string',
            'payee' => 'nullable|string|max:255',
            'currency' => 'nullable|string|size:3',
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
            // ⭐ الحساب المصروف منه وقت الصرف — اختياري، ولو متبعتش
            // بيترجع للافتراضي وقت الصرف
            'financial_account_id' => [
                'nullable',
                Rule::exists('financial_accounts', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
        ]);

        $expense = Expense::create([
            'organization_id' => $request->user()->organization_id,
            'branch_id' => $data['branch_id'] ?? null,
            'category_id' => $data['category_id'],
            'amount' => $data['amount'],
            'currency' => $data['currency'] ?? 'EGP',
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'payment_method' => $data['payment_method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'payee' => $data['payee'] ?? null,
            'account_id' => $data['financial_account_id'] ?? null,
            'created_by' => $request->user()->id,
            // ⭐ الإنشاء بيفتتح الدورة مباشرة في «مُرسَل» — الحالة
            // `draft` محفوظة لصناديق مسودّات مستقبلية، وبالتالي أي
            // قيمة قادمة من العميل بتتجاهَل
            'status' => 'submitted',
        ]);

        return response()->json($expense->load('category')->toArray(), 201);
    }

    public function approve(Expense $expense)
    {
        $this->assertSameOrganization($expense);

        $fresh = $this->service->approve($expense, request()->user());

        return response()->json($fresh->load('category')->toArray());
    }

    public function reject(Request $request, Expense $expense)
    {
        $this->assertSameOrganization($expense);

        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $fresh = $this->service->reject($expense, $request->user(), $data['reason'] ?? null);

        return response()->json($fresh->load('category')->toArray());
    }

    public function pay(Request $request, Expense $expense)
    {
        $this->assertSameOrganization($expense);

        $data = $request->validate([
            'financial_account_id' => [
                'nullable',
                Rule::exists('financial_accounts', 'id')
                    ->where('organization_id', $request->user()->organization_id),
            ],
        ]);

        $result = $this->service->pay(
            $expense,
            $request->user(),
            $data['financial_account_id'] ?? null,
        );

        return response()->json([
            // المصروف بعد الصرف
            ...$result['expense']->load('category')->toArray(),
            'ledger' => [
                'recorded' => $result['movement'] !== null,
                'movement_id' => $result['movement']?->id,
                'account_id' => $result['account']?->id,
                'account_name' => $result['account']?->name,
                'account_pick' => $result['account_pick'],
            ],
            'warning' => $result['warning'],
        ]);
    }

    // ═════════════════════════════════════════════════════════
    // المرفقات — تخزين خاص، تنزيل برابط موقّع مؤقت
    // ═════════════════════════════════════════════════════════

    public function uploadAttachment(Request $request, Expense $expense)
    {
        $this->assertSameOrganization($expense);

        $request->validate([
            'file' => 'required|file|mimes:png,jpg,jpeg,webp,pdf|max:5120',
        ]);

        $file = $request->file('file');

        $path = $file->store('expense-attachments', 'local');

        $attachment = ExpenseAttachment::create([
            'expense_id' => $expense->id,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $request->user()->id,
        ]);

        return response()->json($attachment, 201);
    }

    /**
     * تنزيل مرفق — برابط موقّع بدون `auth:sanctum` (التوقيع المؤقت هو الحماية،
     * فمش محتاجين هيدر توكن من متصفح خارج الـ SPA).
     */
    public function downloadAttachment(ExpenseAttachment $attachment)
    {
        return Storage::disk('local')->download($attachment->path, $attachment->original_name);
    }

    /** المصروف مش من منشأة المستخدم → 404 (مش 403 — محاولة الاستكشاف). */
    private function assertSameOrganization(Expense $expense): void
    {
        if ($expense->organization_id !== request()->user()->organization_id) {
            abort(404);
        }
    }
}
