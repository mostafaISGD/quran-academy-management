<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Exceptions\InvalidLessonTransition;
use App\Models\Lesson;
use App\Models\LessonStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐⭐ **PR 3.1 — خدمة حالة الحصة (الكاتب الوحيد).**
 *
 * ⭐ **القاعدة**: `LessonService::transition` هو **القناة
 * الوحيدة** اللي بتكتب `lessons.status`. أي `->update(['status'
 * => …])` بره الخدمة = **بِغ** (وهو اللي كان موجود قبل PR ده
 * في `LessonController::cancel/complete/makeup` و`update`).
 *
 * ⭐ **ليش `lockForUpdate`؟** التحويل بيغيّر صف الحصة **وبيكتب
 * سطر في السجل** — كتابة على جدولين (القاعدة 8). والقفل بيمنع
 * طلبين متزامنين يحوّلوا نفس الحصة لحالتين مختلفتين (من
 * `confirmed` لـ`in_progress` ومنه لـ`student_absent` — النتيجة
 * صفح تاريخ contradict). للقفل معناه: الطلب التاني بيمسك
 * الحالة **بعد** الأول ويشوف التحويل مرفوض (القاعدة هي المرجع).
 *
 * ⭐ **كل تحويل يتسجَّل**: `from` + `to` + **الفاعل** + السبب —
 * عشان «مين غيّر الحالة وإزاي» يبقى له إجابة بعد سنة.
 */
final class LessonService
{
    /**
     * تحويل حالة الحصة — يرمي `InvalidLessonTransition` (422)
     * لو التحويل مش في جدول التحويلات.
     */
    public function transition(
        Lesson $lesson,
        LessonStatus $to,
        User $actor,
        ?string $reason = null,
    ): Lesson {
        return DB::transaction(function () use ($lesson, $to, $actor, $reason): Lesson {
            /** ⭐ القفل على صف الحصة — الطلب التاني ينتظر. */
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->id);

            $from = LessonStatus::fromDatabase((string) $locked->status);

            if (! $from->canTransitionTo($to)) {
                throw new InvalidLessonTransition($from, $to);
            }

            $attributes = ['status' => $to->value];

            // ⭐ أوقات الحقيقة: بداية الحصة وإكمالها.
            if ($to === LessonStatus::InProgress && ! $locked->actual_start) {
                $attributes['actual_start'] = now();
            }

            if ($to === LessonStatus::Completed) {
                // ⭐ الإكمال المباشر (من غير «بدأ») — بداية الحصة
                // الفعلية = الموعد المحدد، مش لحظة الضغط على زر.
                if (! $locked->actual_start) {
                    $attributes['actual_start'] = $locked->scheduled_start_at;
                }

                $attributes['actual_end'] = now();
            }

            // ⭐ الإلغاء لازم سبب — والإلغاء المتأخر Distinguished
            // بالإلغاء المبكر: late يعني الحصة بدأت.
            if ($to->isCancellation()) {
                $attributes['cancellation_reason'] = $reason;
            }

            $locked->forceFill($attributes)->save();

            LessonStatusHistory::create([
                'lesson_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor->id,
                'reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * ⭐ الإلغاء: **إلغاء مبكر** قبل بداية الحصة، **وإلغاء متأخر**
     * بعد ما تبدأ — والفرق ليس شكلياً: سياسات الرصيد والمستحقات
     * (PR 3.x) بتتعامل مع الاتنين بشكل مختلف.
     */
    public function cancel(Lesson $lesson, User $actor, string $reason): Lesson
    {
        $to = now()->greaterThanOrEqualTo($lesson->scheduled_start_at)
            ? LessonStatus::CancelledLate
            : LessonStatus::CancelledByAcademy;

        return $this->transition($lesson, $to, $actor, $reason);
    }
}
