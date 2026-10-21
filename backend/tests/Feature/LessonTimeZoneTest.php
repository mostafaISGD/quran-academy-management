<?php

namespace Tests\Feature;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ⭐⭐⭐ **PR 3.7 — المناطق الزمنية.**
 *
 * ⭐ **القاعدة**: كل `scheduled_*_at` **مخزّن UTC**، وكل سلسلة
 * متكررة بتحفظ **منطقتها + ساعتها المحلية الأصلية** ⇒ الساعة
 * المحلية ما بتتحركش مع التوقيت الصيفي (DST).
 *
 * ⭐ القبول:
 *
 * ① سلسلة أسبوعية عبر تغيير التوقيت الصيفي (Europe/London) تحافظ
 *    على الساعة المحلية.
 * ② طالب في منطقة تانية من المعلم يشوف وقته الصح.
 */
class LessonTimeZoneTest extends TestCase
{
    use RefreshDatabase;

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول ① — التوقيت الصيفي
    // ═══════════════════════════════════════════════════════════════

    public function test_a_weekly_series_keeps_its_local_hour_across_a_daylight_saving_change(): void
    {
        $staff = $this->makeUserWithRole('tz-admin', ['lessons.create']);
        $staff->update(['timezone' => 'Europe/London']);

        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        // ⭐ 2026-03-29 بداية التوقيت الصيفي في لندن ⇒ السلسلة
        // بتبقى قبله وبعده.
        $r = $this->postJsonAs('/api/lessons/recurring', [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            'series_start_date' => '2026-03-23',
            'end_date' => '2026-04-06',
            'weekdays' => [1], // Monday
            'start_time' => '16:00',
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(201);

        $lessons = Lesson::where('teacher_id', $teacher->id)->orderBy('scheduled_start_at')->get();

        $this->assertCount(3, $lessons, 'لازم تتولّد 3 حصص (الأحد والتلاتاء والخميس)');

        foreach ($lessons as $lesson) {
            $this->assertSame(
                '16:00',
                substr((string) $lesson->local_start_time, 0, 5),
                'الساعة المحلية اتغيّرت — التوقيت الصيفي بايظ.Generation'
            );

            $this->assertSame(
                'Europe/London',
                $lesson->timezone,
                'منطقة السلسلة محفوظة على الحصة'
            );

            // ⭐ والمخزّن UTC بيختلف (فرق التوقيت الصيفي اتغيّر) —
            // بس الساعة **المحلية** واحدة.
            $this->assertSame(
                '16:00',
                $lesson->scheduled_start_at->setTimezone('Europe/London')->format('H:i'),
                'الساعة المحلية بعد التحويل لوقت المستخدم اتغيّرت'
            );
        }

        // ⭐ فرق UTC بين أول وآخر حصة = ساعة (التوقيت الصيفي).
        $utcHours = $lessons->map(
            fn (Lesson $l) => (int) $l->scheduled_start_at->setTimezone('UTC')->format('H')
        )->all();

        $this->assertNotSame(
            $utcHours[0],
            $utcHours[count($utcHours) - 1],
            'فرق UTC لازم يتغيّر (دليل إن التوقيت الصيفي اتعامل معاه صح)'
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // ⭐ القبول ② — الطالب في منطقة تانية
    // ═══════════════════════════════════════════════════════════════

    public function test_a_student_in_another_zone_sees_the_right_time(): void
    {
        $staff = $this->makeUserWithRole('tz-admin', ['lessons.create']);
        // ⭐ المعلم في القاهرة والطالب في لندن.
        $staff->update(['timezone' => 'Africa/Cairo']);

        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $r = $this->postJsonAs('/api/lessons', [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            // ⭐ 16:00 توقيت القاهرة (UTC+3) ⇒ 13:00 UTC.
            'scheduled_start' => '2026-10-20T16:00:00+03:00',
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(201);

        $lesson = Lesson::findOrFail($r->json('id'));

        // ⭐ المخزّن UTC.
        $this->assertSame(
            '13:00',
            $lesson->scheduled_start_at->setTimezone('UTC')->format('H:i'),
            'الحصة اتخزّنت UTC (13:00 بتوقيت القاهرة 16:00)'
        );

        // ⭐ والرد بيرجّع ISO-8601 — والعميل بيحوّل لمنطقة المستخدم.
        $this->assertStringContainsString('T13:00:00', (string) $r->json('scheduled_start_at'));

        // ⭐ الطالب في لندن (BST = UTC+1 في أكتوبر) ⇒ 14:00 عنده.
        $this->assertSame(
            '14:00',
            $lesson->scheduled_start_at->setTimezone('Europe/London')->format('H:i'),
            'الطالب في لندن لازم يشوف 14:00'
        );
    }

    public function test_a_booking_without_an_offset_is_read_in_the_user_timezone(): void
    {
        $staff = $this->makeUserWithRole('tz-admin', ['lessons.create']);
        $staff->update(['timezone' => 'Africa/Cairo']);

        $student = $this->makeStudent();
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $r = $this->postJsonAs('/api/lessons', [
            'student_id' => $student->id,
            'teacher_id' => $teacher->id,
            'program_id' => $program->id,
            // ⭐ بلا offset ⇒ تُفسَّر في منطقة المستخدم (القاهرة).
            'scheduled_start' => '2026-10-25 16:00',
            'duration_minutes' => 30,
        ], $staff);

        $r->assertStatus(201);

        $lesson = Lesson::findOrFail($r->json('id'));

        $this->assertSame(
            '13:00',
            $lesson->scheduled_start_at->setTimezone('UTC')->format('H:i'),
            '16:00 في القاهرة = 13:00 UTC'
        );
    }
}
