<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonParticipant;
use App\Models\MemorizationRecord;
use App\Models\ProgressRecord;
use App\Models\QuranSurah;
use App\Models\Student;
use App\Models\User;
use App\Services\LessonJoinTokenService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 4.6 — بوابة الطالب.**
 *
 * ⭐ الطالب المستقل (≥ 13 بموافقة مسجّلة — PR 1.12) بيقرا **نفسه
 * فقط**: جدوله، حضوره، تقدمه، وحفظه. مفيش مسار مالي، ومفيش أي
 * `student_id` جاي من العميل — البروفايل هو اللي بيحدد الطالب.
 *
 * ⭐ الطفل < 13 مالوش حساب، فبيدخل الحصة برابط/PIN (PR 3.11).
 * واجهته عامة، وما بترجّعش أي بيانات شخصية.
 *
 * ⭐ الاختبارات دي بتثبت قبول الـroadmap:
 *   • «الطالب ما يشوفش طالباً تانٍ» — العزل.
 *   • «ولا أي مسار مالي» — فحص المفاتيح في كل الردود.
 *   • «طفل بـPIN ما يعدّلش بياناته» — الرمز بيسجّل دخول بس.
 */
class StudentPortalTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // ① العزل — طالب تاني مفيش
    // ═══════════════════════════════════════════════════════════════

    public function test_a_student_sees_only_their_own_lessons(): void
    {
        [$user, $student] = $this->studentUser();
        [, $other] = $this->studentUser();

        $mine = $this->lessonFor($student);
        $theirs = $this->lessonFor($other);

        $schedule = $this->getJsonAs('/api/student/schedule', $user)->assertOk();

        $ids = collect($schedule->json('upcoming'))->pluck('id')->all();

        $this->assertContains($mine->id, $ids, 'حصة الطالب نفسه مش في جدوله');
        $this->assertNotContains($theirs->id, $ids, '⭐⭐ جدول الطالب فيه حصة طالب تاني — تسريب');
        $this->assertSame($student->id, $schedule->json('student_id'));

        $overview = $this->getJsonAs('/api/student/overview', $user)->assertOk();

        $this->assertSame($student->id, $overview->json('student.id'));
        $this->assertSame($mine->id, $overview->json('next_lesson.id'));
    }

    public function test_a_student_cannot_join_another_students_lesson(): void
    {
        [$user] = $this->studentUser();
        [, $other] = $this->studentUser();

        $theirs = $this->lessonFor($other);

        $this->postJsonAs("/api/student/lessons/{$theirs->id}/join", [], $user)
            ->assertNotFound();

        $this->assertSame(
            0,
            LessonParticipant::where('lesson_id', $theirs->id)->count(),
            '⭐⭐ الطالب سجّل دخول في حصة مش بتاعته',
        );
    }

    public function test_a_group_lesson_appears_for_its_students_and_can_be_joined(): void
    {
        [$user, $student] = $this->studentUser();

        $lesson = $this->lessonFor($student, ['student_id' => null]);
        $lesson->students()->attach($student->id);

        $schedule = $this->getJsonAs('/api/student/schedule', $user)->assertOk();
        $this->assertContains(
            $lesson->id,
            collect($schedule->json('upcoming'))->pluck('id')->all(),
            'حصة المجموعة مش ظاهرة لعضوها',
        );

        $this->postJsonAs("/api/student/lessons/{$lesson->id}/join", [], $user)
            ->assertOk()
            ->assertJsonPath('data.join_url', 'https://zoom.us/j/STUDENT-1');
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الصلاحية — مين يدخل البوابة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_portal_requires_the_student_portal_permission(): void
    {
        $user = $this->makeUserWithRole('staff-no-portal', ['programs.view']);
        $student = $this->makeStudent(['user_id' => $user->id]);
        $lesson = $this->lessonFor($student);

        $this->getJsonAs('/api/student/overview', $user)->assertForbidden();
        $this->getJsonAs('/api/student/schedule', $user)->assertForbidden();
        $this->getJsonAs('/api/student/progress', $user)->assertForbidden();
        $this->postJsonAs("/api/student/lessons/{$lesson->id}/join", [], $user)
            ->assertForbidden();
    }

    public function test_the_permission_alone_is_not_enough_without_a_student_profile(): void
    {
        $user = $this->makeUserWithRole('portal-without-profile', ['student.portal']);

        $this->getJsonAs('/api/student/overview', $user)->assertForbidden();
        $this->getJsonAs('/api/student/schedule', $user)->assertForbidden();
        $this->getJsonAs('/api/student/progress', $user)->assertForbidden();
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ مفيش مسار مالي — لا فواتير ولا مدفوعات ولا أرصدة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_portal_never_exposes_a_financial_path(): void
    {
        [$user, $student] = $this->studentUser();
        $this->lessonFor($student);

        foreach (['/api/student/overview', '/api/student/schedule', '/api/student/progress'] as $url) {
            $response = $this->getJsonAs($url, $user)->assertOk();

            $this->assertNoFinancialKeys($response->json(), $url);
        }

        // ⭐ الراوتس المالية مش موجودة أصلاً في بوابة الطالب.
        $this->getJsonAs('/api/student/invoices', $user)->assertNotFound();
        $this->getJsonAs('/api/student/payments', $user)->assertNotFound();
    }

    // ═══════════════════════════════════════════════════════════════
    // ④ قراءة فقط — الطالب ما يعدّلش بياناته
    // ═══════════════════════════════════════════════════════════════

    public function test_the_portal_is_read_only_and_cannot_change_the_student_record(): void
    {
        [$user, $student] = $this->studentUser();
        $lesson = $this->lessonFor($student);

        $before = $student->only(['first_name', 'last_name', 'phone', 'student_code']);

        // ⭐ محاولة تمرير حقول الطالب مع نداء الدخول — السيرفر
        // بيتجاهلها، لأن الـvalidation بياخد `lesson_id` بس.
        $this->postJsonAs("/api/student/lessons/{$lesson->id}/join", [
            'first_name' => 'اسم متغيّر',
            'phone' => '01099999999',
            'student_code' => 'HACKED',
        ], $user)->assertOk();

        $this->assertSame(
            $before,
            $student->fresh()->only(['first_name', 'last_name', 'phone', 'student_code']),
            '⭐⭐ الطالب عدّل بياناته من بوابة قراءة فقط',
        );

        // ⭐ مفيش راوت كتابة على الملف.
        $this->postJsonAs('/api/student/overview', [], $user)->assertStatus(405);
        $this->putJsonAs('/api/student/overview', [], $user)->assertStatus(405);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑤ زر الانضمام — سجل دخول واحد + رابط الحصة
    // ═══════════════════════════════════════════════════════════════

    public function test_the_join_button_records_the_participant_once_and_returns_the_meeting_url(): void
    {
        [$user, $student] = $this->studentUser();
        $lesson = $this->lessonFor($student);

        $first = $this->postJsonAs("/api/student/lessons/{$lesson->id}/join", [], $user)
            ->assertOk();

        $this->assertSame('https://zoom.us/j/STUDENT-1', $first->json('data.join_url'));

        $second = $this->postJsonAs("/api/student/lessons/{$lesson->id}/join", [], $user)
            ->assertOk();

        $this->assertSame($first->json('data.join_url'), $second->json('data.join_url'));

        $participants = LessonParticipant::where('lesson_id', $lesson->id)->get();

        $this->assertCount(1, $participants, 'الدخول اتسجّل أكتر من مرة');
        $this->assertSame($student->id, $participants[0]->student_id);
        $this->assertSame('student_portal', $participants[0]->source);
        $this->assertNotNull($participants[0]->joined_at);
    }

    public function test_a_cancelled_lesson_or_a_lesson_without_a_link_cannot_be_joined(): void
    {
        [$user, $student] = $this->studentUser();

        $cancelled = $this->lessonFor($student, ['status' => 'cancelled_by_academy']);
        $this->postJsonAs("/api/student/lessons/{$cancelled->id}/join", [], $user)
            ->assertStatus(422);

        $noLink = $this->lessonFor($student, ['meeting_url' => null]);
        $this->postJsonAs("/api/student/lessons/{$noLink->id}/join", [], $user)
            ->assertStatus(422);

        $this->assertSame(0, LessonParticipant::count(), 'حصة غير قابلة للدخول سجّلت مشارك');
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑥ التقدم — الحضور وخريطة الحفظ
    // ═══════════════════════════════════════════════════════════════

    public function test_progress_returns_attendance_rate_and_the_memorization_map(): void
    {
        [$user, $student] = $this->studentUser();
        $teacher = $this->makeTeacher();

        $present = $this->lessonFor($student);
        $late = $this->lessonFor($student);
        $absent = $this->lessonFor($student);

        foreach ([[$present, 'present'], [$late, 'late'], [$absent, 'absent']] as [$lesson, $status]) {
            LessonAttendance::create([
                'lesson_id' => $lesson->id,
                'student_id' => $student->id,
                'status' => $status,
                'marked_at' => now(),
                'marked_by' => $user->id,
            ]);
        }

        ProgressRecord::create([
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            // ⭐ `program_id` NOT NULL في المخطط — والحصة الأولى
            // اتعملت ببرنامج، فبنستخدمه بدل ما ننشئ برنامج تاني.
            'program_id' => $present->program_id,
            'category' => 'memorization',
            'score' => 4.5,
            'recorded_at' => now(),
        ]);

        $surah = QuranSurah::create([
            'number' => 1,
            'name_ar' => 'الفاتحة',
            'name_en' => 'Al-Fatiha',
            'ayah_count' => 7,
            'revelation_type' => 'meccan',
        ]);

        foreach ([[1, 3], [4, 7]] as [$from, $to]) {
            MemorizationRecord::create([
                'student_id' => $student->id,
                'teacher_id' => $teacher->id,
                'surah_id' => $surah->id,
                'from_ayah' => $from,
                'to_ayah' => $to,
                'quality' => 5,
                'recorded_at' => now(),
            ]);
        }

        $progress = $this->getJsonAs('/api/student/progress', $user)->assertOk();

        $this->assertSame(3, $progress->json('attendance.summary.total'));
        $this->assertSame(67, $progress->json('attendance.rate'), 'متأخر بيتحسب حضور');
        $this->assertCount(1, $progress->json('progress'));
        $this->assertCount(2, $progress->json('memorization'));

        $map = $progress->json('memorization_map');
        $this->assertCount(1, $map, 'خريطة الحفظ مش مجمّعة بالسورة');
        $this->assertSame('الفاتحة', $map[0]['surah_name']);
        $this->assertSame(1, $map[0]['from_ayah']);
        $this->assertSame(7, $map[0]['to_ayah']);
        $this->assertCount(2, $map[0]['ayahs']);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑦ /auth/me — الواجهة تعرف إن الحساب طالب
    // ═══════════════════════════════════════════════════════════════

    public function test_auth_me_exposes_the_student_profile(): void
    {
        [$user, $student] = $this->studentUser();

        $me = $this->getJsonAs('/api/auth/me', $user)->assertOk();

        $this->assertTrue($me->json('is_student'));
        $this->assertSame($student->id, $me->json('student.id'));
        $this->assertSame($student->student_code, $me->json('student.student_code'));

        // ⭐ حساب إداري بلا بروفايل طالب = false/null.
        $admin = $this->makeUserWithRole('admin', ['student.portal']);
        $adminMe = $this->getJsonAs('/api/auth/me', $admin)->assertOk();

        $this->assertFalse($adminMe->json('is_student'));
        $this->assertNull($adminMe->json('student'));
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑧ الطفل < 13 — رابط/PIN بلا بيانات شخصية
    // ═══════════════════════════════════════════════════════════════

    public function test_the_public_join_preview_shows_the_lesson_without_personal_data(): void
    {
        [, $student] = $this->studentUser();
        $lesson = $this->lessonFor($student);
        $manager = $this->makeUserWithRole('join-manager', ['lessons.update']);

        $token = app(LessonJoinTokenService::class)->issue($lesson, $student, $manager);

        $preview = $this->getJson("/api/join/{$token['token']}")->assertOk();

        $this->assertSame($lesson->teacher->full_name, $preview->json('data.teacher_name'));
        $this->assertSame($lesson->program->name, $preview->json('data.program_name'));
        $this->assertTrue($preview->json('data.has_meeting'));
        $this->assertFalse($preview->json('data.expired'));
        $this->assertStringContainsString(
            "/join/{$token['token']}",
            (string) $preview->json('data.join_url'),
        );

        // ⭐⭐ مفيش اسم الطالب ولا كوده ولا أي بيانات ولي أمر.
        $body = (string) $preview->getContent();

        $this->assertStringNotContainsString($student->full_name, $body);
        $this->assertStringNotContainsString($student->student_code, $body);
        $this->assertStringNotContainsString('student_id', $body);
        $this->assertStringNotContainsString('parent', $body);
    }

    public function test_the_public_join_preview_marks_an_expired_token_and_hides_the_link(): void
    {
        [, $student] = $this->studentUser();
        $lesson = $this->lessonFor($student);
        $manager = $this->makeUserWithRole('join-manager', ['lessons.update']);

        $token = app(LessonJoinTokenService::class)->issue($lesson, $student, $manager);

        DB::table('lesson_join_tokens')
            ->where('pin', $token['pin'])
            ->update(['expires_at' => now()->subMinute()]);

        $preview = $this->getJson("/api/join/{$token['token']}")->assertOk();

        $this->assertTrue($preview->json('data.expired'));
        $this->assertNull($preview->json('data.join_url'));

        $this->getJson('/api/join/'.str_repeat('a', 40))->assertNotFound();
    }

    public function test_the_pin_flow_records_the_join_and_returns_the_meeting_url_without_editing_the_student(): void
    {
        $this->resetRateLimiter();

        [, $student] = $this->studentUser();
        $lesson = $this->lessonFor($student);
        $manager = $this->makeUserWithRole('join-manager', ['lessons.update']);

        $token = app(LessonJoinTokenService::class)->issue($lesson, $student, $manager);

        $before = $student->only(['first_name', 'last_name', 'phone', 'student_code']);

        $response = $this->postJson('/api/lessons/join', [
            'pin_or_token' => $token['pin'],
            'lesson_id' => $lesson->id,
            // ⭐ حقول إضافية — لازم تتجاهل تماماً.
            'first_name' => 'اسم متغيّر',
            'phone' => '01099999999',
        ])->assertOk();

        $this->assertSame(
            'https://zoom.us/j/STUDENT-1',
            $response->json('data.meeting_url'),
            'الرمز ما رجّعش رابط الحصة',
        );

        $participant = LessonParticipant::where('lesson_id', $lesson->id)->firstOrFail();

        $this->assertSame($student->id, $participant->student_id);
        $this->assertSame('pin', $participant->source);
        $this->assertSame(
            $before,
            $student->fresh()->only(['first_name', 'last_name', 'phone', 'student_code']),
            '⭐⭐ طفل بـPIN عدّل بياناته',
        );

        // ⭐ الاستعمال التاني مرفوض.
        $this->postJson('/api/lessons/join', [
            'pin_or_token' => $token['pin'],
            'lesson_id' => $lesson->id,
        ])->assertStatus(422);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /** @return array{0: User, 1: Student} */
    private function studentUser(array $attrs = []): array
    {
        $user = $this->makeUserWithRole('student', ['student.portal']);
        $student = $this->makeStudent(array_merge(['user_id' => $user->id], $attrs));

        return [$user->fresh(), $student];
    }

    private function lessonFor(Student $student, array $attrs = []): Lesson
    {
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        return $this->makeLesson($program, $student, $teacher, array_merge([
            'scheduled_start_at' => now()->addDay()->setTime(16, 0),
            'scheduled_end_at' => now()->addDay()->setTime(16, 30),
            'meeting_url' => 'https://zoom.us/j/STUDENT-1',
        ], $attrs));
    }

    /**
     * ⭐ أي مفتاح مالي في أي مستوى = فشل. البوابة دي أكاديمية بس.
     */
    private function assertNoFinancialKeys(array $payload, string $path): void
    {
        $forbidden = [
            'invoice', 'invoices', 'payment', 'payments', 'balance', 'balance_due',
            'outstanding', 'amount', 'currency', 'paid_amount', 'price', 'ledger',
        ];

        foreach ($payload as $key => $value) {
            $this->assertNotContains(
                $key,
                $forbidden,
                "⭐⭐ «{$path}» فيه مفتاح مالي «{$key}» — الطالب مالوش مسار مالي",
            );

            if (is_array($value)) {
                $this->assertNoFinancialKeys($value, "{$path}.{$key}");
            }
        }
    }
}
