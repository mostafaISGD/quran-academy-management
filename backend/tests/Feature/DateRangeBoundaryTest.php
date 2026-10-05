<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ⭐ حدود الفترات الزمنية.
 *
 * الباج: `whereBetween('date', ['2026-03-01', '2026-03-31'])`.
 *
 * لو العمود متخزّن `2026-03-31 00:00:00`، المقارنة **نصية** =>
 * `'2026-03-31 00:00:00' > '2026-03-31'` (لأن النص أطول). فآخر
 * يوم في الفترة دايماً بيقع **برّه** النتيجة.
 *
 * يعني: «مارس» ناقص يوم، وكل تقرير بيقلّل. مش بيظهر في الـ UI
 * (٥٠٠ ولا error)، بيظهر في **رقم غلط** — وده أخطر.
 *
 * الاختبارات دي بتقفل السلوك الصح لكل استعلام فترة في النظام.
 */
class DateRangeBoundaryTest extends TestCase
{
    private const START = '2026-03-01';

    private const END = '2026-03-31';

    // ============================================================
    // ⭐ Eigenartig: الـ cast وحده بيخلي التاريخ «datetime»
    // ============================================================

    /**
     * المسجّل عبر Eloquent (cast `date`) بيتخزّن `00:00:00`.
     *
     * والـ seeder (insert مباشر) بيخزّن `2026-03-31` نضيف.
     * يعني الجدول نفسه فيه **صيغتين** لنفس اليوم.
     */
    public function test_two_storage_formats_coexist_in_the_same_table(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        // عبر Eloquent — cast date
        $viaCast = \App\Models\TeacherEarning::create([
            'teacher_id' => $t->id,
            'amount' => 100,
            'currency' => 'EGP',
            'earning_date' => '2026-03-31',
            'status' => 'approved',
        ]);

        $stored = \Illuminate\Support\Facades\DB::table('teacher_earnings')
            ->where('id', $viaCast->id)->value('earning_date');

        $this->assertStringContainsString(
            '00:00:00',
            (string) $stored,
            'الـ cast `date` بيخزّن وقت — وده اللي كان بيكسر whereBetween'
        );
    }

    /**
     * ⭐ `whereBetween` بنص تاريخ بيضيّع آخر يوم.
     *
     * ده السلوك اللي كان خرّب الأرقام. الاختبار بيوثّقه عشان
     * لو صلّحناه في أي مكان، ده يبان.
     */
    public function test_where_between_with_bare_dates_drops_the_last_day(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        foreach (['2026-03-01', '2026-03-15', '2026-03-31'] as $day) {
            $this->makeEarning($t, $day, ['amount' => 100]);
        }

        $bare = \Illuminate\Support\Facades\DB::table('teacher_earnings')
            ->whereBetween('earning_date', [self::START, self::END])->count();

        $this->assertSame(
            2, $bare,
            '٣ سطور، «whereBetween» بنص تاريخ رجّع ٢ — آخر يوم ضاع'
        );
    }

    // ============================================================
    // الحل: حدود بت terminating 23:59:59
    // ============================================================

    /**
     * ⭐ الحدود الصحيحة: آخر اليوم مش آخر تاريخ.
     *
     * `whereBetween(col, ['2026-03-01 00:00:00', '2026-03-31 23:59:59'])`
     * بيلقط كل حاجة في يوم ٣١ — سواء متخزّنة `00:00:00` ولا وقت
     * حقيقي زي `16:30:00`.
     */
    public function test_full_day_bounds_capture_the_whole_last_day(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        foreach (['2026-03-01', '2026-03-15', '2026-03-31'] as $day) {
            $this->makeEarning($t, $day, ['amount' => 100]);
        }

        $full = \Illuminate\Support\Facades\DB::table('teacher_earnings')
            ->whereBetween('earning_date', [self::START.' 00:00:00', self::END.' 23:59:59'])
            ->count();

        $this->assertSame(3, $full, 'التلاتة كلهم');
    }

    /**
     * وكمان لازم وقت حقيقي في يوم ٣١ يتلقّى.
     *
     * الـ cast بيخزّن `00:00:00`، بس الحصة بوقتها `16:30:00`.
     * الحالتين لازم تتحسب.
     */
    public function test_a_real_timestamp_on_the_last_day_is_captured(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        // lesson ليها وقت حقيقي (scheduled_start_at = datetime)
        $p = $this->makeProgram();
        $s = $this->makeStudent();
        $this->makeLesson($p, $s, $t, [
            'status' => 'completed',
            'scheduled_start_at' => '2026-03-31 16:30:00',
            'scheduled_end_at' => '2026-03-31 17:00:00',
            'duration_minutes' => 30,
        ]);

        $bare = \Illuminate\Support\Facades\DB::table('lessons')
            ->whereBetween('scheduled_start_at', [self::START, self::END])->count();

        $full = \Illuminate\Support\Facades\DB::table('lessons')
            ->whereBetween('scheduled_start_at', [self::START.' 00:00:00', self::END.' 23:59:59'])
            ->count();

        $this->assertSame(0, $bare, 'بنص تاريخ: الحصة الأخيرة اتشالت ⭐');
        $this->assertSame(1, $full, 'بحدود كاملة: الحصة اتحسبت ✅');
    }

    // ============================================================
    // ⭐ الحارس: مفيش `whereBetween` بنص تاريخ في الكود كله
    // ============================================================

    /**
     * ⭐ مفيش استعلام فترة بنص تاريخ في أي controller.
     *
     * الـ bug كان منتشر في أكتر من ملف. صعب نلحقه يدوي لو كل
     * مرة، فالـ guard بيفحص كل الـ controllers مرة واحدة.
     *
     * المسموح: `toDateString()` أو `startOfDay`/`endOfDay` أو
     * حدود فيها وقت. الممنوع: نص تاريخ مجرّد.
     */
    public function test_no_controller_uses_bare_dates_in_where_between(): void
    {
        $offenders = [];

        foreach (glob(app_path('Http/Controllers/Api/*.php')) as $file) {
            $src = (string) file_get_contents($file);
            $name = class_basename($file);

            // نقطّ التعليقات ونصوص الـ API
            $code = preg_replace(['#//.*$#m', '#/\*.*?\*/#s'], '', $src);

            foreach (preg_split('/\R/', (string) $code) as $no => $line) {
                if (! str_contains($line, 'whereBetween')) {
                    continue;
                }

                // ✅ لو فيه وقت في الحدود أو دالة بتحوّل لتاريخ
                $safe = str_contains($line, '00:00:00')
                    || str_contains($line, '23:59:59')
                    || str_contains($line, 'startOfDay')
                    || str_contains($line, 'endOfDay')
                    || str_contains($line, 'toDateString')
                    || str_contains($line, 'format(');

                if (! $safe) {
                    $offenders[] = "{$name}:" . ($no + 1) . ' → '.trim($line);
                }
            }
        }

        $this->assertSame([], $offenders,
            "whereBetween بنص تاريخ — آخر يوم بيقع برّه:\n".implode("\n", $offenders));
    }

    /** sanity: الـ guard نفسه بيشتغل على الكود الحقيقي */
    public function test_the_boundary_guard_actually_catches_the_bug(): void
    {
        $bad = "->whereBetween('date', ['2026-03-01', '2026-03-31'])";

        $isSafe = str_contains($bad, '00:00:00')
            || str_contains($bad, '23:59:59')
            || str_contains($bad, 'startOfDay')
            || str_contains($bad, 'endOfDay')
            || str_contains($bad, 'toDateString')
            || str_contains($bad, 'format(');

        $this->assertFalse($isSafe, 'guard مش بيمسك whereBetween بنص تاريخ');
    }

    // ============================================================
    // ⭐ الحل: macro واحد للكل
    // ============================================================

    /**
     * ⭐ `whereRange` بيلقط آخر يوم زي ما لازم.
     *
     * ده البديل اللي بقى مستخدم في كل الـ ٢٦ مكان.
     */
    public function test_where_range_macro_captures_the_last_day(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        foreach (['2026-03-01', '2026-03-15', '2026-03-31'] as $day) {
            $this->makeEarning($t, $day, ['amount' => 100]);
        }

        $count = \Illuminate\Support\Facades\DB::table('teacher_earnings')
            ->whereRange('earning_date', self::START, self::END)
            ->count();

        $this->assertSame(3, $count, 'التلاتة كلهم — آخر day اتحسب ✅');
    }

    /** والـ macro بيشتغل على Eloquent كمان مش Query Builder بس */
    public function test_where_range_macro_works_on_eloquent(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);
        $this->makeEarning($t, self::END, ['amount' => 100]);

        $count = \App\Models\TeacherEarning::whereRange('earning_date', self::START, self::END)->count();

        $this->assertSame(1, $count);
    }

    /** شهر كامل */
    public function test_where_month_range_macro(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        $this->makeEarning($t, '2026-03-01', ['amount' => 100]);
        $this->makeEarning($t, '2026-03-31', ['amount' => 100]);
        $this->makeEarning($t, '2026-04-01', ['amount' => 100]); // برّه

        $count = \Illuminate\Support\Facades\DB::table('teacher_earnings')
            ->whereMonthRange('earning_date', '2026-03')
            ->count();

        $this->assertSame(2, $count, 'مارس بس — يوم ١ أبريل برّه');
    }

    /** يوم واحد */
    public function test_where_same_day_macro(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        $this->makeEarning($t, '2026-03-15', ['amount' => 100]);
        $this->makeEarning($t, '2026-03-16', ['amount' => 100]);

        $count = \Illuminate\Support\Facades\DB::table('teacher_earnings')
            ->whereSameDay('earning_date', '2026-03-15')
            ->count();

        $this->assertSame(1, $count);
    }

    /**
     * ⚠️ `whereDay` اسم محجوز في Laravel.
     *
     * كان الماكرو بتاعنا مسمّى `whereDay`، فلما اتعمل اتعمّى على
     * method حقيقية في `Illuminate\Database\Query\Builder` وبقى
     * signature بتاعنا غلط بصمت — النتيجة `0` من غير error.
     *
     * الاختبار ده بيمنع حد يعمل نفس الغلط.
     */
    public function test_where_day_is_not_used_because_laravel_owns_that_name(): void
    {
        $ref = new \ReflectionClass(\Illuminate\Database\Query\Builder::class);

        $this->assertTrue(
            $ref->hasMethod('whereDay'),
            'لو Laravel شال `whereDay`، ينفع نرجع للاسم بس مش لازم'
        );

        $source = file_get_contents(
            app_path('Providers/AppServiceProvider.php')
        );

        $this->assertStringNotContainsString(
            "macro('whereDay'",
            (string) $source,
            '`whereDay` محجوزة لـ Laravel — استخدم `whereSameDay`'
        );
    }

    /** حصة الساعة الأخيرة في اليوم — لازم تتحسب (ده سبب وجود `23:59:59`) */
    public function test_a_lesson_at_the_last_minute_of_the_day_is_captured(): void
    {
        $t = $this->makeActiveTeacher(['display_name' => 'معلم']);

        $this->makeLesson(
            $this->makeProgram(), $this->makeStudent(), $t,
            [
                'status' => 'completed',
                'scheduled_start_at' => '2026-03-31 23:30:00',
                'scheduled_end_at' => '2026-03-31 23:59:00',
                'duration_minutes' => 29,
            ],
        );

        $count = \App\Models\Lesson::whereRange('scheduled_start_at', self::START, self::END)->count();

        $this->assertSame(1, $count, 'آخر دقيقة في آخر يوم — لازم تتحسب ✅');
    }
}