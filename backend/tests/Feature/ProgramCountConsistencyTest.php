<?php

namespace Tests\Feature;

use App\Models\Program;
use Tests\TestCase;

/**
 * الأرقام بتتطابق مع نفسها.
 *
 * ⚠️ ليه الملف ده هو الأهم في السويت؟
 *
 * كل الباجز اللي اتكشفت في شغل شاشات البرامج كانت من نفس العائلة:
 * **نفس الحاجة بتتحسب في أكتر من مكان، والأماكن بتتعارض.**
 *
 *  - الكارت بيقول طلاب ١٤، قائمة الطلاب بتقول ١٨  (students_count)
 *  - `lessons_count` طلع فاضي من غير error          (teachers endpoint)
 *  - عدم التطابق بيتحسب في method في الـ Model
 *    و method تانية في الـ Controller
 *
 * الباج ده **مش بيقع في المراجعة** — بيقع في production والـ UI
 * بيعرض رقم غلط والحد مش واخد باله. عشان كده الاختبار هنا مش
 * «الـ endpoint بترجع 200»، هو «الرقمين نفسهم متساويين».
 */
class ProgramCountConsistencyTest extends TestCase
{
    public function test_students_count_agrees_between_model_index_and_show(): void
    {
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        // ٣ اشتراكات نشطة لـ ٣ طلاب مختلفين
        foreach (range(1, 3) as $i) {
            $this->makeSubscription($program, $this->makeStudent(), [], $teacher);
        }

        $admin = $this->makeUserWithRole('admin', ['programs.view']);

        // ١) الـ accessor على الـ model
        $model = Program::find($program->id);
        $this->assertSame(3, $model->students_count, 'accessor غلط');

        // ٢) الكارت في قائمة البرامج (set-based query، مش الـ accessor)
        $index = $this->getJsonAs('/api/programs', $admin)->json('data.0');
        $this->assertSame(
            $model->students_count,
            $index['students_count'],
            'الكارت بيقول رقم تاني عن الـ accessor'
        );

        // ٣) ملف البرنامج
        $show = $this->getJsonAs("/api/programs/{$program->id}", $admin)->json('stats');
        $this->assertSame(
            $model->students_count,
            $show['students_count'],
            'ملف البرنامج بيقول رقم تاني عن الكارت'
        );

        // ٤) قائمة طلاب البرنامج
        $students = $this->getJsonAs("/api/programs/{$program->id}/students", $admin)->json('total');
        $this->assertSame(
            $model->students_count,
            $students,
            'قائمة الطلاب بتقول رقم تاني عن الكارت'
        );
    }

    public function test_paused_subscriptions_count_but_cancelled_do_not(): void
    {
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();

        $this->makeSubscription($program, $this->makeStudent(['first_name' => 'نشط']), ['status' => 'active'], $teacher);
        $this->makeSubscription($program, $this->makeStudent(['first_name' => 'موقوف']), ['status' => 'paused'], $teacher);
        $this->makeSubscription($program, $this->makeStudent(['first_name' => 'منتهي']), ['status' => 'expired'], $teacher);
        $this->makeSubscription($program, $this->makeStudent(['first_name' => 'ملغي']), ['status' => 'cancelled'], $teacher);

        $model = Program::find($program->id);

        // التعريف: اشتراك نشط أو موقوف = طالب في البرنامج
        $this->assertSame(2, $model->students_count, 'المنتهي والملغي مش لازم يتحسبوا');

        $breakdown = $model->studentStatusBreakdown();
        $this->assertSame(1, $breakdown['active']);
        $this->assertSame(1, $breakdown['paused']);
        $this->assertSame(2, $breakdown['total']);
    }

    public function test_one_student_with_two_subscriptions_counts_once(): void
    {
        $program = $this->makeProgram();
        $teacher = $this->makeTeacher();
        $student = $this->makeStudent();

        // نفس الطالب في البرنامج مرتين — لازم يتحسب مرة واحدة
        $this->makeSubscription($program, $student, ['status' => 'active'], $teacher);
        $this->makeSubscription($program, $student, ['status' => 'paused'], $teacher);

        $this->assertSame(1, Program::find($program->id)->students_count, 'الطالب اتحسب مرتين');
    }

    public function test_levels_teachers_and_plans_counts_agree_between_index_and_show(): void
    {
        $program = $this->makeProgram();
        $admin = $this->makeUserWithRole('admin', ['programs.view']);

        foreach (range(1, 3) as $i) {
            $this->makeLevel($program);
        }

        // ٤ باقات: ٣ نشطة وواحدة متوقفة.
        // ⚠️ لازم الباقات تتعمل **قبل** الاشتراك، لأن
        // `makeSubscription` بياخد باقة موجودة أو بيعمل واحدة —
        // فلو جت بعده، العدد هيبقى ٥ مش ٤ والاختبار يبقى بيجيب
        // رقم مش متوقع من غير سبب واضح.
        foreach (range(1, 3) as $i) {
            $this->makePlan($program);
        }
        $this->makePlan($program, ['status' => 'inactive']);

        // ٢ معلمين: واحد ليه حصص وواحد مسجّل بس من غير حصص
        $busy = $this->makeTeacher();
        $idle = $this->makeTeacher();
        $student = $this->makeStudent();
        $this->makeSubscription($program, $student, [], $busy);
        $this->makeLesson($program, $student, $busy);

        $this->linkTeacher($program, $busy);
        $this->linkTeacher($program, $idle);

        $model = Program::find($program->id);

        $this->assertSame(3, $model->levels_count);
        $this->assertSame(2, $model->teachers_count);
        $this->assertSame(3, $model->plans_count, 'الباقة المتوقفة مش لازم تتحسب');

        $index = $this->getJsonAs('/api/programs', $admin)->json('data.0');
        $show = $this->getJsonAs("/api/programs/{$program->id}", $admin)->json('stats');

        $this->assertSame($model->levels_count, $index['levels_count'], 'المستويات: كارت ≠ accessor');
        $this->assertSame($model->teachers_count, $index['teachers_count'], 'المعلمون: كارت ≠ accessor');
        $this->assertSame($model->plans_count, $index['plans_count'], 'الباقات: كارت ≠ accessor');

        $this->assertSame($model->levels_count, $show['levels_count'], 'المستويات: ملف ≠ accessor');
        $this->assertSame($model->teachers_count, $show['teachers_count'], 'المعلمون: ملف ≠ accessor');
        $this->assertSame($model->plans_count, $show['plans_count'], 'الباقات: ملف ≠ accessor');
    }

    /**
     * ⚠️ الأهم في الملف ده.
     *
     * عدم التطابق بيتحسب في **مكانين**:
     *  - `Program::teacherLinkHealth()`  (الملف الفردي)
     *  - `ProgramController::teacherMismatches()` (قائمة + الكارت)
     *
     * دول كتبيروتين لنفس المنطق. لو واحد اتغيّر والتاني ما اتغيّرش،
     * الكارت هيقول «سليم» والملف هيقول «فيه خلل» — وده بالظبط
     * اللي حصل في `lessons_count`.
     */
    public function test_mismatch_count_agrees_between_model_and_controller(): void
    {
        // ٤ برامج: سليمة، فيها unlinked، فيها idle، فيها الاتنين
        $clean = $this->makeProgram(['name' => 'سليم']);

        $hasUnlinked = $this->makeProgram(['name' => 'فيه غير مسجّل']);
        $hasIdle = $this->makeProgram(['name' => 'فيه بلا حصص']);
        $hasBoth = $this->makeProgram(['name' => 'فيه الاتنين']);

        $admin = $this->makeUserWithRole('admin', ['programs.view']);

        foreach ([$clean, $hasUnlinked, $hasIdle, $hasBoth] as $p) {
            $this->makeLevel($p);
        }

        $busy = $this->makeTeacher(['display_name' => 'شغّال']);
        $idle = $this->makeTeacher(['display_name' => 'مشغول']);
        $other = $this->makeTeacher(['display_name' => 'في برنامج تاني']);

        // busy ليه حصص في ٣ برامج بس
        foreach ([$hasUnlinked, $hasBoth] as $p) {
            $student = $this->makeStudent();
            $this->makeSubscription($p, $student, [], $busy);
            $this->makeLesson($p, $student, $busy);
        }

        // idle is registered but has no lessons
        $this->linkTeacher($hasIdle, $idle);

        // both problems
        $student = $this->makeStudent();
        $this->makeSubscription($hasBoth, $student, [], $busy);
        $this->makeLesson($hasBoth, $student, $busy);
        $this->linkTeacher($hasBoth, $busy);
        $this->linkTeacher($hasBoth, $idle);

        // انتوقعات كل برنامج حسب منطق الـ Model (المصدر المرجعي)
        $expected = [
            $clean->id => ['unlinked' => 0, 'idle' => 0, 'total' => 0],
            $hasUnlinked->id => ['unlinked' => 1, 'idle' => 0, 'total' => 1],
            $hasIdle->id => ['unlinked' => 0, 'idle' => 1, 'total' => 1],
            $hasBoth->id => ['unlinked' => 0, 'idle' => 1, 'total' => 1],
        ];

        foreach ($expected as $programId => $want) {
            $modelHealth = Program::find($programId)->teacherLinkHealth();
            $this->assertSame(
                $want['total'],
                $modelHealth['total'],
                "الـ Model غلط في البرنامج #{$programId}"
            );
        }

        // ودلوقتي نقارن الـ Controller بالـ Model
        $index = $this->getJsonAs('/api/programs?per_page=50', $admin)->json('data');
        $byId = collect($index)->keyBy('id');

        foreach ($expected as $programId => $want) {
            $controllerMismatches = $byId[$programId]['mismatches'];
            $modelMismatches = Program::find($programId)->teacherLinkHealth();

            $this->assertSame(
                $modelMismatches['total'],
                $controllerMismatches['total'],
                "الـ Controller والـ Model مختلفين في البرنامج #{$programId}: "
                . "model={$modelMismatches['total']} controller={$controllerMismatches['total']}"
            );
        }

        // ومفروض الـ busy في.hasUnlinked يكون مقترح
        $this->assertSame(1, $byId[$hasUnlinked->id]['mismatches']['unlinked']);
    }
}