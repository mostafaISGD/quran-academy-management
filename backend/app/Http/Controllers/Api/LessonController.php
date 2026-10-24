<?php

namespace App\Http\Controllers\Api;

use App\Enums\LessonStatus;
use App\Exceptions\LessonOnHoliday;
use App\Exceptions\SchedulingConflictException;
use App\Exceptions\TeacherUnavailable;
use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonJoinToken;
use App\Models\LessonParticipant;
use App\Models\LessonReschedule;
use App\Models\MemorizationRecord;
use App\Models\ProgressRecord;
use App\Rules\MeetingLinkRule;
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

    /**
     * ⭐⭐⭐ **PR 3.7 — منطقة المستخدم** (من `users.timezone`).
     *
     * ⭐ الfallback = `config('app.timezone')` (UTC) — عشان أي
     * حساب بلا منطقة محفوظة ما يبقاش فيه سلوك غامض.
     */
    private function timezoneFor(Request $request): string
    {
        $timezone = $request->user()?->timezone;

        if (is_string($timezone) && $timezone !== '') {
            return $timezone;
        }

        return (string) config('app.timezone');
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
            // ⭐⭐⭐ **PR 3.11 — الرابط من مزود مسموح** (Zoom/Meet/Teams).
            'meeting_url' => ['nullable', 'string', 'max:2048', new MeetingLinkRule((int) $request->user()->organization_id)],
            'notes' => 'nullable|string',
        ]);

        $data['organization_id'] = $request->user()->organization_id;
        $data['lesson_type'] = $data['lesson_type'] ?? 'regular';

        /**
         * ⭐⭐⭐ **PR 3.7 — تفسير الموعد في منطقة صريحة.**
         *
         * ⭐ قبل PR ده كان `Carbon::parse($value)` **بلا منطقة** ⇒
         * التفسير كان على `APP_TIMEZONE` (UTC) ⇒ عميل في القاهرة
         * كاتب «16:00» كان بيحجز 16:00 **UTC** (يعني 18:00
         * محليًا). دلوقتي: لو القيمة فيها offset (ISO-8601) بيُحترم
         * زي ما هو؛ ولو مفيهاش ⇒ بنفسّرها في **منطقة المستخدم**
         * (`users.timezone`) — وهي المنطقة اللي المستخدم شايف
         * المواعيد بيها.
         */
        $timezone = $this->timezoneFor($request);

        $start = Carbon::parse($data['scheduled_start'], $timezone);
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

        // ⭐⭐⭐ **PR 3.7 — السلسلة بتتولّد في منطقة المستخدم**
        // (أو المعلم) ⇒ الساعة المحلية ثابتة عبر التوقيت الصيفي.
        $data['timezone'] = $this->timezoneFor($request);

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

            /**
             * ⭐⭐⭐ **PR 3.11 — الرابط بيتعدّل من هنا** — والفحص
             * بقاعدة «مزود مسموح»، والفارغ بيرجع افتراضي المعلم
             * (الخدمة بتندهالنا جوه الحجز/التعديل).
             */
            'meeting_url' => ['sometimes', 'string', 'max:2048', new MeetingLinkRule((int) $lesson->organization_id)],
            'meeting_provider' => ['sometimes', 'in:zoom,google_meet,other'],
        ]);

        if (isset($data['scheduled_start']) || isset($data['duration_minutes'])) {
            // ⭐ PR 3.7 — تفسير الموعد في منطقة المستخدم (صريحة).
            $timezone = $this->timezoneFor($request);
            $start = Carbon::parse($data['scheduled_start'] ?? $lesson->scheduled_start_at, $timezone);
            $duration = $data['duration_minutes'] ?? $lesson->duration_minutes;
            $end = $start->copy()->addMinutes($duration);

            // ⭐⭐⭐ **PR 3.6 — إعادة الجدولة بتفحص الجدولة كلها:**
            // عطلة + حظر المعلم + تعارض (معلم **أو طالب**).
            try {
                $this->scheduler->assertBookable(
                    (string) $lesson->teacher_id,
                    $start,
                    $end,
                    (int) $lesson->organization_id,
                    $lesson->student_id,
                    (string) $lesson->id,
                );
            } catch (SchedulingConflictException $e) {
                return $e->render();
            } catch (LessonOnHoliday $e) {
                return $e->render();
            } catch (TeacherUnavailable $e) {
                return $e->render();
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

    /**
     * ⭐⭐⭐ **PR 3.10 — «بداية الدرس» في بوابة المعلم.**
     *
     * ⭐ مش إداري جديد: البوابة بتستعمل المسار ده (`/teacher/*`)
     * والملكية محكومة بـ`Gate::authorize('update', ...)` —
     * معلم تانٍ = 403 (كانت القيمة اللي بتتحقق في الاختبارات).
     *
     * التحويل نفسه من **آلة الحالات** (PR 3.1): `scheduled`
     * أو `confirmed` ⇒ `in_progress`.
     */
    public function start(Request $request, Lesson $lesson)
    {
        Gate::authorize('update', $lesson);

        $updated = app(LessonService::class)->transition(
            $lesson,
            LessonStatus::InProgress,
            $request->user(),
        );

        return response()->json($updated);
    }

    /**
     * ⭐⭐⭐ **PR 3.11 — سجل الدخول** (يغذّي اقتراح الحضور).
     *
     * `lesson_participants` — سطر لكل مشارك دخل الحصة
     * (من `/join/{token}`). الوقّف يقرا السجل ويملّي الحضور
     * بقاعدة «اللي دخل = مقترح حاضر».
     */
    public function participants(Request $request, Lesson $lesson)
    {
        Gate::authorize('view', $lesson);

        $participants = LessonParticipant::query()
            ->where('lesson_id', $lesson->id)
            ->with('student:id,first_name,last_name')
            ->orderBy('id')
            ->get();

        return response()->json([
            'lesson_id' => $lesson->id,
            'participants' => $participants,
        ]);
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

        /**
         * ⭐⭐⭐ **PR 3.13 — تحقق نطاق الآيات + منع تكرار الجزء + فئات
         * التقدم.**
         *
         * ⭐ **قبل PR ده**:
         *
         * - أي `to_ayah` بيتقبل حتى لو تجاوز عدد آيات السورة
         *   (الفاتحة 7 آيات وتقدر تبعث 300!): التحقق الوحيد
         *   كان exists بدون حصر بالنطاق.
         * - إعادة إرسال نفس الجزء كانت تضيف صفاً مكرراً كل مرة.
         * - الفئات الأخرى للتقدم (`tajweed`/`reading`/`revision`…)
         *   موجودة في عمود `progress_records.category` لكن الـAPI
         *   ما كان يكتبهاش — كان يكتب `memorization` فقط.
         *
         * ⭐ **دلوقتي**:
         *
         * - التحقق: الآية لا تتجاوز عدد آيات السورة
         *   (قاعدة إضافية بالسورة القرآنية).
         * - الفئة الافتراضية `memorization` تُكتب في `memorization_records`
         *   بعملية upsert على المفتاح (الطالب + الحصة + السورة
         *   + نطاق الآيات) حتى ما يتكرر شيئاً — والقيمة الأخيرة
         *   تُدوَّن في الصف نفسه.
         * - الفئات الأخرى تُكتب في `progress_records` فقط (upsert
         *   على الطالب + الحصة + الفئة).
         * - في **كل الحالات** يبقى التقدم مربوط بالحصة، و201
         *   عند الإنشاء و200 عند التدوين التحديثي (بدون نسخ).
         */

        // ⭐ الفئة الافتراضية `memorization` حاضرة في الحقل حتى
        // القواعد الاعتمادية تشوفها (بعد ذلك البيانات تجي بدونها).
        if (! $request->filled('category')) {
            $request->merge(['category' => 'memorization']);
        }

        /** @var array{category: string, surah_id?: int, from_ayah?: int, to_ayah?: int, quality?: int, notes?: string} $data */
        $data = $request->validate([
            'category' => [
                'required',
                \Illuminate\Validation\Rule::in([
                    'memorization', 'tajweed', 'pronunciation', 'reading', 'fluency', 'revision',
                ]),
            ],

            // ⭐ حقول الحفظ — إجبارية لحساب `memorization` فقط
            // (الفئات الأخرى قيم تقدم خالصة، بلا آيات).
            'surah_id' => [
                'required_if:category,memorization',
                'integer',
                'exists:quran_surahs,id',
            ],
            'from_ayah' => [
                'required_if:category,memorization',
                'integer',
                'min:1',
            ],
            'to_ayah' => [
                'required_if:category,memorization',
                'integer',
                'min:1',
                'gte:from_ayah',

                // ⭐⭐ **القبول ①**: الآية بلا تجاوز عدد آيات السورة.
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    if ($request->input('category') !== 'memorization') {
                        return;
                    }

                    /** @var \App\Models\QuranSurah|null $surah */
                    $surah = \App\Models\QuranSurah::find($request->input('surah_id'));

                    if ($surah === null) {
                        return;
                    }

                    if ((int) $value > (int) $surah->ayah_count) {
                        $fail('لا يمكن تجاوز عدد آيات السورة — '
                            .($surah->name_ar ?? ('سورة #'.$surah->id)).' عدد آياتها '
                            .$surah->ayah_count.'، وطلبك الآن '.$value.'.');
                    }
                },
            ],

            'quality' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|string',
        ]);

        $category = $data['category'];

        if ($category !== 'memorization') {
            // ⭐ الفئات الأخرى — تسجيل تقدّم فقط، بلا صف حفظ.
            $progress = ProgressRecord::updateOrCreate(
                ['student_id' => $lesson->student_id, 'lesson_id' => $lesson->id, 'category' => $category],
                [
                    'teacher_id' => $lesson->teacher_id,
                    'program_id' => $lesson->program_id,
                    'level_id' => $lesson->level_id,
                    'score' => $data['quality'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'recorded_at' => now(),
                ],
            );

            return response()->json($progress, $progress->wasRecentlyCreated ? 201 : 200);
        }

        // ⭐⭐ **منع تكرار الجزء + تدوين القيمة الأخيرة**: upsert على
        // المفتاح (الطالب + الحصة + السورة + نطاق الآيات)،
        // ويحرسه أيضاً فهرس فريد في القاعدة
        // (memorization_segment_unique) ضد أي سباق كتابة.
        $record = MemorizationRecord::updateOrCreate(
            [
                'student_id' => $lesson->student_id,
                'lesson_id' => $lesson->id,
                'surah_id' => $data['surah_id'],
                'from_ayah' => $data['from_ayah'],
                'to_ayah' => $data['to_ayah'],
            ],
            [
                'teacher_id' => $lesson->teacher_id,
                'quality' => $data['quality'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_at' => now(),
            ],
        );

        ProgressRecord::updateOrCreate(
            ['student_id' => $lesson->student_id, 'lesson_id' => $lesson->id, 'category' => 'memorization'],
            [
                'teacher_id' => $lesson->teacher_id,
                'program_id' => $lesson->program_id,
                'level_id' => $lesson->level_id,
                'score' => $data['quality'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_at' => now(),
            ],
        );

        return response()->json($record, $record->wasRecentlyCreated ? 201 : 200);
    }
}
