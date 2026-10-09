<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\Student;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐ إدارة الأكواد الترويجية (Promo Codes).
 *
 * ⭐ يدعم: نسبة مئوية / مبلغ ثابت، حدود استخدام، صلاحية زمنية،
 * تطبيق على برنامج/فئة/الكل، طلاب جدد فقط.
 *
 * ⭐ التكامل: يطبق على الفاتورة عند الإنشاء (يدوي أو جماعي).
 */
class PromoCodeController extends Controller
{
    // ============================================================
    // ① القائمة والقراءة
    // ============================================================

    public function index(Request $request)
    {
        $promos = PromoCode::where('organization_id', $request->user()->organization_id)
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(function ($sub) use ($s) {
                    $sub->where('code', 'like', "%{$s}%")
                        ->orWhere('name', 'like', "%{$s}%");
                });
            })
            ->with('program:id,name')
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page') ?? 20);

        return response()->json([
            'data' => $promos->getCollection()->map(fn ($p) => $this->present($p))->all(),
            'meta' => [
                'current_page' => $promos->currentPage(),
                'last_page' => $promos->lastPage(),
                'per_page' => $promos->perPage(),
                'total' => $promos->total(),
            ],
        ]);
    }

    public function show(PromoCode $promoCode)
    {
        abort_if($promoCode->organization_id !== request()->user()->organization_id, 404);

        return response()->json(['data' => $this->present($promoCode->load('usages.invoice:id,invoice_number'))]);
    }

    // ============================================================
    // ② التحقق من كود (API للواجهة عند الإدخال)
    // ============================================================

    /**
     * ⭐⭐ التحقق من صلاحية كود وتطبيق خصم تجريبي.
     *
     * POST /api/promo-codes/validate
     * { code, invoice_id, student_id, amount }
     *
     * يرجع: { valid, discount_amount, final_amount, message }
     */
    public function validate(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:50',
            'invoice_id' => 'required|exists:invoices,id',
            'student_id' => 'required|exists:students,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $orgId = $request->user()->organization_id;

        $promo = PromoCode::where('organization_id', $orgId)
            ->where('code', $data['code'])
            ->first();

        if (! $promo) {
            return response()->json([
                'valid' => false,
                'message' => 'الكود غير موجود',
            ], 404);
        }

        $invoice = Invoice::find($data['invoice_id']);
        abort_if($invoice->organization_id !== $orgId, 404);

        $student = Student::find($data['student_id']);
        abort_if($student->organization_id !== $orgId, 404);

        // التحقق من الصلاحية
        if (! $promo->isValidNow()) {
            return response()->json([
                'valid' => false,
                'message' => 'الكود منتهي أو غير نشط أو وصل للحد الأقصى',
            ], 422);
        }

        if (! $promo->appliesTo($invoice)) {
            return response()->json([
                'valid' => false,
                'message' => 'الكود لا ينطبق على هذه الفاتورة/الطالب',
            ], 422);
        }

        if (! $promo->canStudentUse($data['student_id'])) {
            return response()->json([
                'valid' => false,
                'message' => 'الطالب استخدم الكود للحد المسموح',
            ], 422);
        }

        // احتساب الخصم
        $discount = $promo->calculateDiscount((float) $data['amount']);
        $final = max(0, (float) $data['amount'] - $discount);

        return response()->json([
            'valid' => true,
            'promo_code' => [
                'id' => $promo->id,
                'code' => $promo->code,
                'name' => $promo->name,
                'type' => $promo->type,
                'value' => (float) $promo->value,
            ],
            'discount_amount' => $discount,
            'final_amount' => $final,
            'message' => "تم تطبيق خصم {$discount} — المبلغ النهائي: {$final}",
        ]);
    }

    // ============================================================
    // ③ الإنشاء والتعديل
    // ============================================================

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:promo_codes,code,NULL,id,organization_id,' . $request->user()->organization_id,
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0.01',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_student' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'applies_to' => 'required|in:all,program,category',
            'program_id' => 'nullable|exists:programs,id',
            'category' => 'nullable|in:traditional,golden,group,single',
            'new_students_only' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['created_by'] = $request->user()->id;

        $promo = PromoCode::create($data);

        app(AuditLogService::class)->logCreate(
            'promo_code', $promo->id,
            ['code' => $promo->code, 'name' => $promo->name, 'value' => $promo->value],
            $request,
        );

        return response()->json([
            'message' => 'تم إنشاء الكود الترويجي',
            'data' => $this->present($promo->fresh()),
        ], 201);
    }

    public function update(Request $request, PromoCode $promoCode)
    {
        abort_if($promoCode->organization_id !== $request->user()->organization_id, 404);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'description' => 'nullable|string|max:500',
            'value' => 'sometimes|numeric|min:0.01',
            'max_uses' => 'nullable|integer|min:1',
            'max_uses_per_student' => 'nullable|integer|min:1',
            'starts_at' => 'nullable|date',
            'expires_at' => 'nullable|date|after_or_equal:starts_at',
            'applies_to' => 'sometimes|in:all,program,category',
            'program_id' => 'nullable|exists:programs,id',
            'category' => 'nullable|in:traditional,golden,group,single',
            'new_students_only' => 'boolean',
            'is_active' => 'boolean',
        ]);

        $old = $promoCode->only(array_keys($data));
        $promoCode->update($data);

        app(AuditLogService::class)->logUpdate(
            'promo_code', $promoCode->id, $old, $data, $request,
        );

        return response()->json([
            'message' => 'تم تحديث الكود',
            'data' => $this->present($promoCode->fresh()),
        ]);
    }

    public function destroy(PromoCode $promoCode)
    {
        abort_if($promoCode->organization_id !== request()->user()->organization_id, 404);

        if ($promoCode->usages()->exists()) {
            return response()->json([
                'message' => 'الكود فيه استخدامات — لا يمكن الحذف، عطله بس (is_active=false)',
            ], 422);
        }

        $promoCode->delete();

        app(AuditLogService::class)->logDelete(
            'promo_code', $promoCode->id,
            ['code' => $promoCode->code, 'name' => $promoCode->name],
            request(),
        );

        return response()->json(['message' => 'تم الحذف']);
    }

    // ============================================================
    // ④ الإحصائيات
    // ============================================================

    public function stats(PromoCode $promoCode)
    {
        abort_if($promoCode->organization_id !== request()->user()->organization_id, 404);

        $totalUsage = $promoCode->usages()->count();
        $totalDiscount = $promoCode->usages()->sum('discount_amount');
        $uniqueStudents = $promoCode->usages()->distinct('student_id')->count('student_id');

        return response()->json([
            'data' => [
                'total_usage' => $totalUsage,
                'total_discount' => (float) $totalDiscount,
                'unique_students' => $uniqueStudents,
                'remaining_uses' => $promoCode->max_uses ? $promoCode->max_uses - $promoCode->used_count : null,
            ],
        ]);
    }

    // ============================================================
    // العرض
    // ============================================================

    private function present(PromoCode $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'type' => $p->type,
            'type_label' => $p->type === 'percent' ? 'نسبة مئوية' : 'مبلغ ثابت',
            'value' => (float) $p->value,
            'max_uses' => $p->max_uses,
            'used_count' => $p->used_count,
            'max_uses_per_student' => $p->max_uses_per_student,
            'starts_at' => $p->starts_at?->toDateTimeString(),
            'expires_at' => $p->expires_at?->toDateTimeString(),
            'applies_to' => $p->applies_to,
            'applies_to_label' => match ($p->applies_to) {
                'all' => 'كل البرامج والباقات',
                'program' => 'برنامج محدد',
                'category' => 'فئة باقة',
            },
            'program' => $p->program ? ['id' => $p->program->id, 'name' => $p->program->name] : null,
            'category' => $p->category,
            'category_label' => $p->category ? PromoCode::CATEGORY_LABELS[$p->category] ?? $p->category : null,
            'new_students_only' => $p->new_students_only,
            'is_active' => $p->is_active,
            'is_valid_now' => $p->isValidNow(),
            'created_at' => $p->created_at?->toDateTimeString(),
            'created_by' => $p->creator?->name,
        ];
    }
}