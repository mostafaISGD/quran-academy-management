<?php

namespace Tests\Feature;

use App\Models\LessonCreditTransaction;
use App\Models\QuranSurah;
use App\Models\Setting;
use App\Models\TeacherEarning;
use App\Services\TeacherLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.10 — بوابة المعلم (`/teacher/*`).**
 *
 * ⭐ **قبل PR ده** المعلم كان محتاج صلاحيات إدارية
 * (`lessons.view`/`lessons.update`) يجدول حصصه ويسجّل حضوره —
 * والقائمة كانت بتورّل بيانات ولّى الأمر للجميع.
 *
 * ⭐ **القبول:**
 *
 * ① المعلم **يكمّل حصصه هو فقط** — حصص معلم تانٍ = **403** وبلا
 *    أي أثر على الحصة.
 * ② كل مسارات البوابة في مصفوفة الحماية (بصلاحية
 *    `teacher.portal`).
 * ③ هاتف ولي الأمر **مخفي افتراضيًا** — يظهر بس بإعداد.
 */
class TeacherPortalTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ① «اليوم» و«الأسبوع» — حصص المعلم فقط
    // ═══════════════════════════════════════════════════════════════

    public function test_today_shows_only_the_teachers_own_lessons_of_today(): void
    {
        [$user, $teacher] = $this->portalTeacher();

        [$today] = $this->makeCompletableLesson([
            'scheduled_start_at' => now()->startOfDay()->addHours(10),
            'scheduled_end_at' => now()->startOfDay()->addHours(10)->addMinutes(30),
        ], $teacher);
        $this->assertSame($teacher->id, $today->teacher_id);

        // حصة لنفس المعلم بس **بعد اليوم** — مش في «اليوم».
        $this->makeLesson($today->program, $today->student, $teacher, [
            'scheduled_start_at' => now()->addDays(2)->setTime(10, 0),
            'scheduled_end_at' => now()->addDays(2)->setTime(10, 30),
        ]);

        // حصة صاحبتها معلم تاني — **ممنوع** تظهر أي وقت.
        $this->makeCompletableLesson(); // معلم/طالب/برنامج جداد

        $lessons = $this->getJsonAs('/api/teacher/today', $user)
            ->assertOk()
            ->json('lessons.*.id');

        $this->assertEquals([$today->id], $lessons, '«اليوم» لازم يرجّع حصص المعلم نفسه فقط');
    }

    public function test_week_shows_the_teachers_own_lessons_of_the_current_week(): void
    {
        [$user, $teacher] = $this->portalTeacher();
        [$program, $student] = $this->cheapProgramStudent();

        // ⭐ داخليان مضمونان: اليوم + بداية الأسبوع نفسه.
        $today = $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => now()->startOfDay()->addHours(9),
            'scheduled_end_at' => now()->startOfDay()->addHours(9)->addMinutes(30),
        ]);
        $weekOpen = $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => now()->startOfWeek()->setHours(8),
            'scheduled_end_at' => now()->startOfWeek()->setHours(8)->addMinutes(30),
        ]);

        // ⭐ خارج الأسبوع مضمون: بعد نهايته بيومين.
        $outside = $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => now()->endOfWeek()->addDays(2)->setHours(9),
            'scheduled_end_at' => now()->endOfWeek()->addDays(2)->setHours(9)->addMinutes(30),
        ]);

        $lessons = $this->getJsonAs('/api/teacher/week', $user)
            ->assertOk()
            ->json('lessons.*.id');

        $this->assertContains($today->id, $lessons, 'حصة اليوم لازم تكون في الأسبوع');
        $this->assertContains($weekOpen->id, $lessons, 'حصة بداية الأسبوع لازم تكون في نطاقه');
        $this->assertNotContains($outside->id, $lessons, 'حصة بعد نهاية الأسبوع مش في نطاقه');
    }

    // ═══════════════════════════════════════════════════════════════
    // ② دورة العمل: بداية → إتمام → حضور → حفظ
    // ═══════════════════════════════════════════════════════════════

    public function test_a_teacher_starts_and_completes_their_own_lesson(): void
    {
        [$user, $teacher] = $this->portalTeacher();
        [$lesson] = $this->makeCompletableLesson([], $teacher);
        $this->makeCompletableLesson(); // حصة تانية — عشان نشوف إن الرد خاص بالحصة المطلوبة

        $started = $this->postJsonAs("/api/teacher/lessons/{$lesson->id}/start", [], $user)
            ->assertOk()
            ->json();

        $this->assertSame('in_progress', $started['status'], 'البداية بتدخل الحصة in_progress');

        $completed = $this->postJsonAs("/api/teacher/lessons/{$lesson->id}/complete", [], $user)
            ->assertOk()
            ->json();

        $this->assertSame('completed', $completed['status'], 'الإتمام بتكمّل الحصة');

        // ⭐ الأثر المالي: خصم رصيد + مستحق للمعلم.
        $this->assertSame(
            1,
            LessonCreditTransaction::where('lesson_id', $lesson->id)->where('type', 'lesson_used')->count(),
            'البوابة بتخصم رصيد زي المسار الإداري بالظبط',
        );
    }

    public function test_a_teacher_records_attendance_and_memorization_to_their_lesson(): void
    {
        [$user, $teacher] = $this->portalTeacher();
        [$lesson] = $this->makeCompletableLesson([], $teacher);

        $this->postJsonAs("/api/teacher/lessons/{$lesson->id}/attendance", [
            'status' => 'present',
        ], $user)->assertSuccessful();

        $this->assertSame(
            'completed',
            $lesson->fresh()->status,
            'الحضور (حاضر) من البوابة لازم يكمّل الحصة زي المسار الإداري',
        );

        $surah = QuranSurah::create([
            'number' => 1,
            'name_ar' => 'الفاتحة',
            'name_en' => 'Al-Fatiha',
            'ayah_count' => 7,
            'revelation_type' => 'meccan',
        ]);

        $r = $this->postJsonAs("/api/teacher/lessons/{$lesson->id}/memorization", [
            'surah_id' => $surah->id,
            'from_ayah' => 1,
            'to_ayah' => 5,
            'quality' => 4,
        ], $user)->assertStatus(201);

        $progress = $r->json();

        // ⭐ الرقم بيرتبط بالسورة والطالب والمعلّم الصحيحين (مش أي أحد).
        $this->assertSame((int) $surah->id, (int) $progress['surah_id']);
        $this->assertSame($lesson->student_id, $progress['student_id']);
        $this->assertSame((int) $lesson->teacher_id, (int) $progress['teacher_id']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ القبول: حصة معلم تانٍ = 403 وبلا أي أثر
    // ═══════════════════════════════════════════════════════════════

    public function test_touching_another_teachers_lesson_is_403_and_changes_nothing(): void
    {
        [$user] = $this->portalTeacher();

        // حصة معلم تاني (الـfixture بيرجّع المعلم التاني).
        [$foreign, $foreignTeacher] = $this->makeCompletableLesson();

        // ⭐ بداية حصة المعلم التاني.
        $this->postJsonAs("/api/teacher/lessons/{$foreign->id}/start", [], $user)
            ->assertStatus(403);

        // ⭐ إتمامها.
        $this->postJsonAs("/api/teacher/lessons/{$foreign->id}/complete", [], $user)
            ->assertStatus(403);

        // ⭐ تسجيل حضور لها.
        $this->postJsonAs("/api/teacher/lessons/{$foreign->id}/attendance", [
            'status' => 'present',
        ], $user)->assertStatus(403);

        // ⭐ بحصة ما اتأثرت خالص.
        $fresh = $foreign->fresh();
        $this->assertSame('scheduled', $fresh->status, 'حصة معلم تانٍ اتغيّرت رغم الرفض');
        $this->assertSame(
            0,
            LessonCreditTransaction::where('lesson_id', $foreign->id)->where('type', 'lesson_used')->count(),
            'اتخصم رصيد على حصة ما بتخصمش',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ مصفوفة الحماية — كل مسارات البوابة بصلاحيتها
    // ═══════════════════════════════════════════════════════════════

    public function test_the_portal_routes_require_the_teacher_portal_permission(): void
    {
        // مستخدم بدون صلاحية البوابة خالص.
        $noPortal = $this->makeUserWithRole('not-a-teacher-portal', ['teachers.view']);
        $teacher = $this->makeTeacher($noPortal);

        // ⭐ الحصة دي **تخص** المعلّم ده — فالرفض بسبب الصلاحية
        // مش بسبب الملكية.
        [$lesson] = $this->makeCompletableLesson([], $teacher);

        $this->getJsonAs('/api/teacher/today', $noPortal)->assertStatus(403);
        $this->getJsonAs('/api/teacher/week', $noPortal)->assertStatus(403);
        $this->getJsonAs('/api/teacher/lessons', $noPortal)->assertStatus(403);
        $this->getJsonAs('/api/teacher/earnings', $noPortal)->assertStatus(403);
        $this->getJsonAs('/api/teacher/availability', $noPortal)->assertStatus(403);
        $this->getJsonAs('/api/teacher/surahs', $noPortal)->assertStatus(403);
        $this->postJsonAs("/api/teacher/lessons/{$lesson->id}/start", [], $noPortal)->assertStatus(403);
        $this->postJsonAs("/api/teacher/lessons/{$lesson->id}/complete", [], $noPortal)->assertStatus(403);

        // ⭐ والحصة دي تخص المعلم ده — فالرفض بسبب الصلاحية مش الملكية.
        $this->assertSame($teacher->id, $lesson->teacher_id);
        $this->assertSame('scheduled', $lesson->fresh()->status);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ هاتف ولي الأمر — مخفي افتراضيًا (إعداد)
    // ═══════════════════════════════════════════════════════════════

    public function test_the_parent_phone_is_hidden_by_default_and_visible_with_the_setting(): void
    {
        [$user, $teacher] = $this->portalTeacher();

        $student = $this->makeStudent();
        [$program] = $this->cheapProgramStudent();
        $lesson = $this->makeLesson($program, $student, $teacher, [
            'scheduled_start_at' => now()->startOfDay()->addHours(9),
            'scheduled_end_at' => now()->startOfDay()->addHours(9)->addMinutes(30),
        ]);

        // ⭐ ربط والده موثّق (نفس نمط السياسات).
        $profile = $this->makeParentProfile();
        $student->parents()->attach($profile->id, [
            'relationship' => 'father',
            'is_primary' => true,
            'status' => 'verified',
        ]);

        $hidden = $this->getJsonAs('/api/teacher/today', $user)->assertOk()->json('lessons.0');

        $this->assertSame($lesson->id, (int) $hidden['id']);
        $this->assertNull($hidden['parent']['phone'], 'الهاتف ظاهر بدون إعداد — تسريب بيانات أولياء الأمور');

        // ⭐ الإعداد يفتح الهاتف لنفس المؤسسة فقط.
        Setting::set('teacher_portal.expose_parent_phone', true, $teacher->organization_id);

        $revealed = $this->getJsonAs('/api/teacher/today', $user)->assertOk()->json('lessons.0');

        $this->assertSame($profile->phone, $revealed['parent']['phone'], 'الإعداد بيفتح الهاتف');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ كشف مستحقاتي — المعلم بس
    // ═══════════════════════════════════════════════════════════════

    public function test_the_earnings_statement_contains_only_the_teache_rows(): void
    {
        [$user, $teacher] = $this->portalTeacher();

        $earning = TeacherEarning::create([
            'teacher_id' => $teacher->id,
            'amount' => 120,
            'currency' => 'EGP',
            'earning_date' => now()->toDateString(),
            'status' => 'approved',
        ]);

        app(TeacherLedgerService::class)->write([
            'teacher_id' => $teacher->id,
            'earning_id' => $earning->id,
            'type' => 'lesson_earning',
            'credit' => 120,
            'currency' => 'EGP',
            'description' => 'اعتماد مستحق حصة',
            'reference_type' => 'teacher_earning',
            'reference_id' => (string) $earning->id,
            'created_by' => $user->id,
        ]);

        $statement = $this->getJsonAs('/api/teacher/earnings', $user)->assertOk()->json();

        $this->assertSame($teacher->id, (int) $statement['teacher_id']);

        // ⭐ الكشف بتاع المعلم ده مش المعلم التاني.
        $this->assertNotEmpty($statement['entries'], 'مفيش سطور دفتر في الكشف');
        $this->assertSame(
            $teacher->id,
            (int) $statement['entries'][0]['teacher_id'],
        );
        $this->assertArrayHasKey('EGP', $statement['currencies']);
        $this->assertEquals(120, $statement['currencies']['EGP']['balance']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑦ جدول التواجد — المعلم يعدّل جدوله
    // ═══════════════════════════════════════════════════════════════

    public function test_the_teacher_reads_and_edits_their_own_availability(): void
    {
        [$user] = $this->portalTeacher();

        $this->postJsonAs('/api/teacher/availability', [
            'weekday' => 0,
            'starts_at' => '16:00',
            'ends_at' => '18:00',
            'kind' => 'academy',
        ], $user)->assertStatus(201);

        $blocks = $this->getJsonAs('/api/teacher/availability', $user)->assertOk()->json('blocks');

        $this->assertCount(1, $blocks);
        $this->assertSame('16:00', $blocks[0]['starts_at']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑧ احتياجات بوابة الويب (PR 4.4)
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐⭐⭐ **قائمة السور — عشان المعلم يسجّل الحفظ من الموبايل.**
     *
     * ⚠️ قبل كده مفيش أي راو بيرجّع `quran_surahs` للواجهة خالص:
     * `/lessons/{lesson}/memorization` بياخد `surah_id`، بس الواجهة
     * مكانش عندها منين تجيبه. فالحفظ من البوابة كان مستحيل بلا
     * قائمة مكتوبة بالإيد في الفرونت.
     */
    public function test_the_portal_exposes_the_surah_catalog_for_the_memorization_form(): void
    {
        [$user] = $this->portalTeacher();

        // ⭐ بالعكس عمداً — الترتيب لازم يجي من السيرفر مش من الإدخال.
        QuranSurah::create([
            'number' => 2,
            'name_ar' => 'البقرة',
            'name_en' => 'Al-Baqara',
            'ayah_count' => 286,
            'revelation_type' => 'medinan',
        ]);
        QuranSurah::create([
            'number' => 1,
            'name_ar' => 'الفاتحة',
            'name_en' => 'Al-Fatiha',
            'ayah_count' => 7,
            'revelation_type' => 'meccan',
        ]);

        $surahs = $this->getJsonAs('/api/teacher/surahs', $user)
            ->assertOk()
            ->json('surahs');

        $this->assertSame([1, 2], array_column($surahs, 'number'), 'السور لازم تيجي مرتبة برقمها');
        $this->assertSame('الفاتحة', $surahs[0]['name_ar']);
        $this->assertSame(7, (int) $surahs[0]['ayah_count'], 'عدد الآيات لازم يوصل — التحقق في السيرفر بيعتمد عليه');
    }

    /**
     * ⭐⭐⭐ **رابط الحصة — زر «انضم» في البوابة.**
     *
     * ⚠️ `present()` في PR 3.10 كان بيرجّع الطالب وولي الأمر والوقت،
     * وناسي `meeting_url` — فالمعلم كان بيشوف الحصة وما يقدرش يدخلها
     * من البوابة. الـPR ده بيضيف الزر، فلازم الرابط يوصل.
     */
    public function test_the_portal_lesson_carries_the_meeting_link_so_the_teacher_can_join(): void
    {
        [$user, $teacher] = $this->portalTeacher();

        [$lesson] = $this->makeCompletableLesson([
            'scheduled_start_at' => now()->startOfDay()->addHours(10),
            'scheduled_end_at' => now()->startOfDay()->addHours(10)->addMinutes(30),
            'meeting_provider' => 'zoom',
            'meeting_url' => 'https://zoom.us/j/987654321',
        ], $teacher);

        $row = $this->getJsonAs('/api/teacher/today', $user)
            ->assertOk()
            ->json('lessons.0');

        $this->assertSame($lesson->id, (int) $row['id']);
        $this->assertSame('https://zoom.us/j/987654321', $row['meeting_url'], 'رابط الحصة مش واصل للبوابة');
        $this->assertSame('zoom', $row['meeting_provider']);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * معلم مستخدم له بروفايل معلم + صلاحية البوابة.
     *
     * @return array{0: User, 1: Teacher}
     */
    private function portalTeacher(): array
    {
        $user = $this->makeUserWithRole('teacher-portal-user', ['teacher.portal']);
        $teacher = $this->makeTeacher($user);

        return [$user, $teacher];
    }

    /** @return array{0: Program, 1: Student} */
    private function cheapProgramStudent(): array
    {
        return [$this->makeProgram(), $this->makeStudent()];
    }
}
