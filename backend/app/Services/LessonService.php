<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Exceptions\InvalidLessonTransition;
use App\Models\ActivityLog;
use App\Models\Lesson;
use App\Models\LessonStatusHistory;
use App\Models\Notification;
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

            // ⭐⭐⭐ **PR 3.2 — لقطة السياسة.**
            //
            // النسخة المطبَّقة وقت التحويل بتتخزّن على الحصة — فبتعديل
            // السياسة بعدين ما بيغيّرش history الحصة دي (والعكس:
            // الحصة الجديدة بتاخد السياسة الجديدة).
            $attributes['policy_snapshot'] = app(LessonPolicyService::class)->resolve(
                (int) $locked->organization_id,
                $to->value,
                $locked->program_id === null ? null : (int) $locked->program_id,
            );

            $locked->forceFill($attributes)->save();

            // ⭐⭐⭐ **PR 3.3 — استهلاك رصيد الحصص.**
            //
            // بيشتغل **بعد** حفظ اللقطة (اللي بتقول هل النتيجة
            // بتخصم ولا لأ) و**جوّه نفس الـtransaction** — فإما
            // الحصة بتخلص عادي والرصيد بينقص، أو كله بيرجع (422
            // لما الرصيد ما يكفيش والسحب ممنوع).
            app(LessonCreditService::class)->consume($locked, $actor);

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
     * ⭐⭐⭐ **PR 3.4 — إتمام الحصة (خط أنابيب كامل، idempotent).**
     *
     * الأثر الواحد = أربع حركات، **كلها في نفس الـtransaction**:
     *
     * ① تحويل الحالة (`completed`) + سطر في `lesson_status_history`.
     * ② استهلاك رصيد الطالب حسب لقطة السياسة.
     * ③ مستحق المعلم بسعر **اللحظة** (`rate_snapshot`).
     * ④ سطر في سجل النشاط + إشعار لولي الأمر.
     *
     * ⭐ **idempotent**: النداء التاني = **بلا أثر** — بيرجّع
     * الحالة الحالية (200). قبل PR ده كان بيرجّع 422 (التحويل
     * من `completed` لـ`completed` ممنوع في جدول التحويلات).
     */
    public function complete(Lesson $lesson, User $actor): Lesson
    {
        return DB::transaction(function () use ($lesson, $actor): Lesson {
            // ⭐ النداء التاني — الحالة خلاص وصلت، نرجّعها زي ما هي.
            if ($lesson->status === LessonStatus::Completed->value) {
                return $lesson->fresh();
            }

            $completed = $this->transition($lesson, LessonStatus::Completed, $actor);

            // ⭐ ③ المستحق (بلقطة السعر) — idempotent برضه.
            app(TeacherEarningService::class)->ensureForLesson($completed, $actor);

            // ⭐ ④ سجل النشاط + إشعار ولي الأمر.
            $this->logActivity($completed, 'lesson_completed', $actor, 'الحصة اتقبلت');
            $this->notifyParentsOfCompletion($completed, $actor);

            return $completed;
        });
    }

    /**
     * ⭐⭐⭐ **PR 3.4 — تصحيح حالة بعد الإتمام.**
     *
     * ⭐ **القاعدة 7**: التصحيح **مش تعديل** — كل أثر بيتعكس
     * بحركة جديدة:
     *
     * - مستحق المعلم → `cancelled` (مع سبب) — و**ما بينفعش** لو
     *   اتصرف فعلاً (المستحق مدفوع ⇒ العكس محتاج قيد في دفتر
     *   المعلم، ودفتر المعلم مش شغل هنا ⇒ 422).
     * - رصيد الطالب → سطر `refund` (رجوع الحصة).
     *
     * ⭐ **تجاوز جدول التحويلات**: التصحيح قرار إداري بعد قفل
     * الحالة — فبينتاقله ومسجَّل في `lesson_status_history` +
     * `activity_logs` (مش صامت).
     */
    public function correct(Lesson $lesson, LessonStatus $to, User $actor, string $reason): Lesson
    {
        return DB::transaction(function () use ($lesson, $to, $actor, $reason): Lesson {
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->id);

            $from = LessonStatus::fromDatabase((string) $locked->status);

            if ($from === $to) {
                return $locked->fresh();
            }

            // ⭐ المستحق المدفوع — التصحيح مرفوض.
            app(TeacherEarningService::class)->reverseForLesson($locked, $actor, $reason);

            // ⭐ عكس الرصيد — لو اتخصم (الخدمة بتتأكد بنفسها).
            app(LessonCreditService::class)->refund($locked, $actor, 'تصحيح: '.$reason);

            $locked->forceFill(['status' => $to->value])->save();

            // ⭐ التوثيق: سطر في سجل التحويلات + سجل النشاط.
            LessonStatusHistory::create([
                'lesson_id' => $locked->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'actor_id' => $actor->id,
                'reason' => 'تصحيح: '.$reason,
            ]);

            $this->logActivity($locked, 'lesson_corrected', $actor, 'تصحيح حالة الحصة', [
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * ⭐ سطر في سجل النشاط.
     */
    private function logActivity(
        Lesson $lesson,
        string $action,
        User $actor,
        ?string $description = null,
        array $meta = [],
    ): void {
        ActivityLog::create([
            'organization_id' => $lesson->organization_id,
            'user_id' => $actor->id,
            'subject_type' => 'lesson',
            'subject_id' => $lesson->id,
            'action' => $action,
            'description' => $description,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * ⭐ إشعار ولي الأمر بإتمام الحصة.
     *
     * ⭐ **مَن؟** أولياء أمر الطالب (الربط المؤكد بس — زي
     * `ParentChildScope`).
     */
    private function notifyParentsOfCompletion(Lesson $lesson, User $actor): void
    {
        $student = $lesson->student;

        if ($student === null) {
            return;
        }

        $parents = $student->parents()
            ->wherePivot('status', 'verified')
            ->with('user')
            ->get();

        foreach ($parents as $parent) {
            if ($parent->user === null) {
                continue;
            }

            Notification::create([
                'user_id' => $parent->user->id,
                'lesson_id' => $lesson->id,
                'event_type' => 'lesson_completed',
                'channel' => 'in_app',
                'payload' => [
                    'lesson_id' => $lesson->id,
                    'student_id' => $student->id,
                    'program_id' => $lesson->program_id,
                    'completed_at' => now()->toIso8601String(),
                ],
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
