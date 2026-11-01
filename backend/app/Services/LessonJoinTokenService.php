<?php

namespace App\Services;

use App\Models\Lesson;
use App\Models\LessonJoinToken;
use App\Models\Student;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ⭐ PR 1.12 — دخول الحصة للطالب اللي مالوش حساب (تحت 13).
 *
 * ═══════════════════════════════════════════════════════════════
 * ⭐ ليه رموز أصلاً؟ لأن «تحت 13 مالوش حساب» معناها إن الطفل
 * مش هيلاقي صفحة ولا إيميل ولا كلمة مرور. فلازم طريقة يدخل بيها
 * حصته: **رابط/كود بولي الأمر**.
 *
 * ⭐⭐ ليش **PIN من 6 أرقام مش كود عشوائي طويل**؟
 *
 *   لأن الـPIN بيتبعت في **رسالة لولي الأمر** على واتساب/تليفون،
 *   والطفل (تحت 13) مش بيفتح إيميل. فالقصير عملي.
 *
 *   ⭐ والأمان مش في السرّ — الأمان في **القيود**:
 *
 *   • مربوط بـ`lesson_id` واحد: ما ينفعش يدخل حصة تانية.
 *   • `expires_at`: ساعة واحدة.
 *   • used_at: **استعمال واحد** — الرمز ما يشتغلش تاني.
 *   • 5 محاولات فاشلة → بينتهي.
 *
 *   ⭐ وده بالظبط نفس منطق `pin`/`attempts` بتاع الـreset —
 *   السرّ قصير والقيود ضيقة. (اللي بيخلّي الكود عشوائي طويل
 *   في الحاجات اللي **بتتسلّم** رقمياً, مش بتتسلّم لم humans.)
 */
class LessonJoinTokenService
{
    public const TTL_MINUTES = 60;

    public const MAX_ATTEMPTS = 5;

    /**
     * ⭐ الموظف/المعلم يولّد رمز لحصة + طالب.
     *
     * @return array{token: string, pin: string, expires_at: string}
     */
    public function issue(Lesson $lesson, Student $student, User $actor): array
    {
        $plain = Str::random(40);

        $pin = $this->uniquePin();

        $row = LessonJoinToken::create([
            'lesson_id' => $lesson->id,
            'student_id' => $student->id,
            'token_hash' => hash('sha256', $plain),
            'pin' => $pin,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_by' => $actor->id,
        ]);

        app(AuditLogService::class)->log(
            'lesson_join_token_issued',
            'lesson',
            (int) $lesson->id,
            null,
            ['student_id' => $student->id, 'expires_at' => $row->expires_at->toIso8601String()],
            null,
            $actor->id,
        );

        return [
            // ⭐ `pin` بس هو اللي بيروح لولي الأمر. التوكن الطويل
            // للـQR/الرابط المباشر على نفس الجهاز.
            'token' => $plain,
            'pin' => $pin,
            'expires_at' => $row->expires_at->toIso8601String(),
        ];
    }

    /**
     * ⭐ استعمل الرمز — **مرة واحدة**.
     *
     * ⭐ PR 4.6 — الاستعمال بقى **يسجّل الدخول** في
     * `lesson_participants` (مصدر `pin`) ويرجّع رابط الحصة.
     * قبل كده كان بيستهلك الرمز وخلاص، فسجل الدخول اللي بيغذّي
     * اقتراح الحضور مكانش بيعرف حاجة عن أطفال الـPIN.
     *
     * ⭐ والاستهلاك **ذرّي**: `UPDATE ... WHERE used_at IS NULL`
     * — طلبان في نفس اللحظة، واحد بس بياخد السطر.
     *
     * @return array{ok: bool, reason: string, student_id?: int, lesson_id?: int, meeting_url?: string|null}
     */
    public function redeem(string $pinOrToken, int $lessonId): array
    {
        $hash = $this->looksLikeToken($pinOrToken)
            ? hash('sha256', $pinOrToken)
            : null;

        $row = LessonJoinToken::query()
            ->where('lesson_id', $lessonId)
            ->when(
                $hash !== null,
                fn ($q) => $q->where('token_hash', $hash),
                fn ($q) => $q->where('pin', $pinOrToken),
            )
            ->first();

        if (! $row) {
            return ['ok' => false, 'reason' => 'الرمز مش صحيح'];
        }

        if ($row->used_at !== null) {
            // ⭐ مستعمل قبل كده — **مفيش** محاولة تانية.
            return ['ok' => false, 'reason' => 'الرمز اتستخدم قبل كده'];
        }

        if (Carbon::parse($row->expires_at)->isPast()) {
            return ['ok' => false, 'reason' => 'انتهت صلاحية الرمز'];
        }

        $student = $row->student;
        $lesson = $row->lesson;

        if ($student === null || $lesson === null) {
            return ['ok' => false, 'reason' => 'الحصة أو الطالب مش موجود'];
        }

        // ⭐ الاستهلاك الذرّي: أول طلب بيلاقي `used_at` فاضي وبياخده،
        // والتاني بياخد 0 صفوف — حتى لو الاتنين قرأوا السطر مع بعض.
        $claimed = LessonJoinToken::query()
            ->whereKey($row->id)
            ->whereNull('used_at')
            ->update([
                'used_at' => now(),
                // ⭐ `used_by` بيتكلم عن **حساب مستخدم** (FK على
                // `users`) — الطفل غالباً مالوش حساب، فبيبقى null.
                'used_by' => $student->user_id,
            ]);

        if ($claimed === 0) {
            return ['ok' => false, 'reason' => 'الرمز اتستخدم قبل كده'];
        }

        // ⭐ بنسجّل «دخول» بس لو الحصة فعلًا قابلة للدخول (حالة +
        // رابط) — نفس حكم `LessonJoinService`. الحصة الملغاة أو
        // اللي بلا رابط ما بتسجّلش مشارك كاذب.
        $join = app(LessonJoinService::class);

        if ($join->isJoinable($lesson)) {
            $join->record($lesson, $student, 'pin', (int) $row->id);
        }

        app(AuditLogService::class)->log(
            'lesson_join_token_redeemed',
            'lesson',
            (int) $row->lesson_id,
            null,
            ['student_id' => $row->student_id],
        );

        return [
            'ok' => true,
            'reason' => 'تم الدخول',
            'student_id' => (int) $row->student_id,
            'lesson_id' => (int) $row->lesson_id,
            'meeting_url' => $join->meetingUrl($lesson),
        ];
    }

    /** ⭐ PIN غير مكرر بين الرموز الحيّة */
    private function uniquePin(): string
    {
        do {
            $pin = (string) random_int(100000, 999999);
        } while (DB::table('lesson_join_tokens')
            ->where('pin', $pin)
            ->whereNull('used_at')
            ->exists());

        return $pin;
    }

    /**
     * ⭐ التمييز بين PIN (6 أرقام) والتوكن (40 حرف).
     *
     * ⭐ لازم التمييز يكون **بالطول** مش بالنوع: حد ممكن يبعت
     * رقم طويل غلط، والتعامل معاه كـPIN معناه بحث في جدول PIN
     * وفي نفس الوقت هاش واحد فاضي — رسالتين مختلفتين لنفس الغلط.
     */
    private function looksLikeToken(string $value): bool
    {
        return strlen($value) !== 6;
    }
}
