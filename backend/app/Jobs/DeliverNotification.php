<?php

namespace App\Jobs;

use App\Services\NotificationService;
use App\Support\DeliveryDeferredException;
use App\Support\DeliveryRetryableException;
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
 * **سيرة الفشل** (معدَّلة في PR 4.2):
 *
 * - ⭐ **كل الفشل يُسجَّل في الخدمة ولا يعود للطابور** —
 *   `DeliveryRetryableException` تُلتهم هنا ولا تُرمى: تحت
 *   `QUEUE_CONNECTION=sync` كانت سترتد إلى مسار النداء نفسه
 *   (تسجيل دفعة يفشل لأن إيميل مزوّده سقط — علة حدثت فعلاً
 *   خلال دمج الأحداث اللحظية) — إعادة المحاولة **أمر الأمانة**
 *   `notifications:retry-queued` (كل 5 دقائق) من الكاش والمقيد
 *   بـ`MAX_ATTEMPTS`. لذا لا `tries`/`backoff` هنا — محاولة
 *   واحدة لكل جوب والحسم عند الخدمة.
 * - مؤجل إرادةً (ساعات الهدوء/قناة معلَّقة) ⇒ بلا رمي —
 *   الأمر المجدول `notifications:retry-queued` يقبضه لاحقاً.
 * - النهاية نهائية (سقف المحاولات) ⇒ الخدمة ترجع false بلا رمي.
 */
class DeliverNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
        } catch (DeliveryRetryableException) {
            // ⭐ PR 4.2 — الفشل المسجل (`failed` + سببه) يكفي؛
            // إعادة المحاولة من `notifications:retry-queued` —
            // بلا ارتداد للطابور ولا لمسار النداء الأصلي.
            return;
        }
    }
}
