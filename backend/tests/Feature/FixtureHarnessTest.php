<?php

namespace Tests\Feature;

use App\Models\Level;
use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Teacher;
use Tests\TestCase;

/**
 * sanity: نتأكد إن الـ fixtures والـ harness شغالين قبل ما نكتب
 * اختبارات حقيقية فوقهم. لو الـ setup مكسور، كل فشل بعدها هيبقى
 * غامض.
 */
class FixtureHarnessTest extends TestCase
{
    public function test_fixtures_build_a_consistent_world(): void
    {
        $program = $this->makeProgram(['name' => 'تحفيظ القرآن', 'slug' => 'tahfeeq']);
        $level = $this->makeLevel($program);
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();
        $sub = $this->makeSubscription($program, $student, [], $teacher);
        $lesson = $this->makeLesson($program, $student, $teacher, [], $level);

        $this->assertSame(1, Level::where('program_id', $program->id)->count());
        $this->assertSame(1, Teacher::count());
        $this->assertSame($program->id, $sub->program_id);
        $this->assertSame($teacher->id, $lesson->teacher_id);
    }

    public function test_auth_headers_work(): void
    {
        $admin = $this->makeUserWithRole('admin', ['programs.view', 'settings.manage']);

        $r = $this->getJsonAs('/api/programs', $admin);

        $r->assertOk();
        $r->assertJsonStructure(['data', 'counts', 'filters']);
    }

    public function test_each_test_starts_with_a_clean_database(): void
    {
        // لو الـ transaction/rollback مش شغال، الـ program ده هيفضل
        // في الـ test اللي بعده وهنفشل بشكل غامض
        $this->assertSame(0, Program::count());
        $this->assertSame(0, Teacher::count());
        $this->assertSame(0, ProgramCategory::count());
    }
}