<?php

namespace Tests\Feature;

use App\Models\LessonJoinToken;
use App\Models\LessonParticipant;
use App\Services\LessonJoinTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.11 — روابط الدروس وسجل الدخول.**
 *
 * ⭐ **قبل PR ده** رابط الحصة كان حقل حر
 * (`meeting_url => nullable|string`) — أي رابط (حتى غير Zoom)
 * بيتقبل، ومفيش سجل لأن الطالب دخل الحصة ولا لأ.
 *
 * ⭐ **القبول:**
 *
 * ① رابط من مزود ممنوع = مرفوض 422.
 * ② الدخول بيتسجَّل **مرة واحدة لكل مشارك** — الزيارة الثانية
 *    تعيد التحويل من غير سطر تانٍ.
 * ③ رمز منتهي = **410**.
 */
class LessonLinksAndJoinTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ① التحقق من المزود
    // ═══════════════════════════════════════════════════════════════

    public function test_an_allowed_provider_link_is_accepted(): void
    {
        $staff = $this->makeUserWithRole('links-manager', ['lessons.create', 'lessons.view']);
        [$lesson] = $this->bookViaApi($staff, [
            'meeting_url' => 'https://zoom.us/j/123456',
        ]);

        $this->assertSame('https://zoom.us/j/123456', $lesson->meeting_url);
    }

    public function test_a_disallowed_provider_link_is_rejected(): void
    {
        $staff = $this->makeUserWithRole('links-manager', ['lessons.create']);
        $before = \App\Models\Lesson::count();

        // ⭐ رابط مزود مش من قائمة المسموحات — مرفوض قبل أي حجز.
        $this->postJsonAs('/api/lessons', [
            'student_id' => $this->makeStudent()->id,
            'teacher_id' => $this->makeTeacher()->id,
            'program_id' => $this->makeProgram()->id,
            'scheduled_start' => '2026-11-10T16:00:00+03:00',
            'duration_minutes' => 30,
            'meeting_url' => 'https://random-streamer.example/j/abc',
        ], $staff)->assertStatus(422);

        $this->assertSame($before, \App\Models\Lesson::count(), 'الرابط الممنوع اتحجز مقابلوه حصة');
    }

    // ═══════════════════════════════════════════════════════════════
    // ② الرابط الافتراضي للمعلم
    // ═══════════════════════════════════════════════════════════════

    public function test_the_teachers_default_meeting_url_fills_the_lesson(): void
    {
        $staff = $this->makeUserWithRole('links-manager', ['lessons.create']);
        $teacher = $this->makeTeacher([
            'default_meeting_url' => 'https://zoom.us/j/DEFAULT-99',
        ]);

        [$lesson] = $this->bookViaApi($staff, [], $teacher);

        $this->assertSame(
            'https://zoom.us/j/DEFAULT-99',
            $lesson->meeting_url,
            'الحصة من غير رابط لازم تاخد الرابط الافتراضي للمعلم',
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ③ سجل الدخول: مرة واحدة لكل مشارك + تحويل للرابط الحقيقي
    // ═══════════════════════════════════════════════════════════════

    public function test_the_join_link_records_the_participant_once_and_redirects(): void
    {
        [$lesson, $student] = $this->linkActiveLesson('https://zoom.us/j/LIVE-01');
        $manager = $this->makeUserWithRole('join-manager', ['lessons.update']);

        $token = app(LessonJoinTokenService::class)->issue($lesson, $student, $manager);

        $r = $this->get("/join/{$token['token']}");

        // ⭐ تحويل للرابط الحقيقي (مع صف سجل الدخول).
        $r->assertStatus(302);
        $this->assertSame('https://zoom.us/j/LIVE-01', $r->headers->get('Location'));

        $participants = LessonParticipant::where('lesson_id', $lesson->id)->get();
        $this->assertCount(1, $participants, 'الدخول اتعمل غير مرة أو مفيش');
        $this->assertSame($student->id, $participants[0]->student_id);
        $this->assertNotNull($participants[0]->joined_at);

        // ⭐ الرمز اتستهلك مرة واحدة (PR 1.12).
        $tokenRow = LessonJoinToken::where('token_hash', hash('sha256', $token['token']))->firstOrFail();
        $this->assertNotNull($tokenRow->used_at, 'الرمز ما اتستهلكش بعد أول دخول');

        // ⭐⭐ الزيارة التانية: تعيد التوجيه بس **ما بتعملش سطر تاني**.
        $this->get("/join/{$token['token']}")->assertStatus(302);

        $this->assertSame(
            1,
            LessonParticipant::where('lesson_id', $lesson->id)->count(),
            'الدخول اتسجّل مرتين لنفس المشارك',
        );
    }

    public function test_an_expired_join_token_returns_410(): void
    {
        [$lesson, $student] = $this->linkActiveLesson();
        $manager = $this->makeUserWithRole('join-manager', ['lessons.update']);

        $token = app(LessonJoinTokenService::class)->issue($lesson, $student, $manager);

        // ⭐ انتهاء الصلاحية.
        LessonJoinToken::where('token_hash', hash('sha256', $token['token']))
            ->update(['expires_at' => now()->subMinutes(2)]);

        $r = $this->get("/join/{$token['token']}");

        // ⭐ الرمز المنتهي — 410 (قبل أي فحص للرابط).
        $r->assertStatus(410);
        $this->assertSame(
            0,
            LessonParticipant::where('lesson_id', $lesson->id)->count(),
            'رمز منتهي سجّل دخول',
        );
    }

    public function test_an_unknown_join_token_returns_404(): void
    {
        $this->get('/join/'.str_repeat('a', 40))->assertStatus(404);
    }

    // ═══════════════════════════════════════════════════════════════
    // ⑦ سجل الدخول يغذّي اقتراح الحضور
    // ═══════════════════════════════════════════════════════════════

    public function test_the_participants_endpoint_lists_the_join_log(): void
    {
        [$lesson, $student] = $this->linkActiveLesson('https://zoom.us/j/PARTICIPANTS-01');
        $manager = $this->makeUserWithRole('join-manager', ['lessons.update', 'lessons.view']);

        $token = app(LessonJoinTokenService::class)->issue($lesson, $student, $manager);
        $this->get("/join/{$token['token']}")->assertStatus(302);

        $r = $this->getJsonAs("/api/lessons/{$lesson->id}/participants", $manager)
            ->assertOk();

        $row = $r->json('participants.0');

        $this->assertSame($student->id, $row['student_id'], 'المشارك مش في السجل');
        $this->assertArrayHasKey('joined_at', $row, 'مفيش وقت الدخول في السجل');
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ الحجز عبر API (مش fixture) — عشان الحصة تولد من
     * نفس المسار اللي بنختبره (مرّى عبر LessonScheduler).
     *
     * @return array{0: \App\Models\Lesson, 1: \App\Models\Student}
     */
    private function bookViaApi($staff, array $extra = [], ?\App\Models\Teacher $teacher = null): array
    {
        $teacher = $teacher ?? $this->makeTeacher();

        $r = $this->postJsonAs('/api/lessons', array_merge([
            'student_id' => $this->makeStudent()->id,
            'teacher_id' => $teacher->id,
            'program_id' => $this->makeProgram()->id,
            'scheduled_start' => '2026-11-10T16:00:00+03:00',
            'duration_minutes' => 30,
        ], $extra), $staff)->assertStatus(201);

        $payload = $r->json();

        return [
            \App\Models\Lesson::findOrFail($payload['id']),
            \App\Models\Student::findOrFail($payload['student_id']),
        ];
    }

    /**
     * ⭐ حصة **فعّالة** بـرابط + طالب — جاهزة لجواب رموز الدخول.
     *
     * @return array{0: \App\Models\Lesson, 1: \App\Models\Student}
     */
    private function linkActiveLesson(?string $url = null): array
    {
        $staff = $this->makeUserWithRole('link-active-'.uniqid(), ['lessons.create']);
        $teacher = $this->makeTeacher();

        $r = $this->postJsonAs('/api/lessons', array_merge([
            'student_id' => $this->makeStudent()->id,
            'teacher_id' => $teacher->id,
            'program_id' => $this->makeProgram()->id,
            'scheduled_start' => '2026-11-10T16:00:00+03:00',
            'duration_minutes' => 30,
        ], $url ? ['meeting_url' => $url] : []), $staff)->assertStatus(201);

        $payload = $r->json();
        $lesson = \App\Models\Lesson::findOrFail($payload['id']);

        return [$lesson, \App\Models\Student::findOrFail($lesson->student_id)];
    }
}
