<?php

namespace Database\Seeders;

use App\Models\Program;
use App\Models\ProgramCategory;
use App\Models\Teacher;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * تصنيفات البرامج + ربط المعلمين بالبرامج.
 *
 * ملاحظة مهمة: التصنيفات بتتولد مرة واحدة بس — لو موجودة مش هنفرض
 * تاني. عشان لما حد يضيف «التفسير» من الشاشة، ما نمسحش تعديله في
 * أي seed بعد كده.
 *
 * ربط المعلمين مش عشوائي: المعلم بيتربط بالبرنامج اللي فعلياً عنده حصص
 * فيه، عشان الـ pivot يبقى صادق. بعدين الـ suggestions في الشاشة بتقول
 * «في معلمين عندهم حصص ومش مسجّلين» لو حصل عدم تطابق.
 */
class SeedProgramRelations extends Seeder
{
    /**
     * التصنيفات — ٤ أبعاد زي ما اتفقنا:
     * النوع / المجال / الفئة / المستوى.
     *
     * category_group مش عمود في الـ DB: بنستنتجه من الـ slug
     * عشان الواجهة تقدر تعرضهم كـ groups من غير migration زيادة.
     */
    private const CATEGORIES = [
        // النوع — إيه الخدمة؟
        ['name' => 'تحفيظ',   'slug' => 'type-tahfeez',  'icon' => '📖', 'sort_order' => 10, 'group' => 'type'],
        ['name' => 'تجويد',   'slug' => 'type-tajweed',  'icon' => '✨', 'sort_order' => 20, 'group' => 'type'],
        ['name' => 'تلاوة',   'slug' => 'type-tilaawah', 'icon' => '🎙️', 'sort_order' => 30, 'group' => 'type'],
        ['name' => 'تفسير',   'slug' => 'type-tafsir',   'icon' => '📜', 'sort_order' => 40, 'group' => 'type'],

        // المجال — إيه المادة؟
        ['name' => 'قرآن كريم', 'slug' => 'field-quran',  'icon' => '☪️', 'sort_order' => 50, 'group' => 'field'],
        ['name' => 'فقه',       'slug' => 'field-fiqh',   'icon' => '📖', 'sort_order' => 60, 'group' => 'field'],
        ['name' => 'عقيدة',     'slug' => 'field-aqidah', 'icon' => '🕌', 'sort_order' => 70, 'group' => 'field'],
        ['name' => 'سيرة',      'slug' => 'field-seerah', 'icon' => '🌙', 'sort_order' => 80, 'group' => 'field'],

        // الفئة المستهدفة — مين؟
        ['name' => 'أطفال',      'slug' => 'audience-kids',      'icon' => '🧒', 'sort_order' => 90,  'group' => 'audience'],
        ['name' => 'مراهقين',    'slug' => 'audience-teens',     'icon' => '🧑', 'sort_order' => 100, 'group' => 'audience'],
        ['name' => 'كبار',       'slug' => 'audience-adults',    'icon' => '👨', 'sort_order' => 110, 'group' => 'audience'],
        ['name' => 'جديد على الحفظ', 'slug' => 'audience-newcomers', 'icon' => '🌱', 'sort_order' => 120, 'group' => 'audience'],

        // المستوى — من فين؟
        ['name' => 'مبتدئ',  'slug' => 'level-beginner', 'icon' => '🌱', 'sort_order' => 130, 'group' => 'level'],
        ['name' => 'متوسط',  'slug' => 'level-inter',    'icon' => '🌿', 'sort_order' => 140, 'group' => 'level'],
        ['name' => 'متقدم',  'slug' => 'level-advanced', 'icon' => '🌳', 'sort_order' => 150, 'group' => 'level'],
    ];

    public function run(): void
    {
        $this->seedCategories();
        $this->assignToPrograms();
        $this->linkTeachers();
        $this->decoratePrograms();
    }

    // ============================================================

    private function seedCategories(): void
    {
        $orgId = DB::table('organizations')->value('id');

        foreach (self::CATEGORIES as $cat) {
            ProgramCategory::firstOrCreate(
                ['slug' => $cat['slug']],
                [
                    'organization_id' => $orgId,
                    'name' => $cat['name'],
                    'icon' => $cat['icon'],
                    'sort_order' => $cat['sort_order'],
                    'description' => $this->groupLabel($cat['group']),
                ],
            );
        }

        $this->command?->info("  التصنيفات: " . ProgramCategory::count());
    }

    // ============================================================

    /**
     * ربط كل برنامج بتصنيفاته.
     *
     * مبني على اسم/مستويات البرنامج، مش على رقم ثابت — عشان لو حد
     * غيّر أسماء البرامج في seed تاني، الربط يفضل مظبوط.
     */
    private function assignToPrograms(): void
    {
        $bySlug = ProgramCategory::pluck('id', 'slug');

        // تعريف صريح: slug البرنامج => slugs التصنيفات
        $map = [
            'tahfeeq'    => ['type-tahfeez', 'field-quran', 'audience-kids', 'audience-newcomers', 'level-beginner'],
            'tajweed'    => ['type-tajweed', 'field-quran', 'audience-teens', 'level-advanced'],
            'recitation' => ['type-tilaawah', 'field-quran', 'audience-adults', 'level-inter'],
            'beginners'  => ['type-tahfeez', 'field-quran', 'audience-newcomers', 'level-beginner'],
            'sanad'      => ['type-tilaawah', 'field-seerah', 'audience-adults', 'level-advanced'],
            'revision'   => ['type-tahfeez', 'field-quran', 'audience-adults', 'level-advanced'],
        ];

        // احتياطي: أي برنامج مش في الخريطة (أو slug اتغيّر)
        // بنصنّفه بنوع واحد على الأقل عشان ما يبقاش فاضي
        $fallback = ['type-tahfeez', 'field-quran', 'level-beginner'];

        foreach (Program::all() as $program) {
            $slugs = $map[$program->slug] ?? $this->guessFromProgram($program) ?? $fallback;

            $ids = collect($slugs)->map(fn ($s) => $bySlug[$s] ?? null)->filter()->all();

            $program->categories()->sync($ids);
        }

        $this->command?->info("  ربط التصنيفات: " . DB::table('program_category')->count());
    }

    /** لو البرنامج مش في الخريطة — نستنتج من اسمه */
    private function guessFromProgram(Program $program): ?array
    {
        $name = $program->name;

        return match (true) {
            str_contains($name, 'تجويد') => ['type-tajweed', 'field-quran', 'level-advanced'],
            str_contains($name, 'تلاوة') => ['type-tilaawah', 'field-quran', 'level-inter'],
            str_contains($name, 'تسميع') => ['type-tilaawah', 'field-seerah', 'level-advanced'],
            str_contains($name, 'تقوية') => ['type-tahfeez', 'field-quran', 'audience-adults', 'level-advanced'],
            str_contains($name, 'مبتدئ') => ['type-tahfeez', 'field-quran', 'audience-newcomers', 'level-beginner'],
            str_contains($name, 'قرآن') => ['type-tahfeez', 'field-quran', 'level-beginner'],
            default => null,
        };
    }

    // ============================================================

    /**
     * ربط المعلمين بالبرامج — من على أرض الواقع.
     *
     * المعلم بيتربط بالبرنامج اللي عنده فيه حصص فعلاً.
     * «المعلم الأساسي» = اللي عنده أكبر عدد حصص في البرنامج ده.
     *
     * ملاحظة: لو المعلم مربوط ببرنامج واحد بس وعنده حصص في كذا برنامج
     * (نادر في بياناتنا)، هنربطه بالكل — عشان الـ pivot صادق.
     */
    private function linkTeachers(): void
    {
        $rows = DB::table('lessons')
            ->whereNotNull('teacher_id')
            ->whereNotNull('program_id')
            ->selectRaw('program_id, teacher_id, count(*) as lessons_count')
            ->groupBy('program_id', 'teacher_id')
            ->get();

        $linked = 0;

        foreach ($rows as $row) {
            if (!Program::find($row->program_id) || !Teacher::find($row->teacher_id)) {
                continue;
            }

            DB::table('program_teacher')->updateOrInsert(
                ['program_id' => $row->program_id, 'teacher_id' => $row->teacher_id],
                [
                    'is_primary' => 0,
                    'rate_multiplier' => 1.00,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
            $linked++;
        }

        // الأساسي = أعلى عدد حصص في كل برنامج
        foreach (DB::table('lessons')
            ->whereNotNull('teacher_id')->whereNotNull('program_id')
            ->selectRaw('program_id, teacher_id, count(*) as c')
            ->groupBy('program_id', 'teacher_id')
            ->get()
            ->groupBy('program_id') as $programId => $teacherRows) {

            $top = $teacherRows->sortByDesc('c')->first();

            DB::table('program_teacher')
                ->where('program_id', $programId)
                ->update(['is_primary' => 0]);

            if ($top) {
                DB::table('program_teacher')
                    ->where('program_id', $programId)
                    ->where('teacher_id', $top->teacher_id)
                    ->update(['is_primary' => 1]);
            }
        }

        $this->command?->info("  ربط المعلمين: " . DB::table('program_teacher')->count() . " ({$linked} من الحصص)");
    }

    // ============================================================

    /** صورة/لون للبطاقة — مش لازم، بس بيخلي الواجهة أحلى */
    private function decoratePrograms(): void
    {
        $colors = [
            'tahfeeq'    => '#059669', // أخضر
            'tajweed'    => '#7c3aed', // بنفسجي
            'recitation' => '#2563eb', // أزرق
            'beginners'  => '#ea580c', // برتقالي
            'sanad'      => '#0891b2', // سماوي
            'revision'   => '#be123c', // عنّابي
        ];

        foreach (Program::all() as $program) {
            $program->updateQuietly([
                'color' => $colors[$program->slug] ?? '#64748b',
                'image_url' => $program->image_url,
            ]);
        }
    }

    private function groupLabel(string $group): string
    {
        return match ($group) {
            'type' => 'نوع البرنامج — إيه الخدمة التعليمية؟',
            'field' => 'المجال — إيه المادة؟',
            'audience' => 'الفئة المستهدفة — مين الطالب؟',
            'level' => 'مستوى البرنامج — من فين الطالب؟',
            default => '',
        };
    }
}