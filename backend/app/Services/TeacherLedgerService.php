<?php

namespace App\Services;

use App\Models\TeacherLedgerEntry;
use Illuminate\Support\Facades\DB;

/**
 * PR 2.10 — الكاتب الوحيد لدفتر المعلم.
 *
 * الدلالة معكوسة عن دفتر الطالب: هنا الدائن هو اللي يزيد
 * الرصيد (استحقاق درس)، والمدين هو اللي ينقصه (صرف مرتب).
 *
 * المعادلة: `balance_after = آخر رصيد + دائن − مدين`
 *
 * للأمان: آخر سطر لكل (معلم، عملة) مع `lockForUpdate`.
 * الصرف (نوع `payment`) جاي مع PR 2.11. الفاحص (د) في
 * `finance:reconcile` بيقارن الأرباح المعتمدة بالدفتر.
 *
 * القواعد:
 *   1) كل الكتابة من هنا فقط.
 *   2) حارس التعديل/الحذف مع PR 2.11.
 *   3) سطر واحد لكل حدث.
 */
class TeacherLedgerService
{
    /**
     * كتابة سطر برصيد تجميعي.
     *
     * المفاتيح المطلوبة: `teacher_id`, `type`, `currency` —
     * و`debit` أو `credit`. الباقي اختياري: earning_id /
     * payment_id / description / reference_type / reference_id /
     * created_by.
     */
    public function write(array $data): TeacherLedgerEntry
    {
        return DB::transaction(function () use ($data) {
            $teacherId = (int) $data['teacher_id'];
            $currency = $data['currency'];

            $debit = round((float) ($data['debit'] ?? 0), 2);
            $credit = round((float) ($data['credit'] ?? 0), 2);

            /** آخر سطر لنفس (معلم، عملة) — مقفول تقرير */
            $last = TeacherLedgerEntry::query()
                ->where('teacher_id', $teacherId)
                ->where('currency', $currency)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            $opening = $last ? (float) $last->balance_after : 0.0;

            $payload = $data;
            $payload['debit'] = $debit;
            $payload['credit'] = $credit;
            // ⭐ الدائن يزيد رصيد المعلم — عكس دفتر الطالب
            $payload['balance_after'] = round($opening + $credit - $debit, 2);

            return TeacherLedgerEntry::create($payload);
        });
    }

    /**
     * الرصيد الحالي — آخر سطر لكل (معلم، عمولة).
     */
    public function balance(int $teacherId, ?string $currency = null): float
    {
        $query = TeacherLedgerEntry::query()->where('teacher_id', $teacherId);

        if ($currency !== null) {
            $query->where('currency', $currency);
        }

        $last = $query->orderByDesc('id')->first();

        return $last ? (float) $last->balance_after : 0.0;
    }
}
