<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Carbon;

/**
 * ⭐ PR 1.12 — عمر الطالب.
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه خدمة من 15 سطر؟
 *
 * لأن «عمره 12» بيتحسب في **4 أماكن** (إنشاء الحساب، الموافقة،
 * عرض الواجهة، تقرير)، ولو كل واحد عمل `diffInYears()` لوحده،
 * هنبوت في 4 أرقام مختلفة في نفس اللحظة.
 *
 * ⭐ وعلى فكرة: `diffInYears` بتاخد **التقريب** — يعني طفل
 * `1990-05-20` و`now = 2010-05-19` بيطلعله 20 وهو لسه 19. والقاعدة
 * هنا **التقويم مش التقريب**: age = عدد السنوات التامة.
 */
class StudentAge
{
    /** ⭐ تحت سن الـroadmap: 13 */
    public const MINOR_UNDER = 13;

    /**
     * ⭐ العمر بالسنوات التامة (مش بتقريب).
     */
    public static function years(?Student $student, ?Carbon $at = null): ?int
    {
        $dob = self::birthDate($student);

        if ($dob === null) {
            return null;
        }

        return $dob->diffInYears($at ?? now());
    }

    /**
     * ⭐ القاصر أقل من 13؟ ومافيش تاريخ ميلاد = **قاصر**.
     *
     * ⭐ سبب اعتبار المجهول قاصر: العمر المجهول مينفعش يبرر
     * حساب مستقل — والأدمن هو اللي لازم يكمّل التاريخ. الافتراضي
     * الأكثر تحفظاً هو الوحيد الآمن.
     */
    public static function isMinor(?Student $student, ?Carbon $at = null): bool
    {
        $years = self::years($student, $at);

        return $years === null || $years < self::MINOR_UNDER;
    }

    private static function birthDate(?Student $student): ?Carbon
    {
        $raw = $student?->date_of_birth;

        if ($raw === null || $raw === '') {
            return null;
        }

        return $raw instanceof Carbon ? $raw->copy() : Carbon::parse((string) $raw);
    }
}