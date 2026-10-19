<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CreditNoteApplication;
use App\Models\Invoice;
use App\Models\StudentCredit;
use Illuminate\Http\Request;

/**
 * ⭐⭐ الرصيد الدائن والملاحظات الائتمانية.
 *
 * ⭐ **الفرق بين الاثنين:**
 *
 *  - `student_credits` — pool من الفلوس المستقبلية. الطالب ليه
 *    X ج.م واصل معرفة ماسبب (إنعام، تسوية، رصيد من دورة مدفوعة).
 *
 *  - `CreditNoteApplication` — تطبيق جزء من الـ pool على فاتورة
 *    محددة. بتخصم من رصيد الطالب وبتختصر فاتورته.
 *
 * ⭐ **لماذا عمَلكان كاملين:** علشان لو الرصيد اتطبق على فاتورة
 * atal، الباقي ضل جوّا الـ pool.
 */
class StudentCreditController extends Controller
{
    /**   حساب الطالب — كل الرصيد الدائن */
    public function index(Request $request)
    {
        $credits = StudentCredit::where('organization_id', $request->user()->organization_id)
            ->when($request->filled('student_id'), fn ($q) => $q->where('student_id', $request->integer('student_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->with('student:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $credits->map(fn ($c) => [
                'id' => $c->id,
                'student' => $c->student ? ['id' => $c->student->id, 'name' => $c->student->full_name ?? trim($c->student->first_name.' '.$c->student->last_name)] : null,
                'currency' => $c->currency,
                'total' => (float) $c->total,
                'used' => (float) $c->used,
                'refunded' => (float) $c->refunded,
                'balance' => $c->balance(),
                'reason' => $c->reason,
                'source_type' => $c->source_type,
                'status' => $c->status,
                'expires_at' => $c->expires_at?->toDateString(),
                'created_at' => $c->created_at?->toDateString(),
            ]),
        ]);
    }

    /**   إنشاء رصيد دائن جديد */
    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'currency' => 'required|string|size:3',
            'total' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
            'source_type' => 'nullable|string|max:50',
            'expires_at' => 'nullable|date|after:today',
        ]);

        $credit = StudentCredit::create([
            ...$data,
            'organization_id' => $request->user()->organization_id,
            'created_by' => $request->user()->id,
        ]);

        app(\App\Services\AuditLogService::class)->logCreate(
            'student_credit',
            $credit->id,
            ['amount' => $credit->total, 'student_id' => $credit->student_id],
            $request,
        );

        return response()->json(['message' => 'اتضاف الرصيد الدائن', 'data' => $credit], 201);
    }

    /**
     * ⭐⭐ تطبيق الرصيد الدائن على فاتورة.
     *
     * ⭐ التطبيق ممكن يكون على أكتر من فاتورة بنفس الرصيد — تاني
     * السؤال الأول: "طب لو الcredit ١٠٠٠ والفاتورة ٧٠٠؟"، بنخصم
     * ٧٠٠ من credit، والفاتورة بتمدا (partially_paid)، والـ ٣٠٠
     * فاضلة جوّا الـ credit.
     *
     * ⭐ القاعدة الحاسمة: المطبق **مش ممكن يزيد** عن الرصيد المتاح
     * ولا عن المتبقي على الفاتورة.
     */
    public function apply(Request $request, StudentCredit $studentCredit)
    {
        $data = $request->validate([
            'invoice_id' => 'required|exists:invoices,id',
            'amount' => 'required|numeric|min:0.01',
        ]);

        // ── تأكد إن الفاتورة فعلاً للطالب نفسه ──
        $invoice = Invoice::findOrFail($data['invoice_id']);

        if ($invoice->student_id !== $studentCredit->student_id) {
            return response()->json([
                'message' => 'الفاتورة مش بتخص نفس الطالب — راجع الطالب والفاتورة',
            ], 422);
        }

        // ── تأكد إن الرصيد متاح ──
        if (! $studentCredit->isAvailable()) {
            return response()->json([
                'message' => 'الرصيد الدائن غير متاح — ممكن يكون منتهى أو مستهلك بالكامل',
            ], 422);
        }

        if ($data['amount'] > $studentCredit->balance()) {
            return response()->json([
                'message' => 'المبلغ المطلوب تطبيق أكبر من الرصيد المتاح: ' . $studentCredit->balance(),
            ], 422);
        }

        $invoiceBalance = max(0, (float) $invoice->total - (float) $invoice->paid_amount - (float) $invoice->credited_amount);

        if ($data['amount'] > $invoiceBalance) {
            return response()->json([
                'message' => 'المبلغ أكبر من المتبقي على الفاتورة: ' . $invoiceBalance,
            ], 422);
        }

        $application = CreditNoteApplication::create([
            'organization_id' => $request->user()->organization_id,
            'student_credit_id' => $studentCredit->id,
            'invoice_id' => $invoice->id,
            'amount' => $data['amount'],
            'status' => 'active',
            'created_by' => $request->user()->id,
        ]);

        /**
         * ⭐ خصم من الرصيد الدائن
         *
         * ⚠️⚠️ **`increment()` بتتخطّى الـ cast تمامًا!**
         *
         * الـ `increment` بتنفيذ `UPDATE ... SET used = used + ?`
         * على مستوى الـ query builder — من غير ما يعدّي على
         * `MoneyCast::set`. فالمبلغ ٢٠٠ جنيه كان بيتخزّن **٢٠٠
         * قرش**، والرصيد المتبقي بيطلع ١٩٨ بدل صفر.
         *
         * الحل: نقرأ الحالي (وهو **جنيه** من الـ cast) ونمنشنّله
         * بنفسه. كده الاتنين متساويين في الوحدة.
         *
         * @see \App\Casts\MoneyCast
         */
        $studentCredit->update([
            'used' => round((float) $studentCredit->used + (float) $data['amount'], 2),
        ]);

        if ($studentCredit->fresh()->balance() <= 0) {
            $studentCredit->update(['status' => 'depleted']);
        }

        // ⭐ الفاتورة تحسب من جديد — المتبقي قلّ
        $invoice->recalculateFromAllocations();

        app(\App\Services\AuditLogService::class)->log(
            'apply_credit_note',
            'student_credit',
            $studentCredit->id,
            null,
            [
                'invoice_id' => $invoice->id,
                'amount' => $data['amount'],
                'remaining' => $studentCredit->fresh()->balance(),
            ],
            $request,
        );

        return response()->json([
            'message' => 'اتطبق الرصيد الدائن على الفاتورة',
            'data' => [
                'invoice_balance' => (float) $invoice->fresh()->balance_due,
                'credit_remaining' => $studentCredit->fresh()->balance(),
            ],
        ], 201);
    }

    /**   استرداد رصيد دائن نقدياً — decision الإدارة */
    public function refund(Request $request, StudentCredit $studentCredit)
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'required|string|max:255',
        ]);

        if ($data['amount'] > $studentCredit->balance()) {
            return response()->json([
                'message' => 'مبلغ الاسترداد أكبر من الرصيد المتاح: ' . $studentCredit->balance(),
            ], 422);
        }

        /**
         * ⭐⚠️ نفس سبب `used` فوق — `increment()` بتتخطّى الـ cast.
         *
         * الاسترداد ٣٠٠ جنيه كان بيتخزّن ٣٠٠ قرش، فالرصيد بيطلع
         * ٣ جنيه بدل ٣٠٠. بنجمع في الجنيه ونمنّقه بإيدنا.
         */
        $studentCredit->update([
            'refunded' => round((float) $studentCredit->refunded + (float) $data['amount'], 2),
        ]);

        if ($studentCredit->fresh()->balance() <= 0) {
            $studentCredit->update(['status' => 'refunded']);
        }

        app(\App\Services\AuditLogService::class)->log(
            'refund_credit',
            'student_credit',
            $studentCredit->id,
            null,
            ['amount' => $data['amount'], 'reason' => $data['reason']],
            $request,
        );

        return response()->json([
            'message' => 'اتصرف الرصيد الدائن نقداً',
            'data' => ['remaining' => $studentCredit->fresh()->balance()],
        ]);
    }
}
