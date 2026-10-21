<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Exceptions\InvalidLessonTransition;
use App\Models\ActivityLog;
use App\Models\Lesson;
use App\Models\LessonAttendance;
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
     * ⭐⭐⭐ **PR 3.8 — تأجيل حصة بمدة (أيام).**
     *
     * ⭐ بتستخدمها قاعدة الإيقاف (`pause_lesson_action = shift`):
     * الحصة بتتزحزح بنفس المدة — مع سطر في `lesson_reschedules`
     * (سجل مواعيد الحصة) عشان يبقى فيه تاريخ.
     */
    public function reschedule(Lesson $lesson, int $days, User $actor, ?string $reason = null): Lesson
    {
        return DB::transaction(function () use ($lesson, $days, $actor, $reason): Lesson {
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->id);

            $newStart = $locked->scheduled_start_at->copy()->addDays($days);
            $newEnd = $locked->scheduled_end_at->copy()->addDays($days);

            LessonReschedule::create([
                'lesson_id' => $locked->id,
                'old_start' => $locked->scheduled_start_at,
                'old_end' => $locked->scheduled_end_at,
                'new_start' => $newStart,
                'new_end' => $newEnd,
                'reason' => $reason ?? 'تأجيل بسبب إيقاف اشتراك',
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $locked->forceFill([
                'scheduled_start_at' => $newStart,
                'scheduled_end_at' => $newEnd,
            ])->save();

            return $locked->fresh();
        });
    }

    /**
     * تحويل حالة الحصة — يرمي `InvalidLessonTransition` (422)
     * لو التحويل مش في جدول التحويلات.
     */
    public function transition(
        Lesson $lesson,
        LessonStatus $to,
        ?User $actor = null,
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
                'actor_id' => $actor?->id,
                'reason' => $reason,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * ⭐⭐⭐ **PR 3.5 — تسجيل الحضور وقيادة النتيجة.**
     *
     * ⭐ **الحضور مش سجل جانبي** — هو اللي بيحدّد:
     *
     * - **نتيجة الحصة**: لو كل الطلاب حاضرين (ولا excuses) ⇒
     *   الحصة `completed`؛ لو فيه غايب ⇒ النتيجة **مش واحدة
     *   للحصة** (كل طالب ليه نتيجته في سجل الحضور).
     * - **الأثر المالي لكل طالب**: `present`/`late` ⇒ خصم حصة
     *   (حسب سياسة `student_absent`/السnapshot)؛ `absent`/
     *   `excused` ⇒ **بلا خصم** + **سطر حركة** يوثّق القرار
     *   (صفر خصم) ⇒ سجل الرصيد بيقول «ليه» لكل طالب.
     *
     * ⭐ **idempotent**: تسجيل نفس الحضور مرتين **ما بيخصفش
     * مرتين** — السطر بيتحدث (مش سطر جديد) والخصم بيتعمل مرة.
     *
     * @param array<int, array{student_id: int, status: string, late_minutes?: int|null, notes?: string|null}> $entries
     */
    public function recordAttendance(Lesson $lesson, array $entries, User $actor): array
    {
        return DB::transaction(function () use ($lesson, $entries, $actor): array {
            $locked = Lesson::query()->lockForUpdate()->findOrFail($lesson->id);

            $students = $locked->students()->pluck('students.id')->all();
            $single = $locked->student_id !== null ? [$locked->student_id] : [];

            $allowed = array_values(array_unique(array_merge($students, $single)));
            $results = [];

            foreach ($entries as $entry) {
                $studentId = (int) $entry['student_id'];

                if (! in_array($studentId, $allowed, true)) {
                    continue;
                }

                $status = (string) $entry['status'];

                $attendance = LessonAttendance::updateOrCreate(
                    ['lesson_id' => $locked->id, 'student_id' => $studentId],
                    [
                        'status' => $status,
                        'late_minutes' => $entry['late_minutes'] ?? null,
                        'marked_at' => now(),
                        'marked_by' => $actor->id,
                        'notes' => $entry['notes'] ?? null,
                    ],
                );

                $results[] = $attendance;

                // ⭐ الأثر المالي للطالب ده — من لقطة السياسة.
                $this->applyOutcomeToCredit($locked, $studentId, $status, $actor);
            }

            // ⭐ نتيجة الحصة الجماعية: المعلم قدّم الحصة ⇒ بتخلص،
            // و**غياب طالب ماشي حاله** (نتيجة لكل طالب في سجل
            // الحضور، مش حالة واحدة للحصة).
            if ($results !== [] && $locked->status !== LessonStatus::Completed->value) {
                $this->complete($locked, $actor);
            }

            return $results;
        });
    }

    /**
     * ⭐ الأثر المالي للحضور: خصم (حاضر/متأخر) أو سطر توثيق
     * (غائب/بعذر) — **بلا خصم** في الحالتين الأخيرتين.
     */
    private function applyOutcomeToCredit(Lesson $lesson, int $studentId, string $status, User $actor): void
    {
        $service = app(LessonCreditService::class);

        // ⭐ ناتج الحضور ⇢ نتيجة الحصة ⇢ سياسة الخصم.
        $outcome = match ($status) {
            'present', 'late' => 'completed',
            default => 'student_absent',
        };

        $deduct = $status === 'present' || $status === 'late';

        $service->recordAttendanceOutcome($lesson, $studentId, $outcome, $deduct, $actor);
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
