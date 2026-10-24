<?php

namespace App\Services;

use App\Models\Teacher;
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

    /**
     * ⭐⭐⭐ **PR 3.10 — كشف الحساب خدمةً مش `controller`.**
     *
     * كان مكتوب جوّه `TeacherLedgerController::statement()` —
     * وبوابة المعلم (`/teacher/earnings`) محتاجة نفس الشكل
     * بالظبط (`currencies` + `entries`). فبقى واحد في المشي:
     * الـcontroller الإداري والبوابة الاتنين بندوّوا هنا.
     *
     * نفس شكل كشف الطالب (PR 2.9): `currencies` و `entries`.
     *
     * @return array{teacher_id: int, currencies: array<string, array{balance: float, total_debit: float, total_credit: float}>, entries: \Illuminate\Support\Collection}
     */
    public function statementFor(Teacher $teacher): array
    {
        $entries = $teacher->ledgerEntries()
            ->with(['earning:id,lesson_id,earning_date', 'payment:id,paid_at'])
            ->orderBy('id')
            ->get();

        $currencies = [];

        foreach ($entries as $entry) {
            $currency = $entry->currency;

            if (! isset($currencies[$currency])) {
                $currencies[$currency] = [
                    'balance' => 0.0,
                    'total_debit' => 0.0,
                    'total_credit' => 0.0,
                ];
            }

            $currencies[$currency]['total_debit'] += (float) $entry->debit;
            $currencies[$currency]['total_credit'] += (float) $entry->credit;
            // ⭐ مباشرة على العنصر (نسخة الـmap = انظر PR 2.9)
            $currencies[$currency]['balance'] = (float) $entry->balance_after;
        }

        return [
            'teacher_id' => $teacher->id,
            'currencies' => $currencies,
            'entries' => $entries,
        ];
    }
}
