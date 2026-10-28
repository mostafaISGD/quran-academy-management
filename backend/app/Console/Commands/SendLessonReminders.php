<?php

namespace App\Console\Commands;

use App\Models\Lesson;
use App\Models\Organization;
use App\Models\Setting;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * ⭐⭐⭐ **PR 4.2 — تذكير الحصص القادمة (أمر مجدول).**
 *
 * - المدد **من مركز الإعدادات**: `notifications.lesson_reminder_offsets`
 *   (قائمة بالدقائق قبل الحصة — الافتراضي `60` دقيقة).
 * - كل (حصة، فترة) مفتاح ثابت: الآن ≥ بداية − الفترة وما زالت
 *   الحصة لم تبدأ ⇒ تذكير واحد بمفتاح `lesson:{id}:o{offset}` —
 *   **إعادة تشغيل الأمر لا تبعث مرتين** (dedup_key فريد في القاعدة).
 * - **القبول**: التذكير يعرض وقت الحصة **بتوقيت المستلم المحلي**
 *   (حساب المستخدم، ثم المنطقة الافتراضية للمؤسسة، ثم UTC).
 * - المستقبلون: حساب الطالب + حسابات أولياء المؤكدين + حساب المعلم —
 *   بلا حساب = بلا صندوق يُقرأ (قاعدة PR 4.1).
 *
 * ⭐ مجدول كل 10 دقائق (bootstrap/app.php) — `withoutOverlapping`.
 */
class SendLessonReminders extends Command
{
    protected $signature = 'lessons:send-reminders';

    protected $description = 'تذكير الحصص القادمة — بمدد من مركز الإعدادات ووقت محلي لكل مستلم';

    public function handle(NotificationService $notifications): int
    {
        $now = Carbon::now();

        $orgIds = Lesson::query()
            ->whereIn('status', ['scheduled', 'confirmed'])
            ->whereNotNull('scheduled_start_at')
            ->where('scheduled_start_at', '>', $now->copy()->subMinute())
            ->distinct()
            ->pluck('organization_id');

        $sentCount = 0;
        $lessonCount = 0;

        foreach ($orgIds as $orgId) {
            $offsets = $this->offsetsMinutes((int) $orgId);

            if ($offsets === []) {
                continue;
            }

            $maxOffset = max($offsets);

            $orgDefaultTz = (string) (Organization::query()->find($orgId)->default_timezone ?? 'UTC');

            // ⭐ نافذة الجولة = أقصى فترة للأمام — الحصة تُرشَّح
            // إذا مرّ موعد أي فترة معروفة. الحصص التي بدأت لا
            // تُذكَّر إطلاقاً.
            $lessons = Lesson::query()
                ->where('organization_id', (int) $orgId)
                ->whereIn('status', ['scheduled', 'confirmed'])
                ->whereNotNull('scheduled_start_at')
                ->where('scheduled_start_at', '>', $now->copy())
                ->where('scheduled_start_at', '<=', $now->copy()->addMinutes($maxOffset))
                ->with('teacher.user')
                ->get();

            foreach ($lessons as $lesson) {
                $startAt = Carbon::instance($lesson->scheduled_start_at);

                foreach ($offsets as $offset) {
                    if ($startAt->copy()->subMinutes($offset)->greaterThan($now)) {
                        continue; // موعد هذا التذكير لم يحن بعد.
                    }

                    // المستقبلون — حملة حساب فحسب (الصندوق للمستخدم).
                    $users = $notifications->studentAccountUsers($lesson->student);

                    if (($teacherUser = $lesson->teacher?->user) !== null) {
                        $users[] = $teacherUser;
                    }

                    foreach ($users as $user) {
                        $tz = trim((string) ($user->timezone ?? '')) !== ''
                            ? (string) $user->timezone
                            : $orgDefaultTz;

                        try {
                            $localTime = $startAt->copy()->setTimezone($tz)->format('Y-m-d H:i');
                        } catch (\Throwable) {
                            $localTime = $startAt->copy()->timezone('UTC')->format('Y-m-d H:i');
                        }

                        $notifications->safeDispatch('lesson_reminder', $user, [
                            'title' => 'تذكير بحصة',
                            'message' => sprintf(
                                'حصة تبدأ في %s (+%s د) بتوقيتك — لينك الحصة في صفحتها.',
                                $localTime,
                                (string) $offset,
                            ),
                            'lesson_id' => $lesson->id,
                            'lesson_time' => $localTime,
                            'lesson_timezone' => $tz,
                            'offset_minutes' => $offset,
                        ], sprintf('lesson:%d:o%d', $lesson->id, $offset));

                        $sentCount++;
                    }

                    $lessonCount++;
                }
            }
        }

        $this->info(sprintf('تمت معالجة %d تذكيراً لـ%d (حصة×فترة).', $sentCount, $lessonCount));

        return Command::SUCCESS;
    }

    /**
     * مدد التذكير (دقائق) من مركز الإعدادات — نص بفواصل أو
     * مصفوفة (نمط `MeetingLinks::allowedHosts`)، والافتراضي
     * من تعريف السجل نفسه.
     *
     * @return list<int>
     */
    private function offsetsMinutes(int $organizationId): array
    {
        $raw = Setting::get('notifications.lesson_reminder_offsets', null, $organizationId);

        $items = $raw === null
            ? (array) (\App\Support\SettingsRegistry::definition('notifications.lesson_reminder_offsets')['default'] ?? ['60'])
            : array_map('trim', explode(',', implode(',', (array) $raw)));

        return array_values(array_filter(
            array_map(fn ($v) => (int) $v, $items),
            fn ($v) => $v > 0,
        ));
    }
}
