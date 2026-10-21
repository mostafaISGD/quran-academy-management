<?php

namespace App\Enums;

/**
 * ⭐⭐⭐ **PR 3.1 — حالات الحصة + جدول التحويلات.**
 *
 * ⭐ **القاعدة**: ما فيش تغيير حالة من غير قاعدة. `LessonStatus`
 * هو **المرجع الوحيد** للمفردات، و`allowedTransitions()` هو
 * **جدول التحويلات** — مش استعلامات موزّعة في الـcontroller.
 *
 * ⭐ **ليش حالة «نهائية» (terminal)؟** الحصة المكتملة أو
 * الملغاة أو المعوّضة **قرار نهائي** — أي تحويل بعدها يضيف
 * غموضاً في المستحقات (رصيد الحصة، مستحقات المعلم). التصحيح
 * المسموح بعدTerminal هو **قيد عكسي** في السجل المالي (القاعدة 7
 * في AGENTS.md) — مش تعديل الحالة.
 *
 * ⭐ **مفردات مغلقة**: القيم اللي كانت في الجدول قبل PR ده
 * (`cancelled` و`technical_issue`) اتحوّلت في الميجريشن إلى
 * `cancelled_by_academy` — والمفردات القديمة ما عادش لها معنى
 * (إلغاء الأكاديمية = سبب الأكاديمية، والعطل التقني كان بيقع
 * تحتها).
 */
enum LessonStatus: string
{
    case Scheduled = 'scheduled';
    case Confirmed = 'confirmed';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case StudentAbsent = 'student_absent';
    case TeacherAbsent = 'teacher_absent';
    case CancelledByAcademy = 'cancelled_by_academy';
    case CancelledLate = 'cancelled_late';
    case Rescheduled = 'rescheduled';

    /**
     * ⭐ جدول التحويلات المسموحة.
     *
     * - `scheduled` → تأكيد، بداية، **إكمال مباشر**، إلغاء، إعادة
     *   جدولة.
     * - `confirmed` → بداية، **إكمال مباشر**، غياب (الطالب/المعلم)،
     *   إلغاء، إعادة جدولة.
     * - `in_progress` → إكمال، غياب، إلغاء.
     * - الباقي **نهائي**.
     *
     * ⭐ **ليه الإكمال المباشر مسموح؟** المعلم بيسجّل الحصة
     * مكتملة من غير ما يضغط «بدأ» (المتابعة بتتم في الآخر) —
     * وده واقع تشغيلي مش استثناء. `in_progress` حالة اختيارية:
     * بتسجّل إن الحصة كانت شغالة فعلاً وقتتسجيلها.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Scheduled => [
                self::Confirmed,
                self::InProgress,
                self::Completed,
                self::CancelledByAcademy,
                self::CancelledLate,
                self::Rescheduled,
            ],
            self::Confirmed => [
                self::InProgress,
                self::Completed,
                self::StudentAbsent,
                self::TeacherAbsent,
                self::CancelledByAcademy,
                self::CancelledLate,
                self::Rescheduled,
            ],
            self::InProgress => [
                self::Completed,
                self::StudentAbsent,
                self::TeacherAbsent,
                self::CancelledByAcademy,
                self::CancelledLate,
            ],
            self::Completed,
            self::StudentAbsent,
            self::TeacherAbsent,
            self::CancelledByAcademy,
            self::CancelledLate,
            self::Rescheduled => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    /** ⭐ حالة نهائية — مفيش منها تحويل. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** ⭐ هل الحالة دي إلغاء؟ (يعني بتاخد سبب إلزامي) */
    public function isCancellation(): bool
    {
        return $this === self::CancelledByAcademy || $this === self::CancelledLate;
    }

    /** ⭐ التسمية العربية — للمستخدم (رسائل الخطأ + الواجهة). */
    public function label(): string
    {
        return match ($this) {
            self::Scheduled => 'مجدولة',
            self::Confirmed => 'مؤكدة',
            self::InProgress => 'جارية',
            self::Completed => 'مكتملة',
            self::StudentAbsent => 'الطالب غائب',
            self::TeacherAbsent => 'المعلم غائب',
            self::CancelledByAcademy => 'ملغاة من الأكاديمية',
            self::CancelledLate => 'ملغاة متأخرة',
            self::Rescheduled => 'مُعاد جدولتها',
        };
    }

    /**
     * ⭐ قراءة آمنة من قاعدة البيانات: لو في صف بمفردة قديمة
     * (قبل الميجريشن أو من استيراد قديم) ما ننفجرش — بنعتبرها
     * ملغاة من الأكاديمية (أقرب معنى).
     */
    public static function fromDatabase(string $value): self
    {
        return self::tryFrom($value) ?? match ($value) {
            'cancelled', 'technical_issue' => self::CancelledByAcademy,
            default => self::Scheduled,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
