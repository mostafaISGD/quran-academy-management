<?php

namespace App\Jobs;

use App\Services\NotificationService;
use App\Support\DeliveryDeferredException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * ⭐⭐⭐ **PR 4.1 — وظيفة الطابور المفردة لكل تسليم إشعار.**
 *
 * كل صف `queued` يقابله جوب واحد — ينفّذ المحاولة عبر
 * `NotificationService::attemptDelivery()`.
 *
 * **سيرة الفشل**:
 *
 * - المزود فشل وما زالت محاولات متاحة ⇒ الصف سُجِّل `failed`
 *   مع نص سببه داخل الخدمة، ثم تُعاد الرمي (`DeliveryRetryableException`)
 *   ليعيد الطابور بمعامل `tries`/`backoff` حتى النجاح أو السقف.
 * - مؤجل إرادةً (ساعات الهدوء/قناة معلَّقة) ⇒ بلا رمي —
 *   الأمر المجدول `notifications:retry-queued` يقبضه لاحقاً.
 * - النهاية نهائية (سقف المحاولات) ⇒ الخدمة ترجع false بلا رمي.
 */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> انتظارات الإعادة (ثوانٍ) بين المحاولات. */
    public array $backoff = [60, 300];

    public function __construct(
        public int $deliveryId,
    ) {
    }

    public function handle(NotificationService $service): void
    {
        try {
            $service->attemptDelivery($this->deliveryId);
        } catch (DeliveryDeferredException) {
            // مؤجل بإرادة النظام — الصف بقي `queued` بلا عدّ،
            // والمحاولة القادمة على الأمر المجدول أو عامل لاحق.
            return;
        }

        // ⭐ `DeliveryRetryableException` تعود عمداً من الخدمة —
        // الطابور هو من يعيد إلى حين النجاح أو استنفاد السقف.
    }
}
