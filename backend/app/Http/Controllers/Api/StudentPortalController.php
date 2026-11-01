<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lesson;
use App\Services\LessonJoinService;
use App\Services\StudentPortalView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ⭐⭐⭐ **PR 4.6 — بوابة الطالب (`/student/*`).**
 *
 * ⭐ الطالب المستقل (≥ 13 بموافقة مسجّلة — PR 1.12) بيقرا **نفسه
 * فقط**: جدوله، حضوره، تقدمه، وحفظه. مفيش أي مسار مالي هنا.
 *
 * ⭐ الحراسة طبقتين:
 *   ① `permission:student.portal` — الحساب له بوابة طالب.
 *   ② `StudentPortalView::studentFor` — فيه ملف طالب مربوط
 *      بالحساب. صاحب الصلاحية بلا بروفايل = 403.
 *
 * ⭐⭐ **مفيش `{student}` في أي راو** — الطالب بيتحدد من
 * `students.user_id`، فمستحيل طالب يطلب بيانات طالب تاني حتى لو
 * خمّن رقمه. وزر الانضمام بيفحص الملكية (فردية أو مجموعة) قبل
 * أي تسجيل.
 */
class StudentPortalController extends Controller
{
    public function __construct(private readonly StudentPortalView $view) {}

    /** ⭐ نظرة عامة: الحصة القادمة + الحضور + ملخص الحفظ. */
    public function overview(Request $request): JsonResponse
    {
        $student = $this->view->studentFor($request->user());

        return response()->json($this->view->overview($student));
    }

    /** ⭐ جدولي: القادم والسابق — فردي ومجموعات. */
    public function schedule(Request $request): JsonResponse
    {
        $student = $this->view->studentFor($request->user());

        return response()->json($this->view->schedule($student));
    }

    /** ⭐ تقدمي: الحضور والتقدم وخريطة الحفظ. */
    public function progress(Request $request): JsonResponse
    {
        $student = $this->view->studentFor($request->user());

        return response()->json($this->view->progress($student));
    }

    /**
     * ⭐ زر «انضم للحصة».
     *
     * ⭐ الحصة مش بتاعته = **404** (مش 403): مفيش داعي نقول له
     * «فيه حصة بالرقم ده بس مش بتاعتك» — ده بيكشف وجودها.
     *
     * ⭐ الحصة بتاعته لكن ملغاة/بلا رابط = **422** — الكيان موجود
     * والحالة هي اللي مانعة.
     */
    public function join(Request $request, Lesson $lesson): JsonResponse
    {
        $student = $this->view->studentFor($request->user());

        if (! $this->view->ownsLesson($student, $lesson)) {
            abort(404, 'الحصة مش موجودة');
        }

        $join = app(LessonJoinService::class);

        if (! $join->isJoinable($lesson)) {
            return response()->json([
                'message' => 'الحصة مش متاحة للدخول دلوقتي',
            ], 422);
        }

        $participant = $join->record($lesson, $student, 'student_portal');

        return response()->json([
            'data' => [
                'lesson_id' => $lesson->id,
                'join_url' => $join->meetingUrl($lesson),
                'joined_at' => $participant->joined_at?->toIso8601String(),
            ],
        ]);
    }
}
