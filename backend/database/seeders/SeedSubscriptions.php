<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * يولّد اشتراكات الطلاب مرتبطة بالمعلمين والبرامج، مع الأرصدة والإيقاف.
 * يعتمد على وجود: students, teachers, programs, levels, subscription_plans.
 */
class SeedSubscriptions extends Seeder
{
    /** @var array<string, mixed> */
    private array $d;

    public function __construct()
    {
        $this->d = require database_path('data/realistic.php');
    }

    public function run(): void
    {
        mt_srand(20260303);

        $now = now();
        $today = $now->copy();

        $students = DB::table('students')->select('id', 'status')->get();
        $plans = DB::table('subscription_plans')->get();

        $subs = [];
        $pauses = [];
        $accounts = [];
        $txns = [];
        $subId = 0;
        $accountId = 0;
        $txnId = 0;
        $teacherCursor = 0;

        foreach ($students as $student) {
            // الطالب غير النشط ممكن يكون عنده اشتراك قديم
            $count = match ($student->status) {
                'active' => mt_rand(1, 100) < 75 ? 2 : 1,   // غالباً اشتراك حالي + قديم
                'paused' => 2,
                'graduated' => 1,
                default => mt_rand(0, 100) < 60 ? 1 : 0,
            };

            for ($k = 0; $k < $count; $k++) {
                $plan = $plans[mt_rand(0, $plans->count() - 1)];
                // توزيع يغطي كل الـ15 معلم بالتساوي تقريباً
                $teacherId = (($student->id * 3 + $k * 7 + $teacherCursor) % 15) + 1;
                $teacherCursor += 2;

                // الاشتراك الحالي للطالب النشط، والباقي قديم
                $isCurrent = ($k === 0 && in_array($student->status, ['active', 'paused'], true));

                if ($isCurrent) {
                    $start = $today->copy()->subDays(mt_rand(5, 90));
                    $end = $plan->duration_days
                        ? $start->copy()->addDays($plan->duration_days)
                        : null;
                    $status = $student->status === 'paused' ? 'paused' : 'active';
                } else {
                    $start = $today->copy()->subDays(mt_rand(120, 500));
                    $end = $start->copy()->addDays(30);
                    $status = 'expired';
                }

                $subId++;
                $subs[] = [
                    'id' => $subId, 'organization_id' => 1,
                    'student_id' => $student->id,
                    'plan_id' => $plan->id,
                    'program_id' => $plan->program_id,
                    'teacher_id' => $teacherId,
                    'start_date' => $start->format('Y-m-d'),
                    'end_date' => $end?->format('Y-m-d'),
                    'billing_type' => $plan->billing_type,
                    'price' => $plan->price,
                    'currency' => $plan->currency,
                    'lesson_duration_minutes' => $plan->lesson_duration_minutes,
                    'lessons_included' => $plan->lessons_count,
                    'status' => $status,
                    'auto_renew' => $isCurrent && mt_rand(0, 100) < 70,
                    'notes' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                // حساب رصيد الحصص للاشتراكات الشهرية
                if ($plan->billing_type === 'monthly' && $plan->lessons_count > 1) {
                    $accountId++;
                    $included = $plan->lessons_count;

                    // رصيد باقي = included - عدد حصص مستهلكة (تقريبي)
                    $used = $isCurrent ? mt_rand(0, (int) floor($included * 0.7)) : $included;
                    $balance = max(0, $included - $used);

                    $accounts[] = [
                        'id' => $accountId, 'student_id' => $student->id,
                        'subscription_id' => $subId, 'credit_type' => 'regular',
                        'current_balance' => $balance,
                        'expires_at' => $end?->format('Y-m-d H:i:s'),
                        'status' => $balance === 0 ? 'depleted' : 'active',
                        'created_at' => $now, 'updated_at' => $now,
                    ];

                    // حركات الرصيد: إضافة أولية ثم استهلاك
                    $txnId++;
                    $txns[] = [
                        'id' => $txnId,
                        'credit_account_id' => $accountId, 'student_id' => $student->id,
                        'subscription_id' => $subId, 'lesson_id' => null,
                        'type' => 'initial', 'quantity' => $included,
                        'balance_after' => $included,
                        'reason' => 'رصيد الباقة عند الاشتراك',
                        'created_by' => null,
                        'created_at' => $start->format('Y-m-d H:i:s'),
                    ];

                    if ($used > 0) {
                        $txnId++;
                        $txns[] = [
                            'id' => $txnId,
                            'credit_account_id' => $accountId, 'student_id' => $student->id,
                            'subscription_id' => $subId, 'lesson_id' => null,
                            'type' => 'lesson_used', 'quantity' => -$used,
                            'balance_after' => $balance,
                            'reason' => 'استهلاك حصص',
                            'created_by' => null,
                            'created_at' => $start->copy()->addDays(20)->format('Y-m-d H:i:s'),
                        ];
                    }
                }

                // إيقاف مؤقت للاشتراكات المتوقفة
                if ($status === 'paused') {
                    $pStart = $today->copy()->subDays(mt_rand(5, 25));
                    $pDays = mt_rand(7, 30);
                    $pauses[] = [
                        'subscription_id' => $subId,
                        'start_date' => $pStart->format('Y-m-d'),
                        'end_date' => $pStart->copy()->addDays($pDays)->format('Y-m-d'),
                        'days' => $pDays,
                        'reason' => $this->d['cancellation_reasons'][mt_rand(0, count($this->d['cancellation_reasons']) - 1)],
                        'status' => 'active',
                        'created_by' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($subs, 30) as $chunk) {
            DB::table('subscriptions')->insert($chunk);
        }
        if ($pauses) {
            DB::table('subscription_pauses')->insert($pauses);
        }
        foreach (array_chunk($accounts, 30) as $chunk) {
            DB::table('lesson_credit_accounts')->insert($chunk);
        }
        foreach (array_chunk($txns, 30) as $chunk) {
            DB::table('lesson_credit_transactions')->insert($chunk);
        }

        $this->command?->info('   → ' . count($subs) . ' اشتراك');
        if ($pauses) {
            $this->command?->info('   → ' . count($pauses) . ' إيقاف مؤقت');
        }
        $this->command?->info('   → ' . count($accounts) . ' حساب رصيد');
        $this->command?->info('   → ' . count($txns) . ' حركة رصيد');
    }
}