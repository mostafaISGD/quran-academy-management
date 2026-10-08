<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * ⭐ تعريف أوامر التطبيق.
     */
    protected $commands = [
        \App\Console\Commands\ProcessSubscriptionDay::class,
        \App\Console\Commands\CheckSubscriptionExpiry::class,
        \App\Console\Commands\NotifyOverdueInvoices::class,
        \App\Console\Commands\CleanupNotifications::class,
    ];

    /**
     * ⭐⭐ جدولة المهام الدورية.
     *
     * ⭐ يتم تشغيل هذا من `cron` كل دقيقة:
     *     * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
     */
    protected function schedule(Schedule $schedule): void
    {
        // ═══ 1. المعالجة اليومية للاشتراكات (كل يوم الساعة 2:00 فجراً) ═══
        //
        // ⭐ التوقيت مهم: بعد منتصف الليل عشان التاريخ يتغير،
        // وقبل بداية اليوم الدراسي عشان الدروس تتخلق في وقتها.
        $schedule->command('subscriptions:process-day')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->runInBackground()
            ->onSuccess(function () {
                // ممكن نضيف log هنا لو عايزين
            })
            ->onFailure(function () {
                // ممكن نرسل تنبيه للإدارة هنا
            });

        // ═══ 2. تنبيه الفواتير المتأخرة (كل يوم الساعة 09:00 صباحاً) ═══
        //
        // ⭐ الساعة 9 صباحاً عشان الأهالي يشوفوا التنبيه لما يفتحوا التليفون
        $schedule->command('invoices:notify-overdue')
            ->dailyAt('09:00')
            ->withoutOverlapping();

        // ═══ 3. تنظيف الإشعارات القديمة (أول كل شهر) ═══
        //
        // ⭐ نحتفظ بالإشعارات 6 شهور بس
        $schedule->command('notifications:cleanup --months=6')
            ->monthlyOn(1, '03:00');
    }

    /**
     * ⭐ تسجيل Closure commands لو محتاجين.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}