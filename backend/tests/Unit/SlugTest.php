<?php

namespace Tests\Unit;

use App\Support\Slug;
use PHPUnit\Framework\TestCase;

/**
 * توليد الـ slug — unit خالص، من غير داتابيز.
 *
 * الـ slug هنا **معرّف داخلي**: مش جزء من أي URL (الربط بالـ id).
 * فاللي يهمه إنه شخصي وصالح وثابت — مش لازم يكون جميل.
 *
 * قبل كده قلت إن `Str::slug` بيرجّع فاضي للعربي. مش صح —
 * Laravel بيحوّل عن طريق voku/portable-ascii. الاختبار ده بيثبّت
 * السلوك الفعلي (وبيفشل لو ده اتغيّر يوم).
 */
class SlugTest extends TestCase
{
    public function test_it_transliterates_arabic(): void
    {
        // السلوك الفعلي لـ Str::ascii($name, 'ar')
        $this->assertSame('thfyth-alkran', Slug::make(null, 'تحفيظ القرآن'));
        $this->assertSame('tgoyd', Slug::make(null, 'تجويد'));
    }

    public function test_preferred_value_wins_over_the_name(): void
    {
        // ده اللي بنظريه: الأدمن بيكتب slug يدوي فبيبقى اختياره
        $this->assertSame('tahfeeq', Slug::make('tahfeeq', 'تحفيظ القرآن'));
        $this->assertSame('tahfeeq', Slug::make('  tahfeeq  ', 'تحفيظ القرآن'));
    }

    public function test_preferred_value_is_normalised_not_taken_raw(): void
    {
        $this->assertSame('my-new-prog', Slug::make('My New Prog', 'أي اسم'));
        $this->assertSame('prog-2026', Slug::make('Prog_2026!', 'أي اسم'));
        $this->assertSame('tahfeeq-2', Slug::make('TAHFEEQ-2', 'أي اسم'));
    }

    public function test_blank_preferred_falls_back_to_the_name(): void
    {
        // المستخدم سيب الحقل فاضي — لازم نولّد من الاسم
        $this->assertSame('thfyth-alkran', Slug::make('', 'تحفيظ القرآن'));
        $this->assertSame('thfyth-alkran', Slug::make('   ', 'تحفيظ القرآن'));
        $this->assertSame('thfyth-alkran', Slug::make(null, 'تحفيظ القرآن'));
    }

    public function test_names_without_usable_characters_return_empty(): void
    {
        // الـ UI بتستخدم ده عشان تقول «اكتب معرّف بنفسك»
        $this->assertSame('', Slug::make(null, '؟؟؟'));
        $this->assertSame('', Slug::make(null, '   '));
        $this->assertSame('', Slug::make(null, ''));
    }

    public function test_latin_names_pass_through_cleanly(): void
    {
        $this->assertSame('program-2026', Slug::make(null, 'Program 2026'));
        $this->assertSame('tgoyd-2026', Slug::make(null, 'تجويد 2026'));
        $this->assertSame('a-b-g', Slug::make(null, 'أ ب ج'));
    }

    public function test_diacritics_do_not_break_transliteration(): void
    {
        // «تَحْفِيظ» بتشكيل — لازم تطلع زي من غير تشكيل
        $this->assertSame('thfyth', Slug::make(null, 'تَحْفِيظ'));
    }

    public function test_result_never_exceeds_the_length_cap(): void
    {
        $long = str_repeat('برنامج تجويد طويل جداً ', 40);
        $slug = Slug::make(null, $long);

        $this->assertLessThanOrEqual(Slug::MAX, mb_strlen($slug));
    }

    // ============================================================
    // unique()
    // ============================================================

    public function test_unique_returns_the_slug_when_free(): void
    {
        $slug = Slug::unique('tahfeeq', fn () => false);

        $this->assertSame('tahfeeq', $slug);
    }

    public function test_unique_appends_a_counter_on_collision(): void
    {
        $taken = ['tahfeeq' => true, 'tahfeeq-2' => true];

        $slug = Slug::unique('tahfeeq', fn ($s) => isset($taken[$s]));

        $this->assertSame('tahfeeq-3', $slug);
    }

    public function test_unique_uses_the_fallback_when_the_slug_is_empty(): void
    {
        // نفس الـ fallback في البرامج — غيره يبقى program جوه
        $slug = Slug::unique('', fn () => false, 'program');

        $this->assertSame('program', $slug);
    }

    public function test_unique_fallback_also_gets_a_counter(): void
    {
        $slug = Slug::unique('', fn ($s) => $s === 'program', 'program');

        $this->assertSame('program-2', $slug);
    }

    /**
     * ⚠️ الباج الحقيقي اللي اتصلح: الـ soft-deleted programs.
     *
     * الـ unique constraint في الداتابيز بيحسب الصفوف المحذوفة، فلو
     * الـ existence-check استثناها، الكود يقول «متاح» ولاقي
     * UniqueConstraintViolationException → 500.
     *
     * الاختبار ده بيحاكي سلوك الداتابيز: الـ callback بيرجّع true
     * للـ slug المحذوف نُور.
     */
    public function test_unique_checks_against_soft_deleted_rows_too(): void
    {
        // «program» محذوف (soft) — الداتابيز هترفض التكرار
        $deletedButPresent = ['program' => true];

        $slug = Slug::unique('', fn ($s) => isset($deletedButPresent[$s]), 'program');

        $this->assertSame(
            'program-2',
            $slug,
            'لازم يتجاوز الـ slug المحذوف، وإلا هنلاقي unique violation في الداتابيز'
        );
    }
}