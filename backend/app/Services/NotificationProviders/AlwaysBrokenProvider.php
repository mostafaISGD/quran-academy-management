<?php

namespace App\Services\NotificationProviders;

use App\Models\NotificationDelivery;
use RuntimeException;

/** ⭐⭐ مزوّد اختباري يفشل دائماً — يبرهن على حالة `failed` بسببه. */
class AlwaysBrokenProvider implements NotificationProvider
{
    public function send(NotificationDelivery $delivery, array $payload): array
    {
        throw new RuntimeException('مزود الاختبار — فشل مقيم');
    }
}
