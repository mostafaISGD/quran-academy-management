<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * يولّد ~500 حصة موزّعة على 6 شهور السابقة + أسبوع قادم،
 * مع الحضور وسجلات التقدم ومحاولات التحفيظ وإعادة الجدولة.
 */
class SeedLessons extends Seeder
{
    private const TARGET = 500;

    /** الحصص الموزعة على الأسابيع السابقة */
    private const PAST_WEEKS = 6;

    /** نسبة الحصص القادمة من المجموع */
    private const FUTURE_SHARE = 0.3;

    /** @var array<string, mixed> */
    private array $d;

    public function __construct()
    {
        $this->d = require database_path('data/realistic.php');
    }

    public function run(): void
    {
        mt_srand(20260404);

        $now = now();
        $nowTs = $now->timestamp;

        // الاشتراكات النشطة فقط — هي اللي ليها حصص
        $subs = DB::table('subscriptions')
            ->where('status', 'active')
            ->get();

        $levels = DB::table('levels')->get()->groupBy('program_id');

        $lessons = [];
        $attendance = [];
        $reschedules = [];
        $progress = [];
        $memorization = [];

        $lessonId = 0;
        $futureCount = 0;
        $rescheduleId = 0;
        $attId = 0;
        $progId = 0;
        $memoId = 0;

        // مصادر السور للتحفيظ
        $surahs = DB::table('quran_surahs')->get();

        // نولّد الحصص على شكل جدول أسبوعي لكل اشتراك
        // الأسبوع: السبت(6) .. الخميس(4) — الجمعة(5) إجازة
        $slots = [
            [6, 16], [6, 18], [6, 20],
            [0, 16], [0, 18], [0, 20],
            [1, 16], [1, 18], [1, 20],
            [2, 16], [2, 18], [2, 20],
            [3, 16], [3, 18], [3, 20],
            [4, 16], [4, 18], [4, 20],
        ];

        foreach ($subs as $index => $sub) {
            $levelList = $levels[$sub->program_id] ?? collect();
            $levelId = $levelList->isNotEmpty() ? $levelList->random()->id : null;

            // كل اشتراك نشط: عدد حصص أقل عشان نغطي طلاب أكثر
            $perWeek = [1, 2, 2][mt_rand(0, 2)];
            $slotIdx = [];
            $maxSlots = count($slots);
            $picked = 0;
            $guard = 0;
            while ($picked < $perWeek && $guard < 50) {
                $idx = mt_rand(0, $maxSlots - 1);
                if (!in_array($idx, $slotIdx, true)) {
                    $slotIdx[] = $idx;
                    $picked++;
                }
                $guard++;
            }

            // نمر على الأسابيع السابقة + الأسابيع القادمة (نسبة من الحصص)
            $futureCap = (int) ceil(self::TARGET * self::FUTURE_SHARE);
            for ($w = self::PAST_WEEKS; $w >= -6; $w--) {
                foreach ($slotIdx as $si) {
                    if ($lessonId >= self::TARGET) {
                        break 2;
                    }

                    // نحصر عدد الحصص القادمة
                    if ($w < 0 && $futureCount >= $futureCap) {
                        continue;
                    }

                    [$dow, $hour] = $slots[$si] ?? $slots[0];

                    // الوقت: نحسبه من الأسبوع الحالي
                    $weekStart = $now->copy()->startOfWeek(); // السبت
                    $date = $weekStart->copy()->addDays($dow)->setTime($hour, 0);
                    $date = $date->subWeeks($w);

                    if ($date->timestamp > $nowTs + 86400 * 2) {
                        continue; // ما نعملش حصص بعيدة جداً في المستقبل
                    }

                    $duration = $sub->lesson_duration_minutes ?: 30;
                    $start = $date->timestamp;
                    $end = $start + ($duration * 60);

                    // حالة الحصة حسب الوقت
                    $isFuture = $start > $nowTs;
                    $isPast = $end < $nowTs;

                    $lessonId++;
                    $lid = $lessonId;

                    // نوع الحصة: 80% عادية، والباقي متنوعة
                    $roll = mt_rand(0, 100);
                    $lessonType = match (true) {
                        $roll < 80 => 'regular',
                        $roll < 86 => 'makeup',
                        $roll < 90 => 'extra',
                        $roll < 94 => 'trial',
                        $roll < 97 => 'free',
                        default => 'assessment',
                    };

                    // الحالة: للماضية تكون مكتملة/ملغاة، وللمستقبلية مجدولة
                    $status = match (true) {
                        $isFuture => 'scheduled',
                        !$isPast => 'in_progress',
                        default => (function () {
                            $r = mt_rand(0, 100);
                            return match (true) {
                                $r < 84 => 'completed',
                                $r < 89 => 'student_absent',
                                $r < 92 => 'teacher_absent',
                                $r < 95 => 'cancelled',
                                $r < 97 => 'technical_issue',
                                default => 'rescheduled',
                            };
                        })(),
                    };

                    if ($isFuture) {
                        $futureCount++;
                    }

                    $lessons[] = [
                        'id' => $lid,
                        'organization_id' => 1,
                        'branch_id' => null,
                        'student_id' => $sub->student_id,
                        'teacher_id' => $sub->teacher_id,
                        'subscription_id' => $sub->id,
                        'program_id' => $sub->program_id,
                        'level_id' => $levelId,
                        'parent_lesson_id' => null,
                        'lesson_type' => $lessonType,
                        'scheduled_start_at' => date('Y-m-d H:i:s', $start),
                        'scheduled_end_at' => date('Y-m-d H:i:s', $end),
                        'actual_start' => $status === 'completed' ? date('Y-m-d H:i:s', $start) : null,
                        'actual_end' => $status === 'completed' ? date('Y-m-d H:i:s', $end - mt_rand(0, 300)) : null,
                        'duration_minutes' => $duration,
                        'meeting_provider' => ['zoom', 'google_meet', 'other'][mt_rand(0, 2)],
                        'meeting_url' => 'https://meet.example.com/' . Str::random(11),
                        'meeting_id' => mt_rand(100000, 999999),
                        'meeting_password' => mt_rand(1000, 9999),
                        'status' => $status,
                        'cancellation_reason' => in_array($status, ['cancelled', 'student_absent', 'teacher_absent'], true)
                            ? $this->d['cancellation_reasons'][mt_rand(0, 4)]
                            : null,
                        'notes' => null,
                        'created_at' => $now, 'updated_at' => $now,
                    ];

                    // الحضور للمكتملة
                    if ($status === 'completed') {
                        $attStatus = (mt_rand(0, 100) < 78) ? 'present'
                            : ((mt_rand(0, 100) < 60) ? 'late' : 'absent');

                        $attId++;
                        $attendance[] = [
                            'id' => $attId,
                            'lesson_id' => $lid, 'student_id' => $sub->student_id,
                            'status' => $attStatus,
                            'late_minutes' => $attStatus === 'late' ? mt_rand(3, 20) : null,
                            'marked_at' => date('Y-m-d H:i:s', $end + 300),
                            'marked_by' => 1,
                            'notes' => null,
                            'created_at' => $now, 'updated_at' => $now,
                        ];

                        // سجل تقدم
                        $category = ['memorization', 'tajweed', 'pronunciation', 'reading', 'fluency', 'revision'][mt_rand(0, 5)];
                        if (mt_rand(0, 100) < 55) {
                            $progId++;
                            $progress[] = [
                                'id' => $progId,
                                'student_id' => $sub->student_id, 'lesson_id' => $lid,
                                'teacher_id' => $sub->teacher_id,
                                'program_id' => $sub->program_id, 'level_id' => $levelId,
                                'category' => $category,
                                'score' => mt_rand(45, 100),
                                'notes' => null,
                                'recorded_at' => date('Y-m-d H:i:s', $end),
                                'created_at' => $now, 'updated_at' => $now,
                            ];
                        }

                        // سجل تحفيظ
                        if ($category === 'memorization' || mt_rand(0, 100) < 22) {
                            $surah = $surahs->random();
                            $from = mt_rand(1, max(1, $surah->ayah_count - 5));
                            $to = min($surah->ayah_count, $from + mt_rand(2, 12));
                            $memoId++;
                            $memorization[] = [
                                'id' => $memoId,
                                'student_id' => $sub->student_id, 'lesson_id' => $lid,
                                'teacher_id' => $sub->teacher_id,
                                'surah_id' => $surah->id,
                                'from_ayah' => $from, 'to_ayah' => $to,
                                'quality' => mt_rand(2, 5),
                                'notes' => null,
                                'recorded_at' => date('Y-m-d H:i:s', $end),
                                'created_at' => $now, 'updated_at' => $now,
                            ];
                        }
                    }

                    // إعادة جدولة
                    if ($status === 'rescheduled') {
                        $newStart = $start + 86400 * 2;
                        $rescheduleId++;
                        $reschedules[] = [
                            'id' => $rescheduleId,
                            'lesson_id' => $lid,
                            'old_start' => date('Y-m-d H:i:s', $start),
                            'old_end' => date('Y-m-d H:i:s', $end),
                            'new_start' => date('Y-m-d H:i:s', $newStart),
                            'new_end' => date('Y-m-d H:i:s', $newStart + ($duration * 60)),
                            'reason' => $this->d['cancellation_reasons'][mt_rand(0, 4)],
                            'changed_by' => 1,
                            'created_at' => $now,
                        ];
                    }
                }
            }
        }

        foreach (array_chunk($lessons, 100) as $chunk) {
            DB::table('lessons')->insert($chunk);
        }
        if ($attendance) {
            foreach (array_chunk($attendance, 100) as $chunk) {
                DB::table('lesson_attendance')->insert($chunk);
            }
        }
        if ($reschedules) {
            DB::table('lesson_reschedules')->insert($reschedules);
        }
        if ($progress) {
            foreach (array_chunk($progress, 100) as $chunk) {
                DB::table('progress_records')->insert($chunk);
            }
        }
        if ($memorization) {
            foreach (array_chunk($memorization, 100) as $chunk) {
                DB::table('memorization_records')->insert($chunk);
            }
        }

        $this->command?->info('   → ' . count($lessons) . ' حصة');
        $this->command?->info('   → ' . count($attendance) . ' سجل حضور');
        $this->command?->info('   → ' . count($progress) . ' سجل تقدم');
        $this->command?->info('   → ' . count($memorization) . ' سجل تحفيظ');
        if ($reschedules) {
            $this->command?->info('   → ' . count($reschedules) . ' إعادة جدولة');
        }
    }
}