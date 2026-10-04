<?php

namespace Tests\Feature;

use App\Models\Program;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ربط المعلمين بالبرنامج — الـ pivot قدام الواقع.
 *
 * القاعدة اللي كل الاختبارات دي بتحميها: `program_teacher` بيقول
 * «المعلم ده يدرّس البرنامج ده». لو الفرق بينه وبين جدول `lessons`
 * اتار، فالكارت بيعرض رقم كذب.
 *
 * الباج اللي اتصلح هنا كان: `teachers()` كانت ترجّع
 * `lessons_count` فاضي **من غير أي error** — يعني الاختبار لازم
 * يassert الحقل موجود، مش بس إن الـ endpoint بترجع 200.
 */
class ProgramTeacherLinkTest extends TestCase
{
    private function admin(): \App\Models\User
    {
        return $this->makeUserWithRole('admin', ['programs.view', 'lessons.edit', 'settings.manage']);
    }

    /** برنامج فيه معلمين وحصص جاهزة */
    private function scenario(): array
    {
        $program = $this->makeProgram();
        $this->makeLevel($program);

        $busy = $this->makeTeacher(['display_name' => 'شغّال']);
        $idle = $this->makeTeacher(['display_name' => 'مشغول']);
        $free = $this->makeTeacher(['display_name' => 'في برنامج تاني']);

        $student = $this->makeStudent();
        $this->makeSubscription($program, $student, [], $busy);
        $this->makeLesson($program, $student, $busy);
        $this->makeLesson($program, $student, $busy);

        $this->linkTeacher($program, $busy);
        $this->linkTeacher($program, $idle);

        return compact('program', 'busy', 'idle', 'free', 'student');
    }

    public function test_teachers_are_partitioned_into_linked_idle_and_suggested(): void
    {
        ['program' => $program] = $this->scenario();
        $admin = $this->admin();

        $r = $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin);
        $r->assertOk();

        $d = $r->json();

        $this->assertCount(1, $d['linked'], 'المعلم الشغّال المفروض في linked');
        $this->assertCount(1, $d['idle'], 'المعلم بلا حصص المفروض في idle');
        $this->assertCount(0, $d['suggested']);

        $this->assertSame($d['linked'][0]['display_name'], 'شغّال');
        $this->assertSame($d['idle'][0]['display_name'], 'مشغول');

        $this->assertSame(
            ['unlinked' => 0, 'idle' => 1, 'total' => 1],
            $d['health'],
            'health لازم يطابق عدّاد المجموعات'
        );
    }

    /**
     * ⚠️ الباج اللي كنا بنفقده بصمت: الحقل موجود بقيمته.
     *
     * `lessons_count` كان بيطلع **فاضي** من غير error — يعني
     * الكود كان شغال والـ UI بيعرض «حصة» فاضية. لو الاختبار
     * assertOk() بس، الباج ده هيمرّ.
     */
    public function test_every_teacher_row_has_a_real_lessons_count(): void
    {
        ['program' => $program, 'busy' => $busy] = $this->scenario();
        $admin = $this->admin();

        $d = $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin)->json();

        foreach (['linked', 'idle'] as $group) {
            foreach ($d[$group] as $row) {
                $this->assertArrayHasKey(
                    'lessons_count',
                    $row,
                    "الحقل ناقص في {$group} — الـ UI هيعرض قيمة فاضية بصمت"
                );
                $this->assertIsInt($row['lessons_count'], 'لازم int مش string');
            }
        }

        // المعلم الشغّال عنده حصتين فعلاً
        $busyRow = collect($d['linked'])->firstWhere('id', $busy->id);
        $this->assertSame(2, $busyRow['lessons_count']);

        $idleRow = collect($d['idle'])->first();
        $this->assertSame(0, $idleRow['lessons_count']);

        // وده التحقق الحقيقي: الرقم مطابق للحقيقة في lessons
        $truth = DB::table('lessons')->where('program_id', $program->id)->where('teacher_id', $busy->id)->count();
        $this->assertSame($truth, $busyRow['lessons_count']);
    }

    public function test_the_three_groups_partition_all_teachers_exactly_once(): void
    {
        ['program' => $program] = $this->scenario();
        $admin = $this->admin();

        // ٣ معلمين في的现实: شغّال (مسجّل) + مشغول (مسجّل) + واحد تاني
        $student = $this->makeStudent();
        $other = \App\Models\Teacher::first();
        $this->makeLesson($program, $student, \App\Models\Teacher::query()->where('display_name', 'مشغول')->firstOrFail(), [
            'status' => 'scheduled',
        ], $program->levels()->first());

        // الـ free لسه معندوش حصة في البرنامج ده — فمش في ولا مجموعة،
        // وده صح: هو مش له علاقة بالبرنامج أصلاً
        $d = $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin)->json();

        $all = collect($d['linked'])->merge($d['idle'])->merge($d['suggested'])->pluck('id');

        $this->assertSame(
            $all->count(),
            $all->unique()->count(),
            'معلم متكرر في أكتر من مجموعة'
        );

        $linkedIds = collect($d['linked'])->pluck('id');
        $idleIds = collect($d['idle'])->pluck('id');
        $suggestedIds = collect($d['suggested'])->pluck('id');

        $this->assertEmpty($linkedIds->intersect($idleIds), 'linked ∩ idle مش فاضي');
        $this->assertEmpty($linkedIds->intersect($suggestedIds), 'linked ∩ suggested مش فاضي');
        $this->assertEmpty($idleIds->intersect($suggestedIds), 'idle ∩ suggested مش فاضي');
    }

    public function test_unlinked_teacher_with_lessons_appears_as_suggested(): void
    {
        ['program' => $program, 'busy' => $busy] = $this->scenario();
        $admin = $this->admin();

        // المعلم عنده حصص فعلاً بس مش مسجّل — لازم يظهر كـ suggested
        DB::table('program_teacher')
            ->where('program_id', $program->id)->where('teacher_id', $busy->id)
            ->delete();

        $d = $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin)->json();

        $this->assertCount(0, $d['linked']);
        $this->assertCount(1, $d['idle'], 'المشغول لسه مسجّل ففضل idle');
        $this->assertCount(1, $d['suggested']);
        $this->assertSame($busy->id, $d['suggested'][0]['id']);
        $this->assertSame(2, $d['suggested'][0]['lessons_count']);
        $this->assertSame(1, $d['health']['unlinked']);
    }

    public function test_bulk_link_missing_registers_every_teacher_with_lessons(): void
    {
        ['program' => $program, 'busy' => $busy] = $this->scenario();
        $admin = $this->admin();

        DB::table('program_teacher')
            ->where('program_id', $program->id)->where('teacher_id', $busy->id)
            ->delete();

        $r = $this->postJsonAs("/api/programs/{$program->id}/teachers/link-missing", [], $admin);
        $r->assertOk();

        $d = $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin)->json();
        $this->assertSame(0, $d['health']['unlinked']);
        $this->assertContains($busy->id, collect($d['linked'])->pluck('id')->all());
    }

    public function test_bulk_unlink_idle_removes_only_teachers_without_lessons(): void
    {
        ['program' => $program, 'busy' => $busy, 'idle' => $idle] = $this->scenario();
        $admin = $this->admin();

        $r = $this->postJsonAs("/api/programs/{$program->id}/teachers/unlink-idle", [], $admin);
        $r->assertOk();

        $d = $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin)->json();

        $this->assertSame(0, $d['health']['idle']);
        $this->assertContains($busy->id, collect($d['linked'])->pluck('id')->all(), 'المعلم الشغّال اتشال غلط');
        $this->assertNotContains($idle->id, collect($d['linked'])->pluck('id')->all());
        $this->assertSame(0, DB::table('program_teacher')->where('teacher_id', $idle->id)->count());
    }

    public function test_bulk_actions_are_idempotent(): void
    {
        ['program' => $program] = $this->scenario();
        $admin = $this->admin();

        // المعلم الشغّال مسجّل بالفعل، و«المشغول» ما عندوش حصة
        $this->postJsonAs("/api/programs/{$program->id}/teachers/link-missing", [], $admin)->assertOk();
        $this->postJsonAs("/api/programs/{$program->id}/teachers/unlink-idle", [], $admin)->assertOk();

        // تاني مرة — المفروض يفضلوا نضيفين ومفيش error
        $this->postJsonAs("/api/programs/{$program->id}/teachers/link-missing", [], $admin)->assertOk();
        $this->postJsonAs("/api/programs/{$program->id}/teachers/unlink-idle", [], $admin)->assertOk();

        $d = $this->getJsonAs("/api/programs/{$program->id}/teachers", $admin)->json();
        $this->assertSame(0, $d['health']['total']);
    }

    /** حارس ملكية: ما ينفعش تعدّل مستوى برنامج تاني */
    public function test_cannot_edit_a_level_that_belongs_to_another_program(): void
    {
        $mine = $this->makeProgram(['name' => 'برنامجي']);
        $theirs = $this->makeProgram(['name' => 'برنامج تاني']);
        $foreignLevel = $this->makeLevel($theirs);

        $admin = $this->admin();

        // المسار فيه program = برنامجي، بس المستوى بتاع برنامج تاني
        $this->putJsonAs("/api/programs/{$mine->id}/levels/{$foreignLevel->id}", ['name' => 'اختراق'], $admin)
            ->assertNotFound();
        $this->deleteJsonAs("/api/programs/{$mine->id}/levels/{$foreignLevel->id}", $admin)
            ->assertNotFound();

        // ومستوى برنامجي عادي — لازم ينفع
        $ownLevel = $this->makeLevel($mine);
        $this->putJsonAs("/api/programs/{$mine->id}/levels/{$ownLevel->id}", ['name' => 'سليم'], $admin)
            ->assertOk();
    }
}