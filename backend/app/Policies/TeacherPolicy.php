<?php

namespace App\Policies;

use App\Models\Teacher;
use App\Models\User;
use App\Services\ParentChildScope;

/**
 * ⭐⭐⭐ **سياسة بيانات المعلم (PR 1.8).**
 *
 * - موظف (بلا بروفايلات) → غير مقيّد؛ الصلاحيات العامة هي الحكم.
 * - معلم → row بنفسه فقط: لا يقرأ بطاقة معلم آخر، ولا قايمة
 *   طلابه، ولا جدوله.
 * - ولي أمر → يرى المعلم فقط إذا كان يدرّس أحد أبنائه (وقايمة
 *   طلاب المعلم بمعرفة أبنائه).
 */
class TeacherPolicy
{
    public function view(User $user, Teacher $teacher): bool
    {
        $profile = $user->relationLoaded('teacher') ? $user->teacher : $user->teacher()->first();

        if ($profile) {
            return (int) $profile->id === (int) $teacher->id;
        }

        $parentProfile = $user->relationLoaded('parentProfile')
            ? $user->parentProfile
            : $user->parentProfile()->first();

        if ($parentProfile) {
            return ParentChildScope::teacherOf($user, $teacher->id);
        }

        return true;
    }

    /**
     * ⭐ قايمة طلاب معلم (`GET /teachers/{id}/students`) — نفس
     * قواعد مشاهدة المعلم: عياله اللي بيدرّسه، أو طلابه لو هو
     * معلم، أو كل شيء للموظف.
     */
    public function viewStudents(User $user, Teacher $teacher): bool
    {
        return $this->view($user, $teacher);
    }

    /**
     * ⭐ جدول حصص المعلم — نفس قاعدة مشاهدة المعلم.
     */
    public function schedule(User $user, Teacher $teacher): bool
    {
        return $this->view($user, $teacher);
    }
}
