<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\Student;
use App\Models\Subscription;
use App\Models\Teacher;
use App\Models\TeacherRating;
use Illuminate\Http\Request;

class TeacherController extends Controller
{
    public function index(Request $request)
    {
        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        $query = Teacher::query()
            ->with(['branch', 'activeContract', 'currentRate'])
            // عدد الحصص الكلي
            ->withCount('lessons')
            // عدد التقييمات + المتوسط
            ->withCount('ratings')
            ->withAvg('ratings', 'rating')
            // عدد الطلاب المختلفين اللي خدوا معاه حصص
            ->selectSub(
                Lesson::query()
                    ->selectRaw('count(distinct student_id)')
                    ->whereColumn('lessons.teacher_id', 'teachers.id'),
                'students_count'
            )
            // حصص الشهر الحالي
            ->selectSub(
                Lesson::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('lessons.teacher_id', 'teachers.id')
                    ->whereBetween('scheduled_start_at', [$monthStart, $monthEnd]),
                'lessons_this_month'
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')))
            ->when($request->filled('specialization'), fn ($q) => $q->where('specialization', $request->string('specialization')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($sq) use ($search) {
                    $sq->where('display_name', 'like', "%{$search}%")
                        ->orWhere('teacher_code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($request->filled('sort'), function ($q) use ($request) {
                $sort = $request->string('sort')->toString();
                $dir = $request->string('dir')->lower()->toString() === 'asc' ? 'asc' : 'desc';
                $allowed = [
                    'display_name' => 'display_name',
                    'joined_at' => 'joined_at',
                    'lessons_count' => 'lessons_count',
                    'students_count' => 'students_count',
                    'ratings_avg_rating' => 'ratings_avg_rating',
                    'created_at' => 'created_at',
                ];
                $q->orderBy($allowed[$sort] ?? 'created_at', $dir);
            }, fn ($q) => $q->orderBy('created_at', 'desc'));

        $perPage = $request->integer('per_page') ?: 100;
        $paginator = $query->paginate($perPage);

        // الإحصائيات الحقيقية من الداتابيز (مش من الصفحة الحالية)
        $countsQuery = Teacher::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->string('branch_id')))
            ->when($request->filled('specialization'), fn ($q) => $q->where('specialization', $request->string('specialization')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($sq) use ($search) {
                    $sq->where('display_name', 'like', "%{$search}%")
                        ->orWhere('teacher_code', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        // البرامج لكل معلم + قائمة التخصصات المتاحة للفلترة
        $paginator->getCollection()->transform(function ($teacher) use ($monthStart, $monthEnd) {
            $programRows = Lesson::query()
                ->join('programs', 'programs.id', '=', 'lessons.program_id')
                ->where('lessons.teacher_id', $teacher->id)
                ->selectRaw('programs.id as program_id, programs.name as program_name, count(*) as lessons_count')
                ->groupBy('programs.id', 'programs.name')
                ->orderByDesc('lessons_count')
                ->get()
                ->map(fn ($r) => [
                    'id' => $r->program_id,
                    'name' => $r->program_name,
                    'lessons_count' => (int) $r->lessons_count,
                ])
                ->values();

            $teacher->programs = $programRows;
            $teacher->average_rating = $teacher->ratings_avg_rating !== null
                ? round((float) $teacher->ratings_avg_rating, 2)
                : null;
            $teacher->students_count = (int) $teacher->students_count;
            $teacher->lessons_this_month = (int) $teacher->lessons_this_month;

            return $teacher;
        });

        $response = $paginator->toArray();
        $response['counts'] = [
            'active' => (int) ($countsQuery['active'] ?? 0),
            'inactive' => (int) ($countsQuery['inactive'] ?? 0),
        ];

        return response()->json($response);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'display_name' => 'required|string',
            'phone' => 'required|string',
            'email' => 'nullable|email',
            'country_code' => 'nullable|string|max:5',
            'timezone' => 'nullable|string',
            'specialization' => 'nullable|string',
            'qualifications' => 'nullable|string',
            'years_of_experience' => 'nullable|integer|min:0|max:70',
            'languages' => 'nullable|string',
            'bio' => 'nullable|string|max:2000',
            'status' => 'nullable|in:active,inactive',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['teacher_code'] = 'TCH-' . strtoupper(uniqid());
        $data['user_id'] = $request->user()->id;

        $teacher = Teacher::create($data);
        return response()->json($teacher, 201);
    }

    public function show(Teacher $teacher)
    {
        return response()->json($teacher->load(['branch', 'user', 'contracts', 'rates', 'activeContract', 'currentRate', 'ratings' => function ($q) {
            $q->with(['student', 'parent'])->latest()->limit(10);
        }]));
    }

    public function storeRating(Request $request, Teacher $teacher)
    {
        $data = $request->validate([
            'student_id' => 'nullable|exists:students,id',
            'parent_id' => 'nullable|exists:parents,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        $data['teacher_id'] = $teacher->id;

        $rating = TeacherRating::create($data);

        return response()->json([
            'rating' => $rating,
            'average_rating' => $teacher->averageRating(),
            'ratings_count' => $teacher->ratingsCount(),
        ], 201);
    }

    public function ratings(Teacher $teacher)
    {
        return response()->json([
            'ratings' => $teacher->ratings()->with(['student', 'parent'])->latest()->paginate(20),
            'average_rating' => $teacher->averageRating(),
            'ratings_count' => $teacher->ratingsCount(),
        ]);
    }

    public function update(Request $request, Teacher $teacher)
    {
        $data = $request->validate([
            'display_name' => 'sometimes|string',
            'phone' => 'sometimes|string',
            'email' => 'nullable|email',
            'country_code' => 'nullable|string|max:5',
            'timezone' => 'nullable|string',
            'specialization' => 'nullable|string',
            'qualifications' => 'nullable|string',
            'years_of_experience' => 'nullable|integer|min:0|max:70',
            'languages' => 'nullable|string',
            'bio' => 'nullable|string|max:2000',
            'status' => 'sometimes|in:active,inactive',
            'joined_at' => 'nullable|date',
            'notes' => 'nullable|string',
        ]);

        $teacher->update($data);
        return response()->json($teacher->fresh());
    }

    public function destroy(Teacher $teacher)
    {
        $teacher->delete();
        return response()->json(['message' => 'تم حذف المعلم']);
    }

    public function schedule(Request $request, Teacher $teacher)
    {
        return response()->json($teacher->lessons()->with(['student', 'program', 'level'])->orderBy('scheduled_start_at', 'desc')->paginate($request->integer('per_page') ?: 50));
    }

    public function students(Request $request, Teacher $teacher)
    {
        // اجمع الطلاب من مصدرين: من الحصص، ومن الاشتراكات
        $fromLessons = $teacher->lessons()->whereNotNull('student_id')->distinct()->pluck('student_id');
        $fromSubscriptions = Subscription::where('teacher_id', $teacher->id)->whereNotNull('student_id')->distinct()->pluck('student_id');
        $ids = $fromLessons->merge($fromSubscriptions)->unique()->values();

        if ($ids->isEmpty()) {
            return response()->json(['students' => [], 'total' => 0]);
        }

        // إحصائيات كل طالب مع هذا المعلم في query واحد (تفادياً للـ N+1)
        $stats = Lesson::query()
            ->where('teacher_id', $teacher->id)
            ->whereIn('student_id', $ids)
            ->selectRaw('student_id')
            ->selectRaw('count(*) as total')
            ->selectRaw("sum(case when status = 'completed' then 1 else 0 end) as completed")
            ->selectRaw("sum(case when status in ('cancelled','student_absent','teacher_absent') then 1 else 0 end) as cancelled")
            ->selectRaw("sum(case when scheduled_start_at > ? then 1 else 0 end) as upcoming", [now()])
            ->selectRaw('max(scheduled_start_at) as last_lesson_at')
            ->groupBy('student_id')
            ->get()
            ->keyBy('student_id');

        $students = Student::whereIn('id', $ids)
            ->with(['phones'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->string('search');
                $q->where(function ($sq) use ($s) {
                    $sq->where('first_name', 'like', "%{$s}%")
                        ->orWhere('last_name', 'like', "%{$s}%")
                        ->orWhere('student_code', 'like', "%{$s}%");
                });
            })
            ->get()
            ->map(function ($student) use ($stats) {
                $row = $stats->get($student->id);
                $student->teacher_stats = [
                    'total' => (int) ($row->total ?? 0),
                    'completed' => (int) ($row->completed ?? 0),
                    'cancelled' => (int) ($row->cancelled ?? 0),
                    'upcoming' => (int) ($row->upcoming ?? 0),
                    'last_lesson_at' => $row->last_lesson_at ?? null,
                ];
                return $student;
            })
            ->sortByDesc(fn ($s) => $s->teacher_stats['upcoming'])
            ->values();

        return response()->json([
            'students' => $students,
            'total' => $students->count(),
        ]);
    }

    public function financialSummary(Request $request, Teacher $teacher)
    {
        $startOfMonth = now()->startOfMonth();
        $endOfMonth = now()->endOfMonth();

        $earnings = $teacher->earnings()->whereIn('status', ['approved', 'paid']);
        $payments = $teacher->payments()->where('status', 'completed');

        $earnedThisMonth = (float) (clone $earnings)->whereBetween('earning_date', [$startOfMonth, $endOfMonth])->sum('amount');
        $paidThisMonth = (float) (clone $payments)->whereBetween('paid_at', [$startOfMonth, $endOfMonth])->sum('amount');
        $earnedTotal = (float) (clone $earnings)->sum('amount');
        $paidTotal = (float) (clone $payments)->sum('amount');
        $pendingTotal = (float) $teacher->earnings()->where('status', 'pending')->sum('amount');

        return response()->json([
            'summary' => [
                'earned_this_month' => $earnedThisMonth,
                'paid_this_month' => $paidThisMonth,
                'earned_total' => $earnedTotal,
                'paid_total' => $paidTotal,
                'pending_total' => $pendingTotal,
                'outstanding' => round($earnedTotal - $paidTotal, 2),
            ],
            'contract' => $teacher->activeContract,
            'rate' => $teacher->currentRate,
            'currency' => $teacher->currentRate?->currency ?? $teacher->activeContract?->currency ?? 'EGP',
        ]);
    }

    public function lessonsSummary(Request $request, Teacher $teacher)
    {
        $now = now();
        $startOfMonth = $now->copy()->startOfMonth();
        $endOfMonth = $now->copy()->endOfMonth();

        $lessons = $teacher->lessons();

        $total = (clone $lessons)->count();
        $completed = (clone $lessons)->where('status', 'completed')->count();
        $upcoming = (clone $lessons)->where('scheduled_start_at', '>', $now)->count();
        $cancelled = (clone $lessons)->whereIn('status', ['cancelled', 'student_absent', 'teacher_absent'])->count();

        $thisMonth = (clone $lessons)->whereBetween('scheduled_start_at', [$startOfMonth, $endOfMonth])->count();
        $thisMonthCompleted = (clone $lessons)->whereBetween('scheduled_start_at', [$startOfMonth, $endOfMonth])->where('status', 'completed')->count();

        // آخر 5 حصص مكتملة
        $recentLessons = $teacher->lessons()
            ->with(['student', 'program', 'level'])
            ->where('status', 'completed')
            ->orderBy('scheduled_start_at', 'desc')
            ->limit(5)
            ->get();

        // أقرب 5 حصص قادمة
        $upcomingLessons = $teacher->lessons()
            ->with(['student', 'program', 'level'])
            ->where('scheduled_start_at', '>', $now)
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('scheduled_start_at', 'asc')
            ->limit(5)
            ->get();

        // توزيع حسب البرنامج
        $byProgram = Lesson::query()
            ->join('programs', 'programs.id', '=', 'lessons.program_id')
            ->where('lessons.teacher_id', $teacher->id)
            ->selectRaw('programs.name as name, count(*) as lessons_count')
            ->groupBy('programs.id', 'programs.name')
            ->orderByDesc('lessons_count')
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'lessons_count' => (int) $r->lessons_count])
            ->values();

        return response()->json([
            'summary' => [
                'total' => $total,
                'completed' => $completed,
                'upcoming' => $upcoming,
                'cancelled' => $cancelled,
                'this_month' => $thisMonth,
                'this_month_completed' => $thisMonthCompleted,
            ],
            'recent_lessons' => $recentLessons,
            'upcoming_lessons' => $upcomingLessons,
            'by_program' => $byProgram,
        ]);
    }

    public function overview(Teacher $teacher)
    {
        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $lessons = $teacher->lessons();

        return response()->json([
            'basic' => $teacher->load(['branch', 'user', 'activeContract', 'currentRate']),
            'stats' => [
                'students_count' => (int) (clone $lessons)->distinct()->count('student_id'),
                'lessons_count' => (clone $lessons)->count(),
                'lessons_this_month' => (clone $lessons)->whereBetween('scheduled_start_at', [$monthStart, $monthEnd])->count(),
                'upcoming_lessons' => (clone $lessons)->where('scheduled_start_at', '>', $now)->count(),
                'completed_lessons' => (clone $lessons)->where('status', 'completed')->count(),
                'average_rating' => $teacher->averageRating() ? round((float) $teacher->averageRating(), 2) : null,
                'ratings_count' => $teacher->ratingsCount(),
            ],
            'programs' => Lesson::query()
                ->join('programs', 'programs.id', '=', 'lessons.program_id')
                ->where('lessons.teacher_id', $teacher->id)
                ->selectRaw('programs.id as id, programs.name as name, count(*) as lessons_count')
                ->groupBy('programs.id', 'programs.name')
                ->orderByDesc('lessons_count')
                ->get()
                ->map(fn ($r) => ['id' => $r->id, 'name' => $r->name, 'lessons_count' => (int) $r->lessons_count])
                ->values(),
        ]);
    }

    public function earnings(Request $request, Teacher $teacher)
    {
        return response()->json($teacher->earnings()->with(['lesson', 'rate', 'contract'])->orderBy('earning_date', 'desc')->paginate($request->integer('per_page') ?: 50));
    }

    public function contracts(Request $request, Teacher $teacher)
    {
        return response()->json($teacher->contracts()->orderBy('start_date', 'desc')->get());
    }

    public function rates(Request $request, Teacher $teacher)
    {
        return response()->json($teacher->rates()->orderBy('effective_from', 'desc')->get());
    }
}
