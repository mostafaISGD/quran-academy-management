<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * يولّد مستحقات المعلمين من الحصص، فترات الرواتب، مدفوعات الرواتب،
 * كشف حساب المعلم، ثم فئات المصروفات ومصروفات الأكاديمية.
 */
class SeedTeacherFinance extends Seeder
{
    /** @var array<string, mixed> */
    private array $d;

    public function __construct()
    {
        $this->d = require database_path('data/realistic.php');
    }

    public function run(): void
    {
        mt_srand(20260606);

        $now = now();

        // ============ 1) فترات الرواتب (آخر 6 شهور) ============
        $periods = [];
        for ($m = 5; $m >= 0; $m--) {
            $start = $now->copy()->subMonths($m)->startOfMonth();
            $end = $now->copy()->subMonths($m)->endOfMonth();
            $isPast = $end->timestamp < $now->timestamp;

            $periods[] = [
                'id' => count($periods) + 1,
                'organization_id' => 1,
                'name' => 'رواتب ' . $start->format('F Y'),
                'start_date' => $start->format('Y-m-d'),
                'end_date' => $end->format('Y-m-d'),
                'status' => $isPast ? 'paid' : 'open',
                'finalized_at' => $isPast ? $end->copy()->addDays(3)->format('Y-m-d H:i:s') : null,
                'finalized_by' => $isPast ? 1 : null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $this->insert('payroll_periods', $periods);

        // ============ 2) المستحقات من الحصص المكتملة ============
        $teachers = DB::table('teachers')->orderBy('id')->get();
        $rates = DB::table('teacher_rates')->orderBy('id')->get()->keyBy('teacher_id');
        $contracts = DB::table('teacher_contracts')->where('status', 'active')->get()->keyBy('teacher_id');

        // جلب حصص كل معلم
        $lessonsByTeacher = DB::table('lessons')
            ->where('status', 'completed')
            ->where('scheduled_start_at', '<=', $now)
            ->orderBy('scheduled_start_at')
            ->get()
            ->groupBy('teacher_id');

        $earnings = [];
        $earningId = 0;

        foreach ($teachers as $teacher) {
            $rate = $rates[$teacher->id] ?? null;
            $contract = $contracts[$teacher->id] ?? null;
            $lessons = $lessonsByTeacher[$teacher->id] ?? collect();

            if ($rate && $lessons->isNotEmpty()) {
                if ($rate->rate_type === 'per_lesson') {
                    // مستحق لكل حصة
                    foreach ($lessons as $lesson) {
                        $earningId++;
                        $date = Carbon::parse($lesson->scheduled_start_at);
                        // تأكيد خلال 3 أيام بعد الحصة
                        $approved = $date->copy()->addDays(3)->timestamp <= $now->timestamp;

                        $earnings[] = [
                            'id' => $earningId,
                            'teacher_id' => $teacher->id,
                            'lesson_id' => $lesson->id,
                            'contract_id' => $contract->id ?? null,
                            'rate_id' => $rate->id,
                            'amount' => $rate->amount,
                            'currency' => $rate->currency,
                            'earning_date' => $date->format('Y-m-d'),
                            'status' => $approved ? 'approved' : 'pending',
                            'notes' => null,
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                } else {
                    // راتب شهري ثابت
                    foreach ($periods as $p) {
                        $pStart = Carbon::parse($p['start_date']);
                        $monthLessons = $lessons->filter(
                            fn ($l) => Carbon::parse($l->scheduled_start_at)->betweenIncluded(
                                $pStart,
                                Carbon::parse($p['end_date'])
                            )
                        );
                        if ($monthLessons->isEmpty()) {
                            continue;
                        }

                        $earningId++;
                        $approved = $p['status'] === 'paid';

                        $earnings[] = [
                            'id' => $earningId,
                            'teacher_id' => $teacher->id,
                            'lesson_id' => null,
                            'contract_id' => $contract->id ?? null,
                            'rate_id' => $rate->id,
                            'amount' => $rate->amount,
                            'currency' => $rate->currency,
                            'earning_date' => Carbon::parse($p['end_date'])->format('Y-m-d'),
                            'status' => $approved ? 'paid' : 'approved',
                            'notes' => 'راتب شهري - ' . $monthLessons->count() . ' حصة',
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
            }
        }

        $this->insert('teacher_earnings', $earnings);

        // ============ 3) مدفوعات الرواتب ============
        $payments = [];
        $paymentId = 0;

        foreach ($periods as $p) {
            if ($p['status'] !== 'paid') {
                continue; // الفترة المفتوحة مااتدفعتش
            }

            // المستحقات المعتمدة في الفترة دي
            $periodEarnings = array_filter(
                $earnings,
                fn ($e) => $e['teacher_id']
                    && Carbon::parse($e['earning_date'])->betweenIncluded(
                        Carbon::parse($p['start_date']),
                        Carbon::parse($p['end_date'])
                    )
            );

            $byTeacher = [];
            foreach ($periodEarnings as $e) {
                $byTeacher[$e['teacher_id']] = ($byTeacher[$e['teacher_id']] ?? 0) + $e['amount'];
            }

            foreach ($byTeacher as $teacherId => $amount) {
                $paymentId++;
                $payments[] = [
                    'id' => $paymentId,
                    'teacher_id' => $teacherId,
                    'payroll_period_id' => $p['id'],
                    'amount' => round($amount, 2),
                    'currency' => 'EGP',
                    'payment_method' => ['cash', 'bank_transfer', 'wallet'][mt_rand(0, 2)],
                    'reference' => 'PAY-' . strtoupper(Str::random(8)),
                    'paid_at' => Carbon::parse($p['end_date'])->addDays(4)->setTime(12, 0)->format('Y-m-d H:i:s'),
                    'status' => 'completed',
                    'processed_by' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        $this->insert('teacher_payments', $payments);

        // ============ 4) كشف حساب المعلمين ============
        $ledger = [];
        $ledgerId = 0;
        $running = [];

        $entries = [];
        foreach ($earnings as $e) {
            $entries[] = [
                'teacher_id' => $e['teacher_id'],
                'earning_id' => $e['id'], 'payment_id' => null,
                'type' => $e['rate_id'] && $e['lesson_id'] ? 'lesson_earning' : 'monthly_salary',
                'debit' => $e['amount'], 'credit' => 0,
                'currency' => $e['currency'],
                'description' => $e['notes'] ?? 'مستحقات',
                'reference_type' => 'earning', 'reference_id' => $e['id'],
                'created_at' => $e['earning_date'] . ' 23:59:00',
            ];
        }
        foreach ($payments as $pay) {
            $entries[] = [
                'teacher_id' => $pay['teacher_id'],
                'earning_id' => null, 'payment_id' => $pay['id'],
                'type' => 'payment',
                'debit' => 0, 'credit' => $pay['amount'],
                'currency' => $pay['currency'],
                'description' => 'دفع رواتب',
                'reference_type' => 'payment', 'reference_id' => $pay['id'],
                'created_at' => $pay['paid_at'],
            ];
        }

        usort($entries, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));
        foreach ($entries as $e) {
            $running[$e['teacher_id']] = ($running[$e['teacher_id']] ?? 0) + $e['debit'] - $e['credit'];
            $ledgerId++;
            $ledger[] = $e + [
                'id' => $ledgerId,
                'balance_after' => round($running[$e['teacher_id']], 2),
                'created_by' => null,
            ];
        }

        $this->insert('teacher_ledger_entries', $ledger);

        // ============ 5) فئات المصروفات ============
        $cats = [];
        foreach ($this->d['expense_categories'] as $i => [$name, $type]) {
            $cats[] = [
                'id' => $i + 1,
                'organization_id' => 1,
                'name' => $name,
                'slug' => str($name)->slug()->toString() ?: 'cat-' . ($i + 1),
                'description' => $name,
                'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        $this->insert('expense_categories', $cats);

        // ============ 6) المصروفات (آخر 6 شهور) ============
        $expenses = [];
        $expenseId = 0;
        $descriptions = $this->d['expense_descriptions'];

        // مصروفات ثابتة شهرية
        $fixedPerMonth = [
            1 => 8000,   // إيجار
            2 => 1200,   // كهرباء
            3 => 450,    // مياه
            4 => 600,    // نت
            5 => 350,    // تليفون
        ];

        for ($m = 5; $m >= 0; $m--) {
            $monthStart = $now->copy()->subMonths($m)->startOfMonth();

            // المصروفات الثابتة
            foreach ($fixedPerMonth as $catId => $amount) {
                $expenseId++;
                $expenses[] = [
                    'id' => $expenseId, 'organization_id' => 1, 'branch_id' => 1,
                    'category_id' => $catId,
                    'amount' => $amount + mt_rand(-150, 250),
                    'currency' => 'EGP',
                    'expense_date' => $monthStart->copy()->addDays(2)->format('Y-m-d'),
                    'description' => $descriptions[$catId - 1],
                    'payment_method' => ['cash', 'bank_transfer'][mt_rand(0, 1)],
                    'reference' => null,
                    'created_by' => 1,
                    'status' => 'approved',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            // مصروفات متفرقة
            $variableCount = mt_rand(2, 5);
            for ($v = 0; $v < $variableCount; $v++) {
                $expenseId++;
                $catId = mt_rand(6, count($cats));
                $status = (int) array_rand(['approved' => 0, 'pending' => 1]);

                $expenses[] = [
                    'id' => $expenseId, 'organization_id' => 1, 'branch_id' => 1,
                    'category_id' => $catId,
                    'amount' => mt_rand(80, 3500),
                    'currency' => 'EGP',
                    'expense_date' => $monthStart->copy()->addDays(mt_rand(3, 27))->format('Y-m-d'),
                    'description' => $descriptions[mt_rand(5, count($descriptions) - 1)],
                    'payment_method' => ['cash', 'bank_transfer', 'card', 'wallet'][mt_rand(0, 3)],
                    'reference' => mt_rand(0, 100) < 30 ? 'INV-' . mt_rand(100000, 999999) : null,
                    'created_by' => 1,
                    'status' => $status === 0 ? 'approved' : 'pending',
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }
        }

        $this->insert('expenses', $expenses);

        $this->command?->info('   → ' . count($periods) . ' فترة رواتب');
        $this->command?->info('   → ' . count($earnings) . ' مستحق معلم');
        $this->command?->info('   → ' . count($payments) . ' دفعة رواتب');
        $this->command?->info('   → ' . count($ledger) . ' قيد كشف حساب المعلم');
        $this->command?->info('   → ' . count($cats) . ' فئة مصروف + ' . count($expenses) . ' مصروف');
    }

    private function insert(string $table, array $rows): void
    {
        if (!$rows) {
            return;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}