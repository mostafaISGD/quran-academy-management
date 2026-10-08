<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountMovement;
use App\Models\FinancialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ تصدير تقارير Excel (CSV).
 *
 * ⭐ **لماذا CSV مش xlsx؟**
 *
 * xlsx بيحتاج package تقيل (maatwebsite/excel) وcomposer install.
 * CSV بخفيف، بيفتح في Excel، وملفاته صغيرة.
 *
 * ⚠️ **BOM إلزامي:** Excel على Windows ما بيعرفش يفتح UTF-8 CSV
 * من غير Byte Order Mark — فالنصوص العربية بتتظهر زي «؟؟؟».
 * فبنكتب `\xEF\xBB\xBF` في أول الملف.
 */
class ExportController extends Controller
{
    /** ⭐ BOM لاكتشاف UTF-8 من Excel */
    private function csvResponse(string $filename, string $content): \Illuminate\Http\Response
    {
        return response($content)
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    /**   حركات الحسابات — الدفتر الكامل */
    public function accountMovements(Request $request)
    {
        $movements = AccountMovement::where('organization_id', $request->user()->organization_id)
            ->with('account:id,name,type')
            ->when($request->filled('from'), fn ($q) => $q->where('movement_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('movement_date', '<=', $request->string('to')))
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->get();

        $rows = [];
        $rows[] = "id,الحساب,النوع,الاتجاه,الفئة,المبلغ,العملة,التاريخ,الحالة,الملاحظات";

        foreach ($movements as $m) {
            $rows[] = implode(',', [
                $m->id,
                '"' . ($m->account?->name ?? '—') . '"',
                '"' . ($m->account?->type ?? '—') . '"',
                $m->direction === 'in' ? 'داخل' : 'خارج',
                '"' . $m->category . '"',
                (float) $m->amount,
                $m->currency,
                $m->movement_date->toDateString(),
                $m->status === 'posted' ? 'مرحّل' : 'معلّق',
                '"' . str_replace('"', '""', $m->notes ?? '') . '"',
            ]);
        }

        $csv = "\xEF\xBB\xBF" . implode("\r\n", $rows);

        return $this->csvResponse('حركات_الحسابات_' . now()->format('Y-m-d') . '.csv', $csv);
    }

    /**   أرصدة الحسابات */
    public function accountBalances(Request $request)
    {
        $accounts = FinancialAccount::where('organization_id', $request->user()->organization_id)
            ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
            ->orderBy('id')
            ->get();

        $rows = [];
        $rows[] = "id,الحساب,النوع,العملة,الحالة,الرصيد_الافتتاحي,الرصيد_الحالي,عدد_الحركات";

        foreach ($accounts as $a) {
            $rows[] = implode(',', [
                $a->id,
                '"' . $a->name . '"',
                '"' . $a->type . '"',
                $a->currency,
                $a->status === 'active' ? 'نشط' : 'غير نشط',
                (float) $a->opening_balance,
                $a->balance(),
                $a->movements()->count(),
            ]);
        }

        $csv = "\xEF\xBB\xBF" . implode("\r\n", $rows);

        return $this->csvResponse('أرصدة_الحسابات_' . now()->format('Y-m-d') . '.csv', $csv);
    }

    /**   فواتير الطلاب */
    public function invoices(Request $request)
    {
        $invoices = DB::table('invoices')
            ->join('students', 'invoices.student_id', '=', 'students.id')
            ->where('invoices.organization_id', $request->user()->organization_id)
            ->when($request->filled('from'), fn ($q) => $q->where('invoices.issue_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('invoices.issue_date', '<=', $request->string('to')))
            ->select('invoices.*', DB::raw("COALESCE(students.first_name || ' ' || students.last_name, students.first_name) as student_name"))
            ->orderByDesc('invoices.issue_date')
            ->get();

        $rows = [];
        $rows[] = "id,رقم_الفاتورة,الطالب,الإجمالي,المدفوع,الرصيد_الدائن,المتبقي,العملة,الحالة,التاريخ";

        foreach ($invoices as $inv) {
            $rows[] = implode(',', [
                $inv->id,
                '"' . $inv->invoice_number . '"',
                '"' . ($inv->student_name ?? '—') . '"',
                (float) $inv->total,
                (float) $inv->paid_amount,
                (float) ($inv->credited_amount ?? 0),
                (float) $inv->balance_due,
                $inv->currency,
                '"' . $inv->status . '"',
                $inv->issue_date,
            ]);
        }

        $csv = "\xEF\xBB\xBF" . implode("\r\n", $rows);

        return $this->csvResponse('الفواتير_' . now()->format('Y-m-d') . '.csv', $csv);
    }

    /**   المصروفات */
    public function expenses(Request $request)
    {
        $expenses = DB::table('expenses')
            ->leftJoin('expense_categories', 'expenses.category_id', '=', 'expense_categories.id')
            ->where('expenses.organization_id', $request->user()->organization_id)
            ->when($request->filled('from'), fn ($q) => $q->where('expenses.expense_date', '>=', $request->string('from')))
            ->when($request->filled('to'), fn ($q) => $q->where('expenses.expense_date', '<=', $request->string('to')))
            ->select('expenses.*', 'expense_categories.name as category_name')
            ->orderByDesc('expenses.expense_date')
            ->get();

        $rows = [];
        $rows[] = "id,المبلغ,الفئة,الوصف,التاريخ,الحالة";

        foreach ($expenses as $e) {
            $rows[] = implode(',', [
                $e->id,
                (float) $e->amount,
                '"' . ($e->category_name ?? '—') . '"',
                '"' . str_replace('"', '""', $e->description ?? '') . '"',
                $e->expense_date,
                '"' . $e->status . '"',
            ]);
        }

        $csv = "\xEF\xBB\xBF" . implode("\r\n", $rows);

        return $this->csvResponse('المصروفات_' . now()->format('Y-m-d') . '.csv', $csv);
    }
}
