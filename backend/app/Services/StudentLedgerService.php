<?php

namespace App\Services;

use App\Models\StudentLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * PR 2.9 - الكاتب الوحيد لدفتر الطالب.
 *
 * قبل كده كان في **7 كتّاب** بـ4 دلالات مختلفة لـ`balance_after`
 * (رصيد تجميعي بلا فلترة عمولة، «المبلغ نفسه»، و`balance_due`
 * بتاع الفاتورة). النتيجة: كشف حساب الطالب ما كانش معناه واحد.
 *
 * الدلالة الوحيدة من النهاردة:
 *
 *   **رصيد تجميعي لكل (طالب، عملة)** —
 *   `balance_after = آخر رصيد + مدين − دائن`
 *
 * القواعد:
 *   1) كل الكتابة هنا — ولا `DB::table()->insert()` ولا
 *      `StudentLedgerEntry::create()` مباشرة (حارة الموديل
 *      بتمنع التعديل والحذف، ومعهم الكتابة الغريبة بتكسر
 *      التسلسل).
 *   2) الرصيد **بتاع الأخير في نفس العملة** — طالب بجنيه
 *      ودولار فحسابه ما يختلطش.
 *   3) الكتابة بتلف الصفقة (الـnested transaction) وعامل بقرص
 *      مقفول على آخر سطر — كتابتان دفعتين متزامنتين بترقم.
 */
class StudentLedgerService
{
    /**
     * كتابة سطر برصيد تجميعي.
     *
     * المفاتيح المطلوبة: `student_id`, `type`, `currency` —
     * و`debit` أو `credit` (والاتنين شغالة: تصحيحات مستقبلية).
     * الباقي اختياري: invoice_id / payment_id / description /
     * reference_type / reference_id / created_by / created_at.
     */
    public function write(array $data): StudentLedgerEntry
    {
        return DB::transaction(function () use ($data) {
            $studentId = (int) $data['student_id'];
            $currency = $data['currency'];

            $debit = round((float) ($data['debit'] ?? 0), 2);
            $credit = round((float) ($data['credit'] ?? 0), 2);

            /**
             * ⭐ آخر سطر لنفس (الطالب، العملة) — مقفول تقرير.
             * الـ`lockForUpdate` هو حاجز التوازن: فاتورة اتحسّت
             * ودفعة اتسجلت في نفس اللحظة — اللي ثاني يحسب من
             * السطر الأول المحفوظ، مش من ذاكرة قديمة.
             */
            $last = StudentLedgerEntry::query()
                ->where('student_id', $studentId)
                ->where('currency', $currency)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $opening = $last ? (float) $last->balance_after : 0.0;

            $payload = $data;
            $payload['debit'] = $debit;
            $payload['credit'] = $credit;
            $payload['balance_after'] = round($opening + $debit - $credit, 2);

            return StudentLedgerEntry::create($payload);
        });
    }

    /**
     * الرصيد الحالي — آخر سطر لكل (طالب، عمولة).
     */
    public function balance(int $studentId, ?string $currency = null): float
    {
        $query = StudentLedgerEntry::query()
            ->where('student_id', $studentId);

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        $last = $query->orderByDesc('id')->first();

        return $last ? (float) $last->balance_after : 0.0;
    }
}
