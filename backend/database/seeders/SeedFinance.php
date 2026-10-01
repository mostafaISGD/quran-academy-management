<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

/**
 * يولّد الفواتير ومدفوعات الطلاب والاسترجاعات وكشوف الحساب.
 * كل فاتورة ليها بنود، وكل مدفوعة مرتبطة بيها، والرصيد بيتحسب بالتتابع.
 */
class SeedFinance extends Seeder
{
    private const MONTHS_BACK = 8;

    /** @var array<string, mixed> */
    private array $d;

    public function __construct()
    {
        $this->d = require database_path('data/realistic.php');
    }

    public function run(): void
    {
        mt_srand(20260505);

        $now = now();

        // الاشتراكات النشطة والمنتهية = اللي ليها فواتير
        $subs = DB::table('subscriptions')
            ->whereIn('status', ['active', 'expired'])
            ->orderBy('id')
            ->get();

        $studentParents = DB::table('student_parents')
            ->where('is_primary', true)
            ->pluck('parent_id', 'student_id');

        $invoices = [];
        $items = [];
        $payments = [];
        $refunds = [];
        $ledger = [];

        $invoiceId = 0;
        $itemId = 0;
        $paymentId = 0;
        $refundId = 0;
        $ledgerId = 0;

        foreach ($subs as $sub) {
            $parentId = $studentParents[$sub->student_id] ?? null;

            // عدد شهور الفوترة: للاشتراك النشط 1-3، للمنتهي 1
            $months = $sub->status === 'active' ? mt_rand(1, 3) : 1;

            for ($m = 0; $m < $months; $m++) {
                // تاريخ الفاتورة: من تاريخ بداية الاشتراك أو آخر شهر
                $base = $sub->start_date
                    ? Carbon::parse($sub->start_date)
                    : $now->copy()->subMonths($m);

                $issueDate = $base->copy()->addMonths($m);
                if ($issueDate->timestamp > $now->timestamp) {
                    continue;
                }
                $issueDate = $issueDate->addDays(mt_rand(0, 3));
                $dueDate = $issueDate->copy()->addDays(14);

                // الباقة + خطأ أحياناً
                $plan = DB::table('subscription_plans')->where('id', $sub->plan_id)->first();
                if (!$plan) {
                    continue;
                }

                $price = (float) $plan->price;
                $discount = 0.0;

                // خصم Sometimes
                $dRoll = mt_rand(0, 100);
                if ($dRoll < 12) {
                    $discount = round($price * 0.10, 2); // خصم 10%
                } elseif ($dRoll < 16) {
                    $discount = round($price * 0.05, 2); // خصم 5%
                } elseif ($dRoll < 18) {
                    $discount = 50.0; // خصم ثابت
                }

                $tax = 0.0; // الأكاديمية غير ضريبية
                $subtotal = $price;
                $total = max(0, $subtotal - $discount + $tax);

                $invoiceId++;
                $invNo = sprintf('%s-%s-%04d', $this->d['invoice_prefix'], $issueDate->format('Ym'), $invoiceId);

                // حالة الفاتورة حسب العمر
                $age = $now->diffInDays($issueDate);

                $paidAmount = 0.0;

                // إما مدفوعة بالكامل، أو جزئياً، أو متأخرة/غير مدفوعة
                $pRoll = mt_rand(0, 100);
                $paymentPlan = match (true) {
                    $pRoll < 55 => 'full',
                    $pRoll < 75 => 'partial',
                    $pRoll < 90 => 'none',
                    default => 'full',
                };

                if ($paymentPlan === 'full') {
                    $paidAmount = $total;
                } elseif ($paymentPlan === 'partial') {
                    $paidAmount = round($total * (mt_rand(30, 70) / 100), 2);
                }

                $balance = $total - $paidAmount;

                $status = match (true) {
                    $paidAmount >= $total && $total > 0 => 'paid',
                    $paidAmount > 0 => 'partially_paid',
                    $age > 30 => 'overdue',
                    default => 'issued',
                };
                if ($total === 0 && $paidAmount === 0) {
                    $status = 'paid'; // باقة مجانية
                }

                $invoices[] = [
                    'id' => $invoiceId,
                    'organization_id' => 1,
                    'branch_id' => null,
                    'student_id' => $sub->student_id,
                    'parent_id' => $parentId,
                    'subscription_id' => $sub->id,
                    'invoice_number' => $invNo,
                    'issue_date' => $issueDate->format('Y-m-d'),
                    'due_date' => $dueDate->format('Y-m-d'),
                    'subtotal' => $subtotal,
                    'discount' => $discount,
                    'tax' => $tax,
                    'total' => $total,
                    'paid_amount' => $paidAmount,
                    'balance_due' => $balance,
                    'currency' => $plan->currency,
                    'status' => $status,
                    'notes' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                // بند الفاتورة
                $itemId++;
                $items[] = [
                    'id' => $itemId,
                    'invoice_id' => $invoiceId,
                    'description' => "{$plan->name} - " . $issueDate->format('Y-m'),
                    'item_type' => 'subscription',
                    'quantity' => $plan->lessons_count ?? 1,
                    'unit_price' => $plan->price,
                    'total' => $plan->price,
                    'reference_type' => 'subscription',
                    'reference_id' => $sub->id,
                    'created_at' => $now, 'updated_at' => $now,
                ];

                // بند الخصم لو موجود
                if ($discount > 0) {
                    $itemId++;
                    $items[] = [
                        'id' => $itemId,
                        'invoice_id' => $invoiceId,
                        'description' => 'خصم',
                        'item_type' => 'discount',
                        'quantity' => 1,
                        'unit_price' => -$discount,
                        'total' => -$discount,
                        'reference_type' => null,
                        'reference_id' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }

                // المدفوعات
                if ($paidAmount > 0) {
                    $paymentRoll = mt_rand(0, 100);
                    $methodRoll = mt_rand(0, 100);
                    $method = match (true) {
                        $methodRoll < 35 => 'cash',
                        $methodRoll < 55 => 'wallet',
                        $methodRoll < 70 => 'bank_transfer',
                        $methodRoll < 82 => 'online_payment',
                        $methodRoll < 92 => 'card',
                        default => 'other',
                    };

                    $paymentId++;
                    $paidDate = $issueDate->copy()->addDays(mt_rand(1, 10));

                    $payments[] = [
                        'id' => $paymentId,
                        'organization_id' => 1,
                        'student_id' => $sub->student_id,
                        'parent_id' => $parentId,
                        'invoice_id' => $invoiceId,
                        'amount' => $paidAmount,
                        'currency' => $plan->currency,
                        'payment_method' => $method,
                        'transaction_reference' => 'TRX-' . strtoupper(Str::random(10)),
                        'status' => 'completed',
                        'paid_at' => $paidDate->format('Y-m-d H:i:s'),
                        'received_by' => 1,
                        'notes' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];

                    // استرجاع أحياناً (5% من المدفوعات)
                    if ($paymentRoll < 5 && $paidAmount >= 50) {
                        $refundId++;
                        $refunds[] = [
                            'id' => $refundId,
                            'payment_id' => $paymentId,
                            'invoice_id' => $invoiceId,
                            'amount' => round($paidAmount * (mt_rand(20, 60) / 100), 2),
                            'currency' => $plan->currency,
                            'reason' => $this->d['refund_reasons'][mt_rand(0, count($this->d['refund_reasons']) - 1)],
                            'method' => $method,
                            'status' => 'processed',
                            'processed_by' => 1,
                            'processed_at' => $paidDate->copy()->addDays(mt_rand(2, 15))->format('Y-m-d H:i:s'),
                            'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
            }
        }

        // كشف حساب لكل طالب: رصيد تراكمي
        $studentBalances = [];
        $allEntries = [];

        // الفواتير كقيود مدينة
        foreach ($invoices as $inv) {
            $studentBalances[$inv['student_id']] = ($studentBalances[$inv['student_id']] ?? 0) + $inv['total'];
            $allEntries[] = [
                'student_id' => $inv['student_id'],
                'invoice_id' => $inv['id'], 'payment_id' => null,
                'type' => 'invoice',
                'debit' => $inv['total'], 'credit' => 0,
                'balance_after' => $studentBalances[$inv['student_id']],
                'currency' => $inv['currency'],
                'description' => "فاتورة {$inv['invoice_number']}",
                'reference_type' => 'invoice', 'reference_id' => $inv['id'],
                'created_by' => null,
                'created_at' => $inv['issue_date'] . ' 10:00:00',
            ];
        }

        // المدفوعات كقيود دائنة
        foreach ($payments as $pay) {
            $studentBalances[$pay['student_id']] -= $pay['amount'];
            $allEntries[] = [
                'student_id' => $pay['student_id'],
                'invoice_id' => $pay['invoice_id'], 'payment_id' => $pay['id'],
                'type' => 'payment',
                'debit' => 0, 'credit' => $pay['amount'],
                'balance_after' => $studentBalances[$pay['student_id']],
                'currency' => $pay['currency'],
                'description' => 'دفعة',
                'reference_type' => 'payment', 'reference_id' => $pay['id'],
                'created_by' => null,
                'created_at' => $pay['paid_at'],
            ];
        }

        // ترتيب زمني
        usort($allEntries, fn ($a, $b) => strcmp($a['created_at'], $b['created_at']));

        // إعادة حساب الأرصدة بالترتيب
        $bal = [];
        foreach ($allEntries as &$e) {
            $bal[$e['student_id']] = ($bal[$e['student_id']] ?? 0) + $e['debit'] - $e['credit'];
            $e['balance_after'] = round($bal[$e['student_id']], 2);
        }
        unset($e);

        foreach ($allEntries as $e) {
            $ledgerId++;
            $e['id'] = $ledgerId;
            $ledger[] = $e;
        }

        $this->insertChunked('invoices', $invoices);
        $this->insertChunked('invoice_items', $items);
        $this->insertChunked('payments', $payments);
        if ($refunds) {
            $this->insertChunked('refunds', $refunds);
        }
        $this->insertChunked('student_ledger_entries', $ledger);

        $this->command?->info('   → ' . count($invoices) . ' فاتورة');
        $this->command?->info('   → ' . count($items) . ' بند فاتورة');
        $this->command?->info('   → ' . count($payments) . ' دفعة');
        if ($refunds) {
            $this->command?->info('   → ' . count($refunds) . ' استرجاع');
        }
        $this->command?->info('   → ' . count($ledger) . ' قيد في كشف الحساب');
    }

    private function insertChunked(string $table, array $rows): void
    {
        if (!$rows) {
            return;
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
}