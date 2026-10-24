<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\LessonJoinToken;
use App\Models\LessonParticipant;

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

        if ($lesson === null || trim((string) $lesson->meeting_url) === '') {
            return response()->json(['message' => 'الحصة مالهاش رابط اجتماع بعد'], 422);
        }

        // ⭐ **الدخول مرة واحدة لكل مشارك** — `firstOrCreate` فوق
        // قيد فريد `(lesson_id, student_id)`.
        LessonParticipant::firstOrCreate(
            [
                'lesson_id' => $lesson->id,
                'student_id' => $row->student_id,
            ],
            [
                'token_id' => $row->id,
                'source' => 'join_link',
                'joined_at' => now(),
            ],
        );

        // ⭐ الرمز **يستهلك مرة واحدة** (PR 1.12) عند أول دخول
        // ناجح — الزيارة التانية صفّرة زيارات وتعيد التوجيه فقط.
        if ($row->used_at === null) {
            $row->forceFill(['used_at' => now(), 'used_by' => $row->student_id])->save();
        }

        return redirect()->away($lesson->meeting_url);
    }
}
