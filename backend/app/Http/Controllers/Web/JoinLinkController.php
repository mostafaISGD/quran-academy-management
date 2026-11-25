<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LessonJoinToken;
use App\Services\LessonJoinService;
use Illuminate\Http\JsonResponse;

/**
 * ⭐⭐⭐ **PR 3.11 — `/join/{token}`: رابط الدخول الوسيط.**
 *
 * ⭐ **قبل PR ده** مكانش فيه مكان نسجّل فيه إن الطالب/ولي الأمر
 * دخل الحصة فعلاً — الحضور كان رأي الشخص يدويًا.
 *
 * ⭐ **المسار بيقول**: رمز من PR 1.12 (هاش، عائلة `LessonJoinToken`)
 * ⇒ التحقق (غير موجود/انتهى) ⇒ **سطر واحد** في `lesson_participants`
 * (قيد فريد: حصة × مشارك) ⇒ **تحويل 302** للرابط الحقيقي
 * (Zoom/Meet) — والسجل ده بيغذّي اقتراح الحضور من البوابة.
 *
 * ⭐ الحُكَم:
 *
 * • رمز **مش موجود** ⇒ **404** (مش 410: مفيش كيان أصلًا).
 * • رمز **منتهي** ⇒ **410 Gone** (القبول الصريح) — وما يتسجلش
 *   دخول وما بيتوجّهش.
 * • رمز **مستعمل** قبل كده (والتوقيت لسه عالمة) ⇒ يعيد التوجيه
 *   تاني (اتصال ضاع/توكيك) بمنظومة: القيد الفريد ضامن
 *   **دخول واحد لكل مشارك** — مافيش سطر تانٍ.
 *
 * ⭐ **PR 4.6** — أضفنا `preview`: واجهة الطفل (< 13) اللي مالوش
 * حساب بتفتح الرابط وتشوف «حصة مين وامتى» قبل ما تدخل. الرد
 * **ما فيهوش أي بيانات شخصية** — لا اسم الطالب ولا كوده ولا ولي
 * أمره. البيانات كلها عن الحصة والمعلم والبرنامج بس.
 */
class JoinLinkController extends Controller
{
    public function go(string $token)
    {
        $tokenHash = hash('sha256', $token);

        $row = LessonJoinToken::query()
            ->where('token_hash', $tokenHash)
            ->first();

        if ($row === null) {
            return response()->json(['message' => 'رمز الدخول غير صحيح'], 404);
        }

        if ($row->expires_at !== null && $row->expires_at->isPast()) {
            return response()->json(['message' => 'رمز الدخول انتهت صلاحيته'], 410);
        }

        $lesson = $row->lesson;
        $student = $row->student;

        if ($lesson === null || $student === null) {
            return response()->json(['message' => 'الحصة أو الطالب مش موجود'], 404);
        }

        $join = app(LessonJoinService::class);

        if (! $join->isJoinable($lesson)) {
            return response()->json(['message' => 'الحصة مالهاش رابط اجتماع بعد'], 422);
        }

        // ⭐ **الدخول مرة واحدة لكل مشارك** — `firstOrCreate` فوق
        // قيد فريد `(lesson_id, student_id)`.
        $join->record($lesson, $student, 'join_link', (int) $row->id);

        // ⭐ الرمز **يستهلك مرة واحدة** (PR 1.12) عند أول دخول
        // ناجح — الزيارة التانية صفّرة زيارات وتعيد التوجيه فقط.
        if ($row->used_at === null) {
            $row->forceFill([
                'used_at' => now(),
                // ⭐ `used_by` FK على `users` — مش على `students`.
                'used_by' => $student->user_id,
            ])->save();
        }

        return redirect()->away($join->meetingUrl($lesson));
    }

    /**
     * ⭐⭐⭐ **PR 4.6 — معاينة الرابط قبل الدخول (عامة).**
     *
     * ⭐ الطفل اللي مالوش حساب بيفتح `/join/{token}` في الواجهة،
     * والواجهة بتسأل الـendpoint ده: «الرابط ده بتاع أنهي حصة؟».
     *
     * ⭐ الرد **مقفول على بيانات الحصة**: المعلم، البرنامج، الموعد،
     * وهل فيه رابط. مفيش `student_id` ولا اسم طالب ولا أي بيانات
     * ولي أمر — حتى لو الرمز نفسه مربوط بطالب معيّن.
     */
    public function preview(string $token): JsonResponse
    {
        $row = LessonJoinToken::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if ($row === null) {
            return response()->json(['message' => 'رمز الدخول غير صحيح'], 404);
        }

        $lesson = $row->lesson;

        if ($lesson === null) {
            return response()->json(['message' => 'الحصة مش موجودة'], 404);
        }

        $expired = $row->expires_at !== null && $row->expires_at->isPast();
        $used = $row->used_at !== null;
        $meetingUrl = app(LessonJoinService::class)->meetingUrl($lesson);

        return response()->json([
            'data' => [
                'teacher_name' => $lesson->teacher?->full_name,
                'program_name' => $lesson->program?->name,
                'level_name' => $lesson->level?->name,
                'scheduled_start_at' => $lesson->scheduled_start_at?->toIso8601String(),
                'scheduled_end_at' => $lesson->scheduled_end_at?->toIso8601String(),
                'duration_minutes' => (int) $lesson->duration_minutes,
                'status' => $lesson->status,
                'has_meeting' => $meetingUrl !== null,
                'expired' => $expired,
                'used' => $used,
                // ⭐ الرابط الوسيط بس — مش رابط Zoom نفسه. الدخول
                // الحقيقي بيسجّل المشارك ويحوّل من `/join/{token}`.
                'join_url' => (! $expired && ! $used && $meetingUrl !== null)
                    ? url('/join/'.$token)
                    : null,
            ],
        ]);
    }
}
