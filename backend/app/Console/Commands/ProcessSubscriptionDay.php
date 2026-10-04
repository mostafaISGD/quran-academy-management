<?php

namespace App\Console\Commands;

use App\Services\DailySubscriptionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ProcessSubscriptionDay extends Command
{
    protected $signature = 'subscriptions:process-day
        {--date= : تاريخ المعالجة (YYYY-MM-DD) — افتراضياً النهاردة}
        {--dry-run : اعرض اللي هيحصل بدون ما تكتب أي حاجة}';

    protected $description = 'المعالجة اليومية للاشتراكات: تجديد، إشعار قبل الانتهاء، وإقفال المنتهي.';

    public function handle(DailySubscriptionService $service): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();

        if ($this->option('dry-run')) {
            $this->warn('وضع التجربة: مش هيتكتب أي حاجة في قاعدة البيانات.');
        }

        $result = $service->run($date);

        $this->newLine();
        $this->line("  📅 معالجة يوم {$result['date']}");
        $this->line('  ' . str_repeat('─', 46));

        // ---------- التجديد ----------
        $this->line("  🔄 تجديد: " . count($result['renewed']));
        foreach ($result['renewed'] as $row) {
            $this->line(sprintf(
                '     #%d %s — %s ← %s | فاتورة %s | حصة %d%s',
                $row['subscription_id'],
                $row['student'] ?? '؟',
                $row['old_end_date'],
                $row['new_end_date'],
                $row['invoice_id'] ? '#' . $row['invoice_id'] : '—',
                $row['lessons_created'],
                $row['lessons_skipped'] > 0 ? " (اتخطّت {$row['lessons_skipped']})" : '',
            ));
        }

        // ---------- إشعار الانتهاء ----------
        $this->line('  ⏰ إشعار «هينتهي»: ' . count($result['expiring_notified']));
        foreach ($result['expiring_notified'] as $row) {
            $this->line(sprintf(
                '     #%d %s — فاضل %d يوم (%s) — %d مستلم',
                $row['subscription_id'],
                $row['student'] ?? '؟',
                $row['days_left'],
                $row['end_date'],
                $row['recipients'],
            ));
        }

        // ---------- الإقفال ----------
        $this->line('  ⛔ إقفال منتهي: ' . count($result['expired']));
        foreach ($result['expired'] as $row) {
            $this->line(sprintf(
                '     #%d %s — انتهى %s | حصص اتلغت %d',
                $row['subscription_id'],
                $row['student'] ?? '؟',
                $row['end_date'],
                $row['lessons_cancelled'],
            ));
        }

        // ---------- الإحصائيات ----------
        $this->line('  ' . str_repeat('─', 46));
        $this->line('     فواتير جديدة: ' . $result['invoices_created']);
        $this->line('     حصص اتجدولت:  ' . $result['lessons_created']);
        $this->line('     مواعيد اتخطّت: ' . $result['lessons_skipped']);

        foreach ($result['skipped_reasons'] as $reason => $count) {
            $this->line("       - {$count}× {$reason}");
        }

        $this->line('     إشعارات:      ' . $result['notifications_created']);
        $this->newLine();

        $this->info("  ✅ خلصت معالجة {$result['date']}");

        return self::SUCCESS;
    }
}
