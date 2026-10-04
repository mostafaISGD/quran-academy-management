<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Models\Program;
use App\Models\ProgramCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * البرامج التعليمية.
 *
 * البرنامج = تعريف الخدمة التعليمية. القراءات مفتوحة لكل اللي بيشتغل
 * (المعلم لازم يعرف بيحضّر إيه)، والكتابة لإدارة النظام بس.
 */
class ProgramController extends Controller
{
    // ============================================================
    // القائمة
    // ============================================================

    public function index(Request $request)
    {
        $query = Program::query()
            // التصنيفات بتظهر على الكارت — لازم تتحمّل مع الصفحة
            ->with('categories')
            ->withCount([
                'levels',
                'teachers',
                'subscriptionPlans as plans_count_all',
            ])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) =>
                $q->whereHas('categories', fn ($c) => $c->where('program_categories.id', $request->integer('category_id')))
            )
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(fn ($sq) => $sq
                    ->where('name', 'like', "%{$s}%")
                    ->orWhere('description', 'like', "%{$s}%"));
            })
            ->orderBy('name');

        $paginator = $query->paginate($request->integer('per_page') ?: 50);

        $ids = $paginator->getCollection()->pluck('id');

        // أرقام الطلاب من الداتابيز مباشرة — مش من الصفحة الحالية.
        // لازم نفس تعريف Program::getStudentsCountAttribute() عشان
        // الكارت والصفحة يطلعوا بنفس الرقم.
        $studentsByProgram = DB::table('subscriptions')
            ->whereIn('program_id', $ids)->whereIn('status', ['active', 'paused'])
            ->selectRaw('program_id, count(distinct student_id) as c')
            ->groupBy('program_id')->pluck('c', 'program_id');

        $activePlans = DB::table('subscription_plans')
            ->whereIn('program_id', $ids)->where('status', 'active')
            ->selectRaw('program_id, count(*) as c')
            ->groupBy('program_id')->pluck('c', 'program_id');

        $paginator->getCollection()->transform(function ($program) use ($studentsByProgram, $activePlans) {
            $program->students_count = (int) ($studentsByProgram[$program->id] ?? 0);
            $program->plans_count = (int) ($activePlans[$program->id] ?? 0);
            return $program;
        });

        $response = $paginator->toArray();

        // العدادات بتتأثر بنفس الفلاتر (ماعدا البحث النصي للحالة)
        $countsQuery = Program::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('category_id'), fn ($q) =>
                $q->whereHas('categories', fn ($c) => $c->where('program_categories.id', $request->integer('category_id'))));

        $statusCounts = (clone $countsQuery)->selectRaw('status, count(*) as c')
            ->groupBy('status')->pluck('c', 'status');

        $response['counts'] = [
            'active' => (int) ($statusCounts['active'] ?? 0),
            'inactive' => (int) ($statusCounts['inactive'] ?? 0),
        ];

        // التصنيفات المتاحة للفلترة
        $response['filters'] = [
            'categories' => ProgramCategory::orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'slug', 'icon'])
                ->map(fn ($c) => [
                    'id' => $c->id, 'name' => $c->name,
                    'slug' => $c->slug, 'icon' => $c->icon,
                    'programs_count' => $c->programs()->count(),
                ]),
        ];

        return response()->json($response);
    }

    // ============================================================
    // الإنشاء
    // ============================================================

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'slug' => 'nullable|string|max:150',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'status' => 'nullable|in:active,inactive',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:program_categories,id',
        ]);

        // الـ validate() بيشيل المفاتيح اللي العميل مبعتهاش، فالمفتاح نفسه
        // مش موجود أصلاً — لازم ?? مش ?: عشان ما نطلعش undefined
        // array key
        $slug = $data['slug'] ?? null;
        $data['slug'] = $this->uniqueSlug($slug ?: Str::slug($data['name']));
        $data['organization_id'] = $request->user()->organization_id;

        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        $program = Program::create($data);

        if ($categoryIds) {
            $program->categories()->sync($categoryIds);
        }

        app(\App\Services\AuditLogService::class)->logCreate(
            'program', $program->id,
            ['name' => $program->name, 'slug' => $program->slug],
            $request,
        );

        return response()->json($program->load(['levels', 'categories']), 201);
    }

    // ============================================================
    // العرض — الملف الكامل
    // ============================================================

    public function show(Program $program)
    {
        // الباقات كمان — عشان الملف يعرضها. عرض بس: إدارة الباقات
        // ليها قسمها لوحدها، هنا بنعرف إيه التابع للبرنامج.
        $program->load(['levels', 'categories', 'teachers', 'subscriptionPlans']);

        return response()->json([
            'program' => $program,
            'stats' => [
                'students_count' => $program->students_count,
                // تفصيل الرقم: X نشط · Y متوقف — عشان المستخدم
                // يفهم الرقم في الكارت جاي منين
                'students' => $program->studentStatusBreakdown(),
                'teachers_count' => $program->teachers_count,
                'levels_count' => $program->levels_count,
                'plans_count' => $program->plans_count,
                'lessons_count' => (clone $program->lessons())->count(),
                'lessons_upcoming' => (clone $program->lessons())
                    ->where('status', 'scheduled')
                    ->where('scheduled_start_at', '>=', now())->count(),
                'memorization' => $program->memorizationSummary(),
            ],
        ]);
    }

    // ============================================================
    // التعديل
    // ============================================================

    public function update(Request $request, Program $program)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'description' => 'nullable|string',
            'image_url' => 'nullable|string',
            'color' => 'nullable|string|max:7',
            'status' => 'sometimes|in:active,inactive',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:program_categories,id',
        ]);

        $categoryIds = $data['category_ids'] ?? null;
        unset($data['category_ids']);

        $old = $program->only(array_keys($data));

        $program->update($data);

        if ($categoryIds !== null) {
            $program->categories()->sync($categoryIds);
        }

        app(\App\Services\AuditLogService::class)->logUpdate(
            'program', $program->id,
            $old, $program->only(array_keys($data)),
            $request,
        );

        return response()->json($program->fresh()->load(['levels', 'categories', 'teachers']));
    }

    public function destroy(Request $request, Program $program)
    {
        $old = $program->only(['name', 'slug', 'status']);

        $program->delete();

        app(\App\Services\AuditLogService::class)->logDelete('program', $program->id, $old, $request);

        return response()->json(['message' => 'تم حذف البرنامج']);
    }

    // ============================================================
    // المستويات / المراحل
    // ============================================================

    public function levels(Program $program)
    {
        return response()->json($program->levels()->get());
    }

    public function storeLevel(Request $request, Program $program)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $data['program_id'] = $program->id;

        // لو مفيش ترتيب صريح — بعد آخر مستوى موجود
        if (!isset($data['sort_order'])) {
            $data['sort_order'] = (int) (clone $program->levels())->max('sort_order') + 1;
        }

        // الـ code من slug البرنامج + الترتيب، مش من الاسم العربي.
        // Str::slug على عربي بيطلع «msto-alsnd-almtsl» — مش مفهوم.
        // الشكل ده زي الموجود أصلاً: tahfeeq-1, tahfeeq-2.
        $code = $data['code'] ?? null;
        $data['code'] = $code ?: $program->slug . '-' . $data['sort_order'];

        $level = Level::create($data);

        app(\App\Services\AuditLogService::class)->logCreate(
            'level', $level->id,
            ['name' => $level->name, 'program_id' => $program->id],
            $request,
        );

        return response()->json($level, 201);
    }

    public function updateLevel(Request $request, Program $program, Level $level)
    {
        // المستوى لازم يكون belonging للبرنامج ده — غير كده هنعدّل حاجة غلط
        abort_unless($level->program_id === $program->id, 404);

        $data = $request->validate([
            'name' => 'sometimes|string|max:150',
            'code' => 'nullable|string|max:50',
            'sort_order' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
            'status' => 'sometimes|in:active,inactive',
        ]);

        $level->update($data);

        app(\App\Services\AuditLogService::class)->logUpdate(
            'level', $level->id, null, $level->only(array_keys($data)), $request,
        );

        return response()->json($level->fresh());
    }

    public function destroyLevel(Request $request, Program $program, Level $level)
    {
        abort_unless($level->program_id === $program->id, 404);

        // عدد الحصص اللي رايحة على المستوى ده
        $lessons = (clone $level->lessons())->count();
        $students = (clone $level->lessons())->distinct()->count('student_id');

        $level->delete();

        app(\App\Services\AuditLogService::class)->logDelete(
            'level', $level->id, ['name' => $level->name], $request,
        );

        return response()->json([
            'message' => 'تم حذف المستوى',
            'orphaned_lessons' => $lessons,
            'affected_students' => $students,
        ]);
    }

    /** إعادة ترتيب المستويات — للـ drag & drop */
    public function reorderLevels(Request $request, Program $program)
    {
        $data = $request->validate([
            'order' => 'required|array',
            'order.*.id' => 'required|exists:levels,id',
            'order.*.sort_order' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($data, $program) {
            foreach ($data['order'] as $row) {
                Level::where('id', $row['id'])
                    ->where('program_id', $program->id)
                    ->update(['sort_order' => $row['sort_order']]);
            }
        });

        return response()->json($program->levels()->get());
    }

    // ============================================================
    // المعلمون
    // ============================================================

    /**
     * المعلمين المسجّلين على البرنامج + غير المسجّلين اللي عندهم حصص
     * فعلاً (اقتراحات — عشان الداتابيز ما تبقاش متعارضة مع الواقع).
     */
    public function teachers(Request $request, Program $program)
    {
        $linked = $program->teachers()->get()->map(fn ($t) => [
            'linked' => true,
            'id' => $t->id,
            'display_name' => $t->display_name,
            'specialization' => $t->specialization,
            'status' => $t->status,
            'is_primary' => (bool) $t->pivot->is_primary,
            'rate_multiplier' => (float) $t->pivot->rate_multiplier,
            'lessons_count' => (clone $program->lessons())->where('teacher_id', $t->id)->count(),
        ]);

        // اللي عندهم حصص في البرنامج بس مش مسجّلين
        $suggested = DB::table('teachers')
            ->join('lessons', 'lessons.teacher_id', '=', 'teachers.id')
            ->where('lessons.program_id', $program->id)
            ->whereNotIn('teachers.id', $program->teachers()->pluck('teachers.id'))
            ->selectRaw('teachers.id, teachers.display_name, teachers.specialization, teachers.status')
            ->selectRaw('count(*) as lessons_count')
            ->groupBy('teachers.id', 'teachers.display_name', 'teachers.specialization', 'teachers.status')
            ->orderByDesc('lessons_count')
            ->get()
            ->map(fn ($t) => [
                'linked' => false,
                'id' => $t->id,
                'display_name' => $t->display_name,
                'specialization' => $t->specialization,
                'status' => $t->status,
                'is_primary' => false,
                'rate_multiplier' => 1.0,
                'lessons_count' => (int) $t->lessons_count,
            ]);

        return response()->json([
            'linked' => $linked,
            'suggested' => $suggested,
        ]);
    }

    public function linkTeacher(Request $request, Program $program)
    {
        $data = $request->validate([
            'teacher_id' => 'required|exists:teachers,id',
            'is_primary' => 'nullable|boolean',
            'rate_multiplier' => 'nullable|numeric|min:0.1|max:10',
            'notes' => 'nullable|string',
        ]);

        $program->teachers()->syncWithoutDetaching([
            $data['teacher_id'] => [
                'is_primary' => $data['is_primary'] ?? false,
                'rate_multiplier' => $data['rate_multiplier'] ?? 1.0,
                'notes' => $data['notes'] ?? null,
            ],
        ]);

        return response()->json($program->teachers()->get());
    }

    public function unlinkTeacher(Request $request, Program $program, int $teacherId)
    {
        $program->teachers()->detach($teacherId);

        return response()->json($program->teachers()->get());
    }

    // ============================================================
    // الطلاب
    // ============================================================

    /**
     * الطلاب النشطين في البرنامج — عن طريق اشتراكاتهم.
     *
     * رقم فقط، مش إدارة اشتراك — الاشتراكات ليها قسمها.
     */
    public function students(Request $request, Program $program)
    {
        $rows = DB::table('subscriptions')
            ->join('students', 'students.id', '=', 'subscriptions.student_id')
            ->leftJoin('teachers', 'teachers.id', '=', 'subscriptions.teacher_id')
            ->where('subscriptions.program_id', $program->id)
            ->whereIn('subscriptions.status', ['active', 'paused'])
            ->selectRaw('students.id, students.first_name, students.last_name, students.student_code, students.status')
            ->selectRaw('subscriptions.id as subscription_id, subscriptions.status as subscription_status')
            ->selectRaw('subscriptions.start_date, subscriptions.end_date')
            ->selectRaw('teachers.display_name as teacher_name')
            ->selectRaw('subscriptions.lessons_included')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(fn ($sq) => $sq
                    ->where('students.first_name', 'like', "%{$s}%")
                    ->orWhere('students.last_name', 'like', "%{$s}%")
                    ->orWhere('students.student_code', 'like', "%{$s}%"));
            })
            ->orderByDesc('subscriptions.start_date')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'full_name' => trim($r->first_name . ' ' . $r->last_name),
                'student_code' => $r->student_code,
                'status' => $r->status,
                'subscription_status' => $r->subscription_status,
                'teacher_name' => $r->teacher_name,
                'start_date' => $r->start_date,
                'end_date' => $r->end_date,
                'lessons_included' => $r->lessons_included,
            ]);

        return response()->json([
            'students' => $rows,
            'total' => $rows->count(),
        ]);
    }

    // ============================================================
    // التصنيفات
    // ============================================================

    public function categories()
    {
        return response()->json(
            ProgramCategory::withCount('programs')
                ->orderBy('sort_order')->orderBy('name')
                ->get()
        );
    }

    public function storeCategory(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'nullable|string|max:100',
            'icon' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
        ]);

        $catSlug = $data['slug'] ?? null;
        $data['slug'] = $this->uniqueCategorySlug($catSlug ?: Str::slug($data['name']));
        $data['organization_id'] = $request->user()->organization_id;

        return response()->json(ProgramCategory::create($data), 201);
    }

    public function updateCategory(Request $request, ProgramCategory $category)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'icon' => 'nullable|string|max:20',
            'sort_order' => 'nullable|integer|min:0',
            'description' => 'nullable|string',
        ]);

        $category->update($data);

        return response()->json($category->fresh());
    }

    public function destroyCategory(Request $request, ProgramCategory $category)
    {
        $count = $category->programs()->count();

        $category->delete();

        return response()->json([
            'message' => 'تم حذف التصنيف',
            'detached_from_programs' => $count,
        ]);
    }

    // ============================================================

    /**
     * slug فريد للبرنامج.
     *
     * ⚠️ withTrashed مهم: البرنامج uses SoftDeletes، والـ unique
     * constraint في الداتابيز بيحسب الصفوف المحذوفة كمان. فلو استخدمنا
     * query عادي، هنقول الـ slug متاح ونلاقي نفسينا أمام
     * UniqueConstraintViolationException.
     */
    private function uniqueSlug(string $slug): string
    {
        $slug = $slug ?: 'program';
        $base = $slug;
        $i = 2;

        while (Program::withTrashed()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }

    /** نفس المنطق للتصنيفات — الـ unique عليها (organization_id, slug) */
    private function uniqueCategorySlug(string $slug): string
    {
        $slug = $slug ?: 'category';
        $base = $slug;
        $i = 2;

        while (ProgramCategory::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
