<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * @deprecated استُبدل بـ ProcessSubscriptionDay.
 *
 * الأمر ده كان بيبعت الإشعارات على student.user — وهو null لكل الـ
 * ٨٠ طالب، فكان بيطبع إنه بعت إشعارات وهو بعت صفر. وكل المنطق
 * انتقل لـ DailySubscriptionService.
 *
 * الأمر موجود كاسم قديم عشان أي سكربت متعوّط عليه مايفشلش فجأة.
 */
class CheckSubscriptionExpiry extends Command
{
    protected $signature = 'subscriptions:check-expiry {--date=}';

    protected $description = '[مهمل] استخدم subscriptions:process-day';

    public function handle(): int
    {
        $this->warn('الأمر ده مهمل — بيشغّل subscriptions:process-day');

        return $this->call('subscriptions:process-day', array_filter([
            '--date' => $this->option('date'),
        ]));
    }

    /** @deprecated المنطق كله في DailySubscriptionService */
    private function legacyRun(): void
    {
        $today = Carbon::today();

        // 1. Notify for subscriptions expiring within 3 days.
        $expiringSoon = Subscription::query()
            ->where('status', 'active')
            ->whereBetween('end_date', [$today, $today->copy()->addDays(3)])
            ->with('student.user')
            ->get();

        foreach ($expiringSoon as $subscription) {
            // Notify student.
            if ($subscription->student?->user) {
                Notification::create([
                    'user_id' => $subscription->student->user->id,
                    'event_type' => 'subscription_expiring',
                    'channel' => 'in_app',
                    'payload' => [
                        'subscription_id' => $subscription->id,
                        'end_date' => $subscription->end_date->toDateString(),
                    ],
                    'sent_at' => now(),
                ]);
            }
        }

        $this->info("Expiring soon notifications sent: {$expiringSoon->count()}");

        // 2. Mark as expired if end_date passed and not renewed.
        $expired = Subscription::query()
            ->where('status', 'active')
            ->where('end_date', '<', $today)
            ->get();

        foreach ($expired as $subscription) {
            $subscription->update(['status' => 'expired']);

            // Notify student.
            if ($subscription->student?->user) {
                Notification::create([
                    'user_id' => $subscription->student->user->id,
                    'event_type' => 'subscription_expired',
                    'channel' => 'in_app',
                    'payload' => ['subscription_id' => $subscription->id],
                    'sent_at' => now(),
                ]);
            }
        }

        $this->info("Subscriptions marked as expired: {$expired->count()}");
    }
}
