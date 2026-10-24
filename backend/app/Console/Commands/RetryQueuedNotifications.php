<?php

namespace App\Console\Commands;

use App\Models\NotificationDelivery;
use App\Services\NotificationService;
use App\Support\DeliveryDeferredException;
use App\Support\DeliveryRetryableException;
use Illuminate\Console\Command;

/**
 * ⭐⭐⭐ **PR 4.1 — إعادة فتح صفوف الإشعارات المتأخرة.**
 *
 * يمرّ على صفوف تسليم `queued` أو `failed` (ومعها سقف
 * محاولات متاح) ويحاول كل واحدة من جديد عن طريق
 * `NotificationService::attemptDelivery()`:
 *
 * - ساعات الهدوء/القنوات المؤجلة تبقى مؤجلة — بلا عدّ محاولات
 *   ولا حالة جديدة.
 * - الفشل الجديد يُسجل بنص سببه — وبعد استنفاد السقف تتوقف
 *   الصفوف عن الأظهر هنا نهائياً.
 * - يُجدول كل 5 دقائق (routes/console.php) — وهو الذي يحمل
 *   إعادة المحاولة في بيئات تشغيل بلا عامل طوابير مخصص،
 *   بجانب الطابور (tries/backoff) الذي يلعب أولاً.
 */
class RetryQueuedNotifications extends Command
{
    protected $signature = 'notifications:retry-queued
        {--limit=100 : أقصى عدد صفوف في التشغيل الواحد}';

    protected $description = 'محاولة تسليم صفوف الإشعارات المتأخرة (queued/failed) من جديد';

    public function handle(NotificationService $service): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $deliveries = NotificationDelivery::query()
            ->whereIn('status', ['queued', 'failed'])
            ->where('attempts', '<', NotificationService::MAX_ATTEMPTS)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($deliveries->isEmpty()) {
            $this->info('لا صفوف متأخرة حالياً');

            return self::SUCCESS;
        }

        $sent = 0;
        $deferred = 0;
        $failed = 0;

        foreach ($deliveries as $delivery) {
            try {
                if ($service->attemptDelivery($delivery->id)) {
                    $sent++;
                } else {
                    $failed++;
                }
            } catch (DeliveryDeferredException) {
                $deferred++; // مؤجل إرادةً — بلا عدّ ولا حالة جديدة.
            } catch (DeliveryRetryableException) {
                $failed++; // سُجِّل فشلاً بنص سببه — وإعادته لاحقاً.
            }
        }

        $this->info("إشعارات متأخرة: أُرسل {$sent} · مؤجل {$deferred} · فشل {$failed}");

        return self::SUCCESS;
    }
}
