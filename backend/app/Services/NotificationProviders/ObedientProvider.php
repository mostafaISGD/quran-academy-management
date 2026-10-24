<?php

namespace App\Services\NotificationProviders;

use App\Models\NotificationDelivery;

/**
 * ⭐⭐⭐ **PR 4.1 — مزوّدات الاختبار (fakes).**
 *
 * ثلاثة صفوف صغيرة للاختبارات فقط — تُبنى بـ`config()` داخل
 * الاختبار (`providers.email` مثلاً). لا تستدعِها من الإنتاج:
 *
 * - `ObedientProvider` — ينجح ويقدّم معرّفاً خارجياً.
 * - `AlwaysBrokenProvider` — يفشل دائماً.
 * - `FailingThenHealingProvider` — يفشل حتى تُضبط `::$healthy`
 *   (يبرهن مسار «فشل ثم إعادة ناجحة»).
 */
class ObedientProvider implements NotificationProvider
{
    public function send(NotificationDelivery $delivery, array $payload): array
    {
        return ['external_id' => 'fake-'.uniqid()];
    }
}
