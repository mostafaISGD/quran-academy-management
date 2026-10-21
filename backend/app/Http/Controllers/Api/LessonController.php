<?php

namespace App\Http\Controllers\Api;

use App\Enums\LessonStatus;
use App\Exceptions\SchedulingConflictException;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonReschedule;
use App\Models\MemorizationRecord;
use App\Models\ProgressRecord;
use App\Services\LessonScheduler;
use App\Services\LessonService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class LessonController extends Controller
{
    public function __construct(private readonly LessonScheduler $scheduler) {}

    public function schedule(Request $request)
    {
        $view = $request->string('view', 'week');
        $date = $request->filled('date') ? Carbon::parse($request->string('date')) : now();

        [$from, $to] = match ((string) $view) {
            'day' => [$date->copy()->startOfDay(), $date->copy()->endOfDay()],
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()],
            default => [$date->copy()->startOfWeek(), $date->copy()->endOfWeek()],
        };

        $query = Lesson::query()
            ->whereRange('scheduled_start_at', $from, $to)
            ->with(['student', 'teacher', 'program', 'level']);

        if ($request->filled('teacher_id')) {
            $query->where('teacher_id', $request->string('teacher_id'));
        }
        if ($request->filled('student_id')) {
            $query->where('student_id', $request->string('student_id'));
        }

        return response()->json($query->orderBy('scheduled_start_at')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'teacher_id' => 'required|exists:teachers,id',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'program_id' => 'required|exists:programs,id',
            'level_id' => 'nullable|exists:levels,id',
            'lesson_type' => 'nullable|in:regular,trial,makeup,extra,free,assessment',
            'scheduled_start' => 'required|date',
            'duration_minutes' => 'required|integer|min:15',
            'meeting_provider' => 'nullable|in:zoom,google_meet,other',
            'meeting_url' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['lesson_type'] = $data['lesson_type'] ?? 'regular';

        $start = Carbon::parse($data['scheduled_start']);
        $end = $start->copy()->addMinutes($data['duration_minutes']);

        try {
            $lesson = $this->scheduler->createLesson([
                ...$data,
                'scheduled_start_at' => $start,
                'scheduled_end_at' => $end,
            ]);
        } catch (SchedulingConflictException $e) {
            return $e->render();
        }

        return response()->json($lesson, 201);
    }

    public function storeRecurring(Request $request)
    {
        $data = $request->validate([
            'student_id' => 'required|exists:students,id',
            'teacher_id' => 'required|exists:teachers,id',
            'subscription_id' => 'nullable|exists:subscriptions,id',
            'program_id' => 'required|exists:programs,id',
            'level_id' => 'nullable|exists:levels,id',
            'weekdays' => 'required|array|min:1',
            'weekdays.*' => 'integer|min:0|max:6',
            'start_time' => 'required|date_format:H:i',
            'duration_minutes' => 'required|integer|min:15',
            'series_start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        $data['organization_id'] = $request->user()->organization_id;

        $result = $this->scheduler->createRecurringSeries($data);

        return response()->json([
            'created_count' => count($result['created']),
            'created' => $result['created'],
            'skipped' => $result['skipped'],
        ], 201);
    }

    public function update(Request $request, Lesson $lesson)
    {
        // ⚡ سياسة العلاقة (PR 1.8): تعديل الحصة لمعلم غير صاحبها مرفوض.
        Gate::authorize('update', $lesson);

        /**
         * ⭐⭐⭐ **PR 3.1 — `update` ما بيغيّرش الحالة.**
         *
         * المواصفة بتقول «يتجاهل `status`» — اخترنا **422**
         * بدل التجاهل الصامت: التجاهل بيرجّع 200 والعميل يظن إن
         * الحالة اتغيّرت، فبيلاقي الداتا زي ما هي. الرفض الصريح
         * بيقول للعميل إن طريق التحويل هو
         * `POST /api/lessons/{lesson}/transition`.
         */
        if ($request->has('status')) {
            return response()->json([
                'message' => 'تغيير حالة الحصة من /api/lessons/{lesson}/transition بس — الحقل status مش بيتقبل هنا',
            ], 422);
        }

        $data = $request->validate([
            'scheduled_start' => 'sometimes|date',
            'duration_minutes' => 'sometimes|integer|min:15',
            'notes' => 'nullable|string',
        ]);

        if (isset($data['scheduled_start']) || isset($data['duration_minutes'])) {
            $start = Carbon::parse($data['scheduled_start'] ?? $lesson->scheduled_start_at);
            $duration = $data['duration_minutes'] ?? $lesson->duration_minutes;
            $end = $start->copy()->addMinutes($duration);

            $conflict = $this->scheduler->findConflict($lesson->teacher_id, $start, $end, excludeLessonId: $lesson->id);
            if ($conflict) {
                return (new SchedulingConflictException($conflict))->render();
            }

            LessonReschedule::create([
                'lesson_id' => $lesson->id,
                'old_start' => $lesson->scheduled_start_at,
                'old_end' => $lesson->scheduled_end_at,
                'new_start' => $start,
                'new_end' => $end,
                'reason' => 'تحديث يدوي',
                'changed_by' => $request->user()->id,
                'created_at' => now(),
            ]);

            $data['scheduled_start_at'] = $start;
            $data['scheduled_end_at'] = $end;
            $data['duration_minutes'] = $duration;

            unset($data['scheduled_start']);
        }

        $lesson->update($data);
        return response()->json($lesson->fresh());
    }

    /**
     * ⭐⭐⭐ **PR 3.1 — القناة الوحيدة لتغيير حالة الحصة.**
     *
     * `LessonService::transition` هو الكاتب الوحيد للحالة، وكل
     * تحويل يتسجَّل في `lesson_status_history`. التحويل المخالف
     * لجدول التحويلات = **422** (مش 403 — الرفض من القاعدة لا
     * من الصلاحية).
     */
    public function transition(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        $data = $request->validate([
            'to' => ['required', 'string', Rule::in(LessonStatus::values())],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $to = LessonStatus::from($data['to']);

        // ⭐ الإلغاء لازم سبب — من غيره السجل بيكون ناقص.
        if ($to->isCancellation() && blank($data['reason'] ?? null)) {
            return response()->json([
                'message' => 'سبب الإلغاء مطلوب',
            ], 422);
        }

        $updated = app(LessonService::class)->transition(
            $lesson,
            $to,
            $request->user(),
            $data['reason'] ?? null,
        );

        return response()->json($updated);
    }

    public function cancel(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        $data = $request->validate([
            'cancellation_reason' => 'required|string|max:255',
        ]);

        /**
         * ⭐⭐⭐ **PR 3.1 — الإلغاء بقى يمرّ بآلة الحالات.**
         *
         * `LessonService::cancel` يختار **المبكر** (`cancelled_by_academy`)
         * أو **المتأخر** (`cancelled_late`) حسب وقت بداية الحصة —
         * والفرق في **الرصيد والمستحقات** فرق حقيقي، مش شكلي.
         */
        $updated = app(LessonService::class)->cancel(
            $lesson,
            $request->user(),
            $data['cancellation_reason'],
        );

        return response()->json($updated);
    }

    public function makeup(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        $data = $request->validate([
            'scheduled_start' => 'required|date',
        ]);

        $start = Carbon::parse($data['scheduled_start']);
        $end = $start->copy()->addMinutes($lesson->duration_minutes);

        /**
         * ⭐⭐⭐ **PR 3.4 — الحجز الجديد **أول**، وبعدين|Original
         * بيتغيّر.**
         *
         * ⚠️ كان العكس: الأصل بيتحوّل `teacher_absent` **وبعدين**
         * بنحجز التعويضية — فلو الحجز فشل (تعارض جدول) =>
         * الأصل بقى «المعلم غاب» والحصة التعويضية **مش موجودة**.
         * (صُحّح جزئياً في PR 3.1 بإضافة قفل، لكن الترتيب فضل
         * غلط ⇒ دلوقتي Atomicity كاملة.)
         *
         * ⭐ كله في transaction واحد: أي فشل يرجّع الأصل زي ما
         * كان (والتعارض بيرجّع 409 زي الأول).
         */
        try {
            $makeup = DB::transaction(function () use ($lesson, $start, $end, $request) {
                // ⭐ ① الحجز الجديد **أول** — لو فشل، الأصل ماتغيّرش.
                $makeup = $this->scheduler->createMakeupLesson($lesson, $start, $end);

                // ⭐ ② غياب المعلم قرار مسجَّل (مش تعديل status مباشر).
                app(LessonService::class)->transition(
                    $lesson,
                    LessonStatus::TeacherAbsent,
                    $request->user(),
                    'جدولة حصة تعويضية',
                );

                return $makeup;
            });
        } catch (SchedulingConflictException $e) {
            return $e->render();
        }

        return response()->json($makeup, 201);
    }

    public function complete(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        /**
         * ⭐⭐⭐ **PR 3.4 — الإكمال = خط أنابيب واحد (idempotent).**
         *
         * كل الآثار (الحالة + الخصم + المستحق + سجل النشاط +
         * الإشعار) في **نفس الـtransaction** جوه `LessonService`:
         *
         * - النداء التاني = **بلا أثر** (200 بالحالة الحالية) —
         *   بدل 422 القديم اللي كان بيعني «كرّر الطلب» ما كانش
         *   بيتعامل معاه صح.
         * - لو رصيد الطالب ما كفى ⇒ 422 والكل يرجع (مفيش مستحق
         *   معلّق على حصة ما اتقبلتش).
         */
        $updated = app(LessonService::class)->complete($lesson, $request->user());

        return response()->json($updated);
    }

    /**
     * ⭐⭐⭐ **PR 3.4 — تصحيح حالة حصة بعد الإتمام.**
     *
     * ⭐ **مش تعديل**: الأثر بيتعكس بحركات (رصيد + مستحق)،
     * والتعديل بيتسجَّل في سجل التحويلات وسجل النشاط.
     */
    public function correct(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        $data = $request->validate([
            'to' => ['required', 'string', Rule::in(LessonStatus::values())],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $updated = app(LessonService::class)->correct(
            $lesson,
            LessonStatus::from($data['to']),
            $request->user(),
            $data['reason'],
        );

        return response()->json($updated);
    }

    public function attendance(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        /**
         * ⭐⭐⭐ **PR 3.5 — الحضور يدرّج النتيجة.**
         *
         * ⭐ قبل PR ده: الحضور كان **سجل جانبي** (سطر واحد للحصة
         * بـ`unique(lesson_id)`) ولا كان بيلمس الحالة ولا الرصيد.
         * دلوقتي: الحضور **لكل طالب** (`entries`) وهو اللي بيحدّد
         * النتيجة والأثر المالي (خصم الحاضر، بلا خصم الغايب).
         *
         * ⭐ والشكل القديم (حصة فردية: `status` على مستوى الحصة)
         * لسه شغّال — بيتحوّل لentry واحد.
         */
        $data = $request->validate([
            'entries' => ['sometimes', 'array', 'min:1'],
            'entries.*.student_id' => ['required_with:entries', 'integer', 'exists:students,id'],
            'entries.*.status' => ['required_with:entries', 'in:present,absent,late,excused'],
            'entries.*.late_minutes' => ['nullable', 'integer', 'min:0'],
            'entries.*.notes' => ['nullable', 'string', 'max:500'],
            'status' => ['required_without:entries', 'in:present,absent,late,excused'],
            'late_minutes' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $entries = $data['entries'] ?? [[
            'student_id' => $lesson->student_id,
            'status' => $data['status'],
            'late_minutes' => $data['late_minutes'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]];

        $results = app(LessonService::class)->recordAttendance($lesson, $entries, $request->user());

        return response()->json([
            'data' => $results,
            'lesson' => $lesson->fresh(),
        ], 201);
    }

    public function memorization(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        $data = $request->validate([
            'surah_id' => 'required|exists:quran_surahs,id',
            'from_ayah' => 'required|integer|min:1',
            'to_ayah' => 'required|integer|min:1|gte:from_ayah',
            'quality' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string',
        ]);

        $record = MemorizationRecord::create([
            'student_id' => $lesson->student_id,
            'lesson_id' => $lesson->id,
            'teacher_id' => $lesson->teacher_id,
            ...$data,
            'recorded_at' => now(),
        ]);

        ProgressRecord::create([
            'student_id' => $lesson->student_id,
            'lesson_id' => $lesson->id,
            'teacher_id' => $lesson->teacher_id,
            'program_id' => $lesson->program_id,
            'level_id' => $lesson->level_id,
            'category' => 'memorization',
            'score' => $data['quality'] ?? null,
            'recorded_at' => now(),
        ]);

        return response()->json($record, 201);
    }
}
