<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * حضور وانصراف الشهرين اللي فاتوا.
 *
 * واقعي مش مثالي: فيه أيام مش مسجّلة (الأدمن مش بيسجّل كل يوم)،
 * وفيه تأخيرات، وإجازات، ونص يوم. عشان كده نقدر نختبر الحسابات
 * صح.
 */
class SeedAttendance extends Seeder
{
    /** كام يوم رجوع */
    private const DAYS_BACK = 60;

    public function run(): void
    {
        mt_srand(20261005);

        $now = now();

        // الموظفين اللي ليهم دوام — المتطوعين بلا حضور
        $employees = DB::table('employees')
            ->whereIn('status', ['active', 'on_leave'])
            ->whereNotIn('employment_type', ['volunteer'])
            ->get();

        if ($employees->isEmpty()) {
            $this->command?->warn('⚠️  مفيش موظفين بحضور — اتأكد إن SeedEmployees شغال');
            return;
        }

        $adminId = DB::table('users')->where('email', 'admin@quran-academy.com')->value('id');

        $rows = [];
        $cursor = $now->copy()->subDays(self::DAYS_BACK)->startOfDay();
        $today = $now->copy()->startOfDay();

        while ($cursor->lte($today)) {
            // الجمعة إجازة أسبوعية — مفيش حضور خالص
            if ($cursor->dayOfWeek !== 5) {
                foreach ($employees as $employee) {
                    // ٨٪ من الأيام مش مسجّلة — الأدمر مش بيسجّل كل يوم
                    if (mt_rand(0, 99) < 8) {
                        continue;
                    }

                    $roll = mt_rand(0, 99);

                    $status = match (true) {
                        $roll < 4 => 'absent',
                        $roll < 8 => 'late',
                        $roll < 12 => 'on_leave',
                        $roll < 15 => 'half_day',
                        default => 'present',
                    };

                    $checkIn = null;
                    $checkOut = null;
                    $lateMinutes = 0;
                    $workedHours = 0.0;

                    if (in_array($status, ['present', 'late', 'half_day'], true)) {
                        $lateMinutes = $status === 'late' ? mt_rand(5, 45) : mt_rand(0, 4);

                        $startMinutes = (8 * 60) + mt_rand(0, 30) + $lateMinutes;
                        $checkIn = $this->toTime($startMinutes);

                        if ($status === 'half_day') {
                            $workedHours = 4.0;
                            $checkOut = $this->toTime($startMinutes + (4 * 60));
                        } else {
                            $endMinutes = $startMinutes + mt_rand(7, 9) * 60 + mt_rand(0, 45);
                            $checkOut = $this->toTime($endMinutes);
                            $workedHours = round(($endMinutes - $startMinutes) / 60, 2);
                        }
                    }

                    $rows[] = [
                        'organization_id' => 1,
                        'employee_id' => $employee->id,
                        'date' => $cursor->toDateString(),
                        'status' => $status,
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'late_minutes' => $lateMinutes,
                        'worked_hours' => $workedHours,
                        'notes' => null,
                        'marked_by' => $adminId,
                        'created_at' => $cursor,
                        'updated_at' => $cursor,
                    ];
                }
            }

            $cursor->addDay();
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('attendance_records')->insert($chunk);
        }

        // إحصائيات سريعة للطباعة
        $stats = DB::table('attendance_records')
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        $line = [];
        foreach (['present' => 'حاضر', 'late' => 'متأخر', 'absent' => 'غائب', 'on_leave' => 'إجازة', 'half_day' => 'نص يوم'] as $k => $label) {
            if (isset($stats[$k])) {
                $line[] = "{$label} {$stats[$k]}";
            }
        }

        $this->command?->info('✅ ' . count($rows) . ' سجل حضور — ' . implode(' · ', $line));
    }

    /** دقائق من منتصف الليل → HH:MM (مع لفّ لو عدّى اليوم) */
    private function toTime(int $minutes): string
    {
        $minutes %= 24 * 60;

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
