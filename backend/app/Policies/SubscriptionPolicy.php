<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use App\Services\ParentChildScope;

/**
 * ⭐⭐⭐ **سياسة الاشتراكات (PR 1.8).**
 *
 * - موظف (بلا بروفايلات) → غير مقيّد؛ الصلاحيات العامة هي الحكم.
 * - ولي أمر → اشتراكات أبنائه فقط.
 * - معلم → اشتراكاته فقط (`teacher_id` يطابق بروفايله).
 * - طالب → اشتراكه نفسه.
 */
class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        $ids = ParentChildScope::allowedStudentIds($user);

        if ($ids === null) {
            return true;
        }

        $teacher = $user->relationLoaded('teacher') ? $user->teacher : $user->teacher()->first();

        if ($teacher) {
            return (int) $subscription->teacher_id === (int) $teacher->id;
        }

        return in_array($subscription->student_id, $ids, true);
    }
}
