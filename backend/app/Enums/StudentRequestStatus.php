<?php

namespace App\Enums;

/**
 * ⭐⭐ **حالة طلب ولي الأمر (PR 4.5).**
 *
 * `pending` → قرار إدارة (`approved` / `rejected`) أو سحب من الولي
 * (`withdrawn`). الثلاثة **نهائية** — القرار ما بيتغيّرش، والصح
 * لو الإدارة غلطت هو طلب جديد (نفس مبدأ السجلات المالية).
 */
enum StudentRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'قيد المراجعة',
            self::Approved => 'موافق عليه',
            self::Rejected => 'مرفوض',
            self::Withdrawn => 'مسحوب',
        };
    }

    /** ⭐ هل الحالة نهائية؟ (مفيش قرار تاني عليها) */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
