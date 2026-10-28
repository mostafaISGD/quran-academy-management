<?php

namespace App\Enums;

/**
 * ⭐⭐ **نوع طلب ولي الأمر (PR 4.5).**
 *
 * ⭐ المفردات مغلقة هنا — الـcontroller والواجهة بيقروا منها،
 * والقاعدة (`student_requests.type`) بتخزّن نفس القيم.
 */
enum StudentRequestType: string
{
    case Cancel = 'cancel';
    case Postpone = 'postpone';
    case Suspend = 'suspend';

    /** ⭐ التسمية العربية — للمستخدم. */
    public function label(): string
    {
        return match ($this) {
            self::Cancel => 'إلغاء حصة',
            self::Postpone => 'تأجيل حصة',
            self::Suspend => 'تعليق الاشتراك',
        };
    }

    /** ⭐ الطلب مربوط بحصة معيّنة؟ */
    public function needsLesson(): bool
    {
        return $this === self::Cancel || $this === self::Postpone;
    }

    /** ⭐ الطلب مربوط باشتراك معيّن؟ */
    public function needsSubscription(): bool
    {
        return $this === self::Suspend;
    }

    /** ⭐ التأجيل لازم يحدد التاريخ الجديد. */
    public function needsDate(): bool
    {
        return $this === self::Postpone;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
