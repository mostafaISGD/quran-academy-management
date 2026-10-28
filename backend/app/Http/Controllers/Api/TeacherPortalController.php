<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Models\QuranSurah;
use App\Models\Setting;
use App\Models\Teacher;
use App\Services\TeacherLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * ⭐⭐⭐ **PR 3.10 — بوابة المعلم: القوائم والتقارير.**
 *
 * ⭐ **قبل PR ده** المعلم كان محتاج صلاحيات إدارية (`lessons.view`)
 * يشوف جدول حصصه، والقوائم العامة (`/lessons/schedule`) بتورّي
 * بيانات المؤسسة كلها — مش مقصود للمعلم.
 *
 * ⭐ مبدأ البوابة:
 *
 * • **هو بس**: البرنامج مشفّل على `teacher_id` بتاعه
 *   (والملكية في التغييرات محكومة بـ`LessonPolicy` — PR 1.8).
 * • **هاتف ولي الأمر مخفي افتراضيًا**: إعداد
 *   `teacher_portal.expose_parent_phone` (false افتراضيًا) —
 *   قرار المؤسسة هو اللي بيفتحه.
 * • **المنطقة الزمنية بتاعته** (`users.timezone` — PR 3.7) هي
 *   اللي بتحدد «اليوم» و«الأسبوع».
 */
class TeacherPortalController extends Controller
{
    /** ⭐ مفتاح إعداد إظهار هاتف ولي الأمر. */
    public const EXPOSE_PARENT_PHONE = 'teacher_portal.expose_parent_phone';

    public function __construct(private readonly TeacherLedgerService $ledger) {}

    /**
     * ⭐ دروس اليوم — في منطقة المعلم الزمنية (متنساش إن
     * تخزين الحصص UTC جوه PR 3.7).
     */
    public function today(Request $request)
    {
        $day = CarbonImmutable::now($this->zone($request));

        return $this->respondDay($request, $day, 'اليوم');
    }

    /** ⭐ الجدول الأسبوعي. */
    public function week(Request $request)
    {
        $start = CarbonImmutable::now($this->zone($request))->startOfWeek();

        return $this->respondDay($request, $start, 'الأسبوع', 7);
    }

    /**
     * ⭐ كل حصصي — فلاتر اختيارية `from`/`to`/`status`.
     */
    public function lessons(Request $request)
    {
        $teacher = $this->teacher($request);

        $query = Lesson::query()
            ->where('teacher_id', $teacher->id)
            ->where('organization_id', $teacher->organization_id)
            ->with(['student:id,first_name,last_name', 'student.parents'])
            ->orderBy('scheduled_start_at');

        if ($request->filled('from')) {
            $query->where('scheduled_start_at', '>=', CarbonImmutable::parse($request->string('from'), $this->zone($request))->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where('scheduled_start_at', '<=', CarbonImmutable::parse($request->string('to'), $this->zone($request))->endOfDay()->utc());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $lessons = $query->limit(200)->get()
            ->map(fn (Lesson $l) => $this->present($l, $teacher));

        return response()->json([
            'teacher_id' => $teacher->id,
            'lessons' => $lessons,
        ]);
    }

    /** ⭐ كشف مستحقاتي (نفس شكل كشف حساب المعلم — PR 2.11). */
    public function earnings(Request $request)
    {
        $teacher = $this->teacher($request);

        return response()->json($this->ledger->statementFor($teacher));
    }

    /**
     * ⭐⭐⭐ **PR 4.4 — قائمة السور لنموذج الحفظ في البوابة.**
     *
     * ⚠️ قبل كده مفيش راو بيوصل `quran_surahs` للواجهة:
     * `/teacher/lessons/{lesson}/memorization` بياخد `surah_id`،
     * والواجهة مكانش عندها منين تجيبه. فالنموذج كان لازم يكتب
     * الـ114 سورة بالإيد — وده بيتعارض مع أي تعديل في السيدر.
     *
     * ⭐ والمعلم بس: الراو جوه `permission:teacher.portal`، و`teacher()`
     * بترفض أي مستخدم بلا بروفايل معلم.
     */
    public function surahs(Request $request)
    {
        $this->teacher($request);

        return response()->json([
            'surahs' => QuranSurah::query()
                ->orderBy('number')
                ->get(['id', 'number', 'name_ar', 'name_en', 'ayah_count', 'revelation_type']),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════
    // الأدوات
    // ═══════════════════════════════════════════════════════════════

    /**
     * ⭐ المستخدم لازم يكون له بروفايل معلّم.
     *
     * صاحب الصلاحية بلا بروفايل = جمعية غريبة — بيرد 403 مش
     * استعلام على القابل تأجيل.
     */
    private function teacher(Request $request): Teacher
    {
        $teacher = $request->user()->teacher()->first();

        if ($teacher === null) {
            abort(403, 'المستخدم الحالي ليس معلماً — البوابة للمعلمين فقط');
        }

        return $teacher;
    }

    /** ⭐ منطقة المعلم الزمنية (المستخدم ثم المؤسسة ثم app). */
    private function zone(Request $request): string
    {
        $user = $request->user();

        return $user->timezone
            ?: Teacher::find($request->user()->teacher()->value('id'))?->timezone
            ?: config('app.timezone', 'UTC');
    }

    /**
     * ⭐ القراءة لمدة $days — النطاق بيتحسب في منطقة المعلم
     * وبمقارنة الحصص UTC (نفس قاعدة PR 3.7).
     */
    private function respondDay(Request $request, CarbonImmutable $start, string $label, int $days = 1)
    {
        $teacher = $this->teacher($request);

        $from = $start->startOfDay()->utc();
        $to = $start->startOfDay()->addDays($days)->utc();

        return response()->json([
            'teacher_id' => $teacher->id,
            'label' => $label,
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'timezone' => $this->zone($request),
            'lessons' => Lesson::query()
                ->where('teacher_id', $teacher->id)
                ->where('organization_id', $teacher->organization_id)
                ->where('scheduled_start_at', '>=', $from)
                ->where('scheduled_start_at', '<', $to)
                ->with(['student:id,first_name,last_name', 'student.parents'])
                ->orderBy('scheduled_start_at')
                ->get()
                ->map(fn (Lesson $l) => $this->present($l, $teacher)),
        ]);
    }

    /**
     * ⭐ الشكل العام للسطر — هاتف ولي الأمر **مخفي افتراضيًا**.
     */
    private function present(Lesson $l, Teacher $teacher): array
    {
        $student = $l->student;

        $parent = $student
            ? ($student->parents->firstWhere('pivot.is_primary', true) ?? $student->parents->first())
            : null;

        // ⭐ الإعداد بيقرا لكل مؤسسة — مخفي لو القيمة false/ناقصة.
        $expose = (bool) Setting::get(self::EXPOSE_PARENT_PHONE, false, $teacher->organization_id);

        return [
            'id' => $l->id,
            'status' => $l->status,
            'lesson_type' => $l->lesson_type,
            'scheduled_start_at' => optional($l->scheduled_start_at)->toIso8601String(),
            'scheduled_end_at' => optional($l->scheduled_end_at)->toIso8601String(),
            'duration_minutes' => $l->duration_minutes,
            'timezone' => $l->timezone,
            'local_start_time' => $l->local_start_time,

            // ⭐⭐ **PR 4.4 — زر «انضم» محتاج الرابط.**
            // قبل كده المعلم كان بيشوف الحصة وما يعرفش يدخلها من
            // البوابة؛ الرابط موجود في الجدول بس `present()` كان
            // ناسيه.
            'meeting_provider' => $l->meeting_provider,
            'meeting_url' => $l->meeting_url,

            'subscription_id' => $l->subscription_id,
            'student' => $student ? [
                'id' => $student->id,
                'name' => trim(($student->first_name ?? '').' '.($student->last_name ?? '')),
            ] : null,
            'parent' => $parent ? [
                'name' => $parent->name,
                'phone' => $expose ? $parent->phone : null,
            ] : null,
        ];
    }
}
