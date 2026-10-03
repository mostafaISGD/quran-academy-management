<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TeacherSchedule;
use App\Services\TeacherAvailabilityService;
use Illuminate\Http\Request;

class TeacherScheduleController extends Controller
{
    public function __construct(private TeacherAvailabilityService $availability) {}

    /**
     * جدول معلم معيّن — الـ recurring + التأجيلات في أسبوع محدد.
     * GET /teachers/{teacher}/availability
     */
    public function index(Request $request, $teacherId)
    {
        $week = $request->filled('week')
            ? \Carbon\Carbon::parse($request->string('week'))->startOfWeek()
            : now()->startOfWeek();

        $blocks = $this->availability->blocksFor((int) $teacherId, $week);

        return response()->json([
            'teacher_id' => (int) $teacherId,
            'week_start' => $week->toDateString(),
            'blocks' => $blocks,
            'total' => $blocks->count(),
        ]);
    }

    /** كل الجداول — للوحة الأدمن */
    public function indexAll(Request $request)
    {
        $query = TeacherSchedule::query()->with('teacher');

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->integer('teacher_id'));
        }
        if ($request->filled('weekday')) {
            $query->where('weekday', $request->integer('weekday'));
        }

        return response()->json([
            'data' => $query->orderBy('teacher_id')->orderBy('weekday')->orderBy('starts_at')->get(),
        ]);
    }

    /**
     * إضافة block جديد.
     * POST /teachers/{teacher}/availability
     */
    public function store(Request $request, $teacherId)
    {
        $data = $request->validate([
            'weekday' => 'required|integer|min:0|max:6',
            'starts_at' => 'required|date_format:H:i',
            'ends_at' => 'required|date_format:H:i',
            'kind' => 'required|in:academy,external,leave,personal',
            'title' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'is_recurring' => 'nullable|boolean',
            'specific_date' => 'nullable|date',
        ]);

        if ($data['ends_at'] === $data['starts_at']) {
            return response()->json(['message' => 'وقت النهاية لازم يكون مختلف عن البداية'], 422);
        }

        if (($data['is_recurring'] ?? true) === false && empty($data['specific_date'])) {
            return response()->json(['message' => 'التأجيل الواحد يحتاج تاريخ محدد'], 422);
        }

        $block = TeacherSchedule::create([
            'teacher_id' => (int) $teacherId,
            'weekday' => $data['weekday'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'kind' => $data['kind'],
            'title' => $data['title'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_recurring' => $data['is_recurring'] ?? true,
            'specific_date' => $data['specific_date'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($block->fresh(), 201);
    }

    /**
     * تعديل block.
     * PUT /teachers/{teacher}/availability/{block}
     */
    public function update(Request $request, $teacherId, TeacherSchedule $block)
    {
        abort_if($block->teacher_id !== (int) $teacherId, 403);

        $data = $request->validate([
            'weekday' => 'sometimes|integer|min:0|max:6',
            'starts_at' => 'sometimes|date_format:H:i',
            'ends_at' => 'sometimes|date_format:H:i',
            'kind' => 'sometimes|in:academy,external,leave,personal',
            'title' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'is_recurring' => 'sometimes|boolean',
            'specific_date' => 'nullable|date',
        ]);

        $start = $data['starts_at'] ?? substr($block->starts_at, 0, 5);
        $end = $data['ends_at'] ?? substr($block->ends_at, 0, 5);
        if ($start === $end) {
            return response()->json(['message' => 'وقت النهاية لازم يكون مختلف عن البداية'], 422);
        }

        $block->update($data);

        return response()->json($block->fresh());
    }

    /**
     * حذف block.
     * DELETE /teachers/{teacher}/availability/{block}
     */
    public function destroy($teacherId, TeacherSchedule $block)
    {
        abort_if($block->teacher_id !== (int) $teacherId, 403);

        $block->delete();

        return response()->json(['message' => 'تم حذف الفترة']);
    }

    /**
     * المعلم عنده جدول ولا لأ؟ (مطلوب قبل ما ياخد طلاب)
     * GET /teachers/{teacher}/availability/completeness
     */
    public function completeness($teacherId)
    {
        $count = TeacherSchedule::where('teacher_id', (int) $teacherId)->count();

        return response()->json([
            'teacher_id' => (int) $teacherId,
            'has_schedule' => $count > 0,
            'blocks_count' => $count,
        ]);
    }

    /**
     * المعلمين المتاحين في مواعيد معيّنة — للـ dropdown.
     * GET /availability/teachers?weekdays[]=0&weekdays[]=4&start_time=16:00&duration=30
     */
    public function availableTeachers(Request $request)
    {
        $data = $request->validate([
            'weekdays' => 'required|array|min:1|max:7',
            'weekdays.*' => 'integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i',
            'duration' => 'required|integer|min:15|max:240',
            'on_date' => 'nullable|date',
            'exclude_lesson_id' => 'nullable|integer',
        ]);

        $onDate = isset($data['on_date']) ? \Carbon\Carbon::parse($data['on_date']) : null;

        $teachers = $this->availability->availableTeachers(
            $data['weekdays'],
            $data['start_time'],
            $data['duration'],
            $onDate,
            $data['exclude_lesson_id'] ?? 0,
        );

        return response()->json([
            'weekdays' => $data['weekdays'],
            'start_time' => $data['start_time'],
            'duration' => $data['duration'],
            'teachers' => $teachers,
            'available_count' => count(array_filter($teachers, fn ($t) => $t['available'])),
        ]);
    }
}
