<?php

namespace Database\Seeders;

use App\Models\SubscriptionPlan;
use Illuminate\Database\Seeder;

/**
 * ⭐ الـ ٢٨ باقة — الأسعار الثابتة للأكاديمية.
 *
 * ⭐ **ليش ده Seeder مش Migration؟**
 *
 * لأن الأسعار **بيانات مش هيكل** — الإدارة ممكن تعدلها،
 * والـ seeder بيشتغل مرة واحدة (أو لو اتعمل `migrate:fresh --seed`).
 * لو كانت في Migration، أي تعديل على الأسعار كان هيضيع لما
 * حد يعمل `migrate:fresh`.
 *
 * ⭐ الفئات: traditional, golden, group
 * المدد: 30, 45, 60 دقيقة
 * الحصص: 4, 8, 12, 16 حصة
 */
class PricingSeeder extends Seeder
{
    public function run(): void
    {
        $orgId = 1;

        // ⭐ مسح الباقات المشتركة القديمة (اللي أنشأتها الـ migration بالأسعار القديمة)
        SubscriptionPlan::where('organization_id', $orgId)
            ->whereNull('program_id')
            ->delete();

        // ═══ 1. الباقات التقليدية ═══
        $traditional = [
            ['duration' => 30, 'lessons' => 4,  'price' => 200],
            ['duration' => 30, 'lessons' => 8,  'price' => 350],
            ['duration' => 30, 'lessons' => 12, 'price' => 500],
            ['duration' => 30, 'lessons' => 16, 'price' => 650],

            ['duration' => 45, 'lessons' => 4,  'price' => 280],
            ['duration' => 45, 'lessons' => 8,  'price' => 500],
            ['duration' => 45, 'lessons' => 12, 'price' => 700],
            ['duration' => 45, 'lessons' => 16, 'price' => 900],

            ['duration' => 60, 'lessons' => 4,  'price' => 350],
            ['duration' => 60, 'lessons' => 8,  'price' => 620],
            ['duration' => 60, 'lessons' => 12, 'price' => 880],
            ['duration' => 60, 'lessons' => 16, 'price' => 1100],
        ];

        // ═══ 2. الباقات الذهبية ═══
        $golden = [
            ['duration' => 30, 'lessons' => 4,  'price' => 300],
            ['duration' => 30, 'lessons' => 8,  'price' => 550],
            ['duration' => 30, 'lessons' => 12, 'price' => 750],
            ['duration' => 30, 'lessons' => 16, 'price' => 1000],

            ['duration' => 45, 'lessons' => 4,  'price' => 450],
            ['duration' => 45, 'lessons' => 8,  'price' => 750],
            ['duration' => 45, 'lessons' => 12, 'price' => 1050],
            ['duration' => 45, 'lessons' => 16, 'price' => 1350],

            ['duration' => 60, 'lessons' => 4,  'price' => 550],
            ['duration' => 60, 'lessons' => 8,  'price' => 950],
            ['duration' => 60, 'lessons' => 12, 'price' => 1350],
            ['duration' => 60, 'lessons' => 16, 'price' => 1650],
        ];

        // ═══ 3. باقات المجموعات (60 دقيقة بس) ═══
        $group = [
            ['duration' => 60, 'lessons' => 4,  'price' => 150],
            ['duration' => 60, 'lessons' => 8,  'price' => 250],
            ['duration' => 60, 'lessons' => 12, 'price' => 350],
            ['duration' => 60, 'lessons' => 16, 'price' => 450],
        ];

        $this->createPlans('traditional', $traditional, $orgId, 'الباقات التقليدية — حصص فردية');
        $this->createPlans('golden', $golden, $orgId, 'الباقات الذهبية — مميزات إضافية');
        $this->createPlans('group', $group, $orgId, 'حصة جماعية — سعر مخفّض');

        $this->command?->info('   → ' . SubscriptionPlan::where('organization_id', $orgId)->count() . ' باقة تم إنشاؤها');
    }

    private function createPlans(string $category, array $rows, int $orgId, string $description): void
    {
        $billingType = $category === 'group' ? 'monthly' : 'monthly';
        $lessonCounts = [4, 8, 12, 16];
        $durations = [30, 45, 60];

        $sort = 0;
        foreach ($durations as $duration) {
            foreach ($lessonCounts as $lessons) {
                // المجموعات 60 دقيقة بس
                if ($category === 'group' && $duration !== 60) {
                    continue;
                }

                $match = collect($rows)->first(function ($r) use ($duration, $lessons) {
                    return $r['duration'] === $duration && $r['lessons'] === $lessons;
                });

                if (! $match) {
                    continue;
                }

                SubscriptionPlan::updateOrCreate(
                    [
                        'organization_id' => $orgId,
                        'category' => $category,
                        'lesson_duration_minutes' => $duration,
                        'lessons_count' => $lessons,
                    ],
                    [
                        'name' => $this->makeName($category, $duration, $lessons),
                        'program_id' => null,
                        'billing_type' => $billingType,
                        'price' => $match['price'],
                        'currency' => 'EGP',
                        'status' => 'active',
                        'sort_order' => $sort++,
                        'description' => $description,
                    ],
                );
            }
        }
    }

    private function makeName(string $category, int $duration, int $lessons): string
    {
        $catLabel = match ($category) {
            'traditional' => 'تقليدي',
            'golden' => 'ذهبي',
            'group' => 'مجموعات',
            default => $category,
        };

        return "{$catLabel} — {$duration} دقيقة — {$lessons} حصة";
    }
}