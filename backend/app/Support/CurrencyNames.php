<?php

namespace App\Support;

/**
 * ⭐⭐⭐ **PR 3.12 — أسماء العملات بالعربي (للعرض).**
 *
 * ⭐ عناوين الجداول المصدّرة كانت مكتوبة «ج.م» ثابتة — دلوقتي
 * التسمية بتعتمد على العملة الافتراضية من مركز الإعدادات.
 */
final class CurrencyNames
{
    /** @var array<string, string> كود العملة → الاسم العربي للعرض */
    private const ARABIC_NAMES = [
        'EGP' => 'ج.م',
        'USD' => 'دولار',
        'EUR' => 'يورو',
        'SAR' => 'ريال سعودي',
        'AED' => 'درهم إماراتي',
    ];

    /** ⭐ الاسم العربي للعملة، أو الكود نفسه لو مش معرّف. */
    public static function arabic(string $currency): string
    {
        return self::ARABIC_NAMES[strtoupper($currency)] ?? strtoupper($currency);
    }
}
