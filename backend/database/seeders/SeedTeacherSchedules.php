<?php
/**
 * Seeder لجداول المعلمين — المرحلة ١ من خطة "نظام الجدولة والتوافر".
 *
 * المعلم المستقل بيشتغل في أكاديميّات متعددة، فبيسجّل commitments بتاعه.
 * هنا بنحط جدول واقعي متنوّع لكل معلم من الـ 15.
 */

namespace Database\Seeders;

use App\Models\Teacher;
use App\Models\TeacherSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SeedTeacherSchedules extends Seeder
{
    /**
     * جدول كل معلم: [weekday => [[start, end, kind, title], ...]]
     * weekday: 0=الأحد … 6=السبت
     */
    private function blueprintFor(Teacher $teacher): array
    {
        $spec = $teacher->specialization ?? '';

        // تخصيص حسب التخصص — المعلمين اللي بيعلّموا التحفيظ بيشتغلوا بدري
        if (str_contains($spec, 'تحفيظ')) {
            return [
                0 => [['16:00', '18:00', 'academy', null], ['19:00', '21:00', 'external', 'حلقة خاصة']],
                2 => [['16:00', '18:00', 'academy', null]],
                4 => [['18:00', '20:00', 'external', 'حلقة خاصة']],
                5 => [['10:00', '12:00', 'leave', 'إجازة أسبوعية']],
            ];
        }

        if (str_contains($spec, 'تجويد')) {
            return [
                1 => [['17:00', '19:00', 'academy', null]],
                3 => [['17:00', '19:00', 'academy', null], ['20:00', '22:00', 'external', 'تصحيح تسميعات']],
                4 => [['17:00', '19:00', 'academy', null]],
                5 => [['10:00', '14:00', 'leave', 'إجازة أسبوعية']],
            ];
        }

        if (str_contains($spec, 'تلاوة')) {
            return [
                0 => [['18:00', '20:00', 'academy', null]],
                2 => [['18:00', '20:00', 'academy', null]],
                6 => [['11:00', '14:00', 'external', 'حلقة أولياء الأمور']],
                5 => [['10:00', '13:00', 'leave', 'إجازة أسبوعية']],
            ];
        }

        if (str_contains($spec, 'مبتدئ')) {
            return [
                6 => [['10:00', '12:00', 'academy', null]],
                0 => [['10:00', '12:00', 'academy', null]],
                1 => [['10:00', '12:00', 'academy', null]],
                5 => [['09:00', '13:00', 'leave', 'إجازة أسبوعية']],
            ];
        }

        if (str_contains($spec, 'تسميع')) {
            return [
                1 => [['19:00', '21:00', 'academy', null]],
                4 => [['19:00', '21:00', 'academy', null]],
                6 => [['17:00', '20:00', 'external', 'تسميع جماعي']],
                5 => [['10:00', '14:00', 'leave', 'إجازة أسبوعية']],
            ];
        }

        if (str_contains($spec, 'تقوية')) {
            return [
                2 => [['20:00', '22:00', 'academy', null]],
                4 => [['20:00', '22:00', 'academy', null]],
                0 => [['20:00', '22:00', 'external', 'حلقة تقوية']],
                5 => [['10:00', '14:00', 'leave', 'إجازة أسبوعية']],
            ];
        }

        // fallback — أي تخصص تاني
        return [
            0 => [['17:00', '19:00', 'academy', null]],
            3 => [['17:00', '19:00', 'academy', null]],
            5 => [['10:00', '13:00', 'leave', 'إجازة أسبوعية']],
        ];
    }

    public function run(): void
    {
        DB::table('teacher_schedules')->delete();

        $teachers = Teacher::with('user')->orderBy('id')->get();
        $adminId = DB::table('users')->orderBy('id')->value('id');
        $total = 0;

        foreach ($teachers as $teacher) {
            $blueprint = $this->blueprintFor($teacher);

            foreach ($blueprint as $weekday => $slots) {
                foreach ($slots as [$start, $end, $kind, $title]) {
                    TeacherSchedule::create([
                        'teacher_id' => $teacher->id,
                        'weekday' => $weekday,
                        'starts_at' => $start,
                        'ends_at' => $end,
                        'kind' => $kind,
                        'title' => $title,
                        'is_recurring' => true,
                        'created_by' => $adminId,
                    ]);
                    $total++;
                }
            }
        }

        $this->command->info("  🌐 teacher_schedules: {$total} block(s) for {$teachers->count()} teacher(s)");
    }
}
