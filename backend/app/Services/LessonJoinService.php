<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\Lesson;
use App\Models\LessonParticipant;
use App\Models\Student;

/**
 * ⭐⭐⭐ **PR 4.6 — قواعد الدخول للحصة في مكان واحد.**
 *
 * ⭐ قبل كده كل مسار كان بيقرر لوحده:
 *
 *   • `/join/{token}` — يفحص `meeting_url` ويكتب `join_link`.
 *   • `POST /lessons/join` (PIN) — ما بيسجّلش مشارك أصلاً.
 *   • بوابة الطالب — مكانتش موجودة.
 *
 * ⭐ النتيجة: نفس الحصة «قابلة للدخول» في مسار ومش قابلة في تاني،
 * والدخول بالـPIN مكانش بيوصل لسجل `lesson_participants` اللي
 * بيغذّي اقتراح الحضور.
 *
 * ⭐ الخدمة دي هي **المصدر الوحيد** للحكم:
 *   • `isJoinable` — الحصة لسه متاحة + عندها رابط.
 *   • `meetingUrl` — الرابط النظيف (أو null).
 *   • `record` — سطر واحد لكل (حصة، طالب) مهما تكرر الدخول.
 */
final class LessonJoinService
{
    /**
     * ⭐ الحالات اللي لسه ينفع يدخل فيها.
     *
     * ⭐ الملغاة والمؤجّلة والمكتملة مش من ضمنهم: الدخول بعد
     * الإلغاء مش «دخول متأخر»، ده باب مفتوح لحصة مش موجودة.
     *
     * @var array<int, string>
     */
    private const JOINABLE_STATUSES = [
        LessonStatus::Scheduled->value,
        LessonStatus::Confirmed->value,
        LessonStatus::InProgress->value,
    ];

    /** ⭐ الرابط الحقيقي (Zoom/Meet) — فاضي = null مش سلسلة فاضية. */
    public function meetingUrl(Lesson $lesson): ?string
    {
        $url = trim((string) $lesson->meeting_url);

        return $url === '' ? null : $url;
    }

    /** ⭐ هل الحصة دي ينفع الطالب يدخلها دلوقتي؟ */
    public function isJoinable(Lesson $lesson): bool
    {
        return $this->meetingUrl($lesson) !== null
            && in_array($lesson->status, self::JOINABLE_STATUSES, true);
    }

    /**
     * ⭐ تسجيل الدخول — **مرة واحدة لكل مشارك**.
     *
     * ⭐ `firstOrCreate` فوق القيد الفريد `(lesson_id, student_id)`:
     * الزيارة التانية بترجّع نفس السطر، مش سطر جديد. و`source`
     * بيفضل أول مصدر دخل بيه الطالب (رابط/PIN/بوابة).
     */
    public function record(
        Lesson $lesson,
        Student $student,
        string $source,
        ?int $tokenId = null,
    ): LessonParticipant {
        return LessonParticipant::firstOrCreate(
            [
                'lesson_id' => $lesson->id,
                'student_id' => $student->id,
            ],
            [
                'token_id' => $tokenId,
                'source' => $source,
                'joined_at' => now(),
            ],
        );
    }
}
