<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pagination + الفلاتر على شاشة البرامج.
 *
 * الباج اللي اتصلح جوّه الملف ده: فلتر «فيه عدم تطابق» كان بيتطبّق
 * **بعد** `paginate()`، فالـ `total` والـ `last_page` فضلوا عدد
 * البرامج كلها. النتيجة: «٦ برامج» فوق كارت واحد، وصفحات فاضية.
 */
class ProgramPaginationTest extends TestCase
{
    private function admin()
    {
        return $this->makeUserWithRole('admin', ['programs.view', 'lessons.edit']);
    }

    /** ٥ برامج: ٣ سليمة و ٢ فيهم عدم تطابق */
    private function fiveProgramsTwoDirty(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $p = $this->makeProgram(['name' => 'برنامج '.$i]);
            $this->makeLevel($p);

            if ($i > 3) {
                // معلم مسجّل بلا حصص → idle
                $this->linkTeacher($p, $this->makeTeacher());
            }
        }
    }

    public function test_total_matches_the_filtered_result_not_everything(): void
    {
        $this->fiveProgramsTwoDirty();
        $admin = $this->admin();

        $all = $this->getJsonAs('/api/programs?per_page=50', $admin)->json();
        $this->assertSame(5, $all['total']);
        $this->assertSame(5, count($all['data']));
        $this->assertSame(2, $all['mismatch_programs']);

        $filtered = $this->getJsonAs('/api/programs?has_mismatches=1&per_page=50', $admin)->json();
        $this->assertCount(2, $filtered['data'], 'المفروض ٢ برنامج بس');
        $this->assertSame(
            2,
            $filtered['total'],
            'total لازم يتبع الفلتر — لو رجع 5، الـ pagination في الواجهة هيتكسر'
        );

        foreach ($filtered['data'] as $p) {
            $this->assertGreaterThan(0, $p['mismatches']['total'], 'برنامج نضيف ظهر مع الفلتر');
        }
    }

    public function test_last_page_is_calculated_from_the_filtered_total(): void
    {
        $this->fiveProgramsTwoDirty();
        $admin = $this->admin();

        $r = $this->getJsonAs('/api/programs?has_mismatches=1&per_page=2', $admin)->json();

        $this->assertCount(2, $r['data']);
        $this->assertSame(2, $r['total']);
        $this->assertSame(1, $r['last_page'], '٢ برنامج على صفحتين / ٢ = صفحة واحدة');

        // و الصفحة التانية لازم تطلع فاضية (مفيش صفحة زيادة وهمية)
        $page2 = $this->getJsonAs('/api/programs?has_mismatches=1&per_page=2&page=2', $admin)->json();
        $this->assertCount(0, $page2['data']);
    }

    public function test_pages_carry_different_programs(): void
    {
        $this->fiveProgramsTwoDirty();
        $admin = $this->admin();

        $p1 = collect($this->getJsonAs('/api/programs?has_mismatches=1&per_page=2&page=1', $admin)->json('data'))->pluck('id');
        $p2 = collect($this->getJsonAs('/api/programs?has_mismatches=1&per_page=2&page=2', $admin)->json('data'))->pluck('id');

        $this->assertCount(2, $p1);
        $this->assertEmpty($p1->intersect($p2), 'الصفحة الأولى والتانية فيهما نفس البرامج');
    }

    public function test_mismatch_programs_count_is_not_affected_by_its_own_filter(): void
    {
        $this->fiveProgramsTwoDirty();
        $admin = $this->admin();

        $r = $this->getJsonAs('/api/programs?has_mismatches=1', $admin)->json();

        // لازم يفضل ٢ (الكل) مش ٢ (المعروض) — عشان الفلتر يفضل
        // ثابت والإحصائية متسقة
        $this->assertSame(2, $r['mismatch_programs']);
    }

    public function test_filters_compose_with_the_mismatch_filter(): void
    {
        $this->fiveProgramsTwoDirty();
        $admin = $this->admin();

        // كل البرامج نشطة، فمفيش حاجة غير نشطة
        $r = $this->getJsonAs('/api/programs?has_mismatches=1&status=inactive', $admin)->json();
        $this->assertCount(0, $r['data']);
        $this->assertSame(0, $r['total']);

        // بحث نصي مش موجود
        $r = $this->getJsonAs('/api/programs?search='.urlencode('لاشيء'), $admin)->json();
        $this->assertCount(0, $r['data']);
        $this->assertSame(0, $r['total']);
    }

    public function test_search_and_status_filters_work(): void
    {
        $program = $this->makeProgram(['name' => 'تحفيظ القرآن الكريم', 'status' => 'active']);
        $this->makeProgram(['name' => 'تجويد', 'status' => 'inactive']);
        $admin = $this->admin();

        $r = $this->getJsonAs('/api/programs?search='.urlencode('تحفيظ'), $admin)->json();
        $this->assertCount(1, $r['data']);
        $this->assertSame($program->id, $r['data'][0]['id']);

        $r = $this->getJsonAs('/api/programs?status=inactive', $admin)->json();
        $this->assertCount(1, $r['data']);
        $this->assertSame('inactive', $r['data'][0]['status']);

        // العدادات بتتبع الفلاتر
        $r = $this->getJsonAs('/api/programs?status=inactive', $admin)->json();
        $this->assertSame(0, $r['counts']['active']);
        $this->assertSame(1, $r['counts']['inactive']);
    }

    public function test_pagination_survives_a_program_with_zero_rows(): void
    {
        // برنامج جديد من غير مستويات ولا معلمين ولا باقات
        $this->makeProgram(['name' => 'فاضي تماماً']);
        $admin = $this->admin();

        $r = $this->getJsonAs('/api/programs', $admin)->json();

        $this->assertCount(1, $r['data']);
        $p = $r['data'][0];
        $this->assertSame(0, $p['levels_count']);
        $this->assertSame(0, $p['teachers_count']);
        $this->assertSame(0, $p['students_count']);
        $this->assertSame(0, $p['plans_count']);
        $this->assertSame(0, $p['mismatches']['total']);
    }
}