<?php

namespace App\Services;

use App\Enums\LessonStatus;
use App\Models\ActivityLog;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\SubscriptionPause;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ⭐⭐⭐ **PR 3.8 — إيقاف الاشتراك واستئنافه (بأثر حقيقي).**
 *
 * ⭐ قبل PR ده: `subscription_pauses` كان جدولًا فاضيًا (لا أحد
 * يكتب فيه)، و`PUT /subscriptions/{id}` كان يغيّر `status` مباشرة
 * ⇒ إيقاف بلا أثر: الحصص تبقى محجوزة، و`end_date` ما بيتزحزحش،
 * ومفيش سجل ولا إشعار.
 *
 * ⭐ **الأثر (بالقواعد القابلة للضبط)**:
 *
 * - **الحصص جوه نافذة الإيقاف**: `subscriptions.pause_lesson_action`
 *   = `cancel` (افتراضي) ⇒ تُلغى عبر آلة الحالات (فبتتسجَّل)،
 *   أو `shift` ⇒ بتتأجل بمدة الإيقاف.
 * - **نهاية الاشتراك**: `subscriptions.pause_extends_end_date`
 *   (افتراضي true) ⇒ `end_date` بيزاح أسبوعين.
 * - **الاستئناف**: بيعيد توليد الحصص الناقصة **من غير تكرار**
 *   (`SubscriptionActivationService::regenerateLessons`).
 */
final class SubscriptionPauseService
{
    public function __construct(
        private readonly LessonService $lessons,
        private readonly SubscriptionActivationService $activation,
    ) {}

    /**
     * ⭐ إيقاف الاشتراك — بترجع سطر الإيقاف.
     */
    public function pause(Subscription $subscription, User $actor, array $data): SubscriptionPause
    {
        return DB::transaction(function () use ($subscription, $actor, $data): SubscriptionPause {
            $start = \Carbon\Carbon::parse($data['start_date'])->startOfDay();
            $end = \Carbon\Carbon::parse($data['end_date'])->startOfDay();
            $days = max(1, (int) $start->diffInDays($end) + 1);

            $pause = SubscriptionPause::create([
                'subscription_id' => $subscription->id,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'days' => $days,
                'reason' => $data['reason'] ?? null,
                'status' => 'active',
                'created_by' => $actor->id,
            ]);

            // ⭐ الحالة من الخدمة بس (الـupdate يرفض التغيير المباشر).
            $subscription->forceFill(['status' => 'paused'])->save();

            // ⭐ أثر الإيقاف على الحصص.
            $this->applyPauseToLessons($subscription, $start, $end, $days, $actor);

            // ⭐ تمديد النهاية (قاعدة قابلة للضبط).
            if ($this->extendsEndDate($subscription) && $subscription->end_date !== null) {
                $subscription->forceFill([
                    'end_date' => $subscription->end_date->copy()->addDays($days)->toDateString(),
                ])->save();
            }

            $this->logActivity($subscription, 'subscription_paused', $actor, [
                'start_date' => $pause->start_date?->toDateString(),
                'end_date' => $pause->end_date?->toDateString(),
                'days' => $days,
                'reason' => $data['reason'] ?? null,
            ]);

            $this->notifyParent($subscription, 'subscription_paused', $actor, [
                'start_date' => $pause->start_date?->toDateString(),
                'end_date' => $pause->end_date?->toDateString(),
                'days' => $days,
                'reason' => $data['reason'] ?? null,
            ]);

            return $pause->fresh();
        });
    }

    /**
     * ⭐ استئناف الاشتراك — بيعيد توليد الحصص الناقصة.
     */
    public function resume(Subscription $subscription, User $actor): Subscription
    {
        return DB::transaction(function () use ($subscription, $actor): Subscription {
            $subscription->forceFill(['status' => 'active'])->save();

            SubscriptionPause::where('subscription_id', $subscription->id)
                ->where('status', 'active')
                ->update(['status' => 'ended']);

            // ⭐ إعادة التوليد: بتحسب الناقص بس ⇒ **من غير تكرار**.
            $regenerated = $this->activation->regenerateLessons($subscription);

            $this->logActivity($subscription, 'subscription_resumed', $actor, [
                'regenerated' => $regenerated['created'] ?? 0,
            ]);

            $this->notifyParent($subscription, 'subscription_resumed', $actor, [
                'regenerated' => $regenerated['created'] ?? 0,
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * ⭐ أثر الإيقاف على الحصص: إلغاء (افتراضي) أو تأجيل.
     */
    private function applyPauseToLessons(
        Subscription $subscription,
        \Carbon\Carbon $start,
        \Carbon\Carbon $end,
        int $days,
        User $actor,
    ): void {
        $action = (string) Setting::get(
            'subscriptions.pause_lesson_action',
            'cancel',
            $subscription->organization_id,
        );

        $lessons = Lesson::where('subscription_id', $subscription->id)
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->where('scheduled_start_at', '>=', $start)
            ->where('scheduled_start_at', '<=', $end->copy()->endOfDay())
            ->get();

        foreach ($lessons as $lesson) {
            if ($action === 'shift') {
                // ⭐ تأجيل: نفس المدة، ونفس قواعد الحجز (تعارض/عطلة).
                $this->lessons->reschedule($lesson, $days, $actor);

                continue;
            }

            // ⭐ إلغاء عبر **آلة الحالات** ⇒ بيتسجَّل في السجل.
            $this->lessons->transition(
                $lesson,
                LessonStatus::CancelledByAcademy,
                $actor,
                'إيقاف اشتراك',
            );
        }
    }

    /** ⭐ تمديد النهاية عند الإيقاف (قاعدة قابلة للضبط). */
    private function extendsEndDate(Subscription $subscription): bool
    {
        return (bool) Setting::get(
            'subscriptions.pause_extends_end_date',
            true,
            $subscription->organization_id,
        );
    }

    private function logActivity(Subscription $subscription, string $action, User $actor, array $meta): void
    {
        ActivityLog::create([
            'organization_id' => $subscription->organization_id,
            'user_id' => $actor->id,
            'subject_type' => 'subscription',
            'subject_id' => $subscription->id,
            'action' => $action,
            'description' => $action === 'subscription_paused' ? 'إيقاف اشتراك' : 'استئناف اشتراك',
            'meta' => $meta,
            'created_at' => now(),
        ]);
    }

    /**
     * ⭐ إشعار ولي الأمر (الربط المؤكد بس).
     */
    private function notifyParent(Subscription $subscription, string $eventType, User $actor, array $payload): void
    {
        $student = $subscription->student;

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
                'student_id' => $student->id,
                'subscription_id' => $subscription->id,
                'event_type' => $eventType,
                'channel' => 'in_app',
                'payload' => $payload,
                'sent_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
