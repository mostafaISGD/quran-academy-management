<?php

namespace App\Console\Commands;

use App\Services\DailySubscriptionService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ⭐⭐⭐ **PR 4.2 — إشعار اشتراكات «هينتهي قريب» (أمر مجدول).**
 *
 * المواصفة طلبت نقل الإشعار الموجود داخل `DailySubscriptionService`
 * إلى بنية إشعارات PR 4.1 — تم: `notify()` يكتب الآن عبر
 * `NotificationService::dispatchInbox()` بمفتاح منع تكرار فريد،
 * وهذا الأمر يستدعي المسار نفسه وحده (بلا تجديد ولا إقفال ولا فواتير).
 *
 * التكرار آمن: كل (حدث، مستلم، اشتراك، نهاية) مفتاحه في القاعدة —
 * تشغيل الأمر مرتين لا يرسل شيئاً في الثانية.
 */
class NotifyExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:notify-expiring
        {--date= : تاريخ التشغيل (YYYY-MM-DD) — افتراضي اليوم}';

    protected $description = 'إشعار الاشتراكات التي توشك على الانتهاء (نافذة كل مؤسسة من مركز الإعدادات)';

    public function handle(DailySubscriptionService $daily): int
    {
        $date = $this->option('date') ? Carbon::parse((string) $this->option('date')) : Carbon::today();

        $result = $daily->notifyExpiringOnly($date->copy());

        $this->info(sprintf(
            '📅 إشعارات «هينتهي قريب» للتاريخ %s: %d اشتراكاً / %d إشعاراً',
            (string) $result['date'],
            count($result['expiring_notified']),
            (int) $result['notifications_created'],
        ));

        return Command::SUCCESS;
    }
}
