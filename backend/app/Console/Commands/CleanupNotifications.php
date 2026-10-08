<?php

namespace App\Console\Commands;

use App\Models\Notification;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ⭐ تنظيف الإشعارات القديمة.
 *
 * ⭐ نحتفظ بالإشعارات لفترة محددة (افتراضي 6 شهور) ونحذف الأقدم.
 */
class CleanupNotifications extends Command
{
    protected $signature = 'notifications:cleanup
        {--months=6 : عدد الشهور للاحتفاظ (افتراضي 6)}
        {--dry-run : عرض عدد اللي هيتحذف من غير ما نمسح}';

    protected $description = 'حذف الإشعارات الأقدم من N شهور';

    public function handle(): int
    {
        $months = (int) $this->option('months');
        $dryRun = $this->option('dry-run');

        $cutoff = Carbon::now()->subMonths($months);

        $this->info("🗑️ تنظيف الإشعارات الأقدم من {$months} شهر (قبل {$cutoff->toDateString()})");

        $query = Notification::where('created_at', '<', $cutoff);
        $count = $query->count();

        $this->line("  📊 إشعارات هتت حذف: {$count}");

        if ($count === 0) {
            $this->info("✅ مفيش حاجة تتحذف");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn("🔍 [DRY RUN] كان هيتحذف {$count} إشعار");
            return self::SUCCESS;
        }

        // نحذف على batches عشان ما نقعش الذاكرة
        $deleted = 0;
        $chunkSize = 1000;

        while (true) {
            $ids = Notification::where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit($chunkSize)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            Notification::whereIn('id', $ids)->delete();
            $deleted += $ids->count();

            if ($deleted % 5000 === 0) {
                $this->line("    🗑️ تم حذف {$deleted}...");
            }
        }

        $this->newLine();
        $this->info("✅ تم حذف {$deleted} إشعار");

        return self::SUCCESS;
    }
}