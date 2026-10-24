<?php

namespace App\Rules;

use App\Support\MeetingLinks;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * ⭐⭐⭐ **PR 3.11 — رابط الاجتماع من مزود مسموح.**
 *
 * ✅ رابط zoom.us / meet.google.com / teams.microsoft.com
 *    (أو الإعداد بمؤسسة) ⇒ فيّه.
 * ✅ **فارغ** ⇒ صحيح (الرابط بيتعبى تلقائيًا من الرابط الافتراضي
 *    للمعلم).
 * ❌ أي مضيف خارج القائمة ⇒ 422 برسالة عربية.
 */
class MeetingLinkRule implements ValidationRule
{
    public function __construct(?int $organizationId = null)
    {
        $this->organizationId = $organizationId;
    }

    private ?int $organizationId;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! MeetingLinks::allowed($this->organizationId, is_string($value) ? $value : null)) {
            $fail('رابط الاجتماع غير مسموح — الروابط بتقبل من Zoom أو Google Meet أو Microsoft Teams بس');
        }
    }
}
