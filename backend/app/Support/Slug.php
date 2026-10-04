<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * توليد الـ slug لمكان واحد بس في كل النظام.
 *
 * لissoو service/class مستقلة مش helper في الـ controller؟ لأن في
 * precedent التنين بس بيولّدوا slug (البرامج + التصنيفات)، وقواعد
 * الـ uniqueness مختلفة عن بعض (البرنامج soft-delete). فلو كررنا
 * المنطق في المكانين هيتفرق من غير ما حد ياخد باله.
 *
 * الـ slug هنا **معرّف داخلي** — مش جزء من أي URL (الربط بالـ id)،
 * فاللي يهمه إنه: شخصي، صحيح الشكل، وثابت. مش لازم يكون جميل.
 */
final class Slug
{
    /** أقصى طول — عشان يفضل قصير ومقروء في اللوجات */
    public const MAX = 120;

    /**
     * تطبيع المُدخل وتحويله لـ slug صالح.
     *
     * @param  string|null  $preferred  المُدخل اللي اليوزر كتبه (متشالي)
     * @param  string       $source     الاسم العربي — مصدر التلقيل
     * @return string  slug نظيف، أو '' لو مفيش أي حرف صالح
     */
    public static function make(?string $preferred, string $source): string
    {
        $candidate = self::normalize($preferred);

        if ($candidate === '') {
            $candidate = self::normalize($source);
        }

        // تطبيع بيشيل التشكيل ويحوّل العربي لـ latin — فالرموز
        // لوحدها («؟؟؟») أو المسافات بترجّع نص فاضي.
        return mb_substr($candidate, 0, self::MAX);
    }

    /**
     * تنظيف خام: حروف لاتينية صغيرة + أرقام + شرطة واحدة بين الكلمات.
     *
     * `Str::slug` بيعمل ده، بس إحنا بناديه على `Str::ascii` الأول
     * عشان يضمن تحويل العربي (Str::slug بيعملها أصلاً، بس صريح
     * أحسن من الاعتماد على تفاصيل جوّية).
     */
    private static function normalize(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return Str::slug(Str::ascii($value, 'ar'));
    }

    /**
     * يضمن فريدية الـ slug.
     *
     * ⚠️ لازم الـ existence-check يشمل الصفوف المحذوفة (soft delete).
     * الـ unique constraint في الداتابيز بيحسبها، فلو استثنيناها
     * هنقول «متاح» ونلاقي UniqueConstraintViolationException.
     *
     * @param  callable(string): bool  $exists
     */
    public static function unique(string $slug, callable $exists, string $fallback = 'item'): string
    {
        $base = $slug !== '' ? $slug : $fallback;
        $candidate = $base;
        $i = 2;

        while ($exists($candidate)) {
            $suffix = "-{$i}";
            // نقص من الأساس عشان الطول الكلي يفضل تحت الحد
            $candidate = mb_substr($base, 0, self::MAX - mb_strlen($suffix)) . $suffix;
            $i++;
        }

        return $candidate;
    }
}