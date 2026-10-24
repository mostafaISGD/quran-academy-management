<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\MemorizationRecord;
use App\Models\ProgressRecord;
use App\Models\QuranSurah;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.13 — التحقق من سجلات الحفظ والتقدم.**
 *
 * ⭐ **قبل PR ده** `LessonController::memorization` كان بيكتب
 * أي آيات بلا حصر بالسورة (سورة الفاتحة 7 آيات وتقدر تبعث
 * آية 300!)، وإعادة الإرسال كانت تضيف صفاً مكرراً لكل مرة،
 * والفئات الأخرى للتقدم (`tajweed`/`reading`/`revision`…)
 * موجودة في الجدول لكن الـAPI ما يكتبهاش.
 *
 * ⭐ **القبول:**
 *
 * ① آية خارج نطاق السورة = 422 وبلا أي كتابة.
 * ② إعادة الإرسال لنفس «الجزء» (الطالب + الحصة + السورة +
 *    نطاق الآيات) لا تكرر السجل — تدوّن القيمة الأخيرة.
 * ③ الفئات الأخرى تُكتب في `progress_records`.
 */
class MemorizationValidationTest extends TestCase
{
    use RefreshDatabase;

    protected QuranSurah $surah;

    protected function makeRecorder()
    {
        return $this->makeUserWithRole('memorization-recorder', ['lessons.update']);
    }

    protected function makeMeasurableSurah(): QuranSurah
    {
        return QuranSurah::create([
            'number' => 1,
            'name_ar' => 'الفاتحة',
            'name_en' => 'Al-Fatiha',
            'ayah_count' => 7,
            'revelation_type' => 'meccan',
        ]);
    }

    protected function makeSurahWithMoreAyahs(): QuranSurah
    {
        return QuranSurah::create([
            'number' => 2,
            'name_ar' => 'البقرة',
            'name_en' => 'Al-Baqarah',
            'ayah_count' => 286,
            'revelation_type' => 'medinan',
        ]);
    }

    protected function payload(QuranSurah $surah, int $from = 1, int $to = 5, int $quality = 4): array
    {
        return [
            'surah_id' => $surah->id,
            'from_ayah' => $from,
            'to_ayah' => $to,
            'quality' => $quality,
        ];
    }

    // ═══════════════════════════════════════════════════════════════
    // ① الآية خارج نطاق السورة = 422 وبلا كتابة
    // ═══════════════════════════════════════════════════════════════

    public function test_a_verse_over_the_surah_ayah_count_is_rejected(): void
    {
        $recorder = $this->makeRecorder();
        $surah = $this->makeMeasurableSurah(); // 7 آيات.
        [$lesson] = $this->makeCompletableLesson([], null);

        // ⭐ الفاتحة عندها 7 آيات — طلب (1..8) برة النطاق = 422.
        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/memorization",
            $this->payload($surah, 1, 8),
            $recorder,
        )->assertStatus(422);

        $this->assertSame(
            0,
            MemorizationRecord::count(),
            'الرفض سجّل حفظاً بأرشيف الحفظ',
        );
        $this->assertSame(
            0,
            ProgressRecord::count(),
            'الرفض سجّل تقدماً',
        );
    }

    public function test_a_verse_within_the_surah_range_is_accepted(): void
    {
        $recorder = $this->makeRecorder();
        $big = $this->makeSurahWithMoreAyahs(); // 286 آيات.
        [$lesson] = $this->makeCompletableLesson([], null);

        // ⭐ البقرة عندها 286 آية — (280..286) شريعي.
        $r = $this->postJsonAs(
            "/api/lessons/{$lesson->id}/memorization",
            $this->payload($big, 280, 286),
            $recorder,
        )->assertStatus(201);

        $this->assertSame(280, (int) $r->json('from_ayah'));
        $this->assertSame(286, (int) $r->json('to_ayah'));
        $this->assertSame(1, MemorizationRecord::count());
    }

    // ═══════════════════════════════════════════════════════════════
    // ② إعادة الإرسال لا تكرر السجل (نفس الجزء = صف واحد)
    // ═══════════════════════════════════════════════════════════════

    public function test_resubmitting_the_same_segment_does_not_duplicate(): void
    {
        $recorder = $this->makeRecorder();
        $surah = $this->makeMeasurableSurah();
        [$lesson] = $this->makeCompletableLesson([], null);

        // أول إرسال.
        $first = $this->postJsonAs(
            "/api/lessons/{$lesson->id}/memorization",
            $this->payload($surah, 1, 5, 3),
            $recorder,
        )->assertStatus(201);

        // ثاني إرسال لنفس الجزء بنفس القيمة والجودة.
        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/memorization",
            $this->payload($surah, 1, 5, 3),
            $recorder,
        )->assertSuccessful();

        $this->assertSame(
            1,
            MemorizationRecord::count(),
            'إعادة الإرسال ضاعفت صف الحفظ',
        );
        $this->assertSame(
            1,
            ProgressRecord::count(),
            'إعادة الإرسال ضاعفت صف التقدم',
        );

        // ⭐ تحديث الجودة لنفس الجزء = تدوين آخر قيمة (بلا صف تاني).
        $updated = $this->postJsonAs(
            "/api/lessons/{$lesson->id}/memorization",
            $this->payload($surah, 1, 5, 5),
            $recorder,
        )->assertSuccessful();

        $this->assertSame(
            1,
            MemorizationRecord::count(),
            'تحديث الجودة نفسها فتح صف جديد',
        );
        $this->assertSame(
            5,
            (int) MemorizationRecord::first()->quality,
            'الجودة الجديدة ما اتدوّنتش',
        );
        $this->assertSame((string) $first->json('id'), (string) $updated->json('id'));
    }

    public function test_the_same_segment_in_another_lesson_or_surah_is_a_new_record(): void
    {
        $recorder = $this->makeRecorder();
        $surah = $this->makeMeasurableSurah();
        [$lessonA] = $this->makeCompletableLesson([], null);
        [$lessonB] = $this->makeCompletableLesson([], null);

        $this->postJsonAs(
            "/api/lessons/{$lessonA->id}/memorization",
            $this->payload($surah, 1, 5, 4),
            $recorder,
        )->assertStatus(201);

        $this->postJsonAs(
            "/api/lessons/{$lessonB->id}/memorization",
            $this->payload($surah, 1, 5, 4),
            $recorder,
        )->assertSuccessful();

        // ⭐ نفس الجزء في **حصة تانية** = سجل مستقل (مفتاح التكرار
        // يجمع الطالب + الحصة + الجزء).
        $this->assertSame(2, MemorizationRecord::count());
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ الفئات الأخرى للتقدم
    // ═══════════════════════════════════════════════════════════════

    public function test_the_tajweed_category_writes_a_progress_record(): void
    {
        $recorder = $this->makeRecorder();
        [$lesson] = $this->makeCompletableLesson([], null);

        $r = $this->postJsonAs(
            "/api/lessons/{$lesson->id}/memorization",
            [
                'category' => 'tajweed',
                'quality' => 4,
                'notes' => 'خروج واضح في المدّال',
            ],
            $recorder,
        )->assertSuccessful();

        $this->assertSame('tajweed', $r->json('category'));

        $this->assertSame(
            1,
            ProgressRecord::where('category', 'tajweed')->count(),
            'فئة التاجيد ما اتسجلتش في التقدم',
        );
        $this->assertSame(
            0,
            MemorizationRecord::count(),
            'فئة التاجيد سجلت حفظاً',
        );
    }

    public function test_the_reading_and_revision_categories_are_writable(): void
    {
        $recorder = $this->makeRecorder();
        [$lesson] = $this->makeCompletableLesson([], null);

        foreach (['reading', 'revision'] as $category) {
            $r = $this->postJsonAs(
                "/api/lessons/{$lesson->id}/memorization",
                ['category' => $category, 'quality' => 3],
                $recorder,
            )->assertSuccessful();

            $this->assertSame($category, $r->json('category'));
        }

        $this->assertSame(
            2,
            ProgressRecord::count(),
            'الفئتين ما اتكتبتش في التقدم',
        );
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        $recorder = $this->makeRecorder();
        [$lesson] = $this->makeCompletableLesson([], null);

        $this->postJsonAs(
            "/api/lessons/{$lesson->id}/memorization",
            ['category' => 'magic', 'quality' => 4],
            $recorder,
        )->assertStatus(422);

        $this->assertSame(0, ProgressRecord::count());
    }
}
