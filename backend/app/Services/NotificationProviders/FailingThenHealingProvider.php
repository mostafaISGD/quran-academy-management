<?php

namespace App\Services\NotificationProviders;

use App\Models\NotificationDelivery;
use RuntimeException;

/** ⭐⭐ مزوّد اختباري يفشل حتى تُضبط `::$healthy` — يبرهن على الإصلاح بالإعادة. */
class FailingThenHealingProvider implements NotificationProvider
{
    public static bool $healthy = false;

    public function send(NotificationDelivery $delivery, array $payload): array
    {
        if (! self::$healthy) {
            throw new RuntimeException('مزود الاختبار — الصلة مقسومة');
        }

        return ['external_id' => 'healed-'.uniqid()];
    }
}
