<?php

namespace App\Support;

/**
 * ⭐⭐⭐ **PR 4.1 — فشل تسليم قابل للإعادة.**
 *
 * يُرمى بعد أن يثبّت الصف `failed` مع نص السبب و`attempts`
 * محدَّثة — الطابور (tries/backoff) هو من يعيد المحاولة، أو
 * الأمر المجدول `notifications:retry-queued`.
 */
class DeliveryRetryableException extends \RuntimeException
{
}
